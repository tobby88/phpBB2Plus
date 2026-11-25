"""Visible category sorting, picture neighbours and legacy comment bookmarks."""
from html.parser import HTMLParser
from html import unescape
import json
import re
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request

from http_board import Page, submit_form


class AlbumPage(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.grid = ""
        self.pictures, self.neighbours, self.pages, self.categories = {}, {}, {}, []
        self.recent, self.grids = {}, []
        self.comments, self.methods = [], []
        self.select = ""
        self.feed(html)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "section" and "data-album-grid" in attrs:
            self.grid = attrs["data-album-grid"]
            self.grids.append(self.grid)
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
        if self.grid == "category" and match:
            self.pictures[int(match[1])] = address
        if self.grid == "recent" and match:
            self.recent[int(match[1])] = address
        if match and attrs.get("rel") in {"prev", "next"}:
            self.neighbours[attrs["rel"]] = (int(match[1]), address)
        offsets = parse_qs(where.query).get("start")
        if re.search(r"/album/category/\d+$", where.path):
            self.categories.append(address)
            if offsets:
                self.pages[int(offsets[0])] = address

    def handle_endtag(self, tag):
        if tag == "section":
            self.grid = ""
        elif tag == "select":
            self.select = ""


def saved_comment(actor, path, html, form, text):
    class Submission:
        base = actor.base

        def fetch(self, url, fields, expected_status):
            if urlsplit(url).netloc != urlsplit(self.base).netloc:
                raise RuntimeError("The synthetic comment form left the local board")
            with actor.opener.open(Request(url, data=urlencode(fields).encode()), timeout=20) as response:
                if response.status != expected_status:
                    raise RuntimeError("Saving the picture comment did not complete")
                return urlsplit(response.geturl()), response.read().decode("utf-8")

    location, page = submit_form(Submission(), path, html, form, {"body": text})
    ids = AlbumPage(page).comments
    if (not re.fullmatch(r"comment-\d+", location.fragment) or int(location.fragment[8:]) not in ids or
            text not in page or not location.path.endswith("/album/picture/7010")):
        raise RuntimeError("Saving the picture comment did not land on the page containing its fragment")
    return parse_qs(location.query), ids


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
        # The converted board has 15 comments per page, and fixture timestamps run opposite to comment IDs.
        thread = album + "/picture/7010"
        query, comments = saved_comment(member, thread, member.fetch(thread), "album-discuss", "New comment in ascending order")
        if int(query.get("start", ["0"])[0]) != (limit + 4) // limit * limit or comments != sorted(comments):
            raise RuntimeError("A newly posted comment used a fixed page size or lost ascending order")
        descending = thread + "?sort_order=DESC"
        query, comments = saved_comment(member, descending, member.fetch(descending), "album-discuss", "New comment in descending order")
        if query.get("sort_order") != ["DESC"] or "start" in query or comments != sorted(comments, reverse=True):
            raise RuntimeError("Saving from the newest-first listing did not return its first page")
        tail = administrator.fetch(descending + f"&start={limit}")
        edit = re.search(r'href="([^"]*/album/picture/7010/comment/9001/edit[^"]*)"', tail)
        if not edit:
            raise RuntimeError("The test comment cannot be edited from its displayed descending page")
        edit_url = album + "/legacy/comment/9001/edit?sort_order=DESC"
        native_form = Page(administrator.fetch(edit_url)).forms["album-comment-edit"][0]
        if (not urlsplit(native_form).path.endswith("/album/picture/7010/comment/9001/edit")
                or parse_qs(urlsplit(native_form).query).get("sort_order") != ["DESC"]):
            raise RuntimeError("The historic edit bookmark did not resolve to the same native form and order")
        query, comments = saved_comment(administrator, edit_url, administrator.fetch(edit_url), "album-comment-edit", "Edited comment with an out-of-order timestamp")
        if query.get("sort_order") != ["DESC"] or query.get("start") != [str(limit)] or 9001 not in comments:
            raise RuntimeError("Editing an existing comment used its old timestamp or lost the selected order")
        for mode in ("edit", "delete"):
            guest.fetch(album + "/legacy/comment/9900/" + mode, expected_status=404)
            member.fetch(album + "/legacy/comment/9901/" + mode, expected_status=404)
            member.fetch(album + "/legacy/comment/9001/" + mode, expected_status=404)
            administrator.fetch(album + "/legacy/comment/999999/" + mode, expected_status=404)
            own = f"album_comment_{mode}.php?comment_id=9900"
            form = Page(member.fetch(own)).forms["album-comment-edit"][0]
            if not urlsplit(form).path.endswith("/album/picture/7002/comment/9900/" + mode):
                raise RuntimeError("The comment author could not open their own native form")
        submit_form(member, own, member.fetch(own), "album-comment-edit", {})
        member.fetch(own, expected_status=404)
        check("controls-off")
        off = AlbumPage(guest.fetch(album + "/category/601?sort_method=rating&sort_order=ASC"))
        if {"rating", "comments", "new_comment"} & set(off.methods) or list(off.pictures) != sorted(allowed)[:12]:
            raise RuntimeError("A disabled gallery feature remained a picture sorting option")
        large_page = AlbumPage(guest.fetch(album + "/category/603?sort_method=pic_time&sort_order=ASC&start=100"))
        if list(large_page.pictures) != list(range(8100, 8200)):
            raise RuntimeError("The legacy hundred-picture pages changed their bookmark offsets")
        guest.fetch(album + "/legacy/comment/9000", expected_status=404)
        administrator.fetch(album + "/legacy/comment/9001/edit", expected_status=404)
    finally:
        check("controls-on")
        check("cleanup")
