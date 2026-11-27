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

    try:
        fixture = json.loads(check("setup" if source_enabled else "setup-missing"))
        visible, hidden, old = fixture["names"]
        category = "viewforum.php?f=" + str(fixture["category"])
        visitor = Browser(admin.base)
        index = block(member.fetch("index.php"))
        owner = block(admin.fetch("index.php"))
        public = block(visitor.fetch("index.php"))
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
        if block(member.fetch(category)):
            raise RuntimeError("A member with index-only display saw the block inside a forum")
        page = member.fetch(prefs)
        if FormFields(page, "ucp").values.get("plus_daily_scope") != "1":
            raise RuntimeError("The personal display selection did not survive conversion")
        submit_form(member, prefs, page, "ucp", {"plus_daily_scope": "2", "submit": "1"})
        if not block(member.fetch(category)) or block(member.fetch("viewforum.php?f=1")):
            raise RuntimeError("The category setting failed or included ordinary forums")
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
        check("force")
        if block(member.fetch("index.php")) or 'id="plus_daily_scope"' in member.fetch(prefs):
            raise RuntimeError("An enforced board-wide opt-out left the block or its personal override visible")
    finally:
        check("restore")
