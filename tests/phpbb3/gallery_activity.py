"""Distinguish all pictures in an album from a member's uploads, ordinary ratings and comments."""
from html import unescape
import re
from urllib.parse import parse_qs, urlsplit

from gallery import category_grid
from http_board import FormFields, Page


def browse(administrator, member, applicant, guest, check, *, bookmarks=True):
    uid = int(check("seed"))
    path = f"app.php/album/member/{uid}"
    all_photos = path + "/pictures"
    activity = path + "/activity/"

    def records(html):
        grid = category_grid(html)
        rows = {}
        for item in re.findall(r"<li>(.*?)</li>", grid, re.S):
            link = re.search(r'<a href="[^"]*/album/picture/(\d+)(?:\?[^"]*)?">', item)
            if link:
                rows[int(link[1])] = item
        return rows

    def read(actor, route, query=""):
        page = actor.fetch(route + query)
        first = records(page)
        links = [unescape(link) for link in re.findall(r'href="([^"]*/album/member/[^"]+)"', page)]
        pages = {}
        for link in links:
            parts = urlsplit(link)
            offset = parse_qs(parts.query).get("start")
            if parts.path.endswith(route.removeprefix("app.php")) and offset and int(offset[0]):
                pages[int(offset[0])] = link
        rows = dict(first)
        for start in sorted(pages):
            additional = records(actor.fetch(pages[start]))
            if rows.keys() & additional.keys():
                raise RuntimeError("A member picture was repeated across gallery pages")
            rows.update(additional)
        if len(rows) > 4 and len(first) != 4:
            raise RuntimeError("The member gallery did not use rows_per_page times cols_per_page")
        return {pic: markup for pic, markup in rows.items() if 17000 <= pic <= 17012}, page

    try:
        for viewer in (guest, applicant, member, administrator):
            privileged = viewer in (member, administrator)
            all_expected = {17000,17001,17006} | ({17002,17012} if privileged else set()) | ({17005} if viewer is administrator else set())
            own_expected = {17001,17003,17004} | ({17002} if privileged else set()) | ({17005} if viewer is administrator else set())
            total, page = read(viewer, all_photos)
            uploads, _ = read(viewer, activity + "pic")
            if set(total) != all_expected or set(uploads) != own_expected:
                raise RuntimeError(f"Wrong scope for owners, uploaders, historic unassigned pictures or approvals ({list(total)}, {list(uploads)})")
            if ("/album/category/720" not in total[17000] or "#album-comments" not in total[17000] or
                    ((activity.removeprefix("app.php") + "pic") in page) != (viewer is not guest)):
                raise RuntimeError("Gallery links or photo category captions are missing from the combined view")
            if (("192.0.2.66" in total[17000]) != (viewer is administrator) or
                    (viewer is not applicant and ("/manage/delete" in total[17000]) != (viewer is administrator))):
                raise RuntimeError("The gallery exposed an upload address or another user's moderation link")
            rated, page = read(viewer, activity + "rating")
            if list(rated) != [17007,17009] + ([17012] if privileged else []) or not re.search(r'>4[,.]0(?:</dd>|<span class="plus-album-ranks")', rated[17007]):
                raise RuntimeError("Member ratings included another member's ballot, separate Hot or Not score or an inaccessible album")
            if FormFields(page, "album-sort").values.get("sort_method") != "rating" or FormFields(page, "album-sort").values.get("sort_order") != "ASC":
                raise RuntimeError("The member rating list lost its default order")
            commented, _ = read(viewer, activity + "comment")
            expected = [17011] + ([17012] if privileged else []) + [17010]
            if list(commented) != expected or '#album-comments">2</a>' not in commented[17010] or '#album-comments">1</a>' not in commented[17011]:
                raise RuntimeError("A member's comment list multiplied rows or displayed another author's comments")
        latest, _ = read(guest, activity + "comment", "?sort_method=new_comment&sort_order=DESC")
        if list(latest) != [17010,17011]:
            raise RuntimeError("The latest comment sort included other authors' later comments")
        if not records(guest.fetch(all_photos + "?start=1000000")):
            raise RuntimeError("The last page of the personal collection was not preserved")
        profile = f"memberlist.php?mode=viewprofile&u={uid}"
        if f"/album/member/{uid}" in guest.fetch(profile) or not all(x.removeprefix("app.php") in member.fetch(profile) for x in (all_photos,activity + "pic")):
            raise RuntimeError("The profile advertised private member details to guests or hid the registered member's gallery links")
        for location in (path, "app.php/album/category/720"):
            if all_photos.removeprefix("app.php") not in guest.fetch(location):
                raise RuntimeError("The enabled personal picture-view switch cannot be reached from the album")
        check("gallery_admin")
        try:
            admin_photos, _ = read(applicant, all_photos)
            if 17005 not in admin_photos or "192.0.2.66" in admin_photos[17000]:
                raise RuntimeError("Gallery administration alone did not respect the board administrator's address boundary")
        finally:
            check("revoke_admin")
        resolver = "app.php/album/legacy/member/1"
        if records(guest.fetch(resolver + "?mode=list&type=rating")) != records(guest.fetch(activity + "rating")):
            raise RuntimeError("The legacy gallery used native anonymous ID 1 for a real historical member")
        if 17000 not in read(member, all_photos)[0] or "Member listing 0" not in member.fetch(resolver + "?mode=all&cat_id=720&sort_method=pic_title&sort_order=ASC"):
            raise RuntimeError("The legacy personal category did not open its combined view")
        if "Activity category 721" not in guest.fetch(resolver + "?mode=list&cat_id=721") or "/album/category/721/upload" in guest.fetch(resolver + "?mode=list&cat_id=721"):
            raise RuntimeError("A non-root legacy category was not opened with its own rights")
        guest.fetch(resolver + "?mode=all&cat_id=723", expected_status=404)
        guest.fetch("app.php/album/member/1/activity/pic", expected_status=404)
        guest.fetch("app.php/album/member/12345678/activity/comment", expected_status=404)
        if bookmarks:
            for query, native in [("mode=all",all_photos),("mode=list&type=pic",activity+"pic"),("mode=list&type=rating",activity+"rating"),("mode=list&type=comment",activity+"comment")]:
                if set(records(guest.fetch("album.php?user_id=1&" + query))) != set(records(guest.fetch(native))):
                    raise RuntimeError("An original personal album or member activity bookmark reached different photos")
        check("toggle")
        if all_photos.removeprefix("app.php") in member.fetch(path) or all_photos.removeprefix("app.php") in member.fetch(profile):
            raise RuntimeError("The disabled combined-view link remained on the album or member profile")
        if "album-sort" not in Page(guest.fetch(all_photos)).forms:
            raise RuntimeError("Disabling the view switch broke a previously visible personal album bookmark")
        for kind in ("rating","comment"):
            guest.fetch(activity + kind, expected_status=404)
    finally:
        check("cleanup")
