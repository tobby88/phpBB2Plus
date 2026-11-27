"""Staff-only imported visit statistics and ordinary native session updates."""
from html import unescape
import json
import os
import re

from delegation import authenticate
from http_board import Browser, login


def browse(base, admin, ordinary, check):
    original = json.loads(check("prepare"))
    profile = "memberlist.php?mode=viewprofile&u=87"
    de = ("Gesamte Onlinezeit", "Dauer des letzten Besuchs", "Anzahl der Besuche", "Seitenaufrufe")
    en = ("Total time online", "Duration of last visit", "Number of visits", "Page views")
    password = os.environ["PLUS_TEST_MODERN_PASSWORD"]

    def page(actor, expected=None, labels=de, url=profile, member_name="DepartingFixture", audience="reader"):
        html = unescape(actor.fetch(url))
        if member_name not in html:
            raise RuntimeError("The synthetic member's native profile was unavailable")
        if expected is None:
            if any(re.search(r"<dt>\s*" + label + r"\s*:</dt>", html) for label in de + en):
                raise RuntimeError(f"The {audience} received staff-only visit statistics")
        else:
            for label, value in zip(labels, expected):
                if not re.search(r"<dt>" + label + r"\s*:</dt>\s*<dd>" + re.escape(value) + r"</dd>", html):
                    raise RuntimeError(f"Missing staff-only {label} / {value}")

    try:
        moderator = login(base, "ModerationFixture", password)
        page(Browser(base), audience="guest")
        page(ordinary, audience="ordinary member")
        page(admin, ("1 Jahr, 1 Woche, 2 Tage, 01:01:01", "01:01:01", "29", "2147483647"))
        page(moderator, ("1 year, 1 week, 2 days, 01:01:01", "01:01:01", "29", "2147483647"), en)
        check("deny")
        page(moderator, audience="moderator with the separate visit permission withdrawn")
        # This member may open a particular ACP module. That delegation alone never opened the old moderator block.
        first, _ = authenticate(base, "JuniorFixture")
        second = login(base, "JuniorFixture", password)
        page(first, audience="delegated ACP member")
        page(first, url="memberlist.php?mode=viewprofile&u=91", member_name="JuniorFixture", audience="profile owner")
        anchor = json.loads(check("overlap"))
        if anchor["logins"] != original["logins"] + 2:
            raise RuntimeError("Native ACP reauthentication or page navigation counted as a new visit")
        first.fetch("viewtopic.php?t=1")
        second.fetch("index.php")
        activity = json.loads(check("activity"))
        if (activity["seconds"] < 90 or activity["seconds"] != activity["until"] - anchor["anchor"]
                or activity["pages"] != "2147483649"):
            raise RuntimeError(f"Two genuine overlapping visits received incorrect online time or page counts: {activity}")
        check("limit")
        second.fetch("viewforum.php?f=1")
        check("limits")
    finally:
        check("restore")
