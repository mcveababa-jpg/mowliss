import os
import re
import threading
import time

import pyperclip
import requests

from common import resource_path, log_audit, notify

URL_REGEX = re.compile(r'https?://[^\s<>"\')]+')
POLL_SECONDS = 2

_malicious_domains_cache = None
_thread = None
_stop_event = None


def _load_malicious_domains():
    global _malicious_domains_cache
    if _malicious_domains_cache is not None:
        return _malicious_domains_cache

    domains = set()
    path = resource_path("malware_hosts.txt")
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8-sig") as f:
            for line in f:
                domain = line.strip().lower()
                if domain:
                    domains.add(domain)
    _malicious_domains_cache = domains
    return domains


def _extract_domain(url):
    return (
        url.replace("http://", "").replace("https://", "").replace("www.", "")
        .split("/")[0].split("?")[0].lower()
    )


def _check_urlhaus_live(url):
    try:
        resp = requests.post("https://urlhaus-api.abuse.ch/v1/url/", data={"url": url}, timeout=5)
        return resp.json().get("query_status") == "ok"
    except Exception:
        return None


def _scan_url(url):
    domain = _extract_domain(url)
    flagged = domain in _load_malicious_domains() or _check_urlhaus_live(url)
    if flagged:
        log_audit("url_flagged", {"url": url, "domain": domain})
        notify("MoWLiSS Agent: Suspicious link detected", f"{domain}\nmatched a known malware/phishing list.")
    else:
        log_audit("url_clean", {"url": url})


def _watch_loop(stop_event):
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
                    _scan_url(url)
        except Exception as e:
            log_audit("scanner_error", {"error": str(e)})
        stop_event.wait(POLL_SECONDS)


def is_enabled():
    return _thread is not None and _thread.is_alive()


def enable():
    """Idempotent: does nothing if already running."""
    global _thread, _stop_event
    if is_enabled():
        return False
    _stop_event = threading.Event()
    _thread = threading.Thread(target=_watch_loop, args=(_stop_event,), daemon=True)
    _thread.start()
    return True


def disable():
    """Idempotent: does nothing if already stopped."""
    global _thread, _stop_event
    if not is_enabled():
        return False
    _stop_event.set()
    _thread = None
    return True
