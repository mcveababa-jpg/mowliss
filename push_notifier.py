"""
MoWLiSS push-notification service.

Runs alongside the existing Apache+PHP stack as a small always-on WebSocket
server. It does NOT duplicate any of device_poll.php's delivery/ack logic -
it only watches device_commands / device_controls for changes and, when a
relevant device has an open WebSocket connection, sends it a "poll_now"
nudge. The agent then immediately performs its normal HTTP poll against
device_poll.php, which remains the single source of truth for delivering
commands, marking them delivered, and reconciling controls.

This keeps all state-changing logic in one place (PHP) and makes the push
service purely a low-latency "something changed, go check" signal.
"""

import asyncio
import hashlib
import hmac
import json
import logging
import os
import re

import pymysql
import pymysql.cursors
import websockets


def _load_env(path):
    """Minimal KEY=VALUE .env parser - reuses the same file the PHP app itself
    reads (env.php), so DB credentials live in exactly one place on the server
    instead of being duplicated (and hardcoded) here."""
    values = {}
    try:
        with open(path, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                values[key.strip()] = value.strip()
    except OSError:
        pass
    return values


_env = _load_env(os.environ.get("MOWLISS_ENV_FILE", "/var/www/html/.env"))

DB_HOST = _env.get("DB_HOST", "127.0.0.1")
DB_NAME = _env.get("DB_NAME", "mowliss")
DB_USER = _env.get("DB_USER", "root")
DB_PASS = _env.get("DB_PASS", "")

LISTEN_HOST = "127.0.0.1"
LISTEN_PORT = 8765

DB_SCAN_INTERVAL = 0.5  # seconds between checks for new pending commands / control changes
AUTH_TIMEOUT = 10

TOKEN_RE = re.compile(r"^([0-9a-f]+):([0-9a-f]+)$", re.IGNORECASE)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("mowliss-push")

# device_id -> websocket connection (single active connection per device)
connections: dict[int, "websockets.ServerConnection"] = {}
connections_lock = asyncio.Lock()

# In-memory cursors used purely to detect *new* changes since the last scan.
last_control_snapshot: dict[tuple[int, str], str] = {}
first_scan_done = False


def db_connect():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
        connect_timeout=5,
    )


def _query_pending_device_ids():
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT DISTINCT device_id FROM device_commands WHERE status = 'pending'")
            return {row["device_id"] for row in cur.fetchall()}
    finally:
        conn.close()


def _query_control_snapshot():
    # Compares the actual enabled value, not updated_at: that column is a plain
    # TIMESTAMP (whole-second precision), so two rapid toggles of the same control
    # within the same second - e.g. an admin clicking App Turn Off then immediately
    # App Turn On - can land on an identical updated_at and make the second change
    # invisible to timestamp-based diffing, even though the on/off state genuinely
    # changed. Comparing enabled directly has no such collision window.
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT device_id, control_name, enabled FROM device_controls")
            return {(row["device_id"], row["control_name"]): row["enabled"] for row in cur.fetchall()}
    finally:
        conn.close()


def _lookup_device_token(selector):
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            cur.execute("SELECT id, token_hash, status FROM devices WHERE token_selector = %s LIMIT 1", (selector,))
            return cur.fetchone()
    finally:
        conn.close()


async def authenticate(token, loop):
    m = TOKEN_RE.match((token or "").strip())
    if not m:
        return None
    selector, validator = m.group(1), m.group(2)

    device = await loop.run_in_executor(None, _lookup_device_token, selector)
    if not device:
        return None
    expected = hashlib.sha256(validator.encode()).hexdigest()
    if not hmac.compare_digest(str(device["token_hash"]), expected):
        return None
    if device["status"] == "deleted":
        return None
    return int(device["id"])


async def handler(websocket):
    device_id = None
    try:
        raw = await asyncio.wait_for(websocket.recv(), timeout=AUTH_TIMEOUT)
        msg = json.loads(raw)
        if msg.get("type") != "auth" or "token" not in msg:
            await websocket.send(json.dumps({"type": "auth_failed", "reason": "bad_request"}))
            return

        loop = asyncio.get_event_loop()
        device_id = await authenticate(msg["token"], loop)
        if device_id is None:
            await websocket.send(json.dumps({"type": "auth_failed"}))
            return

        async with connections_lock:
            connections[device_id] = websocket
        await websocket.send(json.dumps({"type": "auth_ok", "device_id": device_id}))
        log.info("device %s connected (%d active)", device_id, len(connections))

        async for _raw in websocket:
            pass  # agent doesn't send anything post-auth today; ignore/keepalive only

    except (asyncio.TimeoutError, websockets.exceptions.ConnectionClosed):
        pass
    except Exception as e:  # noqa: BLE001 - log and drop the connection, never crash the server
        log.warning("handler error: %s", e)
    finally:
        if device_id is not None:
            async with connections_lock:
                if connections.get(device_id) is websocket:
                    del connections[device_id]
            log.info("device %s disconnected (%d active)", device_id, len(connections))


async def nudge(device_id, loop):
    async with connections_lock:
        ws = connections.get(device_id)
    if ws is None:
        return
    try:
        await ws.send(json.dumps({"type": "poll_now"}))
        log.info("nudged device %s", device_id)
    except Exception as e:  # noqa: BLE001
        log.warning("nudge failed for device %s: %s", device_id, e)


async def scan_loop():
    global first_scan_done, last_control_snapshot

    loop = asyncio.get_event_loop()

    while True:
        try:
            async with connections_lock:
                active_ids = set(connections.keys())

            if active_ids:
                pending_ids = await loop.run_in_executor(None, _query_pending_device_ids)
                for device_id in pending_ids & active_ids:
                    await nudge(device_id, loop)

                snapshot = await loop.run_in_executor(None, _query_control_snapshot)
                if not first_scan_done:
                    # Don't nudge everyone just because the service restarted.
                    last_control_snapshot = snapshot
                    first_scan_done = True
                else:
                    changed_devices = set()
                    for key, updated_at in snapshot.items():
                        if last_control_snapshot.get(key) != updated_at:
                            changed_devices.add(key[0])
                    last_control_snapshot = snapshot
                    for device_id in changed_devices & active_ids:
                        await nudge(device_id, loop)
            else:
                # Nobody connected - still keep the snapshot warm so a reconnect
                # right after a control change doesn't immediately re-nudge on stale data.
                if not first_scan_done:
                    last_control_snapshot = await loop.run_in_executor(None, _query_control_snapshot)
                    first_scan_done = True

        except Exception as e:  # noqa: BLE001 - never let one bad cycle kill the service
            log.warning("scan_loop error: %s", e)

        await asyncio.sleep(DB_SCAN_INTERVAL)


async def main():
    async with websockets.serve(handler, LISTEN_HOST, LISTEN_PORT, ping_interval=20, ping_timeout=20):
        log.info("mowliss-push listening on %s:%s", LISTEN_HOST, LISTEN_PORT)
        await scan_loop()


if __name__ == "__main__":
    asyncio.run(main())
