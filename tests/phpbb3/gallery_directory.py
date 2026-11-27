"""Read owner directories through both generations' addresses, with current gallery permissions."""
from html import unescape
import json
import re
from urllib.parse import parse_qs, urlencode, urlsplit

from gallery_member_links import member_links
from http_board import FormFields, Page


def browse(administrator, member, applicant, guest, check, *, bookmarks=True):
    route = "app.php/album/members"

    def listing(html):
        table = re.search(r'<table\b[^>]*id="album-members"[^>]*>(.*?)</table>', html, re.S)
        if not table:
            return []
        return [(int(uid), unescape(name), int(pics), unescape(link)) for link, uid, name, pics in re.findall(
            r'<tr><td><a href="([^"]*/album/member/(\d+)[^"]*)">([^<]*)</a></td>\s*<td><time [^>]+>[^<]+</time></td><td>(\d+)</td></tr>', table[1])]

    def pages(actor, mode="joindate", order="ASC"):
        path = route + "?" + urlencode({"mode": mode, "order": order})
        page = actor.fetch(path)
        first = listing(page)
        urls = [unescape(url) for url in re.findall(r'href="([^"]*/album/members\?[^"]+)"', page)]
        starts = {int(params["start"][0]): url for url in urls if (params := parse_qs(urlsplit(url).query)).get("start")
                  and params.get("mode") == [mode] and params.get("order") == [order]}
        if not starts:
            raise RuntimeError("A multi-page owner directory has no working continuation with the selected sort")
        rows = list(first)
        for start in sorted(starts):
            if start:
                rows.extend(listing(actor.fetch(starts[start])))
        return [row for row in rows if 41000 <= row[0] <= 41119], first

    setup = json.loads(check("seed"))
    try:
        count, size = setup["owners"], setup["size"]
        if not re.search(r'href="[^"]*/album/members(?:\?[^"]*)?"', guest.fetch("app.php/album")):
            raise RuntimeError("A guest cannot get to the photo album directory from the gallery menu")
        defaults = guest.fetch(route)
        if ("album-search" not in Page(defaults).forms or
                {key: FormFields(defaults, "album-member-sort").values.get(key) for key in ("mode", "order")} != {"mode":"joindate", "order":"ASC"}):
            raise RuntimeError("The directory did not retain its album search or legacy default sort")
        order_key = {
            "joindate": lambda i: (i // 2) % 3,
            "username": lambda i: count + 3 - i,
            "pics": lambda i: 2 if i == 0 else 1,
            "last_pic": lambda i: 990000000 if i == 1 else 900000000 - ((i // 2) % 3) * 86400,
        }
        for mode, key in order_key.items():
            for order in ("ASC", "DESC"):
                rows, first = pages(guest, mode, order)
                expected = sorted(range(count), key=lambda i: (key(i), i), reverse=order == "DESC")
                if [uid - 41000 for uid, *_ in rows] != expected or len(first) != size:
                    raise RuntimeError(f"The owner directory lost rows, tie order or its page size for {mode} {order}")
        for viewer, public_photos in ((guest, 2), (applicant, 2), (member, 3), (administrator, 4)):
            rows = {uid: (name, pics, url) for uid, name, pics, url in pages(viewer, "pics", "DESC")[0]}
            if (rows[41000][1] != public_photos or rows[41002][1] != 1 or 41000 + count in rows or 41000 + count + 2 in rows or
                    (41000 + count + 1 in rows) != (viewer is administrator)):
                raise RuntimeError("The directory counted an inaccessible, empty or inactive owner's album")
            first_owner = viewer.fetch(rows[41000][2])
            if "Member gallery 0" not in first_owner or ("Member child 42112" in first_owner) != (viewer in (member, administrator)):
                raise RuntimeError("The listed owner URL did not open a usable album with the same permissions")
        target = route + "?mode=username&order=ASC"
        post = {"mode": "pics", "order": "DESC"}
        first = listing(guest.fetch(route + "?mode=pics&order=DESC"))
        if listing(guest.fetch(target, post)) != first:
            raise RuntimeError("The read-only native sort form did not give POST parameters precedence")
        last = listing(guest.fetch(route + "?mode=username&order=ASC&start=1000000"))
        if not last or len(last) > size or listing(guest.fetch(route + "?mode=username&order=ASC&start=99999999")) != last:
            raise RuntimeError("An old owner-directory bookmark no longer resolves to the last available page")
        if bookmarks:
            if listing(guest.fetch("album_personal_index.php?mode=pics&order=DESC")) != first:
                raise RuntimeError("The former directory URL lost the selected sort")
            if listing(guest.fetch("album_personal_index.php?mode=username&order=ASC", post)) != first:
                raise RuntimeError("The former directory's read-only sort form did not reach the correct owner order")
        member_links(administrator, member, applicant, guest, check, count)
    finally:
        check("cleanup")
