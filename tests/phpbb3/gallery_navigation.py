"""Visible category sorting, picture neighbours and legacy comment bookmarks."""
from html.parser import HTMLParser
import json
import re
from urllib.parse import parse_qs, urlencode, urlsplit


class AlbumPage(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.grid = False
        self.pictures, self.neighbours, self.pages, self.categories = {}, {}, {}, []
        self.comments, self.methods = [], []
        self.select = ""
        self.feed(html)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "ul" and "plus-album-grid" in attrs.get("class", "").split():
            self.grid = True
        if tag == "select":
            self.select = attrs.get("id", "")
        if tag == "option" and self.select == "album-sort-method":
            self.methods.append(attrs["value"])
        if tag == "article" and re.fullmatch(r"comment-\d+", attrs.get("id", "")):
            self.comments.append(int(attrs["id"][8:]))
        if tag != "a" or "href" not in attrs:
            return
        address = attrs["href"]
        where = urlsplit(address)
        match = re.search(r"/album/picture/(\d+)$", where.path)
        if self.grid and match:
            self.pictures[int(match[1])] = address
        if match and attrs.get("rel") in {"prev", "next"}:
            self.neighbours[attrs["rel"]] = (int(match[1]), address)
        offsets = parse_qs(where.query).get("start")
        if re.search(r"/album/category/\d+$", where.path):
            self.categories.append(address)
            if offsets:
                self.pages[int(offsets[0])] = address

    def handle_endtag(self, tag):
        if tag == "ul":
            self.grid = False
        elif tag == "select":
            self.select = ""


def sequence(actor, method, order):
    address = "app.php/album/category/601?" + urlencode({"sort_method": method, "sort_order": order})
    page = AlbumPage(actor.fetch(address))
    return list(page.pictures), page.methods


def navigation(actor, path, previous, following):
    page = AlbumPage(actor.fetch(path))
    actual = {key: item[0] for key, item in page.neighbours.items()}
    expected = {"prev": previous, "next": following} if previous is not None else {}
    if actual != expected:
        raise RuntimeError(f"Incorrect picture neighbours at {path}: {actual} instead of {expected}")
    return page


def browse(administrator, member, guest, check):
    limit = json.loads(check("seed"))["comment_limit"]
    album = "app.php/album"
    allowed = set(range(7000, 7012)) - {7001}
    methods = {"pic_time", "pic_title", "username", "pic_view_count", "rating", "comments", "new_comment"}
    title_order = sorted(allowed, key=lambda n: (n != 7007, (n - 7000) % 5, n))
    orders = {}
    try:
        for method in sorted(methods):
            ascending, options = sequence(guest, method, "ASC")
            descending, _ = sequence(guest, method, "DESC")
            if set(ascending) != allowed or descending != ascending[::-1] or set(options) != methods:
                raise RuntimeError("Album sort options, ties, pagination or approval were inconsistent for " + method)
            orders[method] = ascending
            checks = {"pic_time": sorted(allowed), "pic_title": title_order,
                      "pic_view_count": sorted(allowed, key=lambda n: ((n - 7000) % 4, n))}
            if method in checks and ascending != checks[method]:
                raise RuntimeError("Wrong category values were sorted for " + method)
            ranks = {"rating": [7009, 7008], "comments": [7010, 7002], "new_comment": [7002, 7010]}
            if method in ranks and descending[:2] != ranks[method]:
                raise RuntimeError("Ordinary ratings, comment counts or last comment identities were mixed up")
            if method == "username":
                if ascending.index(7005) != ascending.index(7004) + 1:
                    raise RuntimeError("Sorting disclosed a former name hidden from visitors")
                members, _ = sequence(member, method, "ASC")
                if members.index(7005) >= members.index(7004):
                    raise RuntimeError("A signed-in reader lost the visible historical author order")

        # Opening an image changes its view count, so check that sort before opening any others.
        for method in ["pic_view_count"] + sorted(methods - {"pic_view_count"}):
            ordered = orders[method]
            photo = 7007 if method == "pic_title" else 7005
            index = ordered.index(photo)
            navigation(guest, album + f"/picture/{photo}?picture_sort={method}&picture_order=ASC",
                       ordered[index - 1], ordered[(index + 1) % len(ordered)])
        category = album + "/category/601?sort_method=pic_title&sort_order=ASC"
        first = AlbumPage(guest.fetch(category)).pictures[7007]
        view = navigation(guest, first, title_order[-1], title_order[1])
        next_link = view.neighbours["next"][1]
        back = [parse_qs(urlsplit(link).query) for link in view.categories if urlsplit(link).path.endswith("/category/601")]
        if (parse_qs(urlsplit(next_link).query).get("picture_sort") != ["pic_title"] or
                not any(query.get("sort_method") == ["pic_title"] and query.get("sort_order") == ["ASC"] for query in back)):
            raise RuntimeError("Picture navigation lost the category's manual order")
        navigation(guest, next_link, 7007, title_order[2])
        navigation(guest, album + "/picture/7000", 7011, 7002)
        navigation(administrator, album + "/picture/7000", 7011, 7002)
        if "Navigation fixture 7002" not in guest.fetch(album + "/picture/7000?mode=next") or "Navigation fixture 7011" not in guest.fetch(album + "/picture/7000?mode=previous"):
            raise RuntimeError("An old arrow did not follow the chronological wraparound")
        for source in ("/picture/7001?mode=next", "/picture/101?mode=previous", "/picture/103?mode=next", "/legacy/comment/9901"):
            guest.fetch(album + source, expected_status=404)
        if "Navigation fixture 7001" not in administrator.fetch(album + "/legacy/comment/9901"):
            raise RuntimeError("An authorised moderator lost the pending picture's comment bookmark")
        navigation(guest, album + "/picture/7900", None, None)
        if "Navigation fixture 7900" not in guest.fetch(album + "/picture/7900?mode=next"):
            raise RuntimeError("A single-picture category did not retain its only published picture")
        navigation(guest, album + "/picture/8000", 8411, 8001)
        navigation(guest, album + "/picture/8201", 8200, 8202)
        navigation(guest, album + "/picture/8411", 8410, 8000)
        big = sorted(range(8000, 8412), key=lambda n: ((n - 8000) % 5, n), reverse=True)
        far = AlbumPage(guest.fetch(album + "/category/603?start=396&sort_method=pic_title&sort_order=DESC"))
        last = far.pages.get(408, "")
        query = parse_qs(urlsplit(last).query)
        if list(far.pictures) != big[396:408] or query.get("sort_method") != ["pic_title"] or query.get("sort_order") != ["DESC"] or list(AlbumPage(guest.fetch(last)).pictures) != big[408:]:
            raise RuntimeError("A distant album page lost its sort, pagination or tie order")
        historical = AlbumPage(member.fetch(album + "/legacy/member/1?sort_method=pic_title&sort_order=ASC"))
        if "username" in historical.methods or 103 not in historical.pictures or "picture_sort=pic_title" not in historical.pictures[103]:
            raise RuntimeError("A personal bookmark lost its eligible sorting options or its own collection")
        comment = 9000 + limit + 1
        older = AlbumPage(guest.fetch(album + f"/legacy/comment/{comment}"))
        newer = AlbumPage(guest.fetch(album + "/legacy/comment/9000?sort_order=DESC"))
        if older.comments != list(range(9000 + limit, 9000 + limit + 4)) or newer.comments != [9003, 9002, 9001, 9000]:
            raise RuntimeError("A comment bookmark landed on the wrong page or direction")
        ordinary = guest.fetch(album + "/picture/7010?picture_sort=pic_title&picture_order=ASC&sort_order=DESC")
        if AlbumPage(ordinary).comments[:2] != [9000 + limit + 3, 9000 + limit + 2] or 'id="album-comment-sort"' not in ordinary:
            raise RuntimeError("The comment order was replaced by the picture order")
        check("controls-off")
        off = AlbumPage(guest.fetch(album + "/category/601?sort_method=rating&sort_order=ASC"))
        if {"rating", "comments", "new_comment"} & set(off.methods) or list(off.pictures) != sorted(allowed)[:12]:
            raise RuntimeError("A disabled gallery feature remained a picture sorting option")
        large_page = AlbumPage(guest.fetch(album + "/category/603?sort_method=pic_time&sort_order=ASC&start=100"))
        if list(large_page.pictures) != list(range(8100, 8200)):
            raise RuntimeError("The legacy hundred-picture pages changed their bookmark offsets")
        guest.fetch(album + "/legacy/comment/9000", expected_status=404)
    finally:
        check("controls-on")
        check("cleanup")
