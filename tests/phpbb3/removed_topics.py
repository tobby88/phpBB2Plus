"""Fully erased legacy threads retain links and positions without approving native content."""
import re
from html import unescape
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin
from http_board import Browser, Page
from legacy_links import link


def positions(page):
    return [int(post) for post in re.findall(r'class="post bg2 plus-removed-post" id="p(\d+)"', page)]


def inaccessible(actor, path):
    try:
        with actor.opener.open(urljoin(actor.base, path)) as response:
            page = response.read().decode("utf-8")
    except HTTPError as error:
        if error.code not in (403, 404):
            raise
        page = error.read().decode("utf-8")
    if (positions(page) or 'class="post removed"' in page or "\r\nGelöschter Beitrag\r\n" in page
            or "UnrecoverableMarkerFixture" in page or "Native recoverable marker fixture" in page):
        raise RuntimeError("A private or unregistered removed topic was disclosed")


def browse(base, owner, member, fixture):
    guest = Browser(base)
    fixture("markers-import")
    for reader in (guest, member):
        try:
            with reader.opener.open(urljoin(base, "download/file.php?id=48")) as response:
                if response.headers.get_content_type().startswith("image/") or response.read(6) in (b"GIF87a", b"GIF89a"):
                    raise RuntimeError("An erased post still distributed its residual source attachment")
        except HTTPError as error:
            if error.code not in (403, 404):
                raise
    for reader, title, caption in ((guest, "Gelöschtes Thema", "Gelöschter Beitrag"), (member, "Deleted topic", "Deleted post"),
                                    (owner, "Gelöschtes Thema", "Gelöschter Beitrag")):
        page = reader.fetch("viewtopic.php?t=805100")
        first = positions(page)
        if (not first or first[0] != 805200 or len(first) >= 42 or f"{title}</h2>" not in page
                or page.count(f'class="notice">{caption}</p>') != len(first) or "UnrecoverableMarkerFixture" in page):
            raise RuntimeError(f"The public historical topic did not retain localized, anonymous positions: {first[:8]}")
        if (805241 not in positions(reader.fetch("viewtopic.php?p=805241")) or 805241 not in positions(reader.fetch("viewtopic.php?t=805100&sd=d"))
                or f"t=805100" not in reader.fetch("viewforum.php?f=15") or title not in reader.fetch("viewforum.php?f=15&st=7")):
            raise RuntimeError("Historical forum navigation, sorting, pagination or post bookmarks lost the locked topic")
        printed = reader.fetch("viewtopic.php?p=805241&view=print")
        archive = reader.fetch("export.php?p=805241")
        if (printed.count('class="post removed"') != 42 or "UnrecoverableMarkerFixture" in printed
                or archive.count(caption) != 42 or title not in archive or "UnrecoverableMarkerFixture" in archive):
            raise RuntimeError("A historical-topic output lost positions or exposed former text")
    for target in ("viewtopic.php?t=805101", "viewtopic.php?p=805250", "app.php/topic/805101/removed", "export.php?p=805250"):
        inaccessible(guest, target)
    if positions(owner.fetch("viewtopic.php?t=805101")) != [805250]:
        raise RuntimeError("An authorized administrator lost the marker in a private forum")
    single = owner.fetch("viewtopic.php?p=31&view=show")
    row = re.search(r'<div id="p31" class="post has-profile(.*?)(?:<div id="p\d+" class="post has-profile|</main>|<!-- EVENT)', single, re.S)
    if not row or 'class="plus-erased-caption">Gelöschter Beitrag' not in row[1] or 'view=show' in row[1] or 'AliasFixture' in row[1]:
        raise RuntimeError("The native moderator page offered to expand a historically erased post")
    healthy = owner.fetch("viewtopic.php?p=805300")
    file_link = re.search(r'''(?:href|src)="([^"]*download/file\.php\?id=\d+[^"]*)"''', healthy)
    if not file_link:
        raise RuntimeError("The attachment's other, healthy source association disappeared")
    if not owner.fetch(unescape(file_link[1]), raw=True).startswith(b"GIF"):
        raise RuntimeError("The shared synthetic attachment lost its healthy bytes")
    for reader in (guest, owner):
        for query in ("search.php?" + urlencode({"keywords": "UnrecoverableMarkerFixture", "sr": "posts"}),
                      "search.php?search_id=active_topics", "search.php?search_id=newposts&sr=posts", "statistics.php", "portal.php"):
            page = reader.fetch(query)
            if re.search(r'(?:viewtopic\.php\?[^"]*\bt=|/topic/)(?:805100|805101)\b', page):
                raise RuntimeError(f"An erased topic reached a search or ranking at {query}")
    fixture("markers-deny")
    try:
        read = member.fetch("viewtopic.php?t=805100")
        if not positions(read) or "/topic/805100/export.txt" in read:
            raise RuntimeError("A print restriction changed ordinary reading of a historical topic")
        member.fetch("export.php?t=805100", expected_status=403)
        member.fetch("viewtopic.php?t=805100&view=print", expected_status=403)
    finally:
        fixture("markers-allow")
    try:
        fixture("markers-upgrade")
    fixture("markers-prune")
        for target in ("app.php/topic/805102/removed", "app.php/topic/805103/removed", "app.php/topic/805102/print",
                       "export.php?t=805103", "viewtopic.php?t=805102"):
            inaccessible(guest, target)
        if not positions(guest.fetch("viewtopic.php?t=805100")):
            raise RuntimeError("An existing conversion lost its documented erased topic during upgrade")
    finally:
        fixture("markers-cleanup")


def password(base):
    guest = Browser(base)
    path = "app.php/post/805241/removed"
    link(base, "viewtopic.php?p=805241", path, {})
    locked = guest.fetch(path)
    action, fields = Page(locked).forms["login_forum"]
    if positions(locked) or "Gelöschtes Thema" in locked:
        raise RuntimeError("The password prompt disclosed the historical topic")
    opened = guest.fetch(urljoin(urljoin(base, path), action), dict(fields, password="local-print-room"))
    if 805241 not in positions(opened) or positions(Browser(base).fetch(path)):
        raise RuntimeError("A historical post bookmark did not respect per-session forum authorization")
