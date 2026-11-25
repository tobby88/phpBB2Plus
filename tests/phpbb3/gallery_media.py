"""Exercise the thumbnail and original as separate resources of the same gallery picture."""
from base64 import b64decode
from html import unescape
from pathlib import Path
import re
import struct
import time
import zlib
from urllib.parse import urljoin, urlsplit

from gallery import IMPORTED_THUMB, ORIGINAL, png, submit
from http_board import FormFields, Page, admin_url, submit_form


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


def settings_url(admin):
    entry = admin_url(admin)
    menu = admin.fetch(entry + "&i=acp_board&mode=settings")
    link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Fotoalbum-Uploads</span>', menu)
    if not link:
        raise RuntimeError("The ACP has no gallery upload policy")
    return urljoin(entry, unescape(link[1]))


def resize(admin, owner, root, check):
    url = settings_url(admin)
    form_id = "album-media-settings"
    page = admin.fetch(url)
    data = FormFields(page, form_id).values
    fields = ("gd_version", "thumbnail_size", "resize_pic", "resize_width", "resize_height", "resize_quality", "max_width", "max_height", "max_file_size")
    original = {key: data[key] for key in fields}
    change = dict(gd_version="2", resize_pic="0", max_file_size="2048", max_width="32", max_height="32",
                  resize_width="20", resize_height="18", resize_quality="70")
    result = submit_form(admin, url, page, form_id, dict(change, resize_quality="101"))
    if "Prüfe den Wert für" not in result or FormFields(admin.fetch(url), form_id).values["max_file_size"] != original["max_file_size"]:
        raise RuntimeError("The ACP stored an out-of-range image processing option")
    submit_form(admin, url, page, form_id, change)
    folder = "app.php/album/category/" + root
    form = folder + "/upload"
    upload(owner, form, "Too large", [("large.png", "image/png", ORIGINAL)], status=422)
    submit_form(admin, url, admin.fetch(url), form_id, {"resize_pic":"1"})
    if "vor dem Speichern verkleinert" not in owner.fetch(form):
        raise RuntimeError("The uploader did not disclose server-side resizing")
    image = png(40, 24, b"\x01\x02\x03\x00")
    # The supplied metadata alone exceeds the output-byte limit. Resize before applying that limit.
    extra = b"tEXtFixture\0" + b"x" * 2200
    oversized = image[:-12] + struct.pack(">I", len(extra) - 4) + extra + struct.pack(">I", zlib.crc32(extra)) + image[-12:]
    batch = [("large.png", "image/png", oversized)]
    _, key = upload(owner, form, "PNG resized", batch)
    upload(owner, form, "PNG resized", batch, retry=key)
    jpeg = (Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images/cellpic2.jpg").read_bytes()
    tiff = b"II" + struct.pack("<HIHHHIHI", 42, 8, 1, 0x112, 3, 1, 6, 0) + b"\0\0"
    exif = b"Exif\0\0" + tiff
    oriented = jpeg[:2] + b"\xff\xe1" + struct.pack(">H", len(exif) + 2) + exif + jpeg[2:]
    upload(owner, form, "Portrait resized", [("portrait.jpg", "image/jpeg", oriented)])
    small = png(10, 8, b"\x33\x44\x55")
    upload(owner, form, "Small original", [("small.png", "image/png", small)])
    if (admin.fetch(picture(owner, folder, "Small original") + "/full", raw=True) != small or
            admin.fetch("app.php/album/picture/100/full", raw=True) != ORIGINAL or owner.fetch(folder).count("PNG resized") != 1):
        raise RuntimeError("Resizing changed a small upload, a converted original or the repeat receipt")
    check("resize_rendered")
    for title in ("PNG resized", "Portrait resized", "Small original"):
        submit(owner, picture(owner, folder, title) + "/manage/delete", "album-manage", {})
    submit_form(admin, url, admin.fetch(url), form_id, original)
