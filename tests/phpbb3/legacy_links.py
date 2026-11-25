"""Follow the forum's historical public link formats against the converted fixture."""
from http.client import HTTPConnection
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit

from http_board import Browser, Page


def link(base, path, expected_path, query, fragment="", method="GET", form=None, status=302):
    origin = urlsplit(base)
    if origin.hostname != "127.0.0.1":
        raise ValueError("The URL fixture requires loopback")
    conn = HTTPConnection(origin.hostname, origin.port, timeout=30)
    try:
        body = urlencode(form) if form is not None else None
        headers = {"Content-Type": "application/x-www-form-urlencoded"} if body is not None else {}
        conn.request(method, "/" + path, body=body, headers=headers)
        response = conn.getresponse()
        location = urlsplit(urljoin(urljoin(base, path), response.getheader("Location", "")))
        if response.status != status or location.netloc != origin.netloc or location.path != "/" + expected_path:
            raise RuntimeError(f"Wrong redirect for {path} ({response.status}, {location.path})")
        values = parse_qs(location.query)
        values.pop("sid", None)
        if values != {k: [str(v)] for k, v in query.items()} or location.fragment != fragment:
            raise RuntimeError(f"Wrong sort, pagination, identity or fragment for {path}")
    finally:
        conn.close()


def browse(base, owner, historic_id):
    cases = (
        ("hacks_list.php", "app.php/credits", {}, ""),
        ("staff.php", "memberlist.php", {"mode": "team"}, ""),
        ("download.php?id=50&thumb=1", "app.php/legacy/attachment/50/thumb", {}, ""),
        ("groupcp.php", "ucp.php", {"i": "ucp_groups", "mode": "membership"}, ""),
        ("groupcp.php?g=73&start=25", "app.php/legacy/group/73/members/25", {}, ""),
        ("groupcp.php?g=79&validate=true", "app.php/legacy/group/79/requests/0", {}, ""),
        ("profile_options.php", "ucp.php", {"i": "ucp_prefs", "mode": "personal"}, ""),
        ("change_password.php?start=10", "ucp.php", {"i": "ucp_profile", "mode": "reg_details"}, ""),
        ("privmsg.php?mode=newpm", "ucp.php", {"i": "pm", "mode": "view", "folder": "inbox"}, ""),
        ("privmsg.php?folder=sentbox&start=10", "ucp.php", {"i": "pm", "mode": "view", "folder": "sentbox", "start": 10}, ""),
        ("privmsg.php?mode=post", "ucp.php", {"i": "pm", "mode": "compose"}, ""),
        ("privmsg.php?mode=post&u=1", "app.php/legacy/pm/post/1", {}, ""),
        ("privmsg.php?folder=savebox&start=10", "app.php/legacy/pm/savebox/10", {}, ""),
        ("privmsg.php?folder=savebox&mode=read&p=113", "app.php/legacy/pm/read/113", {}, ""),
        ("privmsg.php?mode=reply&p=110", "app.php/legacy/pm/reply/110", {}, ""),
        ("privmsg.php?mode=quote&p=110", "app.php/legacy/pm/quote/110", {}, ""),
        ("privmsg.php?mode=edit&p=110", "app.php/legacy/pm/edit/110", {}, ""),
        ("kb.php?mode=stats&stats=toprated&start=12", "app.php/knowledge/statistics/toprated", {"start": 12}, ""),
        ("ranks.php", "app.php/ranks", {}, ""),
        ("album.php?user_id=0", "app.php/album", {}, ""),
        ("album_hotornot.php", "app.php/album/hot-or-not", {}, ""),
        ("album_comment_edit.php?comment_id=95&sort_order=DESC", "app.php/album/legacy/comment/95/edit", {"sort_order": "DESC"}, ""),
        ("album_comment_delete.php?comment_id=95", "app.php/album/legacy/comment/95/delete", {}, ""),
        ("album_personal_cat_admin.php", "app.php/album/legacy/member/0/manage", {}, ""),
        ("album_personal_cat_admin.php?user_id=1&action=edit&cat_id=782", "app.php/album/legacy/member/1/manage", {"action": "edit", "cat_id": 782}, ""),
        ("album_modcp.php?cat_id=2&sort_method=pic_user_id&sort_order=ASC&start=3", "app.php/album/category/2/moderate",
         {"sort_method": "pic_user_id", "sort_order": "ASC", "start": 3}, ""),
        ("album_modcp.php?mode=approval&pic_id=100", "app.php/album/picture/100/manage/approve", {}, ""),
        ("album_personal_index.php?mode=last_pic&order=DESC&start=9", "app.php/album/members",
         {"mode": "last_pic", "order": "DESC", "start": 9}, ""),
        ("album_search.php?mode=name&search=Gr%C3%BC%C3%9Fe&start=20", "app.php/album/search",
         {"mode": "name", "search": "Grüße", "start": 20}, ""),
        ("album.php?user_id=-1", "app.php/album/legacy/member/0", {}, ""),
        ("album.php?user_id=1&cat_id=11", "app.php/album/category/11", {}, ""),
        ("album.php?user_id=1&mode=list&type=RATING&start=8", "app.php/album/legacy/member/1",
         {"mode": "list", "type": "rating", "start": 8}, ""),
        ("album_cat.php?user_id=1&mode=all&cat_id=11", "app.php/album/legacy/member/1",
         {"mode": "all", "cat_id": 11}, ""),
        ("album.php?user_id=-1&mode=all", "app.php/album/legacy/member/0", {"mode": "all"}, ""),
        ("album_personal.php", "app.php/album/legacy/member/0", {}, ""),
        ("album.php?user_id=1", "app.php/album/legacy/member/1", {}, ""),
        ("album_personal.php?user_id=-1", "app.php/album/legacy/member/-1", {}, ""),
        ("album_cat.php?cat_id=2&sort_method=rating&sort_order=DESC&start=8", "app.php/album/category/2",
         {"sort_method": "rating", "sort_order": "DESC", "start": 8}, ""),
        ("album.php?cat_id=2&sort_method=pic_view_count", "app.php/album/category/2", {"sort_method": "pic_view_count"}, ""),
        ("album_personal.php?user_id=1&sort_method=pic_title&sort_order=ASC", "app.php/album/legacy/member/1",
         {"sort_method": "pic_title", "sort_order": "ASC"}, ""),
        ("album_showpage.php?pic_id=100&mode=previous&sort_order=DESC", "app.php/album/picture/100",
         {"mode": "previous", "sort_order": "DESC"}, ""),
        ("album_showpage.php?comment_id=95&sort_order=ASC", "app.php/album/legacy/comment/95", {"sort_order": "ASC"}, "comment-95"),
        ("dload.php?action=user_upload", "app.php/downloads/submit", {}, ""),
        ("dload.php?action=user_upload&cat_id=2", "app.php/downloads/category/2/submit", {}, ""),
        ("dload.php?action=user_upload&file_id=50", "app.php/downloads/file/50/edit", {}, ""),
        ("dload.php?action=user_upload&do=delete&file_id=50", "app.php/downloads/file/50/delete", {}, ""),
        ("dload.php?action=mcp&cat_id=2", "app.php/downloads/category/2/review", {}, ""),
        ("dload.php?action=stats", "app.php/downloads/stats", {}, ""),
        ("dload.php?action=email&file_id=50", "app.php/downloads/file/50/recommend", {}, ""),
        ("dload.php?action=viewall&sort_method=rating&sort_order=DESC&start=5", "app.php/downloads/all",
         {"sort_method": "rating", "sort_order": "DESC", "start": 5}, ""),
        ("dload.php?action=category&cat_id=21&sort_method=file_update_time", "app.php/downloads/category/21",
         {"sort_method": "file_update_time"}, ""),
        ("dload.php?action=search&search_author=H%2A&cat_id=21&search_terms=all&comments_search=YES&sort_method=rating&sort_order=ASC",
         "app.php/downloads/search", {"search_author": "H*", "cat_id": 21, "search_terms": "all", "comments_search": "YES",
                                      "sort_method": "rating", "sort_order": "ASC"}, ""),
        ("dload.php?action=search&search_id=128&start=4", "app.php/downloads/search", {"search_id": 128, "start": 4}, ""),
        ("dload.php?action=toplist&mode=newest&days=14&selected_date=1136034000", "app.php/downloads/toplist",
         {"mode": "newest", "days": 14, "selected_date": 1136034000}, ""),
        ("dload.php?action=toplist&mode=rating&most_type=per&most_num=5&start=2", "app.php/downloads/toplist",
         {"mode": "rating", "most_type": "per", "most_num": 5, "start": 2}, ""),
        ("forums.html", "index.php", {}, ""),
        ("forum1,public-board.html", "viewforum.php", {"f": 1}, ""),
        ("app.php/viewforum1-7-20.html", "viewforum.php", {"f": 1, "st": 7, "start": 20}, ""),
        ("viewforum1--,public-board.html", "viewforum.php", {"f": 1, "st": 0, "start": 0}, ""),
        ("app.php/ptopic1.html", "viewtopic.php", {"t": 1, "view": "previous"}, ""),
        ("ntopic1.html", "viewtopic.php", {"t": 1, "view": "next"}, ""),
        ("ftopic1-15.html", "viewtopic.php", {"t": 1, "start": 15}, ""),
        ("ftopic1-7-desc-20.html", "viewtopic.php", {"t": 1, "st": 7, "sd": "d", "start": 20}, ""),
        ("fpost32.html", "viewtopic.php", {"p": 32}, "p32"),
        ("profile.php?mode=signature", "ucp.php", {"i": "ucp_profile", "mode": "signature"}, ""),
        ("profile.php?mode=activate&act_key=fixture", "ucp.php", {"mode": "resend_act"}, ""),
        ("app.php/legacy/member/1/viewprofile", "memberlist.php", {"mode": "viewprofile", "u": historic_id}, ""),
    )
    for path, script, query, fragment in cases:
        link(base, path, script, query, fragment)
    link(base, "ftopic1.html", "viewtopic.php", {"t": 1}, method="HEAD")
    link(base, "dload.php?action=category&cat_id=21", "app.php/downloads/all",
         {"sort_method": "file_rating", "sort_order": "ASC", "start": 5}, method="POST",
         form={"action": "viewall", "sort_method": "file_rating", "sort_order": "ASC", "start": 5}, status=303)
    link(base, "dload.php", "app.php/downloads/category/21", {"sort_method": "file_dls"}, method="POST",
         form={"action": "category", "cat_id": 21, "sort_method": "file_dls"}, status=303)
    link(base, "album.php?user_id=2&sort_order=ASC", "app.php/album/legacy/member/1",
         {"mode": "list", "type": "comment", "sort_method": "pic_title", "sort_order": "ASC", "start": 4},
         method="POST", form={"user_id": 1, "mode": "LIST", "type": "comment", "sort_method": "pic_title",
                              "sort_order": "DESC", "start": 4}, status=303)
    guest = Browser(base)
    topic = guest.fetch("ftopic1.html")
    if 'id="32"' not in topic or 'id="p32"' not in topic or "Grüße" not in topic:
        raise RuntimeError("Old topic bookmark has lost its native post or numeric fragment")
    for path in ("forumc11,nested-category.html", "index.php?c=11"):
        if "Nested category" not in owner.fetch(path):
            raise RuntimeError("Old category link opened the wrong category")
    if "HistoricMember" not in guest.fetch("profile.php?mode=viewprofile&u=1"):
        raise RuntimeError("Old profile for source user one opened the wrong native member")
    # A retired source ID can now belong to a different native account.
    guest.fetch("profile.php?mode=viewprofile&u=" + str(historic_id), expected_status=404)
    if "Private fixture text" in guest.fetch("fpost40.html") or "Private fixture text" not in owner.fetch("fpost40.html"):
        raise RuntimeError("Old post link changed the forum's read permissions")
    guest.fetch("profile.php?mode=register", {}, expected_status=405)
    guest.fetch("change_password.php", {"submit": "1"}, expected_status=405)
    guest.fetch("album_modcp.php?mode=delete&pic_id=100", {"confirm": 1}, expected_status=405)
    guest.fetch("album_comment_delete.php?comment_id=95", {"confirm": 1}, expected_status=405)
    guest.fetch("album_personal_cat_admin.php?user_id=87&action=delete", {"confirm": 1}, expected_status=405)
    guest.fetch("dload.php?action=email&file_id=50", {"submit": 1}, expected_status=405)
    guest.fetch("dload.php?action=search", {"action": "user_upload"}, expected_status=405)


def groups(base, owner, member, pending, native_group):
    guest = Browser(base)
    if "login" not in Page(guest.fetch("groupcp.php?g=73")).forms:
        raise RuntimeError("A guest group bookmark did not open the login form")
    for actor in (owner, member):
        roster = actor.fetch("groupcp.php?g=73&validate=true")
        if "Archive Club" not in roster or 'name="usernames"' in roster:
            raise RuntimeError("The converted group bookmark did not open the group roster")
    pending.fetch("groupcp.php?g=77", expected_status=404)
    owner.fetch("groupcp.php?g=" + str(native_group), expected_status=404)
    if "Approved leader" not in member.fetch("groupcp.php?g=79"):
        raise RuntimeError("The converted group leader could not see their group")
    request = member.fetch("groupcp.php?g=79&validate=true")
    if "Approved leader" not in request or 'name="usernames"' not in request or "ucp" not in Page(request).forms:
        raise RuntimeError("A group leader's notification did not open native membership management")
    guest.fetch("groupcp.php?g=73", {}, expected_status=405)
