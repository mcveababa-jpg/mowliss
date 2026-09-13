import ctypes
import json
import os
import subprocess
import sys
import winreg

import requests

from common import APP_DIR, log_audit, notify

RUN_KEY_NAME = "MoWLissAgent"
# CREATE_NO_WINDOW gives the child its own hidden console, which console-subsystem
# programs it then runs (tasklist/curl inside the batch script) need to function
# normally. DETACHED_PROCESS (no console at all) was tried first but left those
# child console apps unable to run properly, silently hanging the whole script.
CREATE_NO_WINDOW = 0x08000000


def _ack(server_url, headers, command_id, status, result_message=""):
    try:
        requests.post(
            f"{server_url}/device_command_ack.php",
            json={"command_id": command_id, "status": status, "result_message": result_message},
            headers=headers,
            timeout=10,
        )
    except Exception as e:
        log_audit("ack_error", {"command_id": command_id, "status": status, "error": str(e)})


def remove_autostart():
    try:
        key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
        winreg.DeleteValue(key, RUN_KEY_NAME)
        winreg.CloseKey(key)
    except FileNotFoundError:
        pass


def set_autostart(exe_path):
    key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Microsoft\Windows\CurrentVersion\Run", 0, winreg.KEY_SET_VALUE)
    winreg.SetValueEx(key, RUN_KEY_NAME, 0, winreg.REG_SZ, f'"{exe_path}"')
    winreg.CloseKey(key)


def handle_remote_lock(server_url, headers, command_id):
    _ack(server_url, headers, command_id, "received")
    notify("MoWLiSS Agent", "This device is being locked by an administrator.")
    log_audit("remote_lock_executed", {"command_id": command_id})
    result = ctypes.windll.user32.LockWorkStation()
    _ack(server_url, headers, command_id, "completed" if result else "failed")


def handle_killswitch(server_url, headers, command_id, state):
    _ack(server_url, headers, command_id, "received")
    state["killed"] = True
    notify("MoWLiSS Agent", "This device has been placed into a locked-down state by an administrator.")
    log_audit("killswitch_engaged", {"command_id": command_id})
    ctypes.windll.user32.LockWorkStation()
    _ack(server_url, headers, command_id, "completed")


def handle_delete(server_url, headers, command_id, blocker_module):
    """
    A running exe can't delete its own file on Windows, so this acks 'received',
    removes what it can from within the current process (unblock hosts file,
    autostart entry), then spawns a detached helper batch script that waits for
    this process to exit before deleting the install directory, copying the
    final audit log to the user's Desktop, and reporting 'completed' back to the
    server itself (since by then this process is already gone).
    """
    _ack(server_url, headers, command_id, "received")
    log_audit("app_delete_started", {"command_id": command_id})
    notify("MoWLiSS Agent", "This device is being unenrolled and the agent removed by an administrator.")

    try:
        blocker_module.disable()
    except Exception as e:
        log_audit("delete_unblock_error", {"error": str(e)})

    remove_autostart()

    pid = os.getpid()
    desktop = os.path.join(os.path.expanduser("~"), "Desktop")
    audit_log_src = os.path.join(APP_DIR, "audit_log.jsonl")
    audit_log_dst = os.path.join(desktop, "MoWLiSS_final_audit_log.jsonl")

    selector_validator = headers.get("Authorization", "").replace("Bearer ", "")

    # Write the ack payload to its own file rather than inlining JSON on the curl
    # command line - cmd.exe's quote-toggle parser doesn't understand backslash-escaped
    # quotes (\"), so an inline JSON string with an odd embedded-quote count leaves an
    # unterminated quote and hangs the whole batch script waiting for more input.
    payload_path = os.path.join(os.environ["TEMP"], f"mowliss_ack_payload_{pid}.json")
    with open(payload_path, "w", encoding="utf-8") as f:
        json.dump({"command_id": command_id, "status": "completed", "result_message": "Uninstalled"}, f)

    batch_lines = [
        "@echo off",
        ":wait_loop",
        f'tasklist /fi "PID eq {pid}" | find "{pid}" >nul',
        "if not errorlevel 1 (",
        "    timeout /t 1 >nul",
        "    goto wait_loop",
        ")",
        f'if exist "{audit_log_src}" copy /y "{audit_log_src}" "{audit_log_dst}" >nul',
        f'curl -s -X POST -H "Authorization: Bearer {selector_validator}" '
        f'-H "Content-Type: application/json" '
        f'-d "@{payload_path}" '
        f'"{server_url}/device_command_ack.php" >nul',
        f'rmdir /s /q "{APP_DIR}"',
        f'del "{payload_path}"',
        'del "%~f0"',
    ]
    batch_path = os.path.join(os.environ["TEMP"], f"mowliss_cleanup_{pid}.bat")
    with open(batch_path, "w", encoding="utf-8") as f:
        f.write("\n".join(batch_lines))

    subprocess.Popen(
        ["cmd", "/c", batch_path],
        creationflags=CREATE_NO_WINDOW,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        close_fds=True,
    )

    log_audit("app_delete_handoff_to_cleanup_helper", {"batch_path": batch_path})
    os._exit(0)


def handle_command(cmd, server_url, headers, state, blocker_module):
    name = cmd["command_name"]
    command_id = cmd["command_id"]

    if name == "remote_lock":
        handle_remote_lock(server_url, headers, command_id)
    elif name == "app_killswitch":
        handle_killswitch(server_url, headers, command_id, state)
    elif name == "app_delete":
        handle_delete(server_url, headers, command_id, blocker_module)
