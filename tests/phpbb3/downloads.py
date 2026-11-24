"""Follow paFileDB category permissions, licence forms and protected downloads."""
import os
import re
import time
from urllib.parse import urljoin
from http_board import Browser, Page, login
from gallery import ORIGINAL

PAYLOAD = "Fixture-Datei mit Grüßen 🎨\n".encode()
MIRROR = b"Separate mirror fixture\n"


def source_media(legacy):
    folder = legacy / "pafiledb/uploads"
    folder.mkdir(parents=True, exist_ok=True)
    for name, data in (("library-50.txt", PAYLOAD), ("mirror-50.txt", MIRROR), ("club-51.txt", b"Club fixture"), ("draft-52.txt", b"Draft fixture")):
        (folder / name).write_bytes(data)
    previews = legacy / "pafiledb/images/screenshots"
    previews.mkdir(parents=True, exist_ok=True)
    (previews / "vorschau.png").write_bytes(ORIGINAL)


def browse(base, founder, member):
    guest = Browser(base)
    pending = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    root = "app.php/downloads"
    if "/downloads" not in guest.fetch("app.php/portal"):
        raise RuntimeError("The portal does not link to the download library")
    for actor, permitted in ((guest, False), (pending, False), (member, True), (founder, True)):
        index = actor.fetch(root)
        search = actor.fetch(root + "?q=Fixture")
        for title in ("Clubbibliothek", "Privatfreigabe"):
            if (title in index) != permitted or (title in search) != permitted:
                raise RuntimeError("Download listings or search ignored inherited or personal permissions")
        if "Ungeprüfter Entwurf" in index or "Ungeprüfter Entwurf" in search:
            raise RuntimeError("An unapproved download appeared in the public listing")
        if not permitted:
            for suffix in ("/category/23", "/file/51", "/file/51/get/0", "/file/51/get/9", "/file/54", "/file/52"):
                actor.fetch(root + suffix, expected_status=404)
    if member.fetch(root + "/file/51/get/0", raw=True) != b"Club fixture" or "Ungeprüfter Entwurf" not in member.fetch(root + "/file/52"):
        raise RuntimeError("Approved group access or access to the owner's draft was lost")
    detail = guest.fetch(root + "/file/50")
    if "Blau, Rot" not in guest.fetch("dload.php?action=file%3Ffile_id%3D50") or "Beispiellizenz" not in guest.fetch("dload.php?action=download&file_id=50&mirror_id=8"):
        raise RuntimeError("Historical file and mirror links did not reach the converted item")
    if any(text not in detail for text in ("Zeichen &amp; Farben", "Blau, Rot", "Testkommentar", "HistoricMember", "Lokale Kopie")) or not re.search(r"<strong[^>]*>Beispiel</strong>", detail):
        raise RuntimeError("Download details lost a custom field, comment, mirror, or native BBCode")
    if guest.fetch(root + "/file/50/preview", raw=True) != ORIGINAL:
        raise RuntimeError("The protected download screenshot differs from the source")
    guest.fetch(root + "/file/50/get/9", expected_status=404)
    guest.fetch(root + "/file/53/get/0", expected_status=404)
    if "Originaldatei" not in guest.fetch(root + "/file/53"):
        raise RuntimeError("Missing files should retain their metadata and explain their status")
    for mirror, expected in ((0, PAYLOAD), (8, MIRROR)):
        path = root + f"/file/50/get/{mirror}"
        license_page = member.fetch(path)
        if "Beispiellizenz" not in license_page or "fiktiven Test" not in license_page:
            raise RuntimeError("Missing download terms")
        action, fields = Page(license_page).forms["dl-license"]
        fields["accept"] = "1"
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        if member.fetch(urljoin(urljoin(base, path), action), fields, raw=True) != expected:
            raise RuntimeError("A licensed download or mirror returned different bytes")
