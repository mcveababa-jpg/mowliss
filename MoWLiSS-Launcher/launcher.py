import os
import shutil
import subprocess
import sys
import traceback
from datetime import datetime

APP_DIR_NAME = "MoWLiSS"


def resource_path(filename):
    # PyInstaller onefile builds extract bundled data to sys._MEIPASS at runtime,
    # which is deleted once this launcher process exits - so the two bundled exes
    # get copied to a stable folder before being launched (see ensure_local_copy).
    base = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, filename)


def ensure_local_copy(filename):
    dest_dir = os.path.join(os.environ["LOCALAPPDATA"], APP_DIR_NAME)
    os.makedirs(dest_dir, exist_ok=True)
    dest_path = os.path.join(dest_dir, filename)
    shutil.copy2(resource_path(filename), dest_path)
    return dest_path


def log_error(context, err):
    log_dir = os.path.join(os.environ["LOCALAPPDATA"], APP_DIR_NAME)
    os.makedirs(log_dir, exist_ok=True)
    with open(os.path.join(log_dir, "launcher_log.txt"), "a", encoding="utf-8") as f:
        f.write(f"{datetime.now().isoformat()} {context}: {err}\n")
        f.write(traceback.format_exc() + "\n")


def launch(path):
    # This launcher is built --windowed (no console), so stdin/stdout/stderr are
    # invalid handles; Popen must not try to inherit them or CreateProcess fails.
    subprocess.Popen(
        [path],
        cwd=os.path.dirname(path),
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )


def main():
    try:
        blocker_path = ensure_local_copy("WebsiteBlocker.exe")
        scanner_path = ensure_local_copy("LinkGuard.exe")
    except Exception as e:
        log_error("copy", e)
        return

    # WebsiteBlocker.exe carries its own requireAdministrator manifest. subprocess.Popen
    # calls CreateProcess directly, which does NOT honor that manifest and fails with
    # WinError 740. os.startfile() goes through ShellExecute, which does trigger the
    # UAC consent prompt correctly, even though this launcher itself isn't elevated.
    try:
        os.startfile(blocker_path)
    except Exception as e:
        log_error("launch WebsiteBlocker", e)

    try:
        launch(scanner_path)
    except Exception as e:
        log_error("launch LinkGuard", e)


if __name__ == "__main__":
    main()
