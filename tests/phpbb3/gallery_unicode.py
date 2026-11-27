"""Preserve ordinary gallery text and literal references through import, search and editing."""
from html import escape
import re
import time
from urllib.parse import urljoin

from gallery import ORIGINAL
from gallery_navigation import AlbumPage
from http_board import FormFields, submit_form


def browse(admin, guest, check):
    album = "app.php/album"
    picture = album + "/picture/105"
    title = "Foto zum Kontowechsel – GRÜN😀au & &#128512;"
    description = "Am Ufer 𠜎 mit &amp; und &#x1F600;"
    comment = "Kommentar zum Kontowechsel – Grüße 😀 und &#128512;"

    def matches(mode, query):
        html = guest.fetch(album + "/search", {"mode": mode, "search": query})
        block = re.search(r'<section\b[^>]* id="album-search-results"[^>]*>(.*?)</section>', html, re.S)
        if not block:
            raise RuntimeError("Missing native album search results")
        return re.search(r'href="[^"]*/album/picture/105(?:\?[^"]*)?"', block[1]) is not None

    check("converted")
    try:
        html = guest.fetch(picture)
        if not all(escape(value) in html for value in (title, description, comment, "SeeFOTO𠜎ern")):
            raise RuntimeError("The imported public photograph did not display its original texts")
        for method in ("pic_title", "username"):
            view = AlbumPage(guest.fetch(picture + f"?picture_sort={method}&picture_order=ASC"))
            following = AlbumPage(guest.fetch(view.neighbours["next"][1]))
            if following.neighbours["prev"][0] != 105:
                raise RuntimeError("Gallery navigation no longer compared the full Unicode sort value")
        for mode, query, expected in [
            ("name", "grün😀au", True), ("name", "GRÜN_au", True), ("name", "grün__au", False),
            ("name", "grün%au", True), ("name", "&#128512;", True), ("name", "GRÜN𠜎au", False),
            ("desc", "UFER 𠜎", True), ("desc", "&#x1F600;", True), ("desc", "😀", False),
            ("user", "seefoto𠜎ern", True), ("user", "SEEFOTO_ern", True), ("user", "SeeFOTO__ern", False),
        ]:
            if matches(mode, query) != expected:
                raise RuntimeError(f"The {mode} search returned the wrong result for {query!r}")
        edit = picture + "/manage/edit"
        form = admin.fetch(edit)
        values = FormFields(form, "album-manage").values
        if values["title"] != title or values["description"] != description:
            raise RuntimeError("The image editor changed original text before submission")
        revised = "Später 😀 mit &#x1F600; & 𠜎"
        submit_form(admin, edit, form, "album-manage", {"title": revised, "description": revised + "!"})
        if not matches("name", "SPÄTER 😀") or escape(revised + "!") not in guest.fetch(picture):
            raise RuntimeError("Editing a photograph lost stored Unicode or literal references")
        edit = picture + "/comment/96/edit"
        form = admin.fetch(edit)
        if FormFields(form, "album-comment-edit").values["body"] != comment:
            raise RuntimeError("The comment editor changed the original text")
        submit_form(admin, edit, form, "album-comment-edit", {"body": revised})
        new_comment = "Neu 🖼 und die Eingabe &#128444;"
        page = admin.fetch(picture)
        submit_form(admin, picture, page, "album-discuss", {"points": "0", "body": new_comment})
        html = guest.fetch(picture)
        if escape(new_comment) not in html or f">{escape(revised)}</div>" not in html or escape(comment) in html:
            raise RuntimeError("The submitted or edited comment was not preserved as entered")
        added = re.search(r'<article\b[^>]*id="comment-(\d+)"[^>]*>(?:(?!</article>).)*?' + re.escape(escape(new_comment)), html, re.S)
        if not added:
            raise RuntimeError("The new Unicode comment has no native record")
        delete = picture + "/comment/" + added[1] + "/delete"
        submit_form(admin, delete, admin.fetch(delete), "album-comment-edit", {})
        path = album + "/category/1/upload"
        fields = FormFields(admin.fetch(path), "album-upload")
        fields.values.update(title="", description="Datei 😀 & &#128512;", file_count="1")
        time.sleep(max(0, int(fields.values["creation_time"]) + 1.05 - time.time()))
        admin.fetch(urljoin(urljoin(admin.base, path), fields.action), fields.values, files=[("photos[]", "Archiv𠜎😀.png", "image/png", ORIGINAL)])
        uploaded = int(check("uploaded"))
        delete = album + f"/picture/{uploaded}/manage/delete"
        submit_form(admin, delete, admin.fetch(delete), "album-manage", {})
    finally:
        check("restore")
