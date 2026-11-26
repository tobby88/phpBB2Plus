"""Exercise both directory logo displays on a disposable converted board."""
from html import unescape
from html.parser import HTMLParser
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
from urllib.parse import urlsplit

from http_board import Browser, FormFields, Page, admin_url, submit_form
from legacy_links import link


class Logos(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.first, self.more = [], []
        self.depth = 0
        self.waiting = False
        self.destination = self.interval = None
        self.feed(body)

    def handle_starttag(self, tag, fields):
        attrs = dict(fields)
        if tag == "div" and "data-wl-interval" in attrs:
            self.interval = int(attrs["data-wl-interval"])
            self.depth = 1
        elif self.depth:
            if tag == "div":
                self.depth += 1
            elif tag == "template":
                self.waiting = True
            elif tag == "a":
                self.destination = urlsplit(attrs["href"]).path
                if attrs.get("target") != "_blank" or "noreferrer" not in attrs.get("rel", "").split():
                    raise ValueError("A website logo lost its protected browsing context")
            elif tag == "img":
                entry = (int(self.destination.rsplit("/", 1)[1]), attrs["alt"], attrs["src"])
                if (not self.destination.startswith("/app.php/links/visit/") or
                        (attrs["width"], attrs["height"], attrs["referrerpolicy"]) != ("86", "28", "no-referrer")):
                    raise ValueError("A website logo used incorrect links, dimensions or referrer settings")
                (self.more if self.waiting else self.first).append(entry)

    def handle_endtag(self, tag):
        if self.depth and tag == "div":
            self.depth -= 1
        elif tag == "template":
            self.waiting = False


def chromium(base, web):
    binary = os.environ.get("PLUS_TEST_CHROME")
    if not binary:
        return
    engine = shutil.which(binary)
    if not engine or urlsplit(base).hostname != "127.0.0.1":
        raise RuntimeError("The logo browser test requires local Chromium and a loopback fixture")
    probe = Path(web) / ("logo-probe-" + secrets.token_hex(10) + ".html")
    with tempfile.TemporaryDirectory(prefix="plus3-logo-browser-") as profile:
        script = Path(__file__).with_name("weblink_logo_browser.js").read_text(encoding="utf-8")
        probe.write_text('<!doctype html><meta charset="utf-8"><iframe id="board"></iframe><output></output><script>' +
                         script + '</script>', encoding="utf-8")
        try:
            run = subprocess.run([engine, "--headless", "--no-first-run", "--no-default-browser-check",
                "--disable-background-networking", "--disable-sync", "--disable-component-update", "--disable-extensions",
                "--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost",
                "--user-data-dir=" + profile, "--window-size=1280,840", "--virtual-time-budget=12000",
                "--dump-dom", base + probe.name], capture_output=True, timeout=40,
                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            result = re.search(r"<output>([^<]*)</output>", run.stdout.decode("utf-8", "replace"))
            if run.returncode or not result or result[1] != "OK":
                raise RuntimeError("Chromium logo rotation: " + (result[1][:240] if result else "missing result"))
        finally:
            probe.unlink(missing_ok=True)


def browse(base, owner, member, check, web):
    guest = Browser(base)
    index, frame = "index.php", "app.php/links/logos"
    if urlsplit(base).hostname != "127.0.0.1":
        raise ValueError("Only disposable loopback boards support logo tests")
    check("prepare")
    try:
        for path in (index, frame, "links.js.php"):
            body = guest.fetch(path)
            logos = Logos(body)
            if (logos.interval != 400 or [row[0] for row in logos.first] != [4112, 4111] or
                    [row[0] for row in logos.more] != [5, 1] or logos.first[0][1:] != ("Stern ⭐", "https://logo.example.invalid/source.png") or
                    logos.first[1][1] != "Zeitschrift & Café" or "Logowechsel pausieren" not in body):
                raise RuntimeError("The idle or rotating logo list changed order, image selection, title or language at " + path)
            if path == index and ('id="wl-heading"' not in body or "/links/logo/0" not in unescape(body)):
                raise RuntimeError("The board index is missing its directory or the board's own button")
        if "Pause logo rotation" not in member.fetch(frame):
            raise RuntimeError("The native frame ignored the visitor's language")
        link(base, "links.js.php", frame, {})
        guest.fetch("links.js.php", {}, expected_status=405)
        if not guest.fetch(logos.first[1][2], raw=True).startswith(b"GIF"):
            raise RuntimeError("Hiding directory list images also hid the index button")
        guest.fetch("app.php/links/logo/4111", expected_status=404)
        guest.fetch("app.php/links/logo/3?index=1", expected_status=404)
        check("local-images")
        chromium(base, web)
        check("withdraw")
        if 4111 in [row[0] for row in Logos(guest.fetch(frame)).first]:
            raise RuntimeError("A withdrawn website remained in the rotating row")
        guest.fetch("app.php/links/logo/4111?index=1", expected_status=404)
        entry = admin_url(owner)
        login = owner.fetch(entry)
        if "login" in Page(login).forms:
            token = Page(login).forms["login"][1]["credential"]
            submit_form(owner, entry, login, "login", {"login":"1", "username":"ModernMember",
                "password_" + token:os.environ["PLUS_TEST_MODERN_PASSWORD"]})
        entry = admin_url(owner) + "&i=acp_board&mode=features"
        form = owner.fetch(entry)
        field = "config[plus_wl_index_logos]"
        if FormFields(form, "acp_board").values.get(field) != "1":
            raise RuntimeError("Board features did not expose the imported index switch")
        submit_form(owner, entry, form, "acp_board", {field:"0", "submit":"1"})
        if 'id="wl-heading"' in guest.fetch(index) or "Linkverzeichnis" not in guest.fetch("app.php/links"):
            raise RuntimeError("Hiding the logo row changed the wrong directory view")
        guest.fetch(frame, expected_status=404)
        guest.fetch("app.php/links/logo/1?index=1", expected_status=404)
    finally:
        check("cleanup")
