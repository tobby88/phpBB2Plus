"""Exercise the thumbnail and original as separate resources of the same gallery picture."""
from base64 import b64decode
from html import unescape
import re
import struct
import time
from urllib.parse import urljoin, urlsplit

from gallery import IMPORTED_THUMB, ORIGINAL, png, submit
from http_board import Page


def upload(actor, form, title, images, previews=(), status=200, retry=None):
    url, fields = retry or Page(actor.fetch(form)).forms["album-upload"]
    fields = dict(fields, title=title, file_count=str(len(images)))
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    files = [(field, name, mime, data) for field, batch in (("photos[]", images), ("previews[]", previews))
             for name, mime, data in batch]
    page = actor.fetch(urljoin(urljoin(actor.base, form), url), fields, files=files, expected_status=status)
    return page, (url, fields)


def picture(actor, folder, title):
    link = re.search(r'<a href="([^"]*/album/picture/\d+[^"]*)"><img [^>]*><strong>' + re.escape(title) + '</strong>', actor.fetch(folder))
    if not link:
        raise RuntimeError("The published item is absent from the visible album")
    return urlsplit(unescape(link[1])).path


def manual(admin, member, guest, root, check):
    path = "app.php/album/picture/100"
    if admin.fetch(path + "/small", raw=True) != IMPORTED_THUMB or admin.fetch(path + "/full", raw=True) != ORIGINAL:
        raise RuntimeError("A migrated thumbnail replaced its original or lost its supplied content")
    watermarked = guest.fetch(path + "/small", raw=True)
    if watermarked == IMPORTED_THUMB or struct.unpack(">II", watermarked[16:24]) != (16, 10):
        raise RuntimeError("A guest thumbnail ignored the imported watermark settings")
    guest.fetch("app.php/album/picture/101/small", expected_status=404)
    if not admin.fetch("app.php/album/picture/101/small", raw=True).startswith(ORIGINAL[:8]):
        raise RuntimeError("An unavailable thumbnail could not be generated from its original")
    folder = "app.php/album/category/" + root
    form = folder + "/upload"
    original = [("book.png", "image/png", ORIGINAL)]
    preview = [("cover.png", "image/png", png(12, 9, b"\xea\x71\x23"))]
    check("manual")
    if 'name="previews[]"' not in member.fetch(form):
        raise RuntimeError("The legacy manual mode has no thumbnail picker")
    upload(member, form, "Fehlende Vorschau", original, status=422)
    upload(member, form, "Zu große Vorschau", original, original, 422)
    gif = b64decode("R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==")
    upload(member, form, "Falsches Format", original, [("sample.gif", "image/gif", gif)], 422)
    _, receipt = upload(member, form, "Manuelle Vorschau", original, preview)
    upload(member, form, "Manuelle Vorschau", original, preview, retry=receipt)
    upload(member, form, "Manuelle Vorschau", original, [("cover.png", "image/png", IMPORTED_THUMB)], 422, receipt)
    image = picture(member, folder, "Manuelle Vorschau")
    if (member.fetch(folder).count("Manuelle Vorschau") != 1 or admin.fetch(image + "/small", raw=True) != preview[0][2] or
            guest.fetch(image + "/small", raw=True) == preview[0][2] or admin.fetch(image + "/full", raw=True) != ORIGINAL or
            struct.unpack(">II", admin.fetch(image + "/medium", raw=True)[16:24]) != (30, 18)):
        raise RuntimeError("The manual crop, watermark, original, medium image or repeat receipt changed")
    check("automatic")
    if 'name="previews[]"' in member.fetch(form) or admin.fetch(image + "/small", raw=True) != preview[0][2]:
        raise RuntimeError("Switching back to automatic thumbnails lost an existing manual crop")
    submit(member, image + "/manage/delete", "album-manage", {})
    guest.fetch(image + "/small", expected_status=404)
    check("preview_cleanup")
