import asyncio
import json

import websockets

from common import log_audit

RECONNECT_MIN = 2
RECONNECT_MAX = 30


def _ws_url(server_url):
    # server_url comes from a free-text field in enroll_ui.py, so matching must not
    # assume the user typed a lowercase scheme (e.g. "HTTPS://...").
    lowered = server_url.lower()
    if lowered.startswith("https://"):
        base = "wss://" + server_url[len("https://"):]
    elif lowered.startswith("http://"):
        base = "ws://" + server_url[len("http://"):]
    else:
        base = server_url
    return base.rstrip("/") + "/ws/agent"


async def _run(server_url, token, stop_event, on_poll_now):
    url = _ws_url(server_url)
    backoff = RECONNECT_MIN
    loop = asyncio.get_event_loop()

    while not stop_event.is_set():
        try:
            async with websockets.connect(url, ping_interval=20, ping_timeout=20, open_timeout=15) as ws:
                await ws.send(json.dumps({"type": "auth", "token": token}))
                reply = json.loads(await asyncio.wait_for(ws.recv(), timeout=10))

                if reply.get("type") != "auth_ok":
                    log_audit("ws_auth_failed", {"reply": reply})
                    await asyncio.sleep(backoff)
                    backoff = min(backoff * 2, RECONNECT_MAX)
                    continue

                log_audit("ws_connected", {})
                backoff = RECONNECT_MIN  # reset once a connection succeeds

                async for raw in ws:
                    if stop_event.is_set():
                        break
                    try:
                        msg = json.loads(raw)
                    except (json.JSONDecodeError, TypeError):
                        continue
                    if msg.get("type") == "poll_now":
                        # Run the (blocking, network-bound) poll off the event loop thread
                        # so it can't stall keepalive pings on this connection.
                        loop.run_in_executor(None, on_poll_now)

        except Exception as e:
            log_audit("ws_error", {"error": str(e)})

        if stop_event.is_set():
            return

        await asyncio.sleep(backoff)
        backoff = min(backoff * 2, RECONNECT_MAX)


def run_ws_client(creds, stop_event, on_poll_now):
    """Blocking entry point - call this in its own daemon thread."""
    asyncio.run(_run(creds["server_url"], creds["token"], stop_event, on_poll_now))
