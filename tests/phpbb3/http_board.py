"""Exercise the ordinary phpBB installer wizard and login forms on loopback."""
from contextlib import contextmanager
from copy import copy
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
import uuid
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urljoin, urlsplit
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler, Request


def add_input(fields, name, value):
    if name.endswith("[]"):
        fields.setdefault(name, []).append(value)
    else:
        fields[name] = value


class Page(HTMLParser):
    def __init__(self, body, checked=False):
        super().__init__(convert_charrefs=True)
        self.actions = []
        self.forms = {}
        self.form = None
        self.checked = checked
        self.feed(body)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "a" and "button1" in attrs.get("class", "").split():
            self.actions.append(attrs.get("href", ""))
        if tag == "form":
            self.form = (attrs.get("action", ""), {})
            self.forms[attrs.get("id")] = self.form
        if tag == "input" and self.form and attrs.get("type") == "hidden" and attrs.get("name"):
            add_input(self.form[1], attrs["name"], attrs.get("value", ""))
        if (self.checked and tag == "input" and self.form and attrs.get("name") and "checked" in attrs
                and attrs.get("type") in ("checkbox", "radio")):
            add_input(self.form[1], attrs["name"], attrs.get("value", "on"))

    def handle_endtag(self, tag):
        if tag == "form":
            self.form = None


class FormFields(HTMLParser):
    """Read the successful controls as the displayed browser form would submit them."""
    def __init__(self, html, form_id):
        super().__init__(convert_charrefs=True)
        self.form_id = form_id
        self.action = None
        self.values = {}
        self.inside = False
        self.select = self.textarea = None
        self.feed(html)

    def handle_starttag(self, tag, pairs):
        if not self.inside and tag != "form":
            return
        attrs = dict(pairs)
        name = attrs.get("name")
        if tag == "form":
            self.inside = attrs.get("id") == self.form_id
            if self.inside:
                self.action = attrs.get("action") or ""
        if not self.inside:
            return
        if tag == "input" and name and "disabled" not in attrs and attrs.get("type") not in {"submit", "button", "reset", "file"}:
            if attrs.get("type") not in {"checkbox", "radio"} or "checked" in attrs:
                add_input(self.values, name, attrs.get("value", "on" if attrs.get("type") in {"checkbox", "radio"} else ""))
        elif tag == "select":
            self.select = name
        elif tag == "option" and self.select and ("selected" in attrs or self.select not in self.values):
            self.values[self.select] = attrs.get("value", "")
        elif tag == "textarea":
            self.textarea = name
            self.values[name] = ""

    def handle_endtag(self, tag):
        if tag == "form":
            self.inside = False
        elif tag == "select":
            self.select = None
        elif tag == "textarea":
            self.textarea = None

    def handle_data(self, text):
        if self.textarea:
            self.values[self.textarea] += text


def submit_form(actor, url, page, name, changes, status=200):
    """Submit an already displayed form, retaining its token and original revision."""
    form = FormFields(page, name)
    if form.action is None:
        raise KeyError(name)
    fields = form.values
    for key, value in changes.items():
        if value is None:
            fields.pop(key, None)
        else:
            fields[key] = value
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    return actor.fetch(urljoin(urljoin(actor.base, url), form.action), fields, expected_status=status)


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
        self.cookies = CookieJar()
        self.opener = build_opener(HTTPCookieProcessor(self.cookies), LoopbackRedirect(urlsplit(base).netloc))

    def separate_client(self):
        client = Browser(self.base)
        for cookie in self.cookies:
            client.cookies.set_cookie(copy(cookie))
        return client

    def fetch(self, path, data=None, ajax=False, raw=False, expected_status=200, files=None):
        address = urljoin(self.base, path)
        if urlsplit(address).netloc != urlsplit(self.base).netloc:
            raise ValueError("Unexpected link away from the disposable board")
        headers = {"X-Requested-With": "XMLHttpRequest"} if ajax else {}
        body = urlencode(data, doseq=True).encode() if data is not None else None
        if files is not None:
            boundary = "plus-test-" + uuid.uuid4().hex
            pieces = []
            for name, values in (data or {}).items():
                values = values if isinstance(values, (tuple, list)) else (values,)
                for value in values:
                    pieces += [("--" + boundary).encode(), f'Content-Disposition: form-data; name="{name}"'.encode(), b"", str(value).encode()]
            for name, filename, content_type, content in files:
                pieces += [("--" + boundary).encode(), f'Content-Disposition: form-data; name="{name}"; filename="{filename}"'.encode(),
                           ("Content-Type: " + content_type).encode(), b"", content]
            body = b"\r\n".join(pieces + [("--" + boundary + "--").encode(), b""])
            headers["Content-Type"] = "multipart/form-data; boundary=" + boundary
        req = Request(address, data=body, headers=headers)
        try:
            with self.opener.open(req, timeout=90) as response:
                if response.status != expected_status:
                    raise RuntimeError(f"Expected HTTP {expected_status} at {urlsplit(address).path}, got {response.status}")
                body = response.read()
                return body if raw else body.decode("utf-8")
        except HTTPError as error:
            if error.code == expected_status:
                body = error.read()
                return body if raw else body.decode("utf-8")
            summary = " ".join(unescape(re.sub(r"<[^>]+>", " ", error.read().decode("utf-8", "replace"))).split())
            raise RuntimeError(f"HTTP {error.code} at {urlsplit(address).path}: {summary[-1500:]}") from None


@contextmanager
def server(web, root):
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    base = f"http://127.0.0.1:{port}/"
    log_file = root / "http-test.log"
    binary = os.environ.get("PLUS_TEST_PHP", "php")
    # phpBB recreates its Symfony container and Twig files when extensions change.
    # Two different containers can have the same path and second-resolution mtime.
    excludes = root / "opcache-exclusions.txt"
    cache_path = (web / "cache").resolve()
    excludes.write_text("\n".join(sorted({str(cache_path) + os.sep, cache_path.as_posix() + "/"})) + "\n", encoding="utf-8")
    options = ["-d", "opcache.enable=1", "-d", "opcache.enable_cli=1", "-d", "opcache.blacklist_filename=" + str(excludes),
               "-d", "opcache.validate_timestamps=1", "-d", "opcache.revalidate_freq=0"]
    if os.name == "nt":
        # On Windows the PHP limit includes time spent waiting on disk and
        # MariaDB while multiple isolated boards are being converted locally.
        options.extend(["-d", "max_execution_time=75"])
        dll = Path(shutil.which(binary) or binary).parent / "ext/php_opcache.dll"
        if dll.is_file() and subprocess.check_output([binary, "-r", "echo (int) extension_loaded('Zend OPcache');"]).strip() == b"0":
            options.extend(["-d", "zend_extension=" + str(dll)])
    with log_file.open("wb") as log:
        proc = subprocess.Popen([binary, *options, "-d", "disable_functions=mail", "-d", "display_errors=0",
                                 "-S", f"127.0.0.1:{port}", "-t", str(web), str(Path(__file__).with_name("rewrite_router.php").resolve())],
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
    for directory in ("images/smiles", "images/ranks", "images/avatars/gallery", "images/flags", "images/icon", "images/banner"):
        if (source / directory).is_dir():
            shutil.copytree(source / directory, target / directory)
    news = Path("templates/fisubsilversh/images/news")
    (target / news).mkdir(parents=True, exist_ok=True)
    for name in ("topicnews.gif", "announcements.gif"):
        shutil.copyfile(source / news / name, target / news / name)
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


def login(base, username, password, expect_success=True, portal=False, remember=False):
    browser = Browser(base)
    path = "app.php/portal" if portal else "ucp.php?mode=login"
    page = Page(browser.fetch(path))
    action, inputs = page.forms["plus-portal-login" if portal else "login"]
    inputs.update(username=username, password=password, login="1")
    if remember:
        inputs["autologin"] = "on"
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


def admin_url(actor):
    link = re.search(r'href="([^"]*adm/index\.php\?[^"]+)"', actor.fetch("index.php"))
    if not link:
        raise RuntimeError("The migrated administrator cannot reach the native ACP")
    return urljoin(actor.base, unescape(link.group(1)))


def profiles(base, member, owner):
    path = "memberlist.php?mode=viewprofile&u=2"
    public = Browser(base).fetch(path)
    private = member.fetch(path)
    if re.search(r'<dt>(?:Age|Alter)\s*:', public) or not re.search(r'<dt>Age\s*:', private):
        raise RuntimeError("Native profile age did not respect Plus member-only visibility")
    if 'stat-block birthday-list' in Browser(base).fetch("index.php") or 'stat-block birthday-list' not in member.fetch("index.php"):
        raise RuntimeError("The native board birthday list did not retain its audience")
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
    flags = re.search(r'<select[^>]+name="pf_plus_country"[^>]*>(.*?)</select>', form, re.S)
    option = re.search(r'<option value="([0-9]+)"[^>]*>\s*Abendrot\s*</option>', flags.group(1)) if flags else None
    if not option or "Nordlicht" not in private or "images/plusflags/" not in private or "Nordlicht" in public or "images/plusflags/" in public:
        raise RuntimeError("Imported native flag options or guest visibility differ from the source")
    action, fields = Page(form, checked=True).forms["ucp"]
    fields["pf_plus_country"] = option.group(1)
    fields.update(submit="1", pf_phpbb_location="Fixture Town", pf_phpbb_facebook="https://www.facebook.com/example.fixture",
                  pf_phpbb_twitter="examplefixture", pf_plus_telegram="updatedfixture", pf_plus_signal="Example.42",
                  pf_plus_threema="ABCD0123", bday_year="1970", bday_month="1", bday_day="1")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    response = owner.fetch(urljoin(urljoin(base, edit), action), fields)
    updated = member.fetch(path)
    if "Abendrot" not in updated or "images/plusflags/" not in updated or "Abendrot" in Browser(base).fetch(path):
        raise RuntimeError("The native UCP did not retain the selected flag and its visibility")
    from profile_media import SMILE
    image = re.search(r'<img src="([^"]*images/plusflags/[a-f0-9]+\.png)"', updated)
    if not image or member.fetch(unescape(image.group(1)), raw=True) != SMILE:
        raise RuntimeError("The edited country field serves the wrong historical image")
    if "https://t.me/updatedfixture" not in updated or "https://www.facebook.com/example.fixture" not in updated:
        error = re.search(r'class="error">(.+?)</', response, re.S)
        raise RuntimeError("Native UCP contact edit failed: " + (error.group(1) if error else "no update"))
    if "updatedfixture" in Browser(base).fetch(path):
        raise RuntimeError("The edited contact became public")


def poll_result_bookmark(guest, path, ballot_page):
    link = 'class="poll_view_results"'
    if link not in ballot_page:
        raise RuntimeError("The open guest ballot did not offer its result view")
    for suffix in ("&view=viewpoll", "&postdays=0&postorder=asc&vote=viewresult"):
        page = guest.fetch(path + suffix)
        if link in page or '<span class="poll_total_vote_cnt">1</span>' not in page:
            raise RuntimeError("The original result bookmark must open the native totals before voting")


def native_content(base, moderator, member):
    poll = member.fetch("viewtopic.php?t=1")
    if "Welche Jahreszeit?" not in poll or "Frühling" not in poll or "Herbst" not in poll:
        raise RuntimeError("A converted poll is missing from the topic")
    guest = Browser(base)
    ballot = 'name="vote_id[]"'
    if ballot in poll or ballot in guest.fetch("viewtopic.php?t=1") or ballot not in moderator.fetch("viewtopic.php?t=1"):
        raise RuntimeError("The imported member-only ballot or previous member vote changed")
    if ballot in guest.fetch("viewtopic.php?t=601"):
        raise RuntimeError("The historical guest vote was forgotten")
    path = "viewtopic.php?t=602"
    public = guest.fetch(path)
    poll_result_bookmark(guest, path, public)
    form = re.search(r'<form\b[^>]*class="topic_poll"[^>]*>.*?</form>', public, re.S)
    if ballot not in public or not form:
        raise RuntimeError("A ballot which explicitly allowed guests was closed")
    action, fields = Page(form.group(0)).forms[None]
    fields.update({"vote_id[]": "2", "update": "1"})
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    guest.fetch(urljoin(urljoin(base, path), action), fields)
    voted = guest.fetch(path)
    if ballot in voted or '<span class="poll_total_vote_cnt">2</span>' not in voted:
        raise RuntimeError("The native guest ballot did not keep the new vote")
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


def former_names(base, member, removed=False, founder=None):
    guest = Browser(base)
    path = "viewtopic.php?t=1"
    public = guest.fetch(path)
    signed_in = member.fetch(path)
    if ("EditorFixture" in public or signed_in.count("EditorFixture") < 2
            or "GuestQuoter" not in public or "Zitatprobe" not in public):
        raise RuntimeError("A historical quote or editor has the wrong public/member attribution")
    if ("DepartingFixture" in public) == removed or "DepartingFixture" not in signed_in:
        raise RuntimeError("Native account removal lost the member-only byline or exposed it publicly")
    for term in ("EditorFixture", "EditorFix*"):
        result = guest.fetch("search.php?" + urlencode({"keywords": term, "sr": "posts"}))
        if "keine passenden Ergebnisse gefunden" not in result or "Zitatprobe" in result or "Retained post" in result:
            raise RuntimeError("A public search matched a retained quote identity")
    if removed:
        author_results(base, member, removed=True, founder=founder)


def author_results(base, member, removed=False, founder=None):
    guest = Browser(base)
    queries = (
        {"author": "retiredfixture", "sr": "posts"},
        {"author": "RetiredFix*", "sr": "posts"},
        {"author": "RetiredFixture", "keywords": "former", "sr": "posts"},
    )
    for params in queries:
        path = "search.php?" + urlencode(params)
        allowed = member.fetch(path)
        if ("Retained post" not in allowed or "RetiredFixture" not in allowed or "GuestQuoter" in allowed
                or "Private retained byline" in allowed or "Retained post" in guest.fetch(path)):
            raise RuntimeError("Retained author search changed its byline, scope or guest visibility")
    topics = member.fetch("search.php?" + urlencode({"author": "RetiredFix*", "sr": "topics"}))
    if not re.search(r'viewtopic\.php\?[^"]*\bt=1(?:[^0-9]|$)',topics):
        raise RuntimeError("Retained author search did not return the parent topic")
    if removed:
        after = member.fetch("search.php?" + urlencode({"author": "DepartingFixture", "sr": "posts"}))
        if "DepartingFixture" not in after or 'p=42' not in after:
            raise RuntimeError("The retained author of a newly removed native account could not be found")
    possible = "search.php?" + urlencode({"author": "RetiredFix*", "sr": "posts"})
    if "Named guest message" not in member.fetch(possible) or "Named guest message" not in guest.fetch(possible):
        raise RuntimeError("A genuine guest disappeared from a wildcard author search")
    if founder and "Private retained byline" not in founder.fetch(possible):
        raise RuntimeError("A founder could not find a retained byline in a restricted forum")
