"""Play the single-frame fixture in a fresh headless Chromium profile."""
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
from browser_process import stop_browser
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
    progress = []

    class Result(BaseHTTPRequestHandler):
        def do_POST(self):
            if self.path != "/" + token or self.headers.get("Origin") != base.rstrip("/"):
                self.send_error(403)
                return
            size = int(self.headers.get("Content-Length", 0))
            if not 0 < size <= 1000:
                self.send_error(400)
                return
            message = self.rfile.read(size).decode("utf-8")
            if message in {"movie decoded", "forum style checked", "gallery style checked", "download style checked", "knowledge style checked"}:
                progress.append((time.monotonic(), message))
            else:
                results.append(message)
            self.send_response(204)
            self.end_headers()

        def log_message(self, fmt, *args):
            pass

    with HTTPServer(("127.0.0.1", 0), Result) as receiver:
        receiver.timeout = 0.35
        probe = Path(web) / ("renderer-" + token + ".html")
        callback = f"http://127.0.0.1:{receiver.server_port}/{token}"
        style_checks = "\n".join(Path(__file__).with_name(name).read_text(encoding="utf-8")
                                 for name in ("style_browser.js", "gallery_style_browser.js", "download_style_browser.js",
                                              "knowledge_style_browser.js", "arcade_style_browser.js"))
        probe.write_text("""<!doctype html><html><body><iframe title="Arcade fixture"></iframe><script>
STYLE_CHECKS
const frame = document.querySelector("iframe");
let started = false, reported = false, checkingStyle = false;
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
setInterval(async () => {
    if (checkingStyle) return;
    const container = frame.contentDocument.getElementById("plus-arc-player");
    const player = container && container.firstElementChild;
    if (player && typeof player.ruffle === "function") {
        const api = player.ruffle(1), meta = api.metadata;
        if (meta && api.readyState === 2) {
            checkingStyle = true;
            const movie = [meta.width, meta.height, meta.frameRate, meta.numFrames];
            try {
                await fetch(CALLBACK, {mode:"no-cors", method:"POST", body:"movie decoded"});
                await checkPlusStyle(frame);
                await fetch(CALLBACK, {mode:"no-cors", method:"POST", body:"forum style checked"});
                await checkGalleryStyle(frame);
                await fetch(CALLBACK, {mode:"no-cors", method:"POST", body:"gallery style checked"});
                await checkDownloadStyle(frame);
                await fetch(CALLBACK, {mode:"no-cors", method:"POST", body:"download style checked"});
                await checkKnowledgeStyle(frame);
                await fetch(CALLBACK, {mode:"no-cors", method:"POST", body:"knowledge style checked"});
                await checkArcadeStyle(frame);
                report(JSON.stringify(movie));
            }
            catch (error) { report(String(error).slice(0, 220)); }
        }
    } else if (container && !player) { report(container.textContent.slice(0, 120)); }
}, 100);
</script></body></html>""".replace("CALLBACK", json.dumps(callback)).replace("STYLE_CHECKS", style_checks), encoding="utf-8")
        profile = Path(root) / ("chromium-" + token)
        args = [browser, "--headless", "--disable-background-networking", "--disable-component-update", "--disable-sync",
                "--no-first-run", "--no-default-browser-check", "--autoplay-policy=no-user-gesture-required",
                "--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost",
                "--user-data-dir=" + str(profile), base + probe.name]
        process = subprocess.Popen(args, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                   creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        try:
            start = time.monotonic()
            while not results and process.poll() is None:
                last = progress[-1][0] if progress else start
                if time.monotonic() >= min(start + 100, last + 45):
                    break
                receiver.handle_request()
            if not results or results[0] != "[80,45,24,1]":
                status = progress[-1][1] if progress else "waiting for the movie"
                raise RuntimeError(f"Browser playback or Plus style check failed after {status}: {results or 'no response'}")
            print(f"Chromium played the Ruffle fixture and checked the desktop and mobile Plus style in {time.monotonic()-start:.1f}s")
        finally:
            try:
                stop_browser(process)
            finally:
                probe.unlink(missing_ok=True)
