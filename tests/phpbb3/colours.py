"""Exercise cosmetic group and account assignments through the native ACP."""
from html import unescape
import re
import time
from urllib.parse import parse_qs, urljoin, urlsplit

from http_board import Page, admin_url


def administration(owner, historic_id):
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_groups&mode=position")
    link = re.search(r'<a[^>]+href="([^"]+)"[^>]*>\s*<span>Namensfarben</span>', menu)
    if not link:
        raise RuntimeError("The member colour catalogue is missing from native group administration")
    panel = urljoin(entry, unescape(link.group(1)))

    def submit(page, form_id, data):
        action, fields = Page(page).forms[form_id]
        fields.update(data)
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(panel, action), fields)

    def find(label):
        link = re.search(r'<a\b[^>]*href="([^"]+)"[^>]*>' + re.escape(label) + r'</a>', owner.fetch(panel))
        if not link:
            raise RuntimeError("Missing colour action " + label)
        return urljoin(panel, unescape(link.group(1)))

    def profile(member, colour):
        body = owner.fetch("memberlist.php?mode=viewprofile&u=" + str(member))
        return re.search(r'color:\s*#' + re.escape(colour) + r'\b', body, re.I) is not None

    form = "plus-colour-entry"
    new = owner.fetch(find("Farbe hinzufügen"))
    values = dict(save="1", label="Projektgruppe", colour="#AA5533", position="-7", in_legend="1")
    submit(new, form, values)
    submit(new, form, values)
    if owner.fetch(panel).count(">Projektgruppe</a>") != 1:
        raise RuntimeError("Submitting a new colour twice created duplicate entries")
    path = find("Projektgruppe")
    colour_id = parse_qs(urlsplit(path).query)["id"][0]
    group = submit(owner.fetch(panel), "plus-colour-group", {"lookup": "1", "group_id": "71"})
    submit(group, "plus-colour-assignment", {"save": "1", "selection": colour_id})
    if not profile(historic_id, "AA5533") or not profile(2, "FF0000"):
        raise RuntimeError("A group assignment failed to refresh profiles or replaced a personal colour")
    stale = submit(group, "plus-colour-assignment", {"save": "1", "selection": "0"})
    if "inzwischen geändert" not in stale:
        raise RuntimeError("An outdated assignment replaced a newer group choice")
    personal = submit(owner.fetch(panel), "plus-colour-member", {"lookup": "1", "member_name": "DepartingFixture"})
    submit(personal, "plus-colour-assignment", {"save": "1", "selection": colour_id})
    if not profile(87, "AA5533"):
        raise RuntimeError("A personal selection is missing from the native profile")
    change = owner.fetch(path)
    revised = dict(values, position="17", colour="#AABBCC")
    del revised["in_legend"]
    submit(change, form, revised)
    if (not profile(historic_id, "5A769B") or not profile(87, "AABBCC")
            or "Projektgruppe" in owner.fetch("index.php")):
        raise RuntimeError("Changing the order, tint or legend visibility ignored existing assignments")
    if "inzwischen geändert" not in submit(change, form, values):
        raise RuntimeError("An outdated palette entry replaced a newer one")
    confirm = submit(owner.fetch(path), form, {"remove": "1"})
    if "Diese Farbe entfernen?" not in confirm:
        raise RuntimeError("Deleting a colour did not ask for confirmation in the ACP")
    confirm_id = next(key for key, (_, data) in Page(confirm).forms.items() if "confirm_uid" in data)
    submit(confirm, confirm_id, {"confirm": "Ja"})
    if ">Projektgruppe</a>" in owner.fetch(panel) or profile(87, "AABBCC"):
        raise RuntimeError("A removed colour remained on an account or in the catalogue")
    restore = submit(owner.fetch(panel), "plus-colour-group", {"lookup": "1", "group_id": "71"})
    submit(restore, "plus-colour-assignment", {"save": "1", "selection": "7"})
    if not profile(historic_id, "000080"):
        raise RuntimeError("An original hidden colour could not be reassigned")
