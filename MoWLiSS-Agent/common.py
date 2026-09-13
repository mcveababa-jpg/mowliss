import json
import os
import sys
from datetime import datetime

APP_DIR_NAME = "MoWLiSS"
APP_DIR = os.path.join(os.environ["LOCALAPPDATA"], APP_DIR_NAME)
CREDENTIALS_PATH = os.path.join(APP_DIR, "credentials.json")
AUDIT_LOG_PATH = os.path.join(APP_DIR, "audit_log.jsonl")


def resource_path(filename):
    # PyInstaller onefile builds extract bundled data to sys._MEIPASS at runtime
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, filename)


def ensure_app_dir():
    os.makedirs(APP_DIR, exist_ok=True)


def load_credentials():
    if not os.path.exists(CREDENTIALS_PATH):
        return None
    try:
        with open(CREDENTIALS_PATH, "r", encoding="utf-8") as f:
            return json.load(f)
    except (OSError, json.JSONDecodeError):
        return None


def save_credentials(data):
    ensure_app_dir()
    with open(CREDENTIALS_PATH, "w", encoding="utf-8") as f:
        json.dump(data, f)


def log_audit(event_type, detail=None):
    ensure_app_dir()
    entry = {
        "timestamp": datetime.now().isoformat(),
        "event_type": event_type,
        "detail": detail or {},
    }
    with open(AUDIT_LOG_PATH, "a", encoding="utf-8") as f:
        f.write(json.dumps(entry) + "\n")


def notify(title, message):
    try:
        from plyer import notification
        notification.notify(title=title, message=message, timeout=10)
    except Exception as e:
        log_audit("notify_error", {"error": str(e)})
