"""Play the single-frame fixture in a fresh headless Chromium profile."""
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import time
from urllib.parse import urlsplit


def playback(web, base, root):
    browser = os.environ.get("PLUS_TEST_CHROME")
    if not browser:
        return
    browser = shutil.which(browser)
    if not browser or urlsplit(base).hostname != "127.0.0.1":
        raise RuntimeError("The renderer check requires Chromium and an isolated loopback board")
    token = secrets.token_hex(16)
    results = []

    class Result(BaseHTTPRequestHandler):
        def do_POST(self):
            if self.path != "/" + token or self.headers.get("Origin") != base.rstrip("/"):
                self.send_error(403)
                return
            size = int(self.headers.get("Content-Length", 0))
            if not 0 < size <= 1000:
                self.send_error(400)
                return
            results.append(self.rfile.read(size).decode("utf-8"))
            self.send_response(204)
            self.end_headers()

        def log_message(self, fmt, *args):
            pass

    with HTTPServer(("127.0.0.1", 0), Result) as receiver:
        receiver.timeout = 0.35
        probe = Path(web) / ("renderer-" + token + ".html")
        callback = f"http://127.0.0.1:{receiver.server_port}/{token}"
        probe.write_text("""<!doctype html><html><body><iframe title="Arcade fixture"></iframe><script>
const frame = document.querySelector("iframe");
let started = false, reported = false;
function report(message) {
    if (reported) return;
    reported = true;
    fetch(CALLBACK, {mode:"no-cors", method:"POST", body:message});
}
frame.addEventListener("load", () => {
    const form = frame.contentDocument.getElementById("arcade-play");
    if (form && !started) {
        started = true;
        setTimeout(() => form.requestSubmit(), 1300);
    }
});
frame.src = "app.php/arcade/game/60";
setInterval(() => {
    const container = frame.contentDocument.getElementById("plus-arc-player");
    const player = container && container.firstElementChild;
    if (player && typeof player.ruffle === "function") {
        const api = player.ruffle(1), meta = api.metadata;
        if (meta && api.readyState === 2) {
            report(JSON.stringify([meta.width, meta.height, meta.frameRate, meta.numFrames]));
        }
    } else if (container && !player) { report(container.textContent.slice(0, 120)); }
}, 100);
</script></body></html>""".replace("CALLBACK", json.dumps(callback)), encoding="utf-8")
        profile = Path(root) / ("chromium-" + token)
        args = [browser, "--headless", "--disable-background-networking", "--disable-component-update", "--disable-sync",
                "--no-first-run", "--no-default-browser-check", "--autoplay-policy=no-user-gesture-required",
                "--user-data-dir=" + str(profile), base + probe.name]
        process = subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                   creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        try:
            deadline = time.monotonic() + 50
            while not results and time.monotonic() < deadline and process.poll() is None:
                receiver.handle_request()
            if not results or results[0] != "[80,45,24,1]":
                raise RuntimeError(f"Ruffle did not decode the 80×45, 24fps test movie: {results or 'no browser response'}")
            print("Chromium played the self-hosted Ruffle fixture")
        finally:
            process.terminate()
            process.wait(timeout=12)
            probe.unlink(missing_ok=True)
