import os
import sys
import time
from datetime import datetime as dt

# Sites blocked regardless of the external blocklist file
CUSTOM_SITES = [
    "facebook.com",
    "www.facebook.com",
    "tiktok.com",
    "www.tiktok.com",
    "instagram.com",
    "www.instagram.com",
    "whatsapp.com",
    "www.whatsapp.com",
    "web.whatsapp.com",
]

REDIRECT = "127.0.0.1"
BEGIN_MARK = "# BEGIN WebsiteBlocker"
END_MARK = "# END WebsiteBlocker"

LINUX_HOST = "/etc/hosts"
WINDOWS_HOST = r"C:\Windows\System32\drivers\etc\hosts"

if os.name == "posix":
    HOSTS_PATH = LINUX_HOST
elif os.name == "nt":
    HOSTS_PATH = WINDOWS_HOST
else:
    print("OS Unknown")
    sys.exit()


def resource_path(filename):
    # PyInstaller onefile builds extract bundled data to sys._MEIPASS at runtime
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, filename)


def load_sites_to_block():
    sites = set(CUSTOM_SITES)
    adult_list_path = resource_path("adult_blocklist.txt")
    if os.path.exists(adult_list_path):
        with open(adult_list_path, "r", encoding="utf-8") as f:
            for line in f:
                domain = line.strip()
                if domain:
                    sites.add(domain)
    return sorted(sites)


def is_block_present(lines):
    return any(line.strip() == BEGIN_MARK for line in lines)


def add_block(sites):
    with open(HOSTS_PATH, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()
    if is_block_present(lines):
        return
    with open(HOSTS_PATH, "a", encoding="utf-8") as f:
        f.write("\n" + BEGIN_MARK + "\n")
        for site in sites:
            f.write(f"{REDIRECT} {site}\n")
        f.write(END_MARK + "\n")
    print(f"Blocked {len(sites)} sites.")


def remove_block():
    with open(HOSTS_PATH, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()
    if not is_block_present(lines):
        return
    new_lines = []
    inside_block = False
    for line in lines:
        stripped = line.strip()
        if stripped == BEGIN_MARK:
            inside_block = True
            continue
        if stripped == END_MARK:
            inside_block = False
            continue
        if not inside_block:
            new_lines.append(line)
    with open(HOSTS_PATH, "w", encoding="utf-8") as f:
        f.writelines(new_lines)
    print("Unblocked sites.")


def block_websites(start_hour, end_hour):
    sites = load_sites_to_block()
    print(f"Loaded {len(sites)} sites to block between {start_hour}:00 and {end_hour}:00.")
    while True:
        try:
            now = dt.now()
            in_work_hours = (
                dt(now.year, now.month, now.day, start_hour)
                < now
                < dt(now.year, now.month, now.day, end_hour)
            )
            if in_work_hours:
                add_block(sites)
            else:
                remove_block()
        except PermissionError as e:
            print(f"Caught a permission error: Try Running as Admin: {e}")
            break
        time.sleep(3)


if __name__ == "__main__":
    block_websites(9, 21)
