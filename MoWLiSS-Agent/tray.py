import os

import pystray
from PIL import Image, ImageDraw

from common import AUDIT_LOG_PATH, ensure_app_dir

APP_NAME = "MoWLiSS Agent"


def _shield(color):
    img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.polygon([(32, 3), (59, 14), (59, 33), (32, 61), (5, 33), (5, 14)], fill=color)
    return img, d


def icon_active():
    img, d = _shield((0, 153, 76, 255))
    d.line([(17, 33), (27, 45), (48, 18)], fill="white", width=6, joint="curve")
    return img


def icon_offline():
    img, d = _shield((120, 120, 120, 255))
    d.line([(32, 16), (32, 38)], fill="white", width=6)
    d.ellipse([(28, 44), (36, 52)], fill="white")
    return img


def icon_locked():
    img, d = _shield((230, 160, 30, 255))
    d.rectangle([(22, 30), (42, 48)], fill="white")
    d.arc([(22, 16), (42, 36)], start=180, end=360, fill="white", width=5)
    return img


def icon_killed():
    img, d = _shield((200, 40, 40, 255))
    d.line([(20, 20), (44, 44)], fill="white", width=6)
    d.line([(44, 20), (20, 44)], fill="white", width=6)
    return img


def open_audit_log(icon, item):
    ensure_app_dir()
    if not os.path.exists(AUDIT_LOG_PATH):
        open(AUDIT_LOG_PATH, "a", encoding="utf-8").close()
    os.startfile(AUDIT_LOG_PATH)


def build_tray(state, on_quit):
    def status_text(item):
        return f"Status: {state.get('status_label', 'Starting...')}"

    def enrollment_text(item):
        return f"Enrolled to: {state.get('owner_display_name', 'Unknown')}"

    def server_text(item):
        return f"Server: {state.get('server_url', '')}"

    icon = pystray.Icon(
        APP_NAME,
        icon_offline(),
        f"{APP_NAME} - starting...",
        menu=pystray.Menu(
            pystray.MenuItem(APP_NAME, None, enabled=False),
            pystray.MenuItem(status_text, None, enabled=False),
            pystray.MenuItem(enrollment_text, None, enabled=False),
            pystray.MenuItem(server_text, None, enabled=False),
            pystray.MenuItem("View local audit log", open_audit_log),
            pystray.MenuItem("Quit", lambda icon, item: on_quit(icon, item)),
        ),
    )
    return icon


def update_status(icon, state, status_label, icon_image, tooltip):
    state["status_label"] = status_label
    icon.icon = icon_image
    icon.title = tooltip
