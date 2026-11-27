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
    check("setup")
    try:
        admin, entry = authenticate(base, "ModernMember") if owner is None else (owner, admin_url(owner))
        actor = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
        guest = Browser(base)
        ucp = "ucp.php?i=ucp_prefs&mode=view"
        acp = entry + "&i=acp_board&mode=features"
        title = "Käse & Grüße im Forum"

        def settings(values):
            submit_form(admin, acp, admin.fetch(acp), "acp_board", dict(values, submit="1"))

        def choose(values):
            submit_form(actor, ucp, actor.fetch(ucp), "ucp", dict(values, submit="1"))

        def title_link(client):
            return re.search(r'title="Käse &amp; Grüße im Forum" class="lastsubject">([^<]*)</a>', client.fetch("index.php"))

        page = actor.fetch(ucp)
        values = FormFields(page, "ucp").values
        if values.get("plus_last_topic_title") != "0" or values.get("plus_sub_level_links") != "1":
            raise RuntimeError("The ordinary member's selected source values are missing from the UCP")
        public = title_link(guest)
        if not public or title_link(actor) or len(unescape(public[1])) > 13 or not unescape(public[1]).startswith("Käse &"):
            raise RuntimeError("The index mixed up the subject of a reply, the topic title, Unicode width or the viewer preference")
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
    finally:
        check("clean")
