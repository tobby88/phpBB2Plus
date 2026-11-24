"""Exercise the ordinary phpBB installer wizard and login forms on loopback."""
from contextlib import contextmanager
from html import unescape
from html.parser import HTMLParser
from http.cookiejar import CookieJar
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request


class Page(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.actions = []
        self.forms = {}
        self.form = None
        self.feed(body)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "a" and "button1" in attrs.get("class", "").split():
            self.actions.append(attrs.get("href", ""))
        if tag == "form":
            self.form = (attrs.get("action", ""), {})
            self.forms[attrs.get("id")] = self.form
        if tag == "input" and self.form and attrs.get("type") == "hidden" and attrs.get("name"):
            self.form[1][attrs["name"]] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self.form = None


class LoopbackRedirect(HTTPRedirectHandler):
    def __init__(self, origin):
        self.origin = origin

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urlsplit(newurl).netloc != self.origin:
            raise ValueError(f"Unexpected redirect from {self.origin} to {urlsplit(newurl).netloc}")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Browser:
    def __init__(self, base):
        self.base = base
        self.opener = build_opener(HTTPCookieProcessor(CookieJar()), LoopbackRedirect(urlsplit(base).netloc))

    def fetch(self, path, data=None, ajax=False, raw=False):
        address = urljoin(self.base, path)
        if urlsplit(address).netloc != urlsplit(self.base).netloc:
            raise ValueError("Unexpected link away from the disposable board")
        headers = {"X-Requested-With": "XMLHttpRequest"} if ajax else {}
        req = Request(address, data=urlencode(data).encode() if data is not None else None, headers=headers)
        try:
            with self.opener.open(req, timeout=90) as response:
                body = response.read()
                return body if raw else body.decode("utf-8")
        except HTTPError as error:
            summary = " ".join(unescape(re.sub(r"<[^>]+>", " ", error.read().decode("utf-8", "replace"))).split())
            raise RuntimeError(f"HTTP {error.code} at {urlsplit(address).path}: {summary[-1500:]}") from None


@contextmanager
def server(web, root):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    base = f"http://127.0.0.1:{port}/"
    log_file = root / "http-test.log"
    with log_file.open("wb") as log:
        proc = subprocess.Popen([os.environ.get("PLUS_TEST_PHP", "php"), "-d", "disable_functions=mail",
                                 "-d", "display_errors=0", "-S", f"127.0.0.1:{port}", "-t", str(web)],
                                cwd=web, stdout=log, stderr=log,
                                creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        try:
            for _ in range(120):
                try:
                    Browser(base).fetch("styles/prosilver/style.cfg")
                    break
                except (ConnectionError, URLError):
                    if proc.poll() is not None:
                        raise RuntimeError("Local PHP server stopped before startup")
                    time.sleep(0.05)
            else:
                raise RuntimeError("Local PHP server did not start")
            yield base
        except Exception:
            print(log_file.read_text(encoding="utf-8", errors="replace")[-5500:])
            raise
        finally:
            proc.terminate()
            proc.wait(timeout=10)


def source_files(source, target):
    for directory in ("images/smiles", "images/ranks", "images/avatars/gallery"):
        if (source / directory).is_dir():
            shutil.copytree(source / directory, target / directory)
    marker = Path("includes/functions_post_author.php")
    (target / marker.parent).mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source / marker, target / marker)


def convert(base, source_name):
    wizard = Browser(base)
    path = "install/app.php/convert"
    listing = wizard.fetch(path)
    if "phpBB2 Plus (UTF-8)" not in listing:
        raise RuntimeError("Plus converter is missing from the official wizard")
    path += "/settings/phpbb2plus"
    data = {"submit": "1", "src_dbms": "mysqli", "src_dbhost": "127.0.0.1",
            "src_dbport": os.environ.get("PLUS_TEST_DB_PORT", "3306"),
            "src_dbuser": os.environ.get("PLUS_TEST_DB_USER", "root"),
            "src_dbpasswd": os.environ.get("PLUS_TEST_DB_PASSWORD", ""),
            "src_dbname": source_name, "src_table_prefix": "phpbb_", "forum_path": "../legacy", "refresh": 0}
    response = wizard.fetch(path, data, ajax=True)
    if "in_progress" not in response:
        raise RuntimeError("Source configuration rejected by the official wizard: " + response[:1400])
    step = "install/app.php/convert/in_progress/phpbb2plus"
    for _ in range(160):
        body = wizard.fetch(step)
        if "/convert/finished" in step:
            return
        errors = re.search(r'<div id="error-container">(.*?)</div>', body, re.S)
        if errors:
            details = " ".join(unescape(re.sub(r"<[^>]+>", " ", errors.group(1))).split())
            raise RuntimeError("Conversion reported an error: " + details[:1200])
        actions = [link for link in Page(body).actions if "/convert/" in link]
        if not actions:
            excerpt = re.sub(r"<[^>]+>", " ", body)
            raise RuntimeError("Converter stopped before its final step: " + " ".join(unescape(excerpt).split())[-2500:])
        step = urljoin(urljoin(base, step), actions[-1])
    raise RuntimeError("Converter did not terminate")


def login(base, username, password, expect_success=True):
    browser = Browser(base)
    path = "ucp.php?mode=login"
    page = Page(browser.fetch(path))
    action, inputs = page.forms["login"]
    inputs.update(username=username, password=password, login="1")
    # The normal phpBB form accepts submissions starting in the next clock second.
    time.sleep(max(0, int(inputs["creation_time"]) + 1.05 - time.time()))
    posted = browser.fetch(urljoin(urljoin(base, path), action), inputs)
    board = browser.fetch("index.php")
    success = "mode=logout" in board and username in board
    if success != expect_success:
        detail = re.search(r'<div class="error">(.+?)</div>', posted, re.S)
        raise RuntimeError(f"Unexpected login result for {username}: {detail.group(1) if detail else 'no login error'}")
    if not expect_success and not re.search(r"incorrect password|falsches Passwort", unescape(posted), re.I):
        raise RuntimeError("The incorrect password must reach the native authentication provider")
    return browser


def public_pages(base, member):
    guest = Browser(base)
    home = guest.fetch("")
    for marker in ("Aktuelle Themen", "/fisubsilversh/theme/", "Welcome to phpBB"):
        if marker not in home:
            raise RuntimeError(f"Missing portal marker {marker!r} on the guest home page")
    if "Restricted topic fixture" in home or "Restricted topic fixture" not in member.fetch("app.php/portal"):
        raise RuntimeError("The portal must respect the migrated forum access of each viewer")
    for name in ("stylesheet.css", "de/stylesheet.css", "en/stylesheet.css", "plupload.css"):
        guest.fetch("styles/fisubsilversh/theme/" + name)
    guest_post = guest.fetch("viewtopic.php?t=1")
    member_post = member.fetch("viewtopic.php?t=1")
    if ("Ehemaliger Nutzer" not in guest_post or "RetiredFixture" in guest_post or
            "RetiredFixture" not in member_post or "Grüße" not in guest_post):
        raise RuntimeError("Migrated post formatting or guest/member byline differs from the source policy")


def profiles(base, member, owner):
    path = "memberlist.php?mode=viewprofile&u=2"
    public = Browser(base).fetch(path)
    private = member.fetch(path)
    if "ModernMember" not in public or "mode=compose" not in public:
        raise RuntimeError("Guest profile must be reachable with a PM login link")
    for marker in ("Fixture Town", "Example.42", "ABCD0123", "examplefixture", "example.fixture"):
        if marker in public or marker not in private:
            raise RuntimeError(f"Incorrect contact or location visibility for {marker}")
    for url in ("https://threema.id/ABCD0123", "https://t.me/examplefixture", "https://www.facebook.com/example.fixture"):
        if url not in private:
            raise RuntimeError("An imported contact URL was not rendered correctly")
    edit = "ucp.php?i=ucp_profile&mode=profile_info"
    form = owner.fetch(edit)
    if 'name="pf_plus_telegram"' not in form or "ABCD0123" not in form:
        raise RuntimeError("The native UCP is missing the converted contacts")
    action, fields = Page(form).forms["ucp"]
    fields.update(submit="1", pf_phpbb_location="Fixture Town", pf_phpbb_facebook="https://www.facebook.com/example.fixture",
                  pf_phpbb_twitter="examplefixture", pf_plus_telegram="updatedfixture", pf_plus_signal="Example.42",
                  pf_plus_threema="ABCD0123", bday_year="1970", bday_month="1", bday_day="1")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    response = owner.fetch(urljoin(urljoin(base, edit), action), fields)
    updated = member.fetch(path)
    if "https://t.me/updatedfixture" not in updated or "https://www.facebook.com/example.fixture" not in updated:
        error = re.search(r'class="error">(.+?)</', response, re.S)
        raise RuntimeError("Native UCP contact edit failed: " + (error.group(1) if error else "no update"))
    if "updatedfixture" in Browser(base).fetch(path):
        raise RuntimeError("The edited contact became public")


def native_content(base, moderator, member):
    poll = member.fetch("viewtopic.php?t=1")
    if "Welche Jahreszeit?" not in poll or "Frühling" not in poll or "Herbst" not in poll:
        raise RuntimeError("A converted poll is missing from the topic")
    search = "search.php?keywords=Archivsuchprobe&sr=posts"
    results = Browser(base).fetch(search)
    restricted = moderator.fetch(search)
    if ("Retained post" not in results or "Restricted topic fixture" in results
            or "Restricted topic fixture" not in restricted):
        raise RuntimeError("The native search must find indexed posts within each viewer's forum permissions")
    inbox = member.fetch("ucp.php?i=pm&folder=inbox")
    if ("Postfachprobe 111" not in inbox or "Postfachprobe 115" not in inbox
            or "Postfachprobe 112" in inbox or "Postfachprobe 113" in inbox):
        raise RuntimeError("Imported new/unread PMs did not arrive in the native inbox")
    private = member.fetch("ucp.php?i=pm&mode=view&p=111")
    if "Vertraulich 111" not in private or "Grüße" not in private:
        raise RuntimeError("Unable to read a converted private message")
