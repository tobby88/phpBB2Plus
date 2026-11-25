"""Manage the converted download tree through its own native ACP module."""
import re
from html import unescape
from http_board import Browser, FormFields, submit_form
from knowledgebase import acp_panel


def administration(owner, verify):
    panel = acp_panel(owner, "Downloadkategorien verwalten")
    guest = Browser(owner.base)
    verify("seed")

    def action(name, ident=0):
        return panel + "&action=" + name + ("&category=" + str(ident) if ident else "")

    def save(url, changes, page=None):
        return submit_form(owner, url, page or owner.fetch(url), "dl-category", changes)

    def created_id(listing, name):
        match = re.search(r'<a href="([^"]+&amp;category=(\d+))">' + re.escape(name) + '</a>', listing)
        if not match:
            raise RuntimeError("Missing newly saved download category: " + name)
        return int(match[2])

    create = action("create")
    previous = owner.fetch(create)
    folder = created_id(save(create, {"title": "Privater Ordner", "allow_files": None}, previous), "Privater Ordner")
    if "inzwischen geändert" not in save(create, {"title": "Veraltet"}, previous):
        raise RuntimeError("Repeating an outdated create form added another category")
    guest.fetch("app.php/downloads/category/" + str(folder), expected_status=404)
    if "Ordnerkategorie" not in save(create, {"title": "Unterordner", "parent": str(folder), "allow_files": None}):
        raise RuntimeError("Nesting fileless folders did not enforce the imported tree rule")
    title = "Grüße 📚 &amp; Bibliothek"
    public = created_id(save(create, {"title": title, "initial_access": "legacy"}), "Grüße 📚 &amp;amp; Bibliothek")
    url = "app.php/downloads/category/" + str(public)
    if title not in unescape(guest.fetch(url)) or "dl-submit" not in guest.fetch(url + "/submit"):
        raise RuntimeError("The explicitly selected public access did not reproduce the historical defaults")
    edit = action("edit", public)
    page = owner.fetch(edit)
    if FormFields(page, "dl-category").values["title"] != title:
        raise RuntimeError("The download category name did not round-trip")
    save(edit, {"parent": str(folder)}, page)
    guest.fetch(url, expected_status=404)
    if "Teilbaum" not in save(action("edit",folder), {"parent": str(public)}):
        raise RuntimeError("A category was moved beneath its own descendant")
    save(edit, {"parent": "0"})
    earlier = owner.fetch(panel)
    order = re.findall(r'&amp;action=edit&amp;category=(\d+)', earlier)
    before = order.index(str(folder))
    save(action("up",folder), {})
    after = re.findall(r'&amp;action=edit&amp;category=(\d+)', owner.fetch(panel))
    if before < 1 or after.index(str(folder)) >= before:
        raise RuntimeError("Moving a category up did not change the sibling order")
    save(action("down",folder), {})
    if "direkt enthaltenen Downloads" not in save(action("edit",9101), {"allow_files": None}):
        raise RuntimeError("Turning a category into a folder concealed its existing files")

    def remove(ident, children, files, children_to="-1", files_to="-1", review=None):
        return save(action("delete",ident), {"children_mode": children, "files_mode": files, "children_to": children_to,
                                            "files_to": files_to, "confirm": "1"}, review)

    deletion = owner.fetch(action("delete",9101))
    verify("additional")
    if "inzwischen geändert" not in remove(9101,"move","move","9104","9104",deletion):
        raise RuntimeError("An old removal confirmation silently included a new download")
    if "Teilbaum" not in remove(9101,"move","move","9102","9104"):
        raise RuntimeError("The destination of the surviving children could create a cycle")
    guest.fetch("app.php/downloads/file/9201", expected_status=404)
    remove(9101,"move","move","9104","9104")
    verify("moved")
    guest.fetch("app.php/downloads/file/9201")
    guest.fetch("app.php/downloads/category/9102", expected_status=404)
    if "bestehen bleibende" not in remove(9102,"delete","move",files_to="9103"):
        raise RuntimeError("Downloads could move to a destination being removed")
    remove(9102,"delete","move",files_to="9104")
    deletion = owner.fetch(action("delete",9105))
    verify("discussion")
    if "inzwischen geändert" not in remove(9105,"delete","delete",review=deletion):
        raise RuntimeError("A removal confirmation could discard a comment added after it was displayed")
    remove(9105,"delete","delete")
    remove(9107,"move","delete",children_to="0")
    verify("complete")
