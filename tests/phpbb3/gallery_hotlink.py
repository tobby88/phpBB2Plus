"""Send every request to the loopback board; referring website names are header values only."""
from urllib.error import HTTPError
from urllib.parse import urljoin, urlsplit
from urllib.request import Request

from gallery import ORIGINAL
from gallery_media import settings_url
from http_board import FormFields, submit_form


def browse(admin, member, applicant, guest, check):
    if urlsplit(guest.base).hostname != "127.0.0.1":
        raise RuntimeError("A synthetic loopback board is required")
    settings = settings_url(admin)
    form = "album-media-settings"

    def save(page, changes, *, valid=True):
        result = submit_form(admin, settings, page, form, changes)
        if ('class="successbox"' if valid else 'class="errorbox"') not in result or "PLUS_ALBUM_CFG_" in result:
            raise RuntimeError("Unexpected result saving album embedding settings")
        return result

    def referred(actor, host):
        client = actor.separate_client()
        # phpBB's session includes the user agent. Keep it when following a link from a different page.
        client.opener.addheaders.append(("Referer", host))
        return client

    def head(actor, path, expected):
        address = urljoin(guest.base, path)
        try:
            with actor.opener.open(Request(address, method="HEAD"), timeout=15) as res:
                cache = ", ".join(res.headers.get_all("Cache-Control", []))
                if res.read() or res.status != expected or "no-store" not in cache:
                    raise RuntimeError(f"The image HEAD request used an unexpected cache policy {cache!r}")
        except HTTPError as error:
            if error.code != expected:
                raise
            cache = ", ".join(error.headers.get_all("Cache-Control", []))
            if error.read() or "no-store" not in cache:
                raise RuntimeError(f"The denied image HEAD request used an unexpected cache policy {cache!r}")

    path = "app.php/album/picture/100/"
    initial = admin.fetch(settings)
    fields = FormFields(initial, form).values
    stored = ".club.example.invalid, https://www.gallery.example.invalid/show"
    if fields["hotlink_prevent"] != "0" or fields["hotlink_allowed"] != stored:
        raise RuntimeError("The referring-site policy was not copied from the source album")
    foreign = referred(guest, "https://magazine.example.invalid/story")
    check("seed")
    try:
        original = {size: guest.fetch(path + size, raw=True) for size in ("full", "medium", "small")}
        if foreign.fetch(path + "full", raw=True) != original["full"]:
            raise RuntimeError("The disabled filter blocked an ordinary external embed")
        save(initial, {"hotlink_prevent": "1", "hotlink_allowed": "https://"}, valid=False)
        if foreign.fetch(path + "small", raw=True) != original["small"]:
            raise RuntimeError("A partial ACP save applied an invalid policy")
        page = save(initial, {"hotlink_prevent": "1"})
        native = referred(guest, urljoin(guest.base, "app.php/album"))
        club = referred(guest, "https://members.club.example.invalid:8443/photos")
        gallery = referred(guest, "https://www.gallery.example.invalid/page")
        direct = guest.separate_client()
        direct.opener.addheaders.append(("Origin", guest.base.rstrip("/")))
        if direct.fetch(path + "small", raw=True) != original["small"]:
            raise RuntimeError("An ordinary direct read incorrectly required a referring page")
        for size, content in original.items():
            for reader in (guest, native, club, gallery):
                if reader.fetch(path + size, raw=True) != content:
                    raise RuntimeError("Direct access or an allowed embed changed the protected image")
            message = foreign.fetch(path + size, expected_status=403)
            if message not in ("This website cannot embed this album picture.", "Diese Website darf das Albumfoto nicht einbetten."):
                raise RuntimeError("The denied picture returned an unexpected message")
        for entry, size in (("album_pic.php", "full"), ("album_picm.php", "medium"), ("album_thumbnail.php", "small")):
            if club.fetch(entry + "?pic_id=100", raw=True) != original[size]:
                raise RuntimeError("An existing album media address lost the permitted picture")
            foreign.fetch(entry + "?pic_id=100", expected_status=403)
        head(native, path + "small", 200)
        head(foreign, path + "full", 403)
        if "Referring site fixture" not in foreign.fetch("app.php/album/picture/30101"):
            raise RuntimeError("Linking to the picture's regular album page stopped working")
        referred(guest, "https://gallery.example.invalid/story").fetch(path + "small", expected_status=403)
        for uid, size in ((101, "small"), (103, "full"), (104, "medium"), (30100, "full")):
            for reader in (guest, club, foreign):
                reader.fetch(f"app.php/album/picture/{uid}/{size}", expected_status=404)
        club_member = referred(member, "https://club.example.invalid/")
        for uid in (101, 103, 104):
            if not club_member.fetch(f"app.php/album/picture/{uid}/full", raw=True).startswith(b"\x89PNG"):
                raise RuntimeError("An approved member lost access to a group or personal image")
        referred(applicant, "https://club.example.invalid/").fetch("app.php/album/picture/101/full", expected_status=404)
        if referred(admin, "https://club.example.invalid/").fetch("app.php/album/picture/30100/full", raw=True) != ORIGINAL:
            raise RuntimeError("The filter changed the administrator's normal moderation view")
        referred(admin, "https://magazine.example.invalid/story").fetch(path + "full", expected_status=403)
        changed = "https://bücher.example.invalid/entry?a=1&view=small"
        page = save(page, {"hotlink_allowed": changed})
        if FormFields(page, form).values["hotlink_allowed"] != changed:
            raise RuntimeError("The ACP did not retain the submitted URL")
        referred(guest, "http://xn--bcher-kva.example.invalid/another-page").fetch(path + "full", raw=True)
        club.fetch(path + "small", expected_status=403)
        page = save(page, {"hotlink_allowed": ""})
        if native.fetch(path + "small", raw=True) != original["small"]:
            raise RuntimeError("An empty extra-site list blocked the board itself")
        referred(guest, "https://xn--bcher-kva.example.invalid/").fetch(path + "small", expected_status=403)
    finally:
        try:
            save(admin.fetch(settings), {"hotlink_prevent": fields["hotlink_prevent"], "hotlink_allowed": fields["hotlink_allowed"]})
        finally:
            check("cleanup")
