"""Operate the converted board and personal display controls through ordinary phpBB forms."""
import os
import re
from html import unescape
from pathlib import Path

from delegation import authenticate
from http_board import Browser, FormFields, admin_url, login, submit_form


def browse(base, web, target, php, owner=None):
    fixture = Path(__file__).with_suffix(".php")
    check = lambda phase: php(fixture, target, phase, cwd=web)
    admin, entry = authenticate(base, "ModernMember") if owner is None else (owner, admin_url(owner))
    guest = Browser(base)
    guest.fetch("index.php")
    actor = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
    check("setup")
    try:
        ucp = "ucp.php?i=ucp_prefs&mode=view"
        acp = entry + "&i=acp_board&mode=features"

        def settings(values):
            submit_form(admin, acp, admin.fetch(acp), "acp_board", dict(values, submit="1"))

        def choose(values):
            submit_form(actor, ucp, actor.fetch(ucp), "ucp", dict(values, submit="1"))

        def title_link(client):
            link = re.search(r'<a href="[^"]*#p985720" title="([^"]*)" class="lastsubject">([^<]*)</a>', client.fetch("index.php"))
            return (unescape(link[1]), unescape(link[2])) if link else None

        page = actor.fetch(ucp)
        values = FormFields(page, "ucp").values
        if values.get("plus_last_topic_title") != "0" or values.get("plus_sub_level_links") != "1":
            raise RuntimeError("The ordinary member's selected source values are missing from the UCP")
        public = title_link(guest)
        if public is None:
            raise RuntimeError("The guest index is missing the newly permitted subforum's last topic link")
        if public[0] != "Käse & Grüße im Forum":
            raise RuntimeError(f"The index linked the correct post but showed its reply subject or encoded title: {public[0]!r}")
        if len(public[1]) > 13 or not public[1].startswith("Käse &"):
            raise RuntimeError(f"The displayed index title did not honour the 13-character limit: {public[1]!r}")
        if title_link(actor):
            raise RuntimeError("The member who disabled last topic titles still saw the link")
        if "subforum_text.css" not in actor.fetch("index.php") or "subforum_text.css" in guest.fetch("index.php"):
            raise RuntimeError("The forum index did not distinguish text-only links from links with status icons")
        choose({"plus_last_topic_title": "1", "plus_sub_level_links": "0"})
        if not title_link(actor) or re.search(r'class="subforum [^"]+"[^>]*>.*?Layout subforum</a>', actor.fetch("index.php"), re.S):
            raise RuntimeError("Visible forum list did not use the saved title or sublink choice")
        settings({"config[plus_last_topic_title]": "0", "config[plus_last_topic_title_over]": "1"})
        stale = actor.fetch(ucp)
        if title_link(actor) or title_link(guest) or "plus_last_topic_title" in FormFields(stale, "ucp").values:
            raise RuntimeError("The enforced board setting did not hide the personal title control and index titles")
        choose({"plus_last_topic_title": "0"})
        settings({"config[plus_last_topic_title_over]": "0"})
        if not title_link(actor) or title_link(guest):
            raise RuntimeError("Releasing the board override lost the saved personal choice")
        choose({"plus_last_topic_title": "-1"})
        if title_link(actor):
            raise RuntimeError("Native members could not return to inheriting the board setting")
        settings({"config[plus_last_topic_title]": "1"})
        check("locked")
        if title_link(actor) or title_link(guest):
            raise RuntimeError("A password-protected child disclosed its topic title in its parent's row")
        check("unlocked")
        if not title_link(actor):
            raise RuntimeError("An inherited, accessible topic title was not restored")
        def forum_rows(page):
            return set(re.findall(r'class="forumtitle">([^<]+)</a>', page))

        choose({"plus_sub_forum": "0", "plus_sub_level_links": "2", "plus_split_cat": "1"})
        expanded = actor.fetch("index.php")
        if (not {"Layout room", "Layout subforum", "Layout third level"} <= forum_rows(expanded)
                or "plus-forum-indent" not in expanded or "Layout empty category" in expanded
                or "Layout private leaf" in expanded or expanded.count('<div class="forabg">') <= 1):
            raise RuntimeError("The expanded index omitted an accessible level, exposed an empty branch or joined distinct categories")
        choose({"plus_split_cat": "0"})
        joined = actor.fetch("index.php")
        if joined.count('<div class="forabg">') != 1 or 'plus-category-heading' not in joined or "Layout index" not in forum_rows(joined):
            raise RuntimeError("Joining visible categories did not retain their navigable headings in one forum list")
        choose({"plus_sub_forum": "2"})
        compact = actor.fetch("index.php")
        if ("Layout index" not in forum_rows(compact) or "Layout room" in forum_rows(compact) or "Layout room" not in compact
                or "Layout empty category" in compact or "Layout private leaf" in compact or not title_link(actor)):
            raise RuntimeError("The compact category lost a permitted child or its inherited last topic")
        opened = actor.fetch("viewforum.php?f=29850")
        if "Layout room" not in forum_rows(opened) or "Layout third level" in forum_rows(opened):
            raise RuntimeError("Opening a compact category did not expand its next level")
        default = guest.fetch("index.php")
        if "Layout room" not in forum_rows(default) or "Layout subforum" in forum_rows(default):
            raise RuntimeError("The expanded/compact member controls changed the guest's medium layout")
        from topic_groups import browse as topic_groups
        topic_groups(web, target, php, admin, actor, acp)
    finally:
        check("clean")
