"""Edit native gallery group grants and check the resulting access with real sessions."""
import os
import re
from html import unescape
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url, submit_form


def browse(admin, member, other, guest, personal, check):
    check("grants_groups")
    form = "album-category-grants"
    actions = ("view", "upload", "rate", "comment", "edit", "delete", "moderator")
    address = "app.php/album/category/8"

    def key(group, action):
        return f"grant[{group}][{action}]"

    def module(actor, full=False):
        entry = admin_url(actor)
        menu = actor.fetch(entry + ("&i=acp_board&mode=settings" if full else ""))
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Gruppenrechte für Fotokategorien</span>', menu)
        if not link or (not full and re.search(r'<span>(Fotokategorien|Fotoalbum-Uploads|Persönliche Fotoalben|Anzeige im Fotoalbum)</span>', menu)):
            raise RuntimeError("The ACP did not expose exactly the permitted gallery modules")
        return urljoin(entry, unescape(link[1]))

    base = module(admin, full=True)
    directory = admin.fetch(base)
    if "Clubfotografie</a>" not in directory or f"category={personal}&amp;" in directory:
        raise RuntimeError("The gallery group directory lost a shared category or included a personal album")
    if form in Page(admin.fetch(base + "&category=" + str(personal))).forms:
        raise RuntimeError("Shared group grants can edit a personal album")
    url = base + "&category=8"
    first = admin.fetch(url)
    granted = lambda page: {k for k in FormFields(page, form).values if k.startswith("grant[")}
    if (granted(first) != {key(71, "view")} or not all(f'name="{key(71, action)}"' in first for action in actions) or
            "ZZ Gallery 9320" in first or "Single HistoricMember" in first or "REGISTERED" in first):
        raise RuntimeError("The seven grant columns or their converted group settings are incorrect")
    second_url = url + "&page=2"
    second = admin.fetch(second_url)
    if f'name="{key(9320, "delete")}"' not in second:
        raise RuntimeError("The second page of named groups is unreachable")
    submit_form(admin, second_url, second, form, {key(9320, "delete"): "1"})
    changed = submit_form(admin, url, first, form, {key(71, action): "1" for action in actions})
    if granted(changed) != {key(71, action) for action in actions}:
        raise RuntimeError("A checked group permission did not survive the native form submission")
    if "inzwischen geändert" not in submit_form(admin, url, first, form, {key(71, "view"): None}):
        raise RuntimeError("An older form overwrote this category’s newer group grants")
    other.fetch(address, expected_status=404)
    guest.fetch(address, expected_status=404)
    check("grants_approved")
    if "Clubfotoprobe" not in other.fetch(address):
        raise RuntimeError("Confirming membership did not activate the existing group grant")
    check("grants_pending")
    other.fetch(address, expected_status=404)
    submit_form(admin, url, changed, form, {key(71, action): None for action in actions})
    if "Clubfotoprobe" not in member.fetch(address):
        raise RuntimeError("Removing a group grant removed an imported individual moderator")

    check("grants_delegate")
    entry = admin_url(other)
    login = other.fetch(entry)
    if "login" in Page(login).forms:
        credential = Page(login).forms["login"][1]["credential"]
        submit_form(other, entry, login, "login", {"login": "1", "username": "DepartingFixture",
                    "password_" + credential: os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    narrow = module(other) + "&category=8"
    updated = submit_form(other, narrow, other.fetch(narrow), form, {key(74, "view"): "1"})
    if "Clubfotoprobe" not in other.fetch(address) or granted(updated) != {key(74, "view")}:
        raise RuntimeError("The restricted group administrator could not save a grant for its active group")
    restored = submit_form(other, narrow, updated, form, {key(74, "view"): None, key(71, "view"): "1"})
    other.fetch(address, expected_status=404)
    check("grants_revoke")
    submit_form(other, narrow, restored, form, {key(74, "view"): "1"}, 403)
    other.fetch(address, expected_status=404)
    if granted(admin.fetch(second_url)) != {key(9320, "delete")}:
        raise RuntimeError("Editing the first page overwrote the group on the second page")
    check("grants_complete")
