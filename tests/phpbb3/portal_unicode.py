"""Read the converted HTML, then exercise the exact source using the native ACP and guest portal."""
from html import unescape
from pathlib import Path
import re

from delegation import authenticate
from http_board import Browser, FormFields, admin_url, submit_form


def browse(base, board, target, php, owner=None):
    fixture = Path(__file__).with_suffix(".php")
    def stage(phase):
        php(fixture, target, phase, cwd=board)

    stage("prepare")
    try:
        if owner is None:
            owner, entry = authenticate(base, "ModernMember")
        else:
            entry = admin_url(owner)
        guest = Browser(base)
        url = entry + "&i=-phpbb2plus-community-acp-portal_module&mode=settings"
        form = "plus-portal-settings"
        def editor():
            page = owner.fetch(url)
            return page, FormFields(page, form).values["welcome"]
        def save(page, welcome):
            changes = {"welcome": welcome, "save": 1}
            for field in ("exclude", "poll_forums"):
                selected = re.search(r'<select[^>]+name="' + field + r'\[\]"[^>]*>(.*?)</select>', page, re.S)[1]
                changes[field+"[]"] = re.findall(r'<option value="(\d+)" selected', selected) or None
            return submit_form(owner, url, page, form, changes)
        def public():
            return unescape(guest.fetch("app.php/portal"))

        _, source = editor()
        if "&#x1F600; / 🪐 / &amp;#128512; / &copy;" not in source or "😀 / 🪐 / &#128512; / ©" not in public():
            raise RuntimeError("The migrated welcome editor or public HTML confused a character with its literal reference")
        stage("old")
        prior, original = editor()
        if original != "<p>Altes &#x1F600; und &amp;#x1F600;</p>" or "Altes 😀 und &#x1F600;" not in public():
            raise RuntimeError("An existing installation lost the original entity spelling in its welcome message")
        changed = "<P title='Grüße 🧩'>Neu 🪐 / &#128512; / &amp;#128512; / 𠜎</P>"
        save(prior, changed)
        current, reopened = editor()
        if reopened != changed or "Neu 🪐 / 😀 / &#128512; / 𠜎" not in public():
            raise RuntimeError("Saving the welcome HTML changed its source or its rendered text")
        equivalent = changed.replace("🪐", "&#129680;")
        save(current, equivalent)
        # Identical rendered HTML is still a different editor revision.
        stale = save(current, changed)
        if "Portal-Einstellungen wurden inzwischen geändert" not in stale or editor()[1] != equivalent:
            raise RuntimeError("A stale portal form replaced an equivalent-looking but differently spelled edit")
        stage("older-write")
        if editor()[1] != "<em>Ältere Änderung &amp;#128512;</em>" or "Ältere Änderung &#128512;" not in public():
            raise RuntimeError("A write by an older installation was hidden by a stale Unicode source")
    finally:
        stage("clean")
