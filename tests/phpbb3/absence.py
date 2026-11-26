"""Converted away notices, personal audiences, actual account controls and return confirmation."""
import re
from html import unescape
from urllib.parse import urljoin

from http_board import Browser, FormFields, Page, admin_url, submit_form


def browse(base, member, admin, verify):
    profile = "memberlist.php?mode=viewprofile&u=2"
    own = "app.php/absence"
    name = "plus-absence"
    def editor(page):
        return name in Page(page).forms

    verify("seed")
    try:
        verify("upgrade")
        guest = Browser(base)
        signed, public = member.fetch(profile), guest.fetch(profile)
        if ("Off sick" not in signed or "Nachricht &amp; Grüße 🎨 &lt;Wort&gt;" not in signed or
                "Nur für Mitglieder &amp;amp;." not in signed or "Nur für Mitglieder" in public or "Erkrankt" in public or "Off sick" in public or
                not re.search(r"<dd>(?:Away|Abwesend)</dd>", public)):
            raise RuntimeError("Active profile absence lost its personal audience, literal text or translated status")
        for path in ("viewtopic.php?t=1", "memberlist.php"):
            signed, public = member.fetch(path), guest.fetch(path)
            if ("Off sick" not in signed or "Nachricht &amp; Grüße" in signed or "Nachricht &amp; Grüße" in public or "Erkrankt" in public):
                raise RuntimeError("An author or directory entry did not show only its permitted absence status")
        verify("contact_label")
        if "Off sick" not in member.fetch("viewtopic.php?t=1"):
            raise RuntimeError("Changing the post badge position lost the absence status")
        if "/absence" not in member.fetch("ucp.php?i=ucp_profile&mode=profile_info") or "login" not in Page(guest.fetch("absence_notify_popup.php")).forms:
            raise RuntimeError("The native profile editor or former return bookmark was unreachable")
        if "plus-away-notice" not in admin.fetch("index.php") or "plus-away-notice" in member.fetch("index.php"):
            raise RuntimeError("The return reminder was displayed to the wrong account")
        initial = member.fetch(own)
        fields = FormFields(initial,name).values
        if fields.get("note") != "0" or fields.get("kind") != "13" or fields.get("active") != "0":
            raise RuntimeError("An inactive member's saved note or earlier unrecognised mode disappeared")
        submit_form(member,own,initial,name,{"active":"1","kind":"1","note":"Eigene Auszeit & 0","action":"save"})
        if "plus-away-notice" not in member.fetch("index.php"):
            raise RuntimeError("The member's active notice did not appear")
        conflict = submit_form(member,own,initial,name,{"active":"0","kind":"2","note":"Earlier form","action":"save"})
        if "already changed" not in conflict or FormFields(conflict,name).values.get("note") != "Eigene Auszeit & 0":
            raise RuntimeError("An older open editor overwrote a newer absence")
        verify("locked")
        limited = member.fetch("absence_notify_popup.php")
        if 'value="save"' in limited or 'value="return"' not in limited or "Eigene Auszeit" in limited:
            raise RuntimeError("Suspended ordinary editing still allowed a new notice or lost the return action")
        submit_form(member,own,limited,name,{"action":"return"})
        if "plus-away-notice" in member.fetch("index.php"):
            raise RuntimeError("Explicit return left the member marked away")
        verify("verify_return")
        member.fetch(own,expected_status=403)
        if not editor(admin.fetch(own)):
            raise RuntimeError("Disabling ordinary member notices also disabled the founder's own notice")
        verify("available")
        panel = admin_url(admin) + "&i=users&mode=profile&u=2"
        page = admin.fetch(panel)
        target = re.search(r'href="([^"]*absence_module[^"]*)"',page)
        if not target:
            raise RuntimeError("The native ACP user profile did not link to the absence editor")
        address = urljoin(panel,unescape(target[1]))
        admin_page = admin.fetch(address)
        if FormFields(admin_page,name).values.get("kind") != "2":
            raise RuntimeError("The native ACP lost the original member's absence")
        submit_form(admin,address,admin_page,name,{"active":"1","kind":"3","note":"Geprüfter Profilhinweis","action":"save"})
        if "Geprüfter Profilhinweis" not in member.fetch(profile) or "Geprüfter Profilhinweis" in guest.fetch(profile):
            raise RuntimeError("The ACP update did not respect the personal note's audience")
    finally:
        verify("restore")
