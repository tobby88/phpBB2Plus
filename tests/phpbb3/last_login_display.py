"""Use real native profiles, member lists, sorting and the ACP on a converted fixture."""
from html import unescape
import json
import re

from http_board import Browser, FormFields, admin_url, submit_form


def browse(admin, member, check):
    ids = json.loads(check("setup"))
    guest = Browser(admin.base)
    preferences = admin_url(admin) + "&i=acp_board&mode=features"
    setting = "config[plus_hide_last_login]"

    def profile(actor, name):
        page = actor.fetch("memberlist.php?mode=viewprofile&u=" + str(ids[name]))
        match = re.search(r'<dd class="plus-last-login">([^<]+)</dd>', page)
        if not match:
            raise RuntimeError("Last sign-in is missing from the native profile")
        return unescape(match[1]).strip()

    def listed(actor, name):
        page = actor.fetch("memberlist.php?mode=searchuser&username=" + name)
        for row in re.findall(r'<tr\b[^>]*>(.*?)</tr>', page, re.S):
            match = re.search(r'<td class="plus-last-login">([^<]+)</td>', row)
            if match and name in row:
                return unescape(match[1]).strip(), page
        raise RuntimeError("The filtered native member list omitted the last sign-in")

    try:
        prefs = admin.fetch(preferences)
        if FormFields(prefs, "acp_board").values.get(setting) != "0":
            raise RuntimeError("ACP did not inherit the stored board setting")
        hidden = {"Hidden", "Verborgen"}
        submit_form(admin, preferences, prefs, "acp_board", {"submit":"Submit", setting:"1"})
        if profile(guest, "ModernMember") not in hidden or listed(member, "ModernMember")[0] not in hidden:
            raise RuntimeError("The old policy did not hide last sign-in times")
        staff, listing = listed(admin, "HistoricMember")
        if "2004" not in staff or "sk=pluslogin" not in listing or "2001" not in profile(admin, "ModernMember"):
            raise RuntimeError("Authorized account administrator cannot see or sort last sign-ins")
        for path, order in (("memberlist.php?mode=searchuser&username=*Member&sk=pluslogin&sd=a", ["ModernMember","HistoricMember"]),
                            ("memberlist.php?mode=lastlogon&order=DESC", ["HistoricMember","ModernMember"])):
            names = []
            start = 0
            while len(names) < len(order):
                page = admin.fetch(path + ("&start=" + str(start) if start else ""))
                for row in re.findall(r'<tr\b[^>]*>(.*?)</tr>', page, re.S):
                    if 'class="plus-last-login"' in row:
                        names.extend(n for n in order if n in row)
                later = [int(n) for n in re.findall(r'(?:&amp;|\?)start=(\d+)', page) if int(n) > start]
                if not later:
                    break
                start = min(later)
            if names != order:
                raise RuntimeError("Native and bookmarked administrator member sorting did not retain sign-in order: " + str(names))
        submit_form(admin, preferences, admin.fetch(preferences), "acp_board", {"submit":"Submit", setting:"0"})
        if "2001" not in profile(guest, "ModernMember") or "2001" not in listed(member, "ModernMember")[0]:
            raise RuntimeError("Public profile and authenticated list differ on an explicitly public sign-in")
        value, html = listed(member, "HistoricMember")
        if value not in hidden or profile(guest, "HistoricMember") not in hidden or "sk=pluslogin" in html:
            raise RuntimeError("The most recent invisible sign-in or its ordering was disclosed")
        check("account-hidden")
        if profile(member, "HistoricMember") not in hidden:
            raise RuntimeError("Account-wide invisibility was ignored")
        check("never")
        if profile(member, "HistoricMember") not in {"Never", "Nie"}:
            raise RuntimeError("An unknown last sign-in became a date")
        if 'class="plus-last-login"' in guest.fetch("memberlist.php"):
            raise RuntimeError("A guest received the authenticated directory column")
    finally:
        check("restore")
