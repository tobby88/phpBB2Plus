"""Exercise the source recipient flags, native header form and delegated message editor."""
import os
import re
from html import unescape
from html.parser import HTMLParser

from delegation import authenticate
from http_board import Browser, FormFields, Page, login, submit_form


TOPIC = "viewtopic.php?t=1&start=0"
READ = "plus-member-notice-read"
EDITOR = "plus-member-notice-admin"
ROUTE = "&i=-phpbb2plus-community-acp-tracker_settings_module&mode="
HISTORICAL = "Grüße &amp; Rückblick 📷\n&lt;Hinweis&gt; & Abschluss"


class Notice(HTMLParser):
    def __init__(self, page):
        super().__init__(convert_charrefs=True)
        self.text, self.links, self.tags = [], [], []
        content = re.search(r'<section[^>]*id="plus-member-notice".*?<div class="content">(.*?)</div>', page, re.S)
        if not content:
            raise RuntimeError("The expected board message is missing")
        self.feed(content[1])

    def handle_data(self, data):
        self.text.append(data)

    def handle_starttag(self, tag, attrs):
        self.tags.append(tag)
        if tag == "a":
            self.links.append(dict(attrs)["href"])


def imported(base, member, owner, text):
    initial = member.fetch(TOPIC)
    note = Notice(initial)
    expected = unescape(HISTORICAL) if text else "0"
    if "".join(note.text) != expected or note.tags != (["br"] if text else []):
        raise RuntimeError("The original message text, line breaks, or historical link fallback changed")
    if READ in Page(Browser(base).fetch(TOPIC)).forms or READ in Page(owner.fetch(TOPIC)).forms:
        raise RuntimeError("A guest or member who had already read the message saw it again")
    if FormFields(initial, READ).values.get("return_page") != "/" + TOPIC:
        raise RuntimeError("The mark-as-read form lost the originating topic or its parameters")
    if not text:
        marked = submit_form(member, TOPIC, initial, READ, {})
        if READ in Page(marked).forms or 'id="p1"' not in marked:
            raise RuntimeError("Marking the migrated message as read did not return to the original topic")


def manage(base, member, owner, create_account):
    publisher, entry = authenticate(base, "MemberNoticeJunior")
    panel = entry + ROUTE + "notice"
    initial = publisher.fetch(panel)
    fields = FormFields(initial, EDITOR).values
    if fields.get("message") != HISTORICAL or fields.get("format") != "text":
        raise RuntimeError("The editable source text, format or delegated board message module was lost")
    if "Module not accessible" not in publisher.fetch(entry + ROUTE + "manage", expected_status=503):
        raise RuntimeError("The board message assignment opened general tracker settings")

    def change(action, page=None, **values):
        return submit_form(publisher, panel, page or publisher.fetch(panel), EDITOR, {"action": action, **values})

    link = "https://example.invalid/termine?jahr=2008&seite=2"
    if "The message has been published." not in change("publish", initial, format="link", message=link):
        raise RuntimeError("The delegated editor could not publish its link")
    if "The message has changed." not in change("withdraw", initial):
        raise RuntimeError("A stale editor withdrew a later publication")
    current = member.fetch(TOPIC)
    if Notice(current).links != [link] or "".join(Notice(current).text) != link or READ not in Page(owner.fetch(TOPIC)).forms:
        raise RuntimeError("Publication did not reach existing accounts as an intact clickable link")
    marked = submit_form(member, TOPIC, current, READ, {})
    if READ in Page(marked).forms or 'id="p1"' not in marked or READ not in Page(owner.fetch(TOPIC)).forms:
        raise RuntimeError("The member's receipt changed another recipient or lost the originating topic")
    if READ in Page(submit_form(member, TOPIC, current, READ, {})).forms:
        raise RuntimeError("A repeated acknowledgement reactivated the message")

    create_account("create")
    try:
        newcomer = login(base, "NoticeNewMember", os.environ["PLUS_TEST_MODERN_PASSWORD"])
        if READ in Page(newcomer.fetch("index.php")).forms:
            raise RuntimeError("A later registration received a previously published message")
        change("publish", format="link", message=link)
        submit_form(member, TOPIC, current, READ, {}, status=409)
        if Notice(member.fetch(TOPIC)).links != [link] or Notice(newcomer.fetch(TOPIC)).links != [link]:
            raise RuntimeError("Publishing identical text again lost a recipient or accepted an old acknowledgement")
        text = "Sommerfest & <Jahrgang>\n".ljust(255, "📷")
        change("publish", format="text", message=text)
        if "up to 255 characters" not in change("publish", format="text", message=text + "x"):
            raise RuntimeError("The source length limit was lost")
        if "complete HTTP or HTTPS link" not in change("publish", format="link", message="Termine im Forum"):
            raise RuntimeError("The editor accepted a link without a web address")
        rendered = Notice(newcomer.fetch(TOPIC))
        if "".join(rendered.text) != text or rendered.tags != ["br"]:
            raise RuntimeError("The text editor changed supplementary characters, line breaks or literal brackets")
        change("withdraw")
        if any(READ in Page(browser.fetch(TOPIC)).forms for browser in (newcomer, member, owner)):
            raise RuntimeError("Withdrawing the message left a recipient's banner visible")
        if FormFields(publisher.fetch(panel), EDITOR).values.get("message") != text:
            raise RuntimeError("Withdrawing the message discarded the editable text")
    finally:
        create_account("remove")
