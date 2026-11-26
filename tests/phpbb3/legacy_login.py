"""Follow old login bookmarks with a real native session, also below a URL prefix."""
from contextlib import contextmanager
from html import unescape
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from urllib.error import URLError
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor

from http_board import Browser, Page, LoopbackRedirect, submit_form


class History(LoopbackRedirect):
    def __init__(self, base):
        super().__init__(urlsplit(base).netloc)
        self.prefix = urlsplit(base).path
        self.steps = []

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if not urlsplit(newurl).path.startswith(self.prefix):
            raise RuntimeError("The compatibility link left the board's installation path")
        if urlsplit(req.full_url).path.endswith("/login.php"):
            if "no-store" not in headers.get("Cache-Control", "") or headers.get("Referrer-Policy") != "no-referrer":
                raise RuntimeError("A compatibility response retained the old login URL")
        self.steps.append((code, newurl))
        return super().redirect_request(req, fp, code, msg, headers, newurl)


@contextmanager
def subdirectory(web):
    # A second loopback origin mounts the same disposable board at a real nested
    # path. The temporary document root contains only that mount.
    with tempfile.TemporaryDirectory(prefix="plus3-login-mount-") as scratch:
        root = Path(scratch)
        mount = root / "klassentreffen"
        if os.name == "nt":
            import _winapi
            _winapi.CreateJunction(str(Path(web).resolve()), str(mount))
        else:
            mount.symlink_to(Path(web).resolve(), target_is_directory=True)
        try:
            with socket.socket() as free:
                free.bind(("127.0.0.1", 0))
                port = free.getsockname()[1]
            host = f"127.0.0.1:{port}"
            base = f"http://{host}/klassentreffen/"
            with subprocess.Popen([os.environ.get("PLUS_TEST_PHP", "php"), "-d", "disable_functions=mail",
                                   "-d", "display_errors=0", "-S", host, "-t", str(root)], cwd=web,
                                  stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                  creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0)) as process:
                try:
                    for attempt in range(100):
                        try:
                            Browser(base).fetch("styles/prosilver/style.cfg")
                            break
                        except (ConnectionError, URLError):
                            if process.poll() is not None or attempt == 99:
                                raise RuntimeError("The local nested board did not start") from None
                            time.sleep(0.05)
                    yield base
                finally:
                    process.terminate()
        finally:
            if not mount.samefile(web):
                raise RuntimeError("The temporary board mount changed")
            if os.name == "nt":
                mount.rmdir()
            else:
                mount.unlink()


def session(base, name, password, full):
    actor = Browser(base)
    trail = History(base)
    actor.opener = build_opener(HTTPCookieProcessor(actor.cookies), trail)
    obsolete = "bead" * 8
    old_password = "obsolete-form-only"
    postlink = "login.php?" + urlencode({"redirect": "viewtopic.php?p=1&sid=" + obsolete + "#1", "sid": obsolete})
    if full:
        actor.fetch("album_cat.php?cat_id=8", expected_status=404)
        cases = (("login.php", "portal.php"), ("login.php?redirect=viewforum.php&f=1", "viewforum.php?f=1"),
                 ("login.php?redirect=retired-page.php", "portal.php"),
                 ("login.php?redirect=album_upload.php?cat_id=1", "album_upload.php?cat_id=1"),
                 ("login.php?redirect=posting.php&mode=newtopic&f=1", "posting.php?f=1&mode=post"),
                 ("login.php?redirect=profile.php&mode=signature", "profile.php?mode=signature"),
                 ("login.php?redirect=search.php?search_id=bookmarks", "ucp.php?i=ucp_main&mode=bookmarks"),
                 ("login.php?redirect=ftopic1.html", "app.php/ftopic1.html"))
        for old, target in cases:
            form = Page(actor.fetch(old)).forms.get("login")
            if not form or form[1].get("redirect") != target:
                raise RuntimeError("An original forum or Plus bookmark lost its native login destination: " + target)
        trail.steps.clear()
        form = Page(actor.fetch("login.php", {"login": "1", "username": "OldFormMember", "password": old_password, "sid": obsolete,
                                             "redirect": "privmsg.php?folder=inbox"})).forms.get("login")
        if not form or form[1].get("redirect") != "privmsg.php?folder=inbox" or trail.steps[0][0] != 303:
            raise RuntimeError("The obsolete password form did not open a fresh native login")
        if any(piece in location for _, location in trail.steps for piece in (obsolete, old_password, "OldFormMember")):
            raise RuntimeError("The obsolete form forwarded credentials or an old session")
    else:
        old = "login.php?" + urlencode({"redirect": urlsplit(base).path + "viewforum.php?f=1"})
        if Page(actor.fetch(old)).forms["login"][1]["redirect"] != "viewforum.php?f=1":
            raise RuntimeError("The nested board lost an absolute path within its own installation")
    trail.steps.clear()
    page = actor.fetch(postlink)
    action, fields = Page(page).forms["login"]
    if "ucp.php" not in action or fields.get("redirect") != "viewtopic.php?p=1#p1" or fields.get("sid") == obsolete:
        raise RuntimeError("The native form lost its fresh session or the bookmarked post")
    logged = submit_form(actor, postlink, page, "login", {"login": "1", "username": name, "password": password})
    if "mode=logout" not in logged or not any(urlsplit(dest).path.endswith("/viewtopic.php") and
            parse_qs(urlsplit(dest).query).get("p") == ["1"] and urlsplit(dest).fragment == "p1" for _, dest in trail.steps):
        raise RuntimeError("Native login did not return to the bookmarked post")
    trail.steps.clear()
    inbox = actor.fetch("login.php?redirect=privmsg.php&folder=inbox&sid=" + obsolete)
    if "mode=logout" not in inbox or not any(parse_qs(urlsplit(dest).query).get("i") == ["pm"] for _, dest in trail.steps):
        raise RuntimeError("A signed-in member could not return to their protected Plus inbox")
    album = actor.fetch("login.php?redirect=album_cat.php&cat_id=8")
    if "Clubfotografie" not in album or "mode=logout" not in album:
        raise RuntimeError("The original bookmark did not reach the signed-in member's group gallery")
    logout = "login.php?" + urlencode({"logout": "true", "sid": obsolete, "redirect": "viewforum.php?f=1"})
    page = actor.fetch(logout)
    action, fields = Page(page).forms["plus-legacy-logout"]
    if (fields.get("sid") in (None, "", obsolete) or not action.endswith("ucp.php?mode=logout") or "Would you like to log out" not in page
            or obsolete in page or "mode=logout" not in actor.fetch("index.php")):
        raise RuntimeError("Opening the historical logout link changed the session before confirmation")
    cancel = re.search(r'<a class="button2" href="([^"]+)"', page)
    if not cancel or "mode=logout" not in actor.fetch(urljoin(base, unescape(cancel[1]))):
        raise RuntimeError("Cancelling the logout did not preserve the active session")
    if "mode=logout" in actor.fetch(urljoin(base, action), fields) or "mode=logout" in actor.fetch("index.php"):
        raise RuntimeError("Confirming the logout failed to close the native session")
    if "plus-legacy-logout" in actor.fetch(logout):
        raise RuntimeError("An anonymous visitor was offered an authenticated logout")


def browse(web, base, member_name, member_password, german=None):
    if urlsplit(base).hostname != "127.0.0.1":
        raise RuntimeError("These login fixtures require the isolated loopback board")
    if german is not None and "Möchtest du dich vom Forum abmelden?" not in german.fetch("login.php?logout=true"):
        raise RuntimeError("The German sign-out confirmation was not available to the signed-in member")
    session(base, member_name, member_password, True)
    with subdirectory(web) as nested:
        session(nested, member_name, member_password, False)
