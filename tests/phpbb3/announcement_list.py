"""Read the board and branch announcement lists with real viewer and board preference changes."""
from html import unescape
import re
from urllib.parse import urljoin

from http_board import Browser, FormFields, admin_url, submit_form
from inline_editor import list_links


def browse(base, admin, member, fixture):
    def forum(number):
        return f"viewforum.php?f={number}"

    def panel(page):
        match = re.search(r'<section id="plus-announcements".*?</section>', page, re.S)
        return match[0] if match else ""

    def cases(block):
        return {int(value) - 926000 for value in re.findall(r'data-announcement="(9261\d\d)"', block)}

    def require(condition, message):
        if not condition:
            raise AssertionError(message)

    def preferences(fields):
        url = "ucp.php?i=ucp_prefs&mode=view"
        submit_form(member, url, member.fetch(url), "ucp", dict(fields, submit="1"))

    former = fixture("seed").strip()
    guest = Browser(base)
    parent = forum(39021)
    deep = forum(39022)
    settings = admin_url(admin) + "&i=acp_board&mode=features"
    try:
        first = panel(guest.fetch("index.php"))
        require(cases(first) == {110,111,112,113,114,122}, "Wrong public index announcements: " + str(cases(first)))
        require("30.03.2024" in first and "31.03.2024" in first and former not in first,
                "A dated announcement lost its calendar range or exposed the former author")
        require("plus-announcement-path" in first and "Notice category" in first and "Globale Bekanntmachungen" in first,
                "The announced routes or separated global heading are absent from the index")
        require(not panel(member.fetch("index.php")), "The old member selection to hide index announcements was lost")

        category = panel(guest.fetch(forum(39020)))
        require(cases(category) == {110,111,112,114,122}, "A category included a regular announcement from a separate branch")
        page = guest.fetch(parent)
        require(cases(panel(page)) == {111,114,122} and page.count('class="topictitle">Notice fixture 114</a>') == 1,
                "The forum did not separate its own announcements from descendants and board-wide announcements")
        require(cases(panel(guest.fetch(deep))) == {110,114}, "A subforum lost its parent's announcements")
        moderating = panel(admin.fetch(parent))
        require(117 in cases(moderating) and 118 not in cases(moderating),
                "The local approving moderator lost pending announcements or received a withdrawn topic")
        personal = panel(member.fetch(parent))
        require(former in personal and "plus-announcement-path" not in personal and '<li class="row bg3">' not in personal
                and "30 Mar 2024" in personal, "The personal path/group settings or forced date option were not applied")
        global_row = re.search(r'data-announcement="926114".*?</li>', personal, re.S)[0]
        require("global_unread" in global_row and "view=unread#unread" in global_row,
                "The announcement ignored its native per-member read marker")
        for listing in (panel(page), personal, moderating, guest.fetch(deep)):
            require("Hinweis &amp; &lt;April&gt;" in listing, "An announcement lost its escaped topic description")
        edit = list_links(moderating).get(926111)
        require(edit and 'data-edit-post="926111" data-title-only' in moderating and 926111 not in list_links(panel(page) + personal),
                "The title editor did not use the first post's actual moderator or owner permissions")
        edit = unescape(edit)
        form = admin.fetch(edit)
        saved = submit_form(admin, edit, form, "postform", {"subject":"Notice fixture 111 – geändert","post":"1"})
        if 'id="postform"' in saved:
            errors = re.findall(r'<[^>]+class="[^"]*(?:error|rules)[^"]*"[^>]*>(.*?)</(?:div|p)>', saved, re.S)
            raise AssertionError("The native title form stayed open: " + " ".join(unescape(re.sub(r"<[^>]+>", " ", e)).strip() for e in errors)[:300])
        fixture("edited")
        require("Notice fixture 111 – geändert" in panel(guest.fetch(parent)),
                "A native edit initiated in the overview was not reflected there")

        gate = forum(39024)
        submit_form(guest, gate, guest.fetch(gate), "login_forum", {"password":"notice-fixture-local","login":"1"})
        require(115 in cases(panel(guest.fetch(parent))) and 115 not in cases(panel(Browser(base).fetch(parent))),
                "A forum password acknowledgement was shared across sessions")

        ucp = "ucp.php?i=ucp_prefs&mode=view"
        require("plus_announce_dates" not in FormFields(member.fetch(ucp), "ucp").values, "A member could edit a forced date policy")
        preferences({"plus_announce_index":"1", "plus_announce_path":"1", "plus_announce_groups":"1"})
        chosen_index = panel(member.fetch("index.php"))
        require("plus-announcement-path" in chosen_index, "The native UCP did not apply newly selected paths")
        require('data-edit-post="926112"' in chosen_index and 'data-edit-post="926111"' not in chosen_index,
                "The owner could not edit their own announcement independently of moderation rights")
        preferences({"plus_split_global_announce":"0", "plus_split_announce":"1"})
        merged = panel(member.fetch(parent))
        require("<strong>Forum announcements</strong>" in merged and "<strong>Global announcements</strong>" not in merged,
                "The overview ignored the member's native choice to group globals with forum announcements")
        submit_form(admin, settings, admin.fetch(settings), "acp_board", {
            "config[plus_announce_index]":"0","config[plus_announce_index_over]":"1","submit":"Submit"})
        require(not panel(member.fetch("index.php")) and panel(member.fetch(parent)),
                "The board override did not independently control the index")
        submit_form(admin, settings, admin.fetch(settings), "acp_board", {
            "config[plus_announce_index]":"1","config[plus_announce_index_over]":"0","submit":"Submit"})
        require(panel(member.fetch("index.php")), "Removing the override did not restore the member choice")
        preferences({"plus_announce_forum":"0"})
        native = member.fetch(parent)
        require(not panel(native) and 'class="topictitle">Notice fixture 114</a>' in native and "Notice fixture 115" not in native,
                "Disabling the cross-forum panel lost native globals or exposed a passworded announcement")

        fixture("short")
        short = panel(guest.fetch(parent))
        following = re.search(r'rel="next" href="([^"]+)"', short)
        require(following and len(cases(short)) == 1, "A long announcement list did not provide a bounded first page")
        next_page = panel(guest.fetch(urljoin(base, unescape(following[1]))))
        require(len(cases(next_page)) == 1 and not (cases(next_page) & cases(short)) and 'rel="prev"' in next_page,
                "Announcement navigation repeated the same row or lost its way back")
        fixture("expire")
        guest.fetch("viewtopic.php?t=926122")
        require(122 not in cases(panel(guest.fetch(parent))), "A natively expired announcement remained in the overview")
    finally:
        fixture("clean")
