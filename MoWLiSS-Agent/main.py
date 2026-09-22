import platform
import socket
import sys
import threading

import requests

import blocker
import scanner
import location as location_mod
import commands
import tray
import enroll_ui
import ws_client
from common import load_credentials, log_audit


def reconcile(desired_controls, state):
    agent_enabled = desired_controls.get("agent_enabled", True)
    website_blocker = desired_controls.get("website_blocker", False)
    url_scanner = desired_controls.get("url_scanner_activation", False)
    state["live_location"] = desired_controls.get("live_location", False)

    if state.get("killed"):
        # Forced maximum restriction until an admin clears it server-side -
        # ignores the normal website_blocker toggle entirely.
        if not blocker.is_enabled():
            blocker.enable()
            log_audit("control_changed", {"control": "website_blocker", "enabled": True, "reason": "killswitch"})
        return

    want_blocker = agent_enabled and website_blocker
    if want_blocker != blocker.is_enabled():
        (blocker.enable if want_blocker else blocker.disable)()
        log_audit("control_changed", {"control": "website_blocker", "enabled": want_blocker})

    want_scanner = agent_enabled and url_scanner
    if want_scanner != scanner.is_enabled():
        (scanner.enable if want_scanner else scanner.disable)()
        log_audit("control_changed", {"control": "url_scanner_activation", "enabled": want_scanner})


def do_poll_once(creds, state, icon):
    """Runs a poll/reconcile cycle against device_poll.php.

    Called both by the regular timed loop and by the WebSocket "poll_now" nudge, so a
    lock guards against the two racing and firing overlapping requests. A nudge that
    arrives while a poll is already in flight doesn't just get dropped though - it sets
    poll_again, which makes the in-flight call immediately run one more cycle once it
    finishes, so whatever the nudge was about (e.g. a newly-issued command) still gets
    picked up right away instead of waiting for the next periodic poll.
    """
    while not state["stop_event"].is_set():
        if not state["poll_lock"].acquire(blocking=False):
            state["poll_again"].set()
            return
        state["poll_again"].clear()
        try:
            _run_poll_cycle(creds, state, icon)
        finally:
            state["poll_lock"].release()

        if not state["poll_again"].is_set():
            return
        # else: a nudge landed mid-poll - go again right away rather than losing it.


def _run_poll_cycle(creds, state, icon):
    try:
        server_url = creds["server_url"]
        headers = {"Authorization": f"Bearer {creds['token']}"}

        body = {
            "device_name": socket.gethostname(),
            "os_info": platform.platform(),
            "agent_version": "0.1.0",
        }
        # Always attempt a location lookup and let the server decide whether to persist
        # it (device_poll.php only stores it if live_location is currently enabled there).
        # Gating this client-side on last-known state would lag one full poll cycle behind
        # since that state is only updated by reconcile() after this request completes.
        #
        # Run it in its own thread with a hard join timeout rather than calling it
        # directly: location.py has its own internal timeout, but that's a cooperative
        # asyncio one, and a genuine hang inside the underlying WinRT/COM call wouldn't
        # necessarily respect it. This is the real backstop - if location lookup ever
        # wedges, it must never be able to hold poll_lock (and therefore all command
        # delivery, including remote_lock/app_delete) hostage indefinitely.
        #
        # Threads can't be killed in Python, so if a lookup is genuinely stuck (not just
        # slow), joining with a timeout bounds *this* poll's wait but leaves that thread
        # running forever. Spawning a fresh one every cycle on top of that would leak one
        # blocked thread per poll for the life of the process. Instead, track the one
        # outstanding attempt in state: if it's still alive, skip fetching this cycle
        # rather than piling on another - a real WLS hang then costs one permanently
        # stuck thread total, not one per poll.
        prev_thread = state.get("location_thread")
        if prev_thread is not None and prev_thread.is_alive():
            log_audit("location_fetch_skipped_prior_still_hung", {})
        else:
            loc_result = {}

            def _fetch_location():
                loc_result["value"] = location_mod.get_location()

            loc_thread = threading.Thread(target=_fetch_location, daemon=True)
            state["location_thread"] = loc_thread
            loc_thread.start()
            loc_thread.join(timeout=10)
            if loc_thread.is_alive():
                log_audit("location_fetch_hung", {"timeout_s": 10})
            elif loc_result.get("value"):
                body["location"] = loc_result["value"]

        resp = requests.post(f"{server_url}/device_poll.php", json=body, headers=headers, timeout=15)

        if resp.status_code == 410:
            # Server has fully removed this device already (e.g. deleted while this
            # agent was offline) - clean up locally rather than polling forever.
            log_audit("device_retired_remotely", {})
            tray.update_status(icon, state, "Retired", tray.icon_offline(), "MoWLiSS Agent - retired")
            state["stop_event"].set()
            icon.stop()
            return

        if resp.status_code != 200:
            # Anything else (401 invalid/expired token, 5xx, etc.) is an error response,
            # not a real poll result - it must be treated the same as a network failure
            # below, NOT parsed as if it were {"controls": {...}}. A 401 error body still
            # decodes as valid JSON with no "controls" key, and reconcile()'s defaults
            # (website_blocker/url_scanner default to False) would otherwise silently
            # switch protections off just because a token expired - exactly what the
            # RequestException handler below is there to prevent.
            log_audit("poll_http_error", {"status_code": resp.status_code, "body": resp.text[:500]})
            # Shown status stays "active" - connectivity/server errors are not something
            # an end user can act on, and protections are still fully enforced underneath
            # (see the RequestException handler below). Only an actual admin action
            # (killswitch/lock/retire) should ever change what the tray displays.
            tray.update_status(
                icon, state, "Protected (reconnecting to server)", tray.icon_active(),
                "MoWLiSS Agent - protections active, reconnecting..."
            )
            return

        data = resp.json()
        device_status = data.get("device_status", "active")
        state["killed"] = device_status == "killed"

        reconcile(data.get("controls", {}), state)

        for cmd in data.get("commands", []):
            commands.handle_command(cmd, server_url, headers, state, blocker)

        if state.get("killed"):
            tray.update_status(icon, state, "Locked down by admin", tray.icon_killed(), "MoWLiSS Agent - locked down")
        elif device_status == "locked":
            tray.update_status(icon, state, "Locked", tray.icon_locked(), "MoWLiSS Agent - locked")
        else:
            tray.update_status(
                icon, state, "Protected (online)", tray.icon_active(),
                f"MoWLiSS Agent - enrolled to {creds.get('owner_display_name', '?')}"
            )

    except requests.RequestException as e:
        log_audit("poll_error", {"error": str(e)})
        # Same reasoning as the non-200 branch above: stay looking "active" rather than
        # flipping to an offline-looking icon over a transient network failure. The full
        # error detail still goes to the audit log for anyone actually troubleshooting.
        tray.update_status(
            icon, state, "Protected (reconnecting to server)", tray.icon_active(),
            "MoWLiSS Agent - protections active, reconnecting..."
        )
        # Deliberately do NOT revert blocker/scanner state on network failure -
        # going offline should never silently disable protections already in place.
    except Exception as e:
        log_audit("poll_unexpected_error", {"error": str(e)})


def poll_loop(creds, state, icon):
    # Safety-net cadence - the WebSocket "poll_now" nudge (see ws_client.py) is what
    # makes admin actions land instantly; this loop just guarantees the agent still
    # checks in periodically if the push connection is ever down.
    poll_interval = creds.get("poll_interval_seconds", 30)

    while not state["stop_event"].is_set():
        do_poll_once(creds, state, icon)
        state["stop_event"].wait(poll_interval)


def on_quit(icon, item, state):
    log_audit("agent_quit", {})
    state["stop_event"].set()
    icon.stop()


def main():
    creds = load_credentials()
    if creds is None:
        enrolled = enroll_ui.run_enrollment()
        if not enrolled:
            sys.exit(0)
        creds = load_credentials()

    try:
        commands.set_autostart(sys.executable)
    except Exception as e:
        log_audit("autostart_error", {"error": str(e)})

    state = {
        "stop_event": threading.Event(),
        "killed": False,
        "live_location": False,
        "poll_lock": threading.Lock(),
        "poll_again": threading.Event(),
        "location_thread": None,
    }
    icon = tray.build_tray(state, lambda ic, it: on_quit(ic, it, state))

    threading.Thread(target=poll_loop, args=(creds, state, icon), daemon=True).start()
    threading.Thread(
        target=ws_client.run_ws_client,
        args=(creds, state["stop_event"], lambda: do_poll_once(creds, state, icon)),
        daemon=True,
    ).start()
    log_audit("agent_started", {"owner": creds.get("owner_display_name")})
    icon.run()


if __name__ == "__main__":
    main()
