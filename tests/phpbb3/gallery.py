"""Public, restricted, personal and pending albums from an offline snapshot."""
import struct
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
