"""Exercise the native grid and rating settings through both ACP forms and the public gallery."""
from html import unescape
import json
import re
from urllib.parse import urljoin

from gallery import category_grid
from gallery_navigation import AlbumPage
from http_board import FormFields, Page, admin_url, submit_form


def browse(admin, member, applicant, guest, check):
    root = admin_url(admin)
    menu = admin.fetch(root + "&i=acp_board&mode=settings")
    modules = {}
    for mode, title in (("display", "Anzeige im Fotoalbum"), ("ratings", "Fotobewertungen und Kommentare")):
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>' + title + r'</span>', menu)
        if not link:
            raise RuntimeError("Missing native gallery ACP module " + mode)
        modules[mode] = urljoin(root, unescape(link[1]))

    def save(mode, fields, *, page=None, valid=True):
        url = modules[mode]
        reply = submit_form(admin, url, page or admin.fetch(url), f"album-{mode}-settings", fields)
        if ('class="successbox"' if valid else 'class="errorbox"') not in reply or "PLUS_ALBUM_CFG_" in reply:
            raise RuntimeError("Unexpected or untranslated gallery ACP result in " + mode)
        return reply

    def state():
        return json.loads(check("state"))

    def votes():
        return [(int(v["points"]), int(v["honour_points"])) for v in state()["votes"]]

    def grid_and_sort():
        grid = dict(rows_per_page="1", cols_per_page="2", sort_order="ASC",
                    img_rows="1", img_cols="1", disp_late="1", disp_high="0", disp_rand="0", fullpic_popup="0")
        initial = admin.fetch(modules["display"])
        save("display", dict(grid, img_rows="0"), page=initial, valid=False)
        save("display", grid, page=initial)
        home = guest.fetch("app.php/album")
        page = guest.fetch("app.php/album/category/940")
        album = AlbumPage(page)
        recent = AlbumPage(home)
        if (FormFields(initial, "album-display-settings").values["sort_method"] != "username" or state()["settings"]["album"]["sort_method"] != "pic_user_id" or
                recent.grids != ["recent"] or len(recent.recent) != 1 or "--album-columns:1;" not in home or
                "--album-columns:2;" not in category_grid(page) or list(album.pictures) != [29001,29002] or 2 not in album.pages or
                list(AlbumPage(guest.fetch(album.pages[2])).pictures) != [29000] or
                FormFields(page, "album-sort").values.get("sort_method") != "username"):
            raise RuntimeError("The two independent grids, converted uploader sort or category pagination were ignored")
        other = save("display", dict(disp_late="0", disp_high="1", disp_rand="1", img_cols="2", sort_method="pic_title", sort_order="DESC"))
        save("display", {"sort_method":"username"}, page=other)
        if (state()["settings"]["album"]["sort_method"] != "username" or
                AlbumPage(guest.fetch("app.php/album")).grids != ["featured","featured"] or
                list(AlbumPage(guest.fetch("app.php/album/category/940")).pictures) != [29000,29002]):
            raise RuntimeError("Switching featured sections or default picture direction did not reach the album")

    pic = "app.php/album/picture/16000"
    hot = "app.php/album/hot-or-not"
    form = "album-hot-vote"

    def rating_modes():
        first = admin.fetch(modules["ratings"])
        controls = FormFields(first, "album-ratings-settings").values
        if not {"rate","rate_scale","comment","rate_type","hon_rate_sep","hon_rate_users","hon_rate_times","hon_rate_where"} <= controls.keys():
            raise RuntimeError("Some discussion or random-ballot policy controls are missing")
        if controls["hon_rate_where"] != "0 / 670" or controls["rate_type"] == "83":
            raise RuntimeError("The converted random category filter or rating scope was lost")
        save("ratings", dict(rate_scale="5", hon_rate_where="bad"), page=first, valid=False)
        save("ratings", dict(rate_scale="5", hon_rate_where=None), page=first, valid=False)
        if state()["settings"]["album"].get("rate_scale") != "10":
            raise RuntimeError("An incomplete or invalid random category scope partially changed the rating scale")
        icons = save("ratings", dict(rate_type="0", rate_scale="5", hon_rate_users="0"), page=first)
        current = state()["settings"]
        if current["album"]["rate_type"] != "83" or current["sp"]["rate_type"] != "0" or current["sp"]["hon_rate_where"] != "0 / 670":
            raise RuntimeError("Saving the rating mode changed an unrelated legacy value or rewrote a historic filter")
        picture = guest.fetch(pic)
        grid = category_grid(guest.fetch("app.php/album/category/670"))
        if (not re.search(r'<span class="sr-only">7[,.]5 \([^<]+\)</span>', picture) or
                not all('--album-ranks:7"' in part for part in (picture,grid)) or
                not guest.fetch("ext/phpbb2plus/gallery/styles/all/theme/rank.gif", raw=True).startswith(b"GIF") or
                form in Page(guest.fetch(hot)).forms or "album-honour" in Page(picture).forms):
            raise RuntimeError("The historic graphic, readable vote count, restricted guest ballot or whole-point rounding is incorrect")
        ordinary = applicant.fetch(pic)
        picker = re.search(r'<select id="album-points" name="points">(.*?)</select>', ordinary, re.S)
        if not picker or re.findall(r'<option value="(\d+)"', picker[1]) != ["0","1","2","3","4","5"]:
            raise RuntimeError("The ordinary ballot offered points outside its current scale")
        stale_ballot = member.fetch(hot)
        submit_form(member, hot, stale_ballot, form, {"hon_rating": "6"}, status=422)
        if votes() != [(7,2),(8,-1)]:
            raise RuntimeError("Changing the scale or submitting an out-of-range vote modified historical ballots")
        result = submit_form(member, hot, stale_ballot, form, {"hon_rating": "3"})
        if form in Page(result).forms or '--album-ranks:2"' not in result or votes() != [(7,2),(8,3)]:
            raise RuntimeError("A separate once-only ballot replaced the ordinary score or produced the wrong aggregate")
        save("ratings", {"rate_type":"1","hon_rate_sep":"0","hon_rate_times":"1"}, page=icons)
        page = member.fetch(hot)
        if 'plus-album-ranks' in page or not re.search(r'id="album-hot-result">\s*7[,.]5 \(', page) or form not in Page(page).forms:
            raise RuntimeError("The shared numeric rating or member replacement policy was ignored")
        submit_form(member, hot, page, form, {"hon_rating":"5"})
        if votes() != [(7,2),(5,3)]:
            raise RuntimeError("Replacing a shared rating changed the separate honour ballot")
        save("ratings", {"rate_type":"2","hon_rate_where":"671, 672","hon_rate_users":"1"})
        for visitor in (guest,applicant):
            restricted = visitor.fetch(hot)
            if 'plus-album-empty' not in restricted or 'album-hot-title' in restricted:
                raise RuntimeError("A restricted or pending album entered the public draw")
        if "Draw picture 16001" not in member.fetch(hot):
            raise RuntimeError("Selecting several categories excluded the approved group member")
        both = guest.fetch(pic)
        if not re.search(r'6[,.]0 \([^<]+\)<span class="plus-album-ranks"', both) or '--album-ranks:6"' not in both:
            raise RuntimeError("Combined scores failed to preserve ordinary historical votes above the current scale")
        save("ratings", {"rate":"0", "comment":"0", "hon_rate_where":""})
        page = applicant.fetch(pic)
        if ("album-discuss" in Page(page).forms or "album-honour" in Page(page).forms or 'id="album-comments"' in page or
                'plus-album-ranks' in page or 'id="album-hot-result"' in guest.fetch(hot) or
                set(AlbumPage(guest.fetch("app.php/album/category/940")).methods) & {"rating","comments","new_comment"} or
                votes() != [(7,2),(5,3)]):
            raise RuntimeError("Disabling ratings and comments left voting controls active or discarded stored content")

    check("base-seed")
    try:
        check("seed")
        try:
            grid_and_sort()
            rating_modes()
        finally:
            check("cleanup")
    finally:
        check("base-cleanup")
