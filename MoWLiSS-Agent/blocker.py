import os
import sys

from common import resource_path

CUSTOM_SITES = [
    "facebook.com", "www.facebook.com",
    "tiktok.com", "www.tiktok.com",
    "instagram.com", "www.instagram.com",
    "whatsapp.com", "www.whatsapp.com", "web.whatsapp.com",
]

REDIRECT = "127.0.0.1"
BEGIN_MARK = "# BEGIN MoWLiSS-WebsiteBlocker"
END_MARK = "# END MoWLiSS-WebsiteBlocker"

LINUX_HOST = "/etc/hosts"
WINDOWS_HOST = r"C:\Windows\System32\drivers\etc\hosts"
HOSTS_PATH = WINDOWS_HOST if os.name == "nt" else LINUX_HOST

_sites_cache = None


def _load_sites():
    global _sites_cache
    if _sites_cache is not None:
        return _sites_cache

    sites = set(CUSTOM_SITES)
    path = resource_path("adult_blocklist.txt")
    if os.path.exists(path):
        with open(path, "r", encoding="utf-8-sig") as f:
            for line in f:
                domain = line.strip().lower()
                if domain:
                    sites.add(domain)
    _sites_cache = sorted(sites)
    return _sites_cache


def _is_block_present(lines):
    return any(line.strip() == BEGIN_MARK for line in lines)


def is_enabled():
    try:
        with open(HOSTS_PATH, "r", encoding="utf-8", errors="ignore") as f:
            return _is_block_present(f.readlines())
    except OSError:
        return False


def enable():
    """Idempotent: does nothing if already enabled."""
    with open(HOSTS_PATH, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()
    if _is_block_present(lines):
        return False

    sites = _load_sites()
    with open(HOSTS_PATH, "a", encoding="utf-8") as f:
        f.write("\n" + BEGIN_MARK + "\n")
        for site in sites:
            f.write(f"{REDIRECT} {site}\n")
        f.write(END_MARK + "\n")
    return True


def disable():
    """Idempotent: does nothing if already disabled."""
    with open(HOSTS_PATH, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()
    if not _is_block_present(lines):
        return False

    new_lines = []
    inside = False
    for line in lines:
        stripped = line.strip()
        if stripped == BEGIN_MARK:
            inside = True
            continue
        if stripped == END_MARK:
            inside = False
            continue
        if not inside:
            new_lines.append(line)

    with open(HOSTS_PATH, "w", encoding="utf-8") as f:
        f.writelines(new_lines)
    return True
