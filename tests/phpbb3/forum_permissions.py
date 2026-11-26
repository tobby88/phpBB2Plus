"""Check which native publishing choices an ordinary converted membership provides."""
from html import unescape
import os
import re
import time
from urllib.parse import urljoin

from http_board import Browser, Page, login


def reading(base, member, pending):
    guest = Browser(base).fetch("index.php")
    if ("Listed reading room" not in guest or "Private reading room" in guest or "Two reading circles" in guest
            or any(f"Reading notes {forum}" in guest for forum in (8, 9, 10))):
        raise RuntimeError("The guest index did not distinguish listing from reading")
    member_index = member.fetch("index.php")
    for forum, name in ((9, "Private reading room"), (10, "Two reading circles")):
        if name not in member_index or f"Reading notes {forum}" not in member.fetch(f"viewforum.php?f={forum}"):
            raise RuntimeError("An approved combination of memberships lost its forum topics")
    pending_index = pending.fetch("index.php")
    if "Two reading circles" not in pending_index or "Reading notes 10" in pending_index or "Private reading room" in pending_index:
        raise RuntimeError("A listed forum exposed its last topic before both permissions were granted")
    public = "viewforum.php?f=1"
    for actor, permitted in ((Browser(base), False), (pending, False), (member, True)):
        listing = actor.fetch(public)
        if ("Verschobener Lesekreis" in listing) != permitted or ("Neue Sitzordnung" in listing) != permitted:
            raise RuntimeError("A moved-topic link or its description did not follow destination access")
        results = actor.fetch("search.php?keywords=Kreisnotizprobe&sr=posts")
        if "#p32" not in results or ("#p490" in results) != permitted:
            raise RuntimeError("Native post search ignored the combined viewing and reading permissions")


def reporting(base, approved, pending):
    guest = Browser(base)
    for actor, expected in ((guest, False), (pending, False), (approved, True)):
        linked = "/post/1/report" in actor.fetch("viewtopic.php?t=1")
        if linked != expected:
            raise RuntimeError("The topic lost its independent reporting permission")
    for actor, post in ((approved, 1), (guest, 601)):
        if "report" not in Page(actor.fetch(f"app.php/post/{post}/report")).forms:
            raise RuntimeError("The native reporting form did not open for an eligible reader")


def publishing(owner, member, pending):
    def editor(actor, forum, choices, poll):
        page = actor.fetch(f"posting.php?mode=post&f={forum}")
        buttons = set(re.findall(r'<input\b(?=[^>]*\bname="topic_type")(?=[^>]*\bvalue="([0-3])")[^>]*>', page))
        has_poll = 'name="poll_title"' in page
        if "postform" not in Page(page).forms or buttons != set(choices) or has_poll != poll:
            raise RuntimeError(f"Unexpected native topic types or poll control in forum {forum}: {sorted(buttons)}, poll={has_poll}")
        return page

    editor(owner, 1, "0123", True)
    editor(member, 1, "012", True)
    editor(pending, 1, "", False)
    moderator = login(owner.base, "ModerationFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    for actor, group in ((moderator, "Designated leader"), (pending, "Waiting leader"), (member, "Approved leader")):
        managed = actor.fetch("ucp.php?i=ucp_groups&mode=manage")
        if group not in managed or "Former leader" in managed:
            raise RuntimeError("The native group management panel lost or reassigned a converted leadership")
    editor(moderator, 12, "012", True)
    editor(member, 12, "", False)
    page = editor(pending, 14, "03", True)
    action, fields = Page(page).forms["postform"]
    fields.update(subject="Aushang zur Probe", message="Eine lokale Vorschau.", topic_type="3", preview="1")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    page = pending.fetch(urljoin(pending.base, unescape(action)), fields)
    if not re.search(r'<input\b(?=[^>]*\bname="topic_type")(?=[^>]*\bvalue="3")(?=[^>]*\bchecked)[^>]*>', page):
        raise RuntimeError("The eligible group could not preview its native global announcement")
