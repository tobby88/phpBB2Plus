"""Read the native questions reached by phpBB2 bookmarks and links from three Plus editors."""
from html import unescape
from html.parser import HTMLParser
import re
from urllib.parse import urljoin, urlsplit
from http_board import Browser


class Questions(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.aliases = {}
        self.question = self.heading = self.answer = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "dt":
            self.heading = "" if re.fullmatch(r"f\d+r\d+", a.get("id", "")) else None
            self.question = None
        if tag == "dd":
            self.answer = self.question
        if a.get("id", "").isdigit():
            key = int(a["id"])
            if self.answer is None or key in self.aliases:
                raise AssertionError("Repeated help bookmark or bookmark outside its native answer")
            self.aliases[key] = self.answer

    def handle_endtag(self, tag):
        if tag == "dt":
            self.question, self.heading = self.heading, None
        if tag == "dd":
            self.answer = None

    def handle_data(self, text):
        if self.heading is not None:
            self.heading += text


def browse(base, german, english, check):
    guest = Browser(base)
    kb, download, wall = "app.php/knowledge/article/201/edit", "app.php/downloads/file/50", "app.php/shoutbox"
    def status(actor, page, form_id, setting):
        html = actor.fetch(page)
        form = re.search(r'<form\b[^>]*\bid="' + form_id + r'"[^>]*>.*?</form>', html, re.S)
        link = form and re.search(r'<a href="([^"]+)" target="_blank" rel="noopener">BBCode</a>([^<]*)<em>([^<]+)</em>', form[0])
        if not link or not urlsplit(urljoin(base, unescape(link[1]))).path.endswith("/help/bbcode") or link[3] != setting:
            raise AssertionError("Wrong help link, draft target or BBCode setting in " + form_id)
        return form[0]

    check("prepare")
    try:
        faq = Questions(guest.fetch("faq.php#20")).aliases
        en = Questions(english.fetch("app.php/help/faq")).aliases
        if set(faq) != set(range(49)) or set(en) != set(faq) or not all(word in faq[20] for word in ("Umfrage", "abstimmen")):
            raise AssertionError("Legacy FAQ questions no longer lead to their matching localized answers")
        for number, word in ((0, "login"), (20, "poll"), (26, "sticky"), (43, "missing"),
                             (45, "file"), (46, "remove"), (47, "download")):
            if word not in en[number].lower():
                raise AssertionError(f"Bookmark {number} reaches the wrong English question: {en[number]}")
        if en[39] != en[42]:
            raise AssertionError("The historic file and comment editing links must reach the native instructions")
        guide = Questions(english.fetch("faq.php?mode=bbcode#9")).aliases
        if set(guide) != set(range(11)) or "image" not in guide[9].lower() or "link" not in guide[8].lower():
            raise AssertionError("BBCode bookmarks must address the guide's own questions")
        if "plus-shout-compose" in guest.fetch(wall):
            raise AssertionError("A guest without shout permission received a writing form")
        status(german, wall, "plus-shout-compose", "eingeschaltet")
        status(english, wall, "plus-shout-compose", "ON")
        status(german, kb, "kb-editor", "eingeschaltet")
        status(german, download, "dl-comment", "eingeschaltet")
        check("downloads-off")
        status(german, download, "dl-comment", "ausgeschaltet")
        status(german, kb, "kb-editor", "eingeschaltet")
        check("board-off")
        if "[page]" not in status(german, kb, "kb-editor", "ausgeschaltet"):
            raise AssertionError("Disabling BBCode also hid the independent Knowledge Base page instructions")
        status(german, download, "dl-comment", "ausgeschaltet")
        status(german, wall, "plus-shout-compose", "ausgeschaltet")
        remaining = Questions(guest.fetch("app.php/help/faq")).aliases
        if set(remaining) != set(range(39)):
            raise AssertionError("Disabling attachments removed unrelated bookmarks or kept obsolete attachment targets")
    finally:
        check("restore")
