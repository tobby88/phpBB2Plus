"""Open converted phpBB2 moderation bookmarks on the native phpBB3 selection forms."""
import os
from urllib.error import HTTPError
from urllib.parse import parse_qs, urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

from http_board import Browser, Page, submit_form
from legacy_links import link


class OneResponse(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def browse(base, member, check):
    guest = Browser(base)
    forum, topic, post = 673, 947820, 947830
    root = "app.php/legacy/moderation/"
    split = f"modcp.php?mode=split&t={topic}"
    merge = f"merge.php?p={post + 1}"
    details = f"modcp.php?mode=ip&p={post + 1}"
    listing = f"modcp.php?f={forum}"
    safely_open_topic = {"mode": "topic_view", "f": forum, "t": topic}

    def native(path, expected, marker="", fragment="", actor=member):
        with actor.opener.open(urljoin(base, path), timeout=30) as response:
            location = urlsplit(response.geturl())
            page = response.read().decode("utf-8")
        query = parse_qs(location.query)
        sid = query.pop("sid", None)
        if (location.path != "/mcp.php" or location.fragment != fragment or
                query != {k: [str(v)] for k, v in dict(expected, i="main").items()} or
                (sid and sid == ["old-session"]) or marker not in page):
            raise RuntimeError(f"Unexpected native view at {location.path}, {query}, expected {expected}, panel present: {marker in page}")
        return page

    def denied(actor, path):
        try:
            with actor.opener.open(urljoin(base, path), timeout=30) as response:
                response.read()
        except HTTPError as error:
            with error:
                page = error.read().decode("utf-8")
                if (error.code == 404 and urlsplit(error.geturl()).path.startswith("/" + root) and
                        "Bookmark selection" not in page and "Bookmark record" not in page and "192.0.2.101" not in page):
                    return
        raise RuntimeError("An unreadable moderation target exposed its native location or contents")

    check("seed")
    try:
        link(base, f"merge.php?p=000{post + 1}&confirm=1&sid=old-session", root + f"merge/post/{post + 1}/0", {}, method="HEAD")
        link(base, "modcp.php?selected_id=Root&start=10", "app.php/forums.html", {})
        link(base, "modcp.php?selected_id=c1", "app.php/forumc1.html", {})
        link(base, f"modcp.php?f={forum}&start=1000100", root + f"forum/forum/{forum}/1000100", {})
        for path in ("merge.php?p=947899", merge):
            signin = guest.fetch(path)
            if "login" not in Page(signin).forms:
                raise RuntimeError("A signed-out moderation bookmark did not go to the native login form")
        returned = submit_form(guest, "ucp.php?mode=login", signin, "login",
                               {"username": "HistoricMember", "password": os.environ["PLUS_TEST_HISTORIC_PASSWORD"], "login": "1"})
        if 'data-show-panel="merge-panel"' not in returned:
            raise RuntimeError("Signing in from a bookmark lost the selected merge source")
        guest.fetch("modcp.php?mode=ip", expected_status=404)
        guest.fetch("merge.php?t=16777216", expected_status=404)
        guest.fetch("modcp.php?mode=split&f=673", expected_status=404)
        native(listing, {"mode": "forum_view", "f": forum}, "Bookmark selection 0")
        paged = root + f"forum/forum/{forum}/2"
        link(base, f"modcp.php?selected_id=f{forum}&start=2", paged, {})
        # The native forum will clamp the offset if the saved page has since disappeared.
        first = build_opener(HTTPCookieProcessor(member.cookies), OneResponse())
        try:
            with first.open(Request(urljoin(base, paged), method="HEAD"), timeout=30):
                raise RuntimeError("A paginated bookmark did not redirect to the forum")
        except HTTPError as hop:
            with hop:
                destination = urlsplit(urljoin(urljoin(base, paged), hop.headers.get("Location", "")))
                args = parse_qs(destination.query)
                args.pop("sid", None)
                if (hop.code != 302 or destination.netloc != urlsplit(base).netloc or destination.path != "/mcp.php" or
                        args != {"i": ["main"], "mode": ["forum_view"], "f": [str(forum)], "start": ["2"]} or
                        "no-store" not in hop.headers.get("Cache-Control", "")):
                    raise RuntimeError("The moderation bookmark lost its forum, page or private response policy")
        native(f"modcp.php?t={topic}", {"mode": "forum_view", "f": forum}, "Bookmark selection 0")
        native(f"merge.php?fid=f{forum}", {"mode": "forum_view", "f": forum}, "Bookmark selection 0")
        native("modcp.php", {"mode": "front"}, 'id="cp-menu"')
        with member.opener.open(urljoin(base, "merge.php"), timeout=30) as response:
            selection = parse_qs(urlsplit(response.geturl()).query)
            if selection.get("mode") != ["forum_view"] or not selection.get("f") or "action" in selection:
                raise RuntimeError("The old source picker did not open a readable native forum")
        native(split, dict(safely_open_topic, action="split"), 'data-show-panel="split-panel"')
        native(merge, dict(safely_open_topic, action="merge"), 'data-show-panel="merge-panel"')
        native(f"merge.php?t={topic}&p=947899", dict(safely_open_topic, action="merge"), 'name="to_topic_id"')
        native(details + "&t=947899&rdns=1", {"mode": "post_details", "p": post + 1}, 'id="ip"', "ip")
        for mode in ("delete", "move", "lock", "unlock", "sticky", "announce", "normalise"):
            link(base, f"modcp.php?mode={mode}&t={topic}&sid=old-session&mod_token=old-token&confirm=1",
                 root + f"topic/topic/{topic}/0", {})
        native(f"modcp.php?mode=lock&t={topic}", safely_open_topic, 'id="mcp"')
        for path in (split, merge, root + f"merge/post/{post + 1}/0"):
            member.fetch(path, {"confirm": "1", "from_topic": str(topic), "to_topic": "1", "post_id_list[]": str(post)}, expected_status=405)
        check("intact")
        denied(member, f"merge.php?p={post + 2}")
        denied(member, f"merge.php?p={post + 3}")
        denied(member, f"merge.php?t={topic + 1}")
        denied(member, "merge.php?p=947899")
        check("rights:approve")
        native(f"merge.php?p={post + 2}", dict(safely_open_topic, action="merge"), 'name="to_topic_id"')
        denied(member, f"modcp.php?mode=ip&p={post + 3}")
        denied(member, f"merge.php?p={post + 4}")
        for right, path in (("m_info", details), ("m_split", split), ("m_merge", merge), ("f_read", merge), ("f_list", merge)):
            check("rights:" + right)
            denied(member, path)
            if right.startswith("f_"):
                denied(member, listing)
            else:
                native(listing, {"mode": "forum_view", "f": forum}, "Bookmark selection 0")
        check("rights:")
        check("password")
        denied(member, merge)
        denied(member, listing)
        room = f"viewforum.php?f={forum}"
        action, fields = Page(member.fetch(room)).forms["login_forum"]
        member.fetch(urljoin(urljoin(base, room), action), dict(fields, password="local-bookmark-room"))
        native(merge, dict(safely_open_topic, action="merge"), 'name="to_topic_id"')
        native(listing, {"mode": "forum_view", "f": forum}, "Bookmark selection 0")
        denied(guest, merge)
        check("intact")
    finally:
        check("cleanup")
