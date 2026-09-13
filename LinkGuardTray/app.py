import os
import re
import socket
import sys
import threading
from datetime import datetime

import pyperclip
import requests
from PIL import Image, ImageDraw
import pystray
from plyer import notification

APP_NAME = "LinkGuard"
CLIPBOARD_POLL_SECONDS = 2
CONNECTIVITY_POLL_SECONDS = 5
LOG_PATH = os.path.join(os.path.expanduser("~"), "LinkGuard", "scan_log.txt")

URL_REGEX = re.compile(r'https?://[^\s<>"\')]+')

IS_ONLINE = True  # updated by connectivity_watcher; read by scan_url


def resource_path(filename):
    # PyInstaller onefile builds extract bundled data to sys._MEIPASS at runtime
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, filename)


def load_malicious_domains():
    domains = set()
    path = resource_path("malware_hosts.txt")
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8-sig") as f:
            for line in f:
                domain = line.strip().lower()
                if domain:
                    domains.add(domain)
    return domains


MALICIOUS_DOMAINS = load_malicious_domains()


def extract_domain(url):
    return (
        url.replace("http://", "")
        .replace("https://", "")
        .replace("www.", "")
        .split("/")[0]
        .split("?")[0]
        .lower()
    )


def has_internet():
    try:
        socket.create_connection(("8.8.8.8", 53), timeout=2).close()
        return True
    except OSError:
        return False


def check_urlhaus_live(url):
    try:
        resp = requests.post(
            "https://urlhaus-api.abuse.ch/v1/url/",
            data={"url": url},
            timeout=5,
        )
        data = resp.json()
        return data.get("query_status") == "ok"
    except Exception:
        return None


def log_event(message):
    os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    with open(LOG_PATH, "a", encoding="utf-8") as f:
        f.write(f"{datetime.now().isoformat()} {message}\n")


def scan_url(url, icon):
    domain = extract_domain(url)
    flagged_offline = domain in MALICIOUS_DOMAINS
    flagged_online = check_urlhaus_live(url) if IS_ONLINE else None

    if flagged_offline or flagged_online:
        log_event(f"FLAGGED: {url}")
        try:
            notification.notify(
                title="LinkGuard: Suspicious link detected",
                message=f"{domain}\nmatched a known malware/phishing list.",
                timeout=10,
            )
        except Exception as e:
            log_event(f"notify error: {e}")
    else:
        log_event(f"clean: {url}")


def clipboard_watcher(icon, stop_event):
    last_seen = ""
    try:
        last_seen = pyperclip.paste()
    except Exception:
        pass

    while not stop_event.is_set():
        try:
            current = pyperclip.paste()
            if current and current != last_seen:
                last_seen = current
                for url in URL_REGEX.findall(current):
                    scan_url(url, icon)
        except Exception as e:
            log_event(f"watcher error: {e}")
        stop_event.wait(CLIPBOARD_POLL_SECONDS)


def make_shield_icon(fill_color):
    img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.polygon([(32, 3), (59, 14), (59, 33), (32, 61), (5, 33), (5, 14)], fill=fill_color)
    return img, d


def make_online_icon():
    img, d = make_shield_icon((0, 153, 76, 255))  # green = protected
    d.line([(17, 33), (27, 45), (48, 18)], fill="white", width=6, joint="curve")
    return img


def make_offline_icon():
    img, d = make_shield_icon((120, 120, 120, 255))  # gray = offline
    d.line([(32, 16), (32, 38)], fill="white", width=6)
    d.ellipse([(28, 44), (36, 52)], fill="white")
    return img


def connectivity_watcher(icon, stop_event):
    global IS_ONLINE
    last_state = None
    while not stop_event.is_set():
        online = has_internet()
        IS_ONLINE = online
        if online != last_state:
            last_state = online
            if online:
                icon.icon = make_online_icon()
                icon.title = f"{APP_NAME} - protected (online)"
                log_event("internet connected - live lookups active")
            else:
                icon.icon = make_offline_icon()
                icon.title = f"{APP_NAME} - offline (local list only)"
                log_event("internet disconnected - offline mode")
        stop_event.wait(CONNECTIVITY_POLL_SECONDS)


def status_text(item):
    return f"Status: {'Protected (online)' if IS_ONLINE else 'Offline (local list only)'}"


def open_log(icon, item):
    os.makedirs(os.path.dirname(LOG_PATH), exist_ok=True)
    if not os.path.exists(LOG_PATH):
        open(LOG_PATH, "a", encoding="utf-8").close()
    os.startfile(LOG_PATH)


def quit_app(icon, item, stop_event):
    stop_event.set()
    icon.stop()


def main():
    global IS_ONLINE
    IS_ONLINE = has_internet()
    stop_event = threading.Event()

    icon = pystray.Icon(
        APP_NAME,
        make_online_icon() if IS_ONLINE else make_offline_icon(),
        f"{APP_NAME} - protected (online)" if IS_ONLINE else f"{APP_NAME} - offline (local list only)",
        menu=pystray.Menu(
            pystray.MenuItem(f"{APP_NAME} is running", None, enabled=False),
            pystray.MenuItem(status_text, None, enabled=False),
            pystray.MenuItem(f"Loaded {len(MALICIOUS_DOMAINS)} known-bad domains", None, enabled=False),
            pystray.MenuItem("View scan log", open_log),
            pystray.MenuItem("Quit", lambda icon, item: quit_app(icon, item, stop_event)),
        ),
    )
    threading.Thread(target=clipboard_watcher, args=(icon, stop_event), daemon=True).start()
    threading.Thread(target=connectivity_watcher, args=(icon, stop_event), daemon=True).start()
    icon.run()


if __name__ == "__main__":
    main()
