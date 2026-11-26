"""Save album and Special Processing settings through the existing ACP, then exercise the resulting uploads and images."""
import json
from html.parser import HTMLParser
import struct
import time
from urllib.error import HTTPError
from urllib.parse import parse_qsl, urlencode, urljoin, urlsplit, urlunsplit

from gallery import ORIGINAL
from gallery_media import settings_url
from http_board import FormFields, Page, submit_form


def browse(admin, moderator, member, guest, check):
    form_id = "album-media-settings"
    pic = "app.php/album/picture/100"
    form = "app.php/album/category/930/upload"
    settings = settings_url(admin)

    def state():
        return json.loads(check("state"))

    def save(page, fields, *, success=True):
        reply = submit_form(admin, settings, page, form_id, fields)
        indicator = 'class="successbox"' if success else 'class="errorbox"'
        if indicator not in reply:
            raise RuntimeError("Unexpected result saving media settings")
        return reply

    def upload(actor, name, description, status):
        page = actor.fetch(form)
        action, fields = Page(page).forms["album-upload"]
        fields.update(title=name, description=description, file_count="1")
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(urljoin(urljoin(actor.base, form), action), fields,
                           files=[("photos[]", "frame.png", "image/png", ORIGINAL)], expected_status=status)

    class Picker(HTMLParser):
        def handle_starttag(self, tag, attrs):
            attrs = dict(attrs)
            if tag == "input" and attrs.get("id") == "album-files":
                self.types = set(attrs["accept"].split(","))

    def dimensions(raw):
        if not raw.startswith(b"\x89PNG\r\n\x1a\n"):
            raise RuntimeError("Expected a gallery PNG response")
        return struct.unpack(">II", raw[16:24])

    check("seed")
    try:
        first = admin.fetch(settings)
        controls = FormFields(first, form_id).values
        expected = {"max_pics","user_pics_limit","mod_pics_limit","desc_length","jpg_allowed","png_allowed","gif_allowed","thumbnail_quality",
                    "thumbnail_cache","midthumb_use","midthumb_width","midthumb_height","midthumb_cache","use_watermark","wut_users","disp_watermark_at"}
        if not expected.issubset(controls) or controls["midthumb_width"] == "927" or "PLUS_ALBUM_CFG_" in first:
            raise RuntimeError("Missing, untranslated or incorrectly scoped ACP media controls")
        parts = urlsplit(settings)
        entry = urlunsplit(parts._replace(query=urlencode([(k,v) for k,v in parse_qsl(parts.query) if k != 'sid'])))
        for actor in (guest, member):
            try:
                with actor.opener.open(entry, timeout=30) as response:
                    if form_id in Page(response.read().decode("utf-8")).forms:
                        raise RuntimeError("A non-administrator could open the upload policy")
            except HTTPError as error:
                if error.code not in (401,403):
                    raise
        changed = dict(max_pics="1", user_pics_limit="0", mod_pics_limit="-1", desc_length="2", jpg_allowed="0", png_allowed="1",
                       gif_allowed="0", max_width="800", max_height="600", max_file_size="128000", gd_version="2", thumbnail_size="7",
                       thumbnail_quality="63", thumbnail_cache="0", midthumb_use="1", midthumb_width="19", midthumb_height="12",
                       midthumb_cache="0", use_watermark="0", wut_users="0", disp_watermark_at="1")
        save(first, dict(changed, midthumb_height="0"), success=False)
        if FormFields(admin.fetch(settings), form_id).values["max_pics"] != controls["max_pics"]:
            raise RuntimeError("The ACP partially saved an invalid mixed-scope form")
        page = save(first, changed)
        save(first, {"desc_length":"3"}, success=False)
        actual = state()["settings"]
        if actual["album"]["midthumb_width"] != "927" or actual["sp"]["midthumb_width"] != "19" or actual["album"]["desc_length"] != "2":
            raise RuntimeError("A stale form replaced a newer value or crossed the historic table boundary")
        picker = Picker()
        public_form = moderator.fetch(form)
        picker.feed(public_form)
        if ({val for val in picker.types if val.startswith("image/")} != {"image/png"} or 'maxlength="2" rows="3"' not in public_form or
                'id="album-file-policy"' not in public_form or '800 × 600' not in public_form or '128000' not in public_form):
            raise RuntimeError("The browser did not receive the allowed formats and description length")
        upload(member, "Ordinary quota", "", 422)
        upload(moderator, "Description over limit", "Ölü", 422)
        upload(moderator, "Allowed PNG", "Öl", 200)
        upload(admin, "Shared quota", "", 422)
        if [(r["title"],r["description"]) for r in state()["pictures"]] != [("Allowed PNG","Öl")]:
            raise RuntimeError("A format, Unicode description or total, moderator or member quota was incorrectly enforced")
        before = state()["variants"]
        if (dimensions(admin.fetch(pic + "/small", raw=True)) != (7,4) or dimensions(guest.fetch(pic + "/medium", raw=True)) != (19,11) or
                guest.fetch(pic + "/full", raw=True) != ORIGINAL or state()["variants"] != before):
            raise RuntimeError("Uncached views changed an original or ignored their own dimensions")
        check("hide_mark")
        try:
            save(admin.fetch(settings), {"use_watermark":"1","desc_length":"3"}, success=False)
            check("partial_mark")
            save(admin.fetch(settings), {"use_watermark":"1","desc_length":"3"}, success=False)
            if state()["settings"]["album"]["desc_length"] != "2":
                raise RuntimeError("Attempting to enable an unavailable watermark partially changed the upload settings")
        finally:
            check("return_mark")
        page = save(admin.fetch(settings), {"use_watermark":"1","thumbnail_cache":"1","midthumb_cache":"1","png_allowed":"0"})
        if "album-upload" in Page(moderator.fetch(form)).forms:
            raise RuntimeError("Empty format policy displayed an unusable file picker")
        marked_left = guest.fetch(pic + "/full", raw=True)
        if marked_left == ORIGINAL or admin.fetch(pic + "/full", raw=True) != ORIGINAL:
            raise RuntimeError("A gallery image did not follow the viewer's watermark policy")
        guest.fetch(pic + "/small", raw=True)
        medium = guest.fetch(pic + "/medium", raw=True)
        after = state()["variants"]
        if after <= before or guest.fetch(pic + "/medium", raw=True) != medium or state()["variants"] != after:
            raise RuntimeError("Cached views were missing or regenerated under a different name")
        save(page, {"wut_users":"1","disp_watermark_at":"3","midthumb_use":"0","jpg_allowed":"1","max_pics":"-1"})
        marked_right = guest.fetch(pic + "/full", raw=True)
        staff = admin.fetch(pic + "/medium", raw=True)
        if marked_left == marked_right or marked_right == ORIGINAL or staff == ORIGINAL or dimensions(staff) != (40,24) or staff != admin.fetch(pic + "/full", raw=True):
            raise RuntimeError("Watermark position, staff rule or full-size fallback was ignored")
        # PNG bytes submitted after the picker switched to JPEG still go through the server-side format policy.
        upload(admin, "PNG disabled", "", 422)
        if len(state()["pictures"]) != 1:
            raise RuntimeError("The upload form admitted a disabled picture format")
    finally:
        for row in state()["pictures"]:
            path = f'app.php/album/picture/{row["pic_id"]}/manage/delete'
            submit_form(admin, path, admin.fetch(path), "album-manage", {})
        check("cleanup")
