"""Check the converted activity block with ordinary board, portal and preference requests."""
import json
import os
import re
from html import unescape

from http_board import Browser, FormFields, admin_url, submit_form


def browse(admin, member, check, source_enabled=True):
    prefs = "ucp.php?i=ucp_prefs&mode=view"
    board = admin_url(admin) + "&i=acp_board&mode=load"

    def block(page):
        section = re.search(r'<section class="stat-block plus-daily-visitors">(.*?)</section>', page, re.S)
        return section.group(1) if section else ""

    def stat(page, name):
        section = re.search(r'<div class="stat-block ' + name + r'">(.*?)</div>', page, re.S)
        return section.group(1) if section else ""

    def words(html):
        # A native <br> separates the registration totals from the newest member.
        # Template indentation around that break differs on the two pages.
        html = re.sub(r"<br\s*/?>", " ", html, flags=re.I)
        return " ".join(unescape(re.sub("<[^>]+>", "", html)).split())

    try:
        fixture = json.loads(check("setup" if source_enabled else "setup-missing"))
        visible, hidden, old = fixture["names"]
        category = "viewforum.php?f=" + str(fixture["category"])
        visitor = Browser(admin.base)
        member_index = member.fetch("index.php")
        index = block(member_index)
        owner = block(admin.fetch("index.php"))
        guest_index = visitor.fetch("index.php")
        public = block(guest_index)
        portal = block(member.fetch("app.php/portal"))
        if not public or not owner or visible not in index or hidden in index or old in index or hidden in public:
            raise RuntimeError("The index lost its public totals, exposed a hidden visit, or crossed midnight")
        if "Visitors today" not in index or "Heute im Forum" not in owner or hidden not in owner or "<em>" not in owner:
            raise RuntimeError("The authorised hidden list or translated heading is missing")
        if not portal or hidden in portal or visible in portal or "last hour" not in portal:
            raise RuntimeError("The portal should contain only the board's daily and last-hour counts")
        private = Browser(admin.base)
        path = "ucp.php?mode=login"
        submit_form(private, path, private.fetch(path), "login", {"username": "HistoricMember",
                    "password": os.environ["PLUS_TEST_HISTORIC_PASSWORD"], "viewonline": "on", "login": "1"})
        logout = re.search(r'href="([^"]*ucp\.php\?mode=logout[^"]*)"', private.fetch("index.php"))
        if not logout:
            raise RuntimeError("The member's normal hidden login failed")
        if "mode=logout" in private.fetch(unescape(logout.group(1))):
            raise RuntimeError("The native logout kept the hidden session active")
        check("hidden-login")
        if "HistoricMember" in block(member.fetch("index.php")):
            raise RuntimeError("A concurrent visible session bypassed the hidden member's daily privacy")
        index_only = member.fetch(category)
        if (block(index_only) or stat(index_only, "online-list") or stat(index_only, "statistics") or stat(index_only, "birthday-list")
                or not stat(member_index, "online-list") or not stat(member_index, "statistics")):
            raise RuntimeError("A member's index-only choice did not limit online users and statistics to the index")
        birthdays = stat(member_index, "birthday-list")
        if (visible not in birthdays or hidden not in birthdays or old in birthdays or "Upcoming birthdays" not in birthdays
                or stat(guest_index, "birthday-list") or stat(visitor.fetch(category), "birthday-list")):
            raise RuntimeError("Birthdays lost the configured look-ahead or appeared to guests")
        page = member.fetch(prefs)
        if FormFields(page, "ucp").values.get("plus_daily_scope") != "1":
            raise RuntimeError("The personal display selection did not survive conversion")
        submit_form(member, prefs, page, "ucp", {"plus_daily_scope": "2", "submit": "1"})
        admin.fetch("index.php")
        overview = member.fetch(category)
        counts = stat(overview, "statistics")
        online = stat(overview, "online-list")
        index_counts = stat(member.fetch("index.php"), "statistics")
        if not counts or words(counts) != words(index_counts):
            raise RuntimeError(f"Category and index board statistics differ: {words(counts)!r} / {words(index_counts)!r}")
        if (not block(overview) or "ModernMember" not in online or 'Redaktion &amp; Gäste' not in online or 'Hidden palette' in online
                or visible not in stat(overview, "birthday-list")
                or hidden not in stat(overview, "birthday-list") or block(member.fetch("viewforum.php?f=1"))):
            raise RuntimeError("The category lost board-wide totals, online users from other pages or upcoming birthdays")
        settings = admin.fetch(board)
        if FormFields(settings, "acp_board").values.get("config[plus_daily_mode]") != "2":
            raise RuntimeError("The native ACP did not display the active counting mode")
        submit_form(admin, board, settings, "acp_board", {"config[plus_daily_mode]": "1", "submit": "1"})
        now = block(visitor.fetch("index.php"))
        if not now or re.search(r'Gäste:|Guests:|letzten Stunde|last hour', now):
            raise RuntimeError("Member-only mode still reported guests or last-hour detail")
        check("native-guests-off")
        if re.search(r'Gäste:|Guests:', block(visitor.fetch("index.php"))):
            raise RuntimeError("The board's native guest display setting was ignored")
        submit_form(admin, board, admin.fetch(board), "acp_board", {"config[plus_daily_mode]": "0", "submit": "1"})
        no_history = member.fetch(category)
        options = member.fetch(prefs)
        if (block(no_history) or not stat(no_history, "online-list") or not stat(no_history, "statistics")
                or FormFields(options, "ucp").values.get("plus_daily_scope") != "2"):
            raise RuntimeError("Disabling daily history removed independently enabled online, statistics or UCP controls")
        submit_form(member, prefs, options, "ucp", {"plus_daily_scope": "0", "submit": "1"})
        for url in ("index.php", category):
            off = member.fetch(url)
            if any(stat(off, kind) for kind in ("online-list", "statistics", "birthday-list")):
                raise RuntimeError("The member's opt-out with daily counting off left data on " + url)
        if not stat(member.fetch("viewforum.php?f=1"), "online-list") or not stat(visitor.fetch(category), "statistics"):
            raise RuntimeError("A personal opt-out changed normal forum browsing or another visitor's selection")
        submit_form(member, prefs, member.fetch(prefs), "ucp", {"plus_daily_scope": "2", "submit": "1"})
        submit_form(admin, board, admin.fetch(board), "acp_board",
                    {"config[load_online]": "0", "config[load_birthdays]": "0", "submit": "1"})
        without_online = visitor.fetch(category)
        if stat(without_online, "online-list") or not stat(without_online, "statistics") or stat(member.fetch(category), "birthday-list"):
            raise RuntimeError("The native online and birthday switches did not remain independent of general board statistics")
        check("force")
        enforced = member.fetch("index.php")
        if (block(enforced) or stat(enforced, "statistics") or stat(member.fetch(category), "statistics")
                or 'id="plus_daily_scope"' in member.fetch(prefs)):
            raise RuntimeError("An enforced board-wide opt-out left the index, category statistics or the personal override visible")
    finally:
        check("restore")
