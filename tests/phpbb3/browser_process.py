"""Close the headless Chromium process created for a disposable loopback board."""
import os
import subprocess


def stop_browser(process):
    if process.poll() is not None:
        return
    if os.name == "nt":
        # The renderer and cache workers share this test's unique user-data directory.
        try:
            subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW, timeout=12)
        except (OSError, subprocess.TimeoutExpired):
            process.terminate()
    else:
        process.terminate()
    try:
        process.wait(timeout=8)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)
