"""Clear the gallery cache through the ACP and regenerate previews from protected source files."""
from html import unescape
import json
import os
import re
from urllib.parse import urljoin

from http_board import Page, admin_url, submit_form


def browse(admin, delegate, guest, check, permissions):
    form = "album-cache-clear"
    media = "app.php/album/picture/100/"

    def module(entry, page):
        found = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>(?:Album-Vorschaucache leeren|Clear album preview cache)</span>', page)
        if not found:
            title = re.search(r"<h1[^>]*>([^<]+)</h1>", page)
            raise RuntimeError("The album cache module is missing from " + (unescape(title[1]) if title else repr(list(Page(page).forms))))
        return urljoin(entry, unescape(found[1]))

    def state():
        return json.loads(check("state"))

    check("seed")
    granted = False
    try:
        images = [guest.fetch(media + size, raw=True) for size in ("small", "medium")]
        before = state()
        if before["variants"].count(before["generation"]) < 2:
            raise RuntimeError("The disposable gallery did not cache two rendered sizes")
        entry = admin_url(admin)
        address = module(entry, admin.fetch(entry + "&i=acp_board&mode=settings"))
        if form not in Page(admin.fetch(address)).forms or state() != before:
            raise RuntimeError("Opening the ACP cache confirmation changed a stored variant")

        permissions("preview_cache_delegate")
        granted = True
        entry = admin_url(delegate)
        login = delegate.fetch(entry)
        credentials = Page(login).forms.get("login")
        if credentials:
            suffix = credentials[1]["credential"]
            submit_form(delegate, entry, login, "login", {"login": "1", "username": "DepartingFixture",
                        "password_" + suffix: os.environ["PLUS_TEST_MODERN_PASSWORD"]})
            entry = admin_url(delegate)
        menu = delegate.fetch(entry)
        narrow = module(entry, menu)
        if "Fotokategorien</span>" in menu or "Fotoalbum-Uploads</span>" in menu:
            raise RuntimeError("The cache-only administrator received another album administration module")
        review = delegate.fetch(narrow)
        permissions("preview_cache_revoke")
        granted = False
        submit_form(delegate, narrow, review, form, {"confirm": 1}, 403)
        if state() != before:
            raise RuntimeError("A confirmation from before revocation cleared the cache")
        permissions("preview_cache_delegate")
        granted = True
        submit_form(delegate, narrow, delegate.fetch(narrow), form, {"confirm": 1})
        changed = state()
        if (changed["generation"] <= before["generation"] or not changed["due"] or changed["files"] != before["files"]
                or changed["variants"] != before["variants"] or changed["actions"] != before["actions"] + 1):
            raise RuntimeError("Clearing failed to retire the old cache or changed a source image")
        check("cron")
        if state() != changed:
            raise RuntimeError("A preview was removed while a response could still be reading it")
        if [guest.fetch(media + size, raw=True) for size in ("small", "medium")] != images:
            raise RuntimeError("Regenerated previews differ from their uploaded and watermarked sources")
        rebuilt = state()
        version = changed["generation"]
        if rebuilt["variants"].count(version) < 2 or any(n not in rebuilt["variants"] for n in before["variants"]):
            raise RuntimeError("New requests reused a retired cached image")
        check("expire")
        collected = state()
        if (collected["due"] or set(collected["variants"]) != {version} or collected["files"] != before["files"]
                or guest.fetch(media + "medium", raw=True) != images[1]):
            raise RuntimeError("Scheduled cache cleanup removed a source file or a current variant")
    finally:
        if granted:
            permissions("preview_cache_revoke")
        check("cleanup")
