"""Follow both the native credits URL and an existing Plus bookmark."""
from html import unescape
import re
from http_board import Browser, FormFields, admin_url, submit_form


def browse(base, english):
    guest = Browser(base)
    first = guest.fetch("hacks_list.php")
    expected = ('Aktive Plus-Erweiterungen', 'Credits der ursprünglichen phpBB2-Installation',
                'phpBB2 Plus Arcade', 'phpBB2 Plus Member Colours', 'id="credit-19000"', '0000 Archive 00', '(0)',
                'mailto:credits@example.invalid', 'https://example.invalid/credits?a=1&amp;b=2')
    if (any(value not in first for value in expected)
            or 'Die Datei C:\\Archiv enthält "Grüße" 📗 und den Text &copy;.' not in unescape(first)):
        raise RuntimeError("The public credits lost an active extension, source attribution or original text")
    second = guest.fetch("app.php/credits?start=30")
    if ('id="credit-19030"' not in second or 'id="credit-19000"' in second or
            any(value in first + second for value in ("local-file.zip", "mailto:unrecorded", "private-fixture-manifest.hl", "0000 Hidden archival entry"))):
        raise RuntimeError("Credit pagination, historic visibility or old contact links changed")
    english_page = english.fetch("app.php/credits")
    if 'Credits from the original phpBB2 board' not in english_page or 'Active Plus extensions' not in english_page:
        raise RuntimeError("The credits do not follow the signed-in member's language")
    if 'app.php/credits' not in guest.fetch("index.php"):
        raise RuntimeError("The forum does not link to the credits")


def colours_disabled(base):
    page = Browser(base).fetch("app.php/credits")
    if 'phpBB2 Plus Arcade' not in page or 'phpBB2 Plus Member Colours' in page:
        raise RuntimeError("A disabled Plus extension was listed as active")


def administration(owner):
    guest = Browser(owner.base)
    panel = admin_url(owner) + "&i=-phpbb2plus-community-acp-credits_module&mode=manage"
    if "Historische Mod-Credits" not in owner.fetch(panel) or "0000 Hidden archival entry" not in owner.fetch(panel + "&start=30"):
        raise RuntimeError("Staff cannot review the archive with hidden entries in the native ACP")
    create = panel + "&action=create"
    first = owner.fetch(create)
    name = "0000 !Credits Testing"
    description = 'Neu C:\\Archiv mit "Text" 📗 &copy;.'
    fields = {"title": name, "description": description, "author": "Test & Team", "version_text": "0"}
    catalogue = submit_form(owner, create, first, "plus-credit", fields)
    match = re.search(r'<tr><td>' + re.escape(name) + r'</td>.*?action=edit&amp;credit=(\d+)', catalogue, re.S)
    if not match or name in guest.fetch("app.php/credits"):
        raise RuntimeError("A new private credit was not saved privately")
    ident = match[1]
    if "zwischenzeitlich geändert" not in submit_form(owner, create, first, "plus-credit", fields):
        raise RuntimeError("Resubmitting the original form created another credit")
    edit = panel + "&action=edit&credit=" + ident
    private = owner.fetch(edit)
    if FormFields(private, "plus-credit").values["description"] != description:
        raise RuntimeError("A newly entered credit lost punctuation or supplementary characters")
    submit_form(owner, edit, private, "plus-credit", {"visible": "1"})
    public = guest.fetch("app.php/credits")
    if name not in public or description not in unescape(public):
        raise RuntimeError("Publishing a credit did not make the saved text public")
    if "zwischenzeitlich geändert" not in submit_form(owner, edit, private, "plus-credit", {"title": "Overwritten credit"}):
        raise RuntimeError("A stale editor overwrote a newer credit")
    duplicate = submit_form(owner, create, owner.fetch(create), "plus-credit", {**fields, "title": name.lower()})
    if "diesem Namen ist bereits vorhanden" not in duplicate:
        raise RuntimeError("The same credit could be created twice under a different case")
    delete = panel + "&action=delete&credit=" + ident
    review = owner.fetch(delete)
    revised = "0000 !Credits Revised"
    submit_form(owner, edit, owner.fetch(edit), "plus-credit", {"title": revised})
    if "zwischenzeitlich geändert" not in submit_form(owner, delete, review, "plus-credit", {"confirm": "1"}):
        raise RuntimeError("An outdated delete confirmation removed a changed credit")
    submit_form(owner, delete, owner.fetch(delete), "plus-credit", {"confirm": "1"})
    if revised in guest.fetch("app.php/credits") or revised in owner.fetch(panel):
        raise RuntimeError("A confirmed deletion did not remove the selected credit")
