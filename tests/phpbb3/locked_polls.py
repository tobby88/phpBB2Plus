import os
import re
import time
from urllib.parse import urljoin

from http_board import Page, login


def exercise(base, member, change):
    moderator = login(base, "ModerationFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    path = "viewtopic.php?t=603"
    ballot = 'name="vote_id[]"'
    if ballot in member.fetch(path) or ballot not in moderator.fetch(path):
        raise RuntimeError("A locked topic must reserve its ballot for a local voting moderator")
    try:
        change("forum")
        if ballot not in moderator.fetch(path) or ballot in moderator.fetch("viewtopic.php?t=602") or ballot in member.fetch(path):
            raise RuntimeError("The forum lock or the moderator's assigned forum was ignored")
        change("expire")
        if ballot in moderator.fetch(path):
            raise RuntimeError("An expired moderator ballot was reopened")
        change("withdraw")
        if ballot in moderator.fetch(path):
            raise RuntimeError("A local moderator without voting permission was offered a ballot")
        change("forum")
        form = re.search(r'<form\b[^>]*class="topic_poll"[^>]*>.*?</form>', moderator.fetch(path), re.S)
        action, fields = Page(form.group(0)).forms[None]
        fields.update({"vote_id[]": "2", "update": "1"})
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        moderator.fetch(urljoin(urljoin(base, path), action), fields)
        result = moderator.fetch(path)
        if ballot in result or '<span class="poll_total_vote_cnt">2</span>' not in result:
            raise RuntimeError("The locked poll did not retain the moderator's single native vote")
    finally:
        change("restore")
