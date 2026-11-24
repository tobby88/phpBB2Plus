"""Public, restricted, personal and pending albums from an offline snapshot."""
import struct
import json
import time
from urllib.parse import urljoin
import zlib


def png(width, height, colour):
    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress((b"\0" + colour * width) * height)) + chunk(b"IEND", b""))


ORIGINAL = png(40, 24, b"\x17\x84\x66")


def source_media(legacy):
    uploads = legacy / "album_mod/upload"
    uploads.mkdir(parents=True, exist_ok=True)
    (uploads / "fixture.png").write_bytes(ORIGINAL)
    (uploads.parent / "mark.png").write_bytes(png(8, 6, b"\xee\x20\x10"))


def browse(base, moderator, member):
    from http_board import Browser
    guest = Browser(base)
    album = "app.php/album"
    if "Test category" not in guest.fetch(album) or "/album" not in guest.fetch("app.php/portal"):
        raise RuntimeError("Album root or portal navigation missing")
    public = guest.fetch(album + "/category/1")
    private = member.fetch(album + "/category/1")
    if ("Öffentliche Fotoprobe" not in public or "Clubfotografie" in public or "Freigabeprobe" in public
            or "Clubfotografie" not in private or "Freigabeprobe" not in moderator.fetch(album + "/category/1")):
        raise RuntimeError("Incorrect category or picture visibility")
    if "Clubfotoprobe" not in member.fetch(album + "/category/8") or "Verschachtelte Fotoprobe" not in member.fetch(album + "/category/9"):
        raise RuntimeError("A native group lost access to the nested album")
    if "Persönliche Fotoprobe" not in member.fetch(album + "/picture/103"):
        raise RuntimeError("The remapped account cannot open its personal album")
    for hidden in ("/category/9", "/picture/101", "/picture/101/small", "/picture/102/medium", "/picture/103/full"):
        guest.fetch(album + hidden, expected_status=404)
    member.fetch(album + "/picture/102/full", expected_status=404)
    details = guest.fetch(album + "/picture/100")
    if ("Historischer Bildkommentar" not in details or "7,0 von 10" not in details or "EditorFixture" in details
            or "EditorFixture" not in member.fetch(album + "/picture/100")):
        raise RuntimeError("Album comments, retained byline or native rating display changed")
    media = album + "/picture/100/"
    if moderator.fetch(media + "full", raw=True) != ORIGINAL:
        raise RuntimeError("The staff full-size original differs from the source")
    protected = guest.fetch(media + "full", raw=True)
    if protected == ORIGINAL or protected[:8] != ORIGINAL[:8]:
        raise RuntimeError("The snapshot's public watermark policy was lost")
    for size, dimensions in (("small", (16, 10)), ("medium", (30, 18))):
        data = guest.fetch(media + size, raw=True)
        if data[:8] != ORIGINAL[:8] or struct.unpack(">II", data[16:24]) != dimensions or guest.fetch(media + size, raw=True) != data:
            raise RuntimeError("Incorrect gallery preview dimensions or cached content")
    uploads(base, guest, moderator, member)


def uploads(base, guest, moderator, member):
    from http_board import Page
    form = "app.php/album/category/1/upload"
    guest.fetch(form, expected_status=404)

    def fields():
        action, hidden = Page(member.fetch(form)).forms["album-upload"]
        time.sleep(max(0, int(hidden["creation_time"]) + 1.05 - time.time()))
        return urljoin(urljoin(base, form), action), dict(hidden, description="Zwölf Grüße", file_count="2")

    action, params = fields()
    pictures = [("photos[]", name + ".png", "image/png", ORIGINAL) for name in ("Mehrfachprobe A", "Mehrfachprobe B")]
    for _ in range(2):
        response = json.loads(member.fetch(action, params, ajax=True, files=pictures))
        if "awaiting approval" not in response.get("message", "") or "/album/category/1" not in response.get("location", ""):
            raise RuntimeError("An approved-only album must acknowledge a pending multi-image upload")
    category = "app.php/album/category/1"
    public, staff = guest.fetch(category), moderator.fetch(category)
    for name in ("Mehrfachprobe A", "Mehrfachprobe B"):
        if name in public or staff.count(name) != 1:
            raise RuntimeError("Retried album publication changed data or visibility")
    action, params = fields()
    response = member.fetch(action, params, expected_status=422, files=pictures)
    if "remaining album quota" not in response:
        raise RuntimeError("The legacy per-user quota must apply to the entire batch")
    action, hidden = Page(moderator.fetch(form)).forms["album-upload"]
    hidden.update(title="Einzelbild ohne JavaScript", description="Grüße")
    time.sleep(max(0, int(hidden["creation_time"]) + 1.05 - time.time()))
    if "Freigabe" not in moderator.fetch(urljoin(urljoin(base, form), action), hidden, files=pictures[:1]):
        raise RuntimeError("The ordinary multipart form must work without JavaScript")
