"""Exercise the five source topic groups, native pagination and source-backed recovery."""
import json
import re
from html import unescape
from pathlib import Path
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page, submit_form


def browse(web, db, php, admin, member, acp):
    fixture = lambda phase: php(Path(__file__).with_suffix(".php"), db, phase, cwd=web, capture=True)
    size = int(fixture("setup"))
    try:
        path = "viewforum.php?f=29852"
        ucp = "ucp.php?i=ucp_prefs&mode=view"
        guest = Browser(admin.base)
        first = guest.fetch(path)
        labels = ("Globale Bekanntmachungen", "Bekanntmachungen des Forums", "News", "Wichtige Themen", "Themen")

        def titles(page):
            return re.findall(r'class="topictitle">([^<]+)</a>', page)

        def inline(page):
            return re.findall(r'class="plus-topic-group-heading"><h3>([^<]*)</h3>', page)

        if tuple(inline(first)) != labels or "plus-topic-box-heading" in first:
            raise RuntimeError("The converted default group headings were missing or not inline")
        for page in (first, guest.fetch(path + "&start=" + str(size)), guest.fetch(path + "&st=1")):
            visible = titles(page)
            if (visible.count("List fixture News") != 1 or "List fixture Global" not in visible or "List fixture Announcement" not in visible
                    or "List fixture Pending" in visible or "List fixture Withdrawn" in visible):
                raise RuntimeError("Displayed news were duplicated, skipped by pagination/time filter or visible before approval")
        other = titles(guest.fetch("viewforum.php?f=1"))
        if "List fixture News" in other or "List fixture Global" not in other:
            raise RuntimeError("A local news item crossed its forum boundary or hid an actual global announcement")
        if "plus_split_sticky" in FormFields(member.fetch(ucp), "ucp").values:
            raise RuntimeError("A source-enforced sticky grouping was offered to the member")
        submit_form(admin, acp, admin.fetch(acp), "acp_board", {"config[plus_split_sticky_over]": "0", "submit": "1"})
        if FormFields(member.fetch(ucp), "ucp").values.get("plus_split_sticky") != "0":
            raise RuntimeError("Releasing the source override did not restore the personal grouping")
        submit_form(member, ucp, member.fetch(ucp), "ucp", {"plus_split_topic_split": "1", "submit": "1"})
        boxed = member.fetch(path)
        if "plus-topic-box-heading" not in boxed or 'aria-level="3">News</span>' not in boxed or "plus-topic-group-heading" in boxed:
            raise RuntimeError("Selecting separate boxes did not label the native topic groups")
        submit_form(member, ucp, member.fetch(ucp), "ucp", {"plus_split_global_announce": "0", "plus_split_announce": "0",
                    "plus_split_news": "1", "plus_split_sticky": "0", "plus_split_topic_split": "0", "submit": "1"})
        merged = member.fetch(path)
        groups = re.split(r'<li class="plus-topic-group-heading"><h3>[^<]+</h3></li>', merged)[1:]
        if (inline(merged) != ["News", "Topics"] or len(groups) != 2 or
                not {"List fixture News", "List fixture Global", "List fixture Announcement"} <= set(titles(groups[0])) or
                "List fixture Sticky" not in titles(groups[1])):
            raise RuntimeError("Disabled topic groups did not flow into the next enabled historical group")
        controls = ("split_global_announce", "split_announce", "split_news", "split_sticky")
        submit_form(member, ucp, member.fetch(ucp), "ucp", {**{"plus_" + key: "0" for key in controls}, "submit": "1"})
        plain = member.fetch(path)
        if inline(plain) or "plus-topic-box-heading" in plain or "List fixture News" not in titles(plain):
            raise RuntimeError("Combining all topic groups changed the scope or hid the pinned news")
        fixture("snapshot")
        command = ("bin/phpbbcli.php", "phpbb2plus:recovery:news", db, "--source-prefix=layout_src_")
        dry = json.loads(php(*command, cwd=web, capture=True))
        if dry != {"eligible": 1, "restored": 0, "already": 0, "skipped": 3}:
            raise RuntimeError(f"Unexpected dry-run source decisions {dry}")
        applied = json.loads(php(*command, "--apply", cwd=web, capture=True))
        repeated = json.loads(php(*command, "--apply", cwd=web, capture=True))
        if applied != dict(dry, restored=1) or repeated != dict(dry, eligible=0, already=1) or "List fixture News" not in titles(guest.fetch(path + "&st=1")):
            raise RuntimeError("Restoring from the source snapshot changed an explicitly edited topic or was not repeatable")
        for action in ("make_sticky", "make_normal"):
            topic = admin.fetch("viewtopic.php?t=985732")
            url = next(unescape(link) for link in re.findall(r'<a href="([^"]+)"', topic) if "action=" + action in link and "quickmod" in link)
            confirm = admin.fetch(url)
            target, fields = Page(confirm).forms["confirm"]
            fields["confirm"] = unescape(re.search(r'name="confirm" value="([^"]+)"', confirm)[1])
            admin.fetch(urljoin(urljoin(admin.base, url), target), fields)
        fixture("unmarked")
        if json.loads(php(*command, "--apply", cwd=web, capture=True)) != dict(dry, eligible=0, already=0, skipped=4):
            raise RuntimeError("Recovery overrode a later moderator's real type change")
        edit = "posting.php?mode=edit&f=29852&p=985738"
        for category, phase in (("2", "published"), ("0", "unpublished")):
            form = admin.fetch(edit)
            if "plus_news_category" not in FormFields(form, "postform").values:
                raise RuntimeError("Authorized native news publisher missing from temporary forum")
            result = submit_form(admin, edit, form, "postform", {"plus_news_category": category, "post": "1",
                                 "message": "Temporärer Testbeitrag zur normalen Veröffentlichung."})
            if 'id="postform"' in result:
                messages = re.findall(r'<[^>]+class="[^"]*(?:error|rules)[^"]*"[^>]*>(.*?)</(?:div|p)>', result, re.S)
                raise RuntimeError("Native posting form did not save: " + " ".join(re.sub(r'<[^>]+>', ' ', value).strip() for value in messages)[:360])
            fixture(phase)
    finally:
        fixture("clean")
