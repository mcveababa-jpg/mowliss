import json
import platform
import socket
import tkinter as tk
from tkinter import messagebox

import requests

from common import resource_path, save_credentials, log_audit


def _default_server_url():
    path = resource_path("config.json")
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f).get("server_url", "http://localhost/comeback")
    except (OSError, json.JSONDecodeError):
        return "http://localhost/comeback"


def _autosize(win, min_width=380, min_height=200):
    win.update_idletasks()
    width = max(win.winfo_reqwidth() + 40, min_width)
    height = max(win.winfo_reqheight() + 40, min_height)
    win.geometry(f"{width}x{height}")
    win.minsize(width, height)


def _show_consent_screen(owner_display_name):
    win = tk.Tk()
    win.title("MoWLiSS Agent - Enrolled")
    win.resizable(True, True)

    tk.Label(win, text="Device Enrolled", font=("Segoe UI", 14, "bold")).pack(pady=(20, 10))
    message = (
        f"This device is now managed by MoWLiSS, linked to the account:\n\n"
        f"    {owner_display_name}\n\n"
        "A tray icon will always be visible while the agent is running, showing its "
        "current status. You can open its local activity log at any time from the tray "
        "menu. Only an administrator can remove this enrollment."
    )
    tk.Label(win, text=message, wraplength=420, justify="left").pack(padx=20)
    tk.Button(win, text="Got it", width=12, command=win.destroy).pack(pady=20)

    _autosize(win, min_width=480, min_height=320)
    win.mainloop()


def run_enrollment():
    """Blocking tkinter flow. Returns True if enrollment succeeded, False if cancelled."""
    result = {"success": False}

    win = tk.Tk()
    win.title("MoWLiSS Agent - Enroll This Device")
    win.resizable(True, True)

    tk.Label(win, text="Enroll This Device", font=("Segoe UI", 14, "bold")).pack(pady=(20, 5))
    tk.Label(
        win,
        text="Enter the enrollment code from your MoWLiSS dashboard's\n'Enroll a Device' page.",
        justify="center",
    ).pack(pady=(0, 15))

    tk.Label(win, text="Server URL").pack(anchor="w", padx=40)
    server_var = tk.StringVar(value=_default_server_url())
    tk.Entry(win, textvariable=server_var, width=40).pack(padx=40)

    tk.Label(win, text="Enrollment Code").pack(anchor="w", padx=40, pady=(10, 0))
    code_var = tk.StringVar()
    tk.Entry(win, textvariable=code_var, width=40).pack(padx=40)

    status_var = tk.StringVar(value="")
    tk.Label(win, textvariable=status_var, fg="red").pack(pady=(10, 0))

    def submit():
        server_url = server_var.get().strip().rstrip("/")
        code = code_var.get().strip().upper()
        if not server_url or not code:
            status_var.set("Please fill in both fields.")
            return

        try:
            resp = requests.post(
                f"{server_url}/device_enroll.php",
                json={
                    "code": code,
                    "device_name": socket.gethostname(),
                    "os_info": platform.platform(),
                    "agent_version": "0.1.0",
                },
                timeout=10,
            )
            data = resp.json()
        except Exception as e:
            status_var.set(f"Connection error: {e}")
            return

        if resp.status_code != 200:
            status_var.set(f"Enrollment failed: {data.get('error', 'unknown error')}")
            log_audit("enrollment_failed", data)
            return

        save_credentials({
            "device_uuid": data["device_uuid"],
            "token": data["token"],
            "server_url": server_url,
            "owner_display_name": data.get("owner_display_name", "Unknown"),
            "poll_interval_seconds": data.get("poll_interval_seconds", 30),
        })
        log_audit("enrolled", {"device_uuid": data["device_uuid"], "owner": data.get("owner_display_name")})
        result["success"] = True
        result["owner_display_name"] = data.get("owner_display_name", "Unknown")
        win.destroy()

    tk.Button(win, text="Enroll", width=15, command=submit).pack(pady=15)

    _autosize(win, min_width=440, min_height=380)
    win.mainloop()

    if result["success"]:
        _show_consent_screen(result["owner_display_name"])
        return True
    return False
