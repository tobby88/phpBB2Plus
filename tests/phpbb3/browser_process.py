"""Close the headless Chromium process created for a disposable loopback board."""
import os
import signal
import subprocess


def start_browser(args):
    return subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
                            start_new_session=os.name != "nt")


def stop_browser(process):
    if os.name == "nt":
        if process.poll() is not None:
            return
        # The renderer and cache workers share this test's unique user-data directory.
        try:
            subprocess.run(["taskkill", "/PID", str(process.pid), "/T", "/F"], stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW, timeout=12)
        except (OSError, subprocess.TimeoutExpired):
            process.terminate()
    else:
        # Even after Chrome's main process exits its cache workers can still be
        # writing to the profile. Only signal the session created by start_browser.
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
    try:
        process.wait(timeout=8)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)
    if os.name != "nt":
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
