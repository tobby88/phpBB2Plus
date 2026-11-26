"""Exercise the legacy single-file ACP editor, private categories and protected uploads."""
import re
import time
from html import unescape
from urllib.parse import urljoin
from download_file_actions import english_file_panel
from gallery import ORIGINAL
from http_board import Browser, FormFields, Page, submit_form
from knowledgebase import acp_panel


def administration(owner, member, verify):
    verify("installed")
    verify("seed")
    panel = acp_panel(owner, "Download-Dateien verwalten")
    delegate, limited = english_file_panel(owner.base, "DownloadEditorDelegate")
    guest = Browser(owner.base)
    entry = "app.php/downloads/file/"
    name = "dl-file-editor"

    def post(actor, url, page, changes, uploads=None):
        if uploads is None:
            return submit_form(actor, url, page, name, changes)
        action = Page(page).forms[name][0]
        values = FormFields(page, name).values
        values.pop("original", None)
        values.pop("screenshot", None)
        values.update(changes)
        time.sleep(max(0, int(values["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(urljoin(url, action), values, files=uploads)

    listing = delegate.fetch(limited)
    link = re.search(r'<a[^>]+href="([^"]+action=create)"', listing)
    if not link or 'name="selected[]"' in listing or 'value="delete"' in listing:
        raise RuntimeError("The limited editor received the wrong catalogue controls")
    create = urljoin(limited, unescape(link[1]))
    first = delegate.fetch(create)
    if ('name="approved"' in first or 'Awaiting approval' not in first or 'value="22"' in first or 'value="23"' not in first or
            'pdf.gif' not in first or FormFields(first, name).values["author"] != ""):
        raise RuntimeError("Creation allowed approval or offered an incomplete destination list or icons")
    values = {"title": "Editor upload fixture", "description": "Archivierter Download 📗", "category": "23", "license": "31",
              "downloads": "5", "pinned": "1", "icon": "pdf.gif", "preview_link": "1", "field[81][]": "Blau"}
    payload = [("original", "Dokument.txt", "text/plain", b"Editor upload body\n"),
               ("screenshot", "Vorschau.png", "image/png", ORIGINAL)]
    bad = post(delegate, create, first, dict(values, category="22"), payload)
    if "Choose an available destination" not in bad:
        raise RuntimeError("Creation accepted a folder that cannot contain downloads")
    saved = post(delegate, create, first, values, payload)
    where = re.search(r'&amp;file=(\d+)', saved[saved.index('id="dl-file-editor"'):])
    if not where:
        raise RuntimeError("Saving the upload did not open its issued edit form")
    new = int(where[1])
    if f"file={new}" not in post(delegate, create, first, values, payload):
        raise RuntimeError("Replaying creation did not return to the same file")
    verify("created")
    delegate.fetch(entry + str(new), expected_status=404)
    guest.fetch(entry + str(new), expected_status=404)

    orphan = limited + "&action=edit&file=10501"
    old = delegate.fetch(orphan)
    choices = {"field[81][]": ["Blau", "Rot"], "icon": "", "category": "21"}
    if 'name="approved"' in old or "Club" not in old or 'id="dl-editor-mirrors"' in old:
        raise RuntimeError("The orphan data or approval controls were unexpected")
    repaired = post(delegate, orphan, old, choices)
    if 'class="successbox"' not in repaired:
        raise RuntimeError("The administrator could not repair an orphan by moving it to an available destination")
    if not guest.fetch(entry + "10501/get/0", raw=True) == b"Club fixture":
        raise RuntimeError("The repaired file lost its protected original")
    correction = post(delegate, orphan, repaired, dict(choices, set_count="1", downloads="12"))
    if "download count has changed" not in correction:
        raise RuntimeError("The counter correction discarded an intervening download")
    fresh = post(delegate, orphan, repaired, dict(choices, summary="An ordinary metadata edit", downloads="12"))
    if 'class="successbox"' not in fresh:
        raise RuntimeError("A normal edit unexpectedly required an unchanged download count")
    verify("retained")
    post(delegate, orphan, fresh, dict(choices, set_count="1", downloads="4294967295"))
    guest.fetch(entry + "10501/get/0")
    if "4294967295" != FormFields(delegate.fetch(orphan), name).values["downloads"]:
        raise RuntimeError("An intentional correction from a current form did not update the counter")
    verify("revoke")
    if name in Page(delegate.fetch(orphan)).forms:
        raise RuntimeError("The new approval-only grant could still open the file editor")

    url = panel + f"&action=edit&file={new}"
    admin = owner.fetch(url)
    if 'name="approved"' not in admin or "Vorschaubild als Link" not in admin:
        raise RuntimeError("The German administrator could not control the display and publication")
    mirrors = re.search(r'id="dl-editor-mirrors" href="([^"]+)"', admin)
    if not mirrors or "dl-mirror-list" not in Page(owner.fetch(urljoin(url, unescape(mirrors[1])))).forms:
        raise RuntimeError("The native editor did not link to mirror management for this file")
    public = {"category": "21", "approved": "1", "icon": "pdf.gif", "field[81][]": ["Blau"]}
    post(owner, url, admin, public)
    detail = guest.fetch(entry + str(new))
    icon = re.search(r'<img src="([^"]*file-icons/pdf\.gif)"', detail)
    if not icon or 'class="postimage"' in detail or "/preview" not in detail or "pdf.gif" not in guest.fetch("app.php/downloads/category/21"):
        raise RuntimeError("The approved upload lost its imported file icon or preview-as-link setting")
    if not guest.fetch(urljoin(owner.base + entry + str(new), unescape(icon[1])), raw=True).startswith(b"GIF8"):
        raise RuntimeError("The displayed file icon did not resolve to the bundled paFileDB image")
    delivery = entry + f"{new}/get/0"
    if submit_form(member, delivery, member.fetch(delivery), "dl-license", {"accept": "1"}) != "Editor upload body\n":
        raise RuntimeError("The ACP upload did not pass through protected, licensed delivery")
    stale = post(owner, url, admin, dict(public, summary="Stale"))
    if "errorbox" not in stale or 'class="successbox"' in stale:
        raise RuntimeError("An older administrative form overwrote a newer file")
    replacement = dict(public, icon="zip.gif", url="https://example.invalid/replacement.txt", remove_preview="1")
    if 'class="successbox"' not in post(owner, url, owner.fetch(url), replacement):
        raise RuntimeError("The local original and preview could not be replaced")
    changed = guest.fetch(entry + str(new))
    if "zip.gif" not in changed or "/preview" in changed or "<dt>Größe</dt><dd>Unbekannt</dd>" not in changed:
        raise RuntimeError("Replacing the local media left the old presentation active")
    remove = panel + f"&action=delete&ids=10501,{new}"
    submit_form(owner, remove, owner.fetch(remove), "dl-files-confirm", {"confirm": "1"})
    verify("finished")
