"""Exercise shared post/PM attachments with harmless synthetic media."""
from base64 import b64decode
import json

GIF = b64decode("R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7")
PNG = b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAKb7a34AAAAASUVORK5CYII=")


def source_media(legacy):
    directory = legacy / "files"
    (directory / "thumbs").mkdir(parents=True, exist_ok=True)
    for name, data in (("public.gif", GIF), ("thumbs/t_public.gif", GIF), ("letter.png", PNG), ("unlinked.gif", GIF)):
        (directory / name).write_bytes(data)


def verify_storage(web, encoded):
    rows = json.loads(encoded)
    uploaded = web / "files"
    expected = {(50, 0, 32), (50, 0, 40), (63, 1, 111), (63, 1, 112), (80, 0, 0)}
    associations = {(int(r["source_id"]), int(r["in_message"]), int(r["parent_id"])) for r in rows}
    if len(rows) != len(expected) or associations != expected:
        raise RuntimeError("Incorrect attachment ownership or shared association")
    if len({r["physical_filename"] for r in rows}) != len(rows):
        raise RuntimeError("Deleting one native attachment could remove another owner's file")
    for row in rows:
        source = int(row["source_id"])
        original = PNG if source == 63 else GIF
        file = uploaded / row["physical_filename"]
        if file.parent != uploaded or file.read_bytes() != original or int(row["filesize"]) != len(original):
            raise RuntimeError("Attachment bytes or size changed during transfer")
        if source == 50 and (uploaded / ("thumb_" + file.name)).read_bytes() != GIF:
            raise RuntimeError("A shared attachment lost its thumbnail")
        if bool(int(row["is_orphan"])) != (source == 80):
            raise RuntimeError("Unassociated source attachments must stay private")
    if sum(int(row["download_count"]) for row in rows) != 17:
        raise RuntimeError("The shared download counter was multiplied")
    for source, parent in ((50, 32), (63, 111), (80, 0)):
        if not any(int(r["attach_id"]) == source and int(r["parent_id"]) == parent for r in rows):
            raise RuntimeError("Historical attachment ID did not survive")


def downloads(member):
    for parent, content in (("viewtopic.php?t=1", GIF), ("ucp.php?i=pm&mode=view&p=111", PNG)):
        page = member.fetch(parent)
        ident = 50 if content == GIF else 63
        if f"file.php?id={ident}" not in page:
            raise RuntimeError(f"Missing native download on {parent}")
        if member.fetch(f"download/file.php?id={ident}", raw=True) != content:
            raise RuntimeError("The attachment download differs from the imported file")
