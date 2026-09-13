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


def poll_loop(creds, state, icon):
    server_url = creds["server_url"]
    headers = {"Authorization": f"Bearer {creds['token']}"}
    poll_interval = creds.get("poll_interval_seconds", 30)

    while not state["stop_event"].is_set():
        try:
            body = {
                "device_name": socket.gethostname(),
                "os_info": platform.platform(),
                "agent_version": "0.1.0",
            }
            # Always attempt a location lookup and let the server decide whether to persist
            # it (device_poll.php only stores it if live_location is currently enabled there).
            # Gating this client-side on last-known state would lag one full poll cycle behind
            # since that state is only updated by reconcile() after this request completes.
            loc = location_mod.get_location()
            if loc:
                body["location"] = loc

            resp = requests.post(f"{server_url}/device_poll.php", json=body, headers=headers, timeout=15)

            if resp.status_code == 410:
                # Server has fully removed this device already (e.g. deleted while this
                # agent was offline) - clean up locally rather than polling forever.
                log_audit("device_retired_remotely", {})
                tray.update_status(icon, state, "Retired", tray.icon_offline(), "MoWLiSS Agent - retired")
                state["stop_event"].set()
                icon.stop()
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
            tray.update_status(
                icon, state, "Offline (enforcing last-known state)", tray.icon_offline(),
                "MoWLiSS Agent - offline"
            )
            # Deliberately do NOT revert blocker/scanner state on network failure -
            # going offline should never silently disable protections already in place.
        except Exception as e:
            log_audit("poll_unexpected_error", {"error": str(e)})

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

    state = {"stop_event": threading.Event(), "killed": False, "live_location": False}
    icon = tray.build_tray(state, lambda ic, it: on_quit(ic, it, state))

    threading.Thread(target=poll_loop, args=(creds, state, icon), daemon=True).start()
    log_audit("agent_started", {"owner": creds.get("owner_display_name")})
    icon.run()


if __name__ == "__main__":
    main()
