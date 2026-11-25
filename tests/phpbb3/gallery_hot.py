"""Exercise the random picture, two rating channels and the original audience of each gallery."""
from html import unescape
import json
import re

from gallery import ORIGINAL
from http_board import Page, submit_form


def browse(administrator, member, applicant, guest, check, *, bookmarks=True):
    route = "app.php/album/hot-or-not"
    form = "album-hot-vote"

    def picture(actor, number, *, can_rate):
        page = actor.fetch(route)
        if (f'id="album-hot-title">Draw picture {number}</h3>' not in page or (form in Page(page).forms) != can_rate or
                not re.search(r'href="[^"]*/album/category/\d+', page) or "Birken &amp; Buchen" not in page):
            raise RuntimeError(f"Unexpected picture, metadata or voting permission for random picture {number}")
        return page

    def empty(actor):
        page = actor.fetch(route)
        if "plus-album-empty" not in page or "album-hot-title" in page or form in Page(page).forms:
            raise RuntimeError("The draw disclosed a photo outside the viewer's accessible and selected albums")

    def scores():
        values = json.loads(check("numbers"))
        return [(int(v["points"]), int(v["honour_points"])) for v in values["votes"]]

    def tally(page, expected):
        if not re.search(r'id="album-hot-result">\s*' + expected, page):
            raise RuntimeError("The random rating did not display the selected channel and positive votes")

    check("seed")
    try:
        if not re.search(r'<a[^>]* href="[^"]*/album/hot-or-not', guest.fetch("app.php/album")):
            raise RuntimeError("The album does not offer the random picture from its menu")
        if bookmarks:
            if "Draw picture 16000" not in guest.fetch("album_hotornot.php"):
                raise RuntimeError("The original random-picture address did not reach the native round")
            guest.fetch("album_hotornot.php", {"hon_rating": "2"}, expected_status=405)
        page = picture(member, 16000, can_rate=True)
        if "16000#album-comments" not in page and not re.search(r'/picture/16000\?[^"]*#album-comments">1</a>', page):
            raise RuntimeError("The random picture lost its comment counter or native link")
        if not re.search(r'id="album-hot-result">[^<]*[^\d<]</dd>', page) or re.search(r'id="album-hot-result">[^<]*\d', page):
            raise RuntimeError("The migrated -1 honour value counted as a rating")
        image = re.search(r'<img src="([^"]*/album/picture/16000/full[^"]*)"', picture(guest, 16000, can_rate=True))
        if not image:
            raise RuntimeError("The draw has no authorized full-size image")
        media = guest.fetch(unescape(image[1]), raw=True)
        if not media.startswith(b"\x89PNG") or media == ORIGINAL or json.loads(check("numbers"))["views"] != 21:
            raise RuntimeError("The draw bypassed the watermark or incremented the picture's view count")
        saved = submit_form(member, route, page, form, {"hon_rating": "4"})
        if 'role="status"' not in saved or form in Page(saved).forms or scores() != [(8, 4)]:
            raise RuntimeError("The registered user's separate rating or once-only confirmation failed")
        tally(saved, r"4[,.]0")
        submit_form(member, route, page, form, {"hon_rating": "7"}, status=422)
        for value in ("6", "8"):
            submit_form(guest, route, picture(guest, 16000, can_rate=True), form, {"hon_rating": value})
        if scores() != [(0, 8), (8, 4)]:
            raise RuntimeError("A guest's replacement ballot changed another reader's votes")
        tally(guest.fetch(route), r"6[,.]0")
        stale = picture(applicant, 16000, can_rate=True)
        check("restricted")
        for viewer in (guest, applicant):
            empty(viewer)
        picture(member, 16001, can_rate=True)
        submit_form(applicant, route, stale, form, {"hon_rating": "7"}, status=404)
        if scores() != [(0, 8), (8, 4)]:
            raise RuntimeError("A rating survived a category restriction introduced while the form was open")
        check("pending")
        empty(member)
        picture(administrator, 16002, can_rate=True)
        check("old_personal")
        empty(administrator)
        if "Draw picture 16003" not in member.fetch("app.php/album/picture/16003"):
            raise RuntimeError("A historical personal picture was lost from its owner's gallery")
        check("personal")
        picture(member, 16004, can_rate=True)
        check("all")
        if "album-honour" not in Page(applicant.fetch("app.php/album/picture/16000")).forms:
            raise RuntimeError("The historic unrestricted category setting '0' was treated as a category")
        check("guests_off")
        if "album-honour" in Page(guest.fetch("app.php/album/picture/16000")).forms:
            raise RuntimeError("Disabling guest ratings did not reach the ordinary picture")
        check("together")
        # Use the known album again so the common channel and its displayed average can be verified.
        check("public")
        common = picture(member, 16000, can_rate=True)
        tally(common, r"8[,.]0")
        tally(submit_form(member, route, common, form, {"hon_rating": "9"}), r"9[,.]0")
        if scores() != [(0, 8), (9, 4)]:
            raise RuntimeError("The shared channel overwrote the separate rating")
        check("locked")
        picture(applicant, 16000, can_rate=False)
        picture(administrator, 16000, can_rate=True)
        check("silent")
        silent = picture(administrator, 16000, can_rate=False)
        if "#album-comments" in silent or 'id="album-hot-result"' in silent:
            raise RuntimeError("Disabled ratings or comments still appear on the random picture")
    finally:
        check("cleanup")
