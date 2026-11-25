"""Check which native publishing choices an ordinary converted membership provides."""
from html import unescape
import os
import re
import time
from urllib.parse import urljoin

from http_board import Page, login


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
    editor(moderator, 12, "012", True)
    editor(member, 12, "", False)
    page = editor(pending, 14, "03", True)
    action, fields = Page(page).forms["postform"]
    fields.update(subject="Aushang zur Probe", message="Eine lokale Vorschau.", topic_type="3", preview="1")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    page = pending.fetch(urljoin(pending.base, unescape(action)), fields)
    if not re.search(r'<input\b(?=[^>]*\bname="topic_type")(?=[^>]*\bvalue="3")(?=[^>]*\bchecked)[^>]*>', page):
        raise RuntimeError("The eligible group could not preview its native global announcement")
