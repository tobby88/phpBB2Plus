"""Manage shared photo categories in the native ACP with full and category-only accounts."""
from html import unescape
import os
import re
from urllib.parse import parse_qs, urljoin, urlsplit

from gallery import ORIGINAL, submit
from gallery_media import picture, upload
from http_board import FormFields, Page, admin_url, submit_form


def browse(admin, member, delegate, guest, personal, check):
    form = "album-public-category"
    full = admin_url(admin)

    def module(entry, menu):
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Fotokategorien</span>', menu)
        if not link:
            heading = re.search(r"<h1[^>]*>([^<]*)</h1>", menu)
            raise RuntimeError("No category entry in the ACP page " + (unescape(heading[1]) if heading else repr(list(Page(menu).forms))))
        return urljoin(entry, unescape(link[1]))

    address = module(full, admin.fetch(full + "&i=acp_board&mode=settings"))
    action = lambda base, mode, cat: base + "&action=" + mode + "&category=" + str(cat)
    category = lambda cat: "app.php/album/category/" + str(cat)

    def save(actor, base, mode, cat, values, page=None, status=200):
        url = action(base, mode, cat)
        return submit_form(actor, url, page or actor.fetch(url), form, values, status)

    def identifier(page, title):
        for link, name in re.findall(r'<a href="([^"]+)"><strong>([^<]*)</strong></a>', page):
            if unescape(name) == title:
                return parse_qs(urlsplit(unescape(link)).query)["category"][0]
        raise RuntimeError("Missing ACP photo category: " + title)

    original = FormFields(admin.fetch(action(address, "edit", 8)), form).values
    save(admin, address, "edit", 8, {"description": "Gruppe &amp; 📷"})
    check("category_grants")
    if "Gruppe &amp;amp; 📷" not in member.fetch(category(8)):
        raise RuntimeError("Editing a group category changed access or literal text")
    guest.fetch(category(8), expected_status=404)
    save(admin, address, "edit", 8, {"description": original["description"]})
    missing = admin.fetch(action(address, "edit", personal))
    if form in Page(missing).forms or "nicht mehr verfügbar" not in missing:
        raise RuntimeError("A personal album appeared in general category administration")
    create = admin.fetch(action(address, "add", 0))
    defaults = FormFields(create, form).values
    if [defaults[key] for key in ("view","upload","rate","comment","edit","delete","approval")] != ["-1","0","0","0","0","2","0"]:
        raise RuntimeError("The default access levels for a new shared photo category changed")
    if "Berechtigungsstufe" not in save(admin, address, "add", 0, {"title":"No guest edits", "edit":"-1"}, create):
        raise RuntimeError("The category editor accepted a guest edit level")
    first = "Category &amp; 📷"
    root = identifier(save(admin, address, "add", 0, {"title":first, "view":"0"}, create), first)
    child = identifier(save(admin, address, "add", root, {"title":"Category source"}), "Category source")
    child2 = identifier(save(admin, address, "add", child, {"title":"Category descendant"}), "Category descendant")
    target = identifier(save(admin, address, "add", 0, {"title":"Category target"}), "Category target")
    guest.fetch(category(child), expected_status=404)
    if "außerhalb" not in save(admin, address, "edit", root, {"parent":child}):
        raise RuntimeError("A shared category was reparented underneath its own descendant")
    for direction, expected_first in (("up", True), ("down", False)):
        listing = save(admin, address, direction, target, {})
        if (listing.index("<strong>Category target</strong>") < listing.index("<strong>Category &amp;amp; 📷</strong>")) != expected_first:
            raise RuntimeError("The ACP did not reorder siblings")
    save(admin, address, "edit", target, {"parent":root})
    guest.fetch(category(target), expected_status=404)
    save(admin, address, "edit", target, {"parent":"0"})
    save(admin, address, "edit", root, {"view":"-1"})
    if "Category descendant" not in guest.fetch(category(child)):
        raise RuntimeError("Opening the parent did not reopen its visible child")
    upload(admin, category(child) + "/upload", "Public category transfer", [("example.png", "image/png", ORIGINAL)])
    stale = admin.fetch(action(address, "delete", child))
    image = picture(admin, category(child), "Public category transfer")
    submit(member, image, "album-discuss", {"body":"Kommentar zum Umzug", "points":8})
    if "inzwischen geändert" not in save(admin, address, "delete", child, {"destination":target}, stale):
        raise RuntimeError("An old removal confirmation ignored a newer picture discussion")
    save(admin, address, "delete", child, {"destination":target})
    if (FormFields(admin.fetch(action(address, "edit", child2)), form).values["parent"] != root or
            admin.fetch(image + "/full", raw=True) != ORIGINAL or "Kommentar zum Umzug" not in guest.fetch(image)):
        raise RuntimeError("Removing a public category lost its retained child, original or discussion")
    check("category_moved")

    check("category_delegate")
    entry = admin_url(delegate)
    login_page = delegate.fetch(entry)
    credential = Page(login_page).forms["login"][1]["credential"]
    submit_form(delegate, entry, login_page, "login", {"login":"1", "username":"DepartingFixture",
                "password_" + credential:os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    entry = admin_url(delegate)  # Native ACP authentication rotates the session ID.
    menu = delegate.fetch(entry)
    narrow = module(entry, menu)
    if (any(label + "</span>" in menu for label in ("Fotoalbum-Uploads", "Persönliche Fotoalben", "Anzeige im Fotoalbum")) or
            form in Page(delegate.fetch(action(narrow, "edit", personal))).forms):
        raise RuntimeError("The category-only grant also opened another gallery control")
    save(delegate, narrow, "edit", child2, {"description":"Delegierte Bearbeitung"})
    if "Delegierte Bearbeitung" not in guest.fetch(category(root)):
        raise RuntimeError("The category-only administrator could not edit its category")
    old = delegate.fetch(action(narrow, "edit", child2))
    check("category_revoke")
    save(delegate, narrow, "edit", child2, {"description":"Revoked category update"}, old, 403)
    if "Delegierte Bearbeitung" not in guest.fetch(category(root)) or "Revoked category update" in guest.fetch(category(root)):
        raise RuntimeError("A stale delegated form changed a shared category")
    for cat in (target, child2, root):
        save(admin, address, "delete", cat, {"destination":"remove"})
    check("category_removed_" + urlsplit(image).path.rsplit("/", 1)[1])
    guest.fetch(image, expected_status=404)
    guest.fetch(category(root), expected_status=404)
