"""Check topic positions through the native page, permission and sort controls."""
import re
from urllib.parse import urlencode
from http_board import Browser


def converted(base, member, founder):
    marker = r'class="post bg2 plus-removed-post" id="p31">\s*<p class="notice">([^<]+)'
    for viewer, caption in ((Browser(base), "Gelöschter Beitrag"), (member, "Deleted post")):
        page = viewer.fetch("viewtopic.php?t=1")
        found = re.search(marker, page)
        if not found or found[1] != caption or "AliasFixture" in page:
            raise RuntimeError("A migrated deletion lost its anonymous position in the topic")
    moderation = founder.fetch("viewtopic.php?t=1")
    if re.search(marker, moderation) or 'id="p31"' not in moderation:
        raise RuntimeError("A placeholder duplicated or displaced the native moderator row")


def native(base, data):
    visitor = Browser(base)
    topic, posts = data["topic"], data["posts"]
    path = f"viewtopic.php?t={topic}"
    marker = "plus-removed-post"
    for direction, first, second in (("a", [posts[0], "two", posts[3], posts[4]], [posts[5]]),
                                     ("d", [posts[5], posts[4], posts[3], "two"], [posts[0]])):
        for start, expected in ((0, first), (2, second)):
            page = visitor.fetch(path + "&" + urlencode({"sd": direction, "start": start}))
            entries = re.findall(r'<div id="p(\d+)" class="post has-profile|class="post bg2 plus-removed-post"(?: id="p(\d+)")?', page)
            actual = [int(visible or removed) if visible or removed else "two" for visible, removed in entries]
            if actual != expected or ('2 gelöschte Beiträge' in page) != ("two" in expected):
                raise RuntimeError(f"Deletion positions changed with sorting or pagination: {actual}, expected {expected}")
            if any(text in page for text in ("Position fixture Removed", "Never published", "Fixture moderation note")):
                raise RuntimeError("A public placeholder included deleted content or a moderation note")
    subjects = visitor.fetch(path + "&sk=s")
    if subjects.count(marker) != 1 or "3 gelöschte Beiträge" not in subjects or marker in visitor.fetch(path + "&sk=s&start=2"):
        raise RuntimeError("A subject-sorted topic repeated or attributed removed posts")
    hits = visitor.fetch("search.php?" + urlencode({"author": "caseguestnat*", "sr": "posts"}))
    if "CaseGuestNative" not in hits or "Position fixture Removed" in hits or "Never published" in hits:
        raise RuntimeError("A new named guest or a removed post had the wrong public search visibility")


def restored(base, data):
    page = Browser(base).fetch(f'viewtopic.php?t={data["topic"]}&start=2')
    if ("Position fixture Removed three body" not in page or "plus-removed-post" in page
            or "Never published" in page):
        raise RuntimeError("Native restoration did not replace the marker with the approved post")
