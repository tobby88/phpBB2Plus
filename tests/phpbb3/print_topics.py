"""Print whole converted topics through their original links and native controls."""
from html import unescape
from http.client import HTTPConnection
import re
from urllib.parse import urljoin, urlsplit

from http_board import Browser, Page
from legacy_links import link
import topic_exports


def posts(page):
    if not page.rstrip().endswith("</html>"):
        raise RuntimeError("The printable topic ended before the final post")
    return [int(value) for value in re.findall(r'<article class="post" id="p([0-9]+)">', page)]


def browse(base, owner, member, fixture):
    guest = Browser(base)
    path = "app.php/topic/981010/print"
    fixture("setup")
    try:
        link(base, "printview.php?topic=981010&start=40&postdays=364&postorder=desc",
             path, {"postdays": 364, "postorder": "desc"})
        link(base, "printview.php?t=981010&topic=7&start=70", path, {}, method="HEAD")
        link(base, "printview.php?t=981010&postdays=365", path, {})
        guest.fetch("printview.php?t=0&topic=1", expected_status=404)
        guest.fetch("printview.php", {"t": "981010"}, expected_status=404)
        whole = guest.fetch("printview.php?topic=981010&start=60")
        published = list(range(982000, 982068)) + [982071]
        fixture("text-limits")
        fixture("text-snapshot")
        topic_exports.downloads(base, guest, owner, member, published)
        if (posts(whole) != published or whole.count('class="post removed"') != 1 or "Print &amp; Grüße" not in whole
                or not re.search(r'<strong[^>]*>Print body 982064</strong>', whole)):
            raise RuntimeError("A full-topic print lost old posts, stable ordering, formatting or the deletion marker")
        for page in (whole, member.fetch(path), owner.fetch(path)):
            if any(text in page for text in ("Print body 982068", "Print subject 982068", "Print body 982069", "Print subject 982069")):
                raise RuntimeError("A printed deletion displayed content or a historical byline")
        if "Print body 982070" not in owner.fetch(path) or "Print body 982070" in whole:
            raise RuntimeError("Pending posts did not follow native approval access")
        sheet = re.search(r'<link rel="stylesheet" href="([^"]+/print\.css)"', whole)
        if not sheet or not guest.fetch(urljoin(urljoin(base, path), unescape(sheet.group(1)))).strip():
            raise RuntimeError("The printable template has no active style print stylesheet")
        fixture("ignore")
        try:
            ignored = member.fetch(path)
            if "Print body" in ignored or 'class="post ignored"' not in ignored or 'class="post removed"' not in ignored:
                raise RuntimeError("Printing uncovered a writer ignored by the signed-in reader")
            if "Print body" in member.fetch("export.php?t=981010"):
                raise RuntimeError("A saved topic uncovered an ignored writer")
        finally:
            fixture("unignore")
        filtered = guest.fetch("printview.php?t=981010&postdays=1&postorder=asc", {"postdays": "7", "postorder": "desc", "t": "7"})
        if posts(filtered) != list(reversed(published[3:])) or posts(guest.fetch(path + "?postdays=364")) != published[2:]:
            raise RuntimeError("The old seven-day or 52-week filter lost its POST precedence or time boundary")
        topical = guest.fetch("viewtopic.php?t=981010&start=20&st=7&sd=d")
        options = [unescape(href) for href in re.findall(r'href="([^"]*/topic/981010/print[^"]*)"', topical)]
        if not options or posts(guest.fetch(options[0])) != list(reversed(published[3:])):
            raise RuntimeError("The normal topic print button lost the time range or printed only one screen")
        native = guest.fetch("viewtopic.php?t=981010&view=print&start=20")
        if "Print body 982000" in native or "Print body" not in native:
            raise RuntimeError("The existing single-page phpBB print view changed")
        old, signed = guest.fetch("printview.php?t=1"), member.fetch("printview.php?t=1")
        if ("RetiredFixture" in old or "EditorFixture" in old or "Ehemaliger Nutzer" not in old or "GuestQuoter" not in old
                or "RetiredFixGuest" not in old or "RetiredFixture" not in signed or "EditorFixture" not in signed):
            raise RuntimeError("A former author's printable byline or quote ignored guest anonymity")
        for url in ("portal.php", "app.php/news"):
            card = re.search(r'<article[^>]*data-news-topic="1".*?</article>', guest.fetch(url), re.S)
            if not card or "/topic/1/print" not in card.group(0):
                raise RuntimeError("An imported news article has no working print link")
        if ("Private fixture text" not in owner.fetch("printview.php?t=7") or "Private fixture text" in guest.fetch("printview.php?t=7")
                or "Reading circle fixture 10" not in member.fetch("printview.php?t=490")):
            raise RuntimeError("Printing lost the native forum access or the combined historical reading circles")
        guest.fetch("printview.php?t=981011", expected_status=404)
        guest.fetch("printview.php?t=981012", expected_status=404)
        guest.fetch("printview.php?t=511", expected_status=404)
        fixture("deny")
        try:
            for topic in (1, 490):
                member.fetch(f"printview.php?t={topic}", expected_status=403)
                member.fetch(f"viewtopic.php?t={topic}&view=print", expected_status=403)
                member.fetch(f"export.php?t={topic}", expected_status=403)
                if f"/topic/{topic}/print" in member.fetch(f"viewtopic.php?t={topic}"):
                    raise RuntimeError("A normal topic retained its forbidden print button")
            if "/topic/1/print" in member.fetch("portal.php"):
                raise RuntimeError("A news card advertised a forbidden print view")
        finally:
            fixture("restore")
        fixture("before-print-import")
        try:
            member.fetch("printview.php?t=490", expected_status=403)
            member.fetch("printview.php?t=1", expected_status=403)
            if "Reading circle fixture 10" not in member.fetch("viewtopic.php?t=490") or not posts(guest.fetch("printview.php?t=1")):
                raise RuntimeError("An earlier conversion lost native reading rights")
        finally:
            fixture("after-print-import")
        fixture("password")
        topic_exports.password(base, "app.php/topic/981010/export.txt")
        locked = guest.fetch(path)
        action, fields = Page(locked).forms["login_forum"]
        if "Print body" in locked or "Print &amp; Grüße" in locked:
            raise RuntimeError("The password prompt exposed the printed topic")
        unlocked = guest.fetch(urljoin(urljoin(base, path), action), dict(fields, password="local-print-room"))
        if posts(unlocked) != published or posts(guest.fetch(path)) != published or "Print body" in Browser(base).fetch(path):
            raise RuntimeError("The native forum password did not unlock this printer for this session alone")
    finally:
        fixture("cleanup")
    origin = urlsplit(base)
    connection = HTTPConnection(origin.hostname, origin.port, timeout=30)
    try:
        connection.request("HEAD", "/app.php/topic/1/print")
        reply = connection.getresponse()
        if reply.status != 200 or "no-store" not in reply.getheader("Cache-Control", "") or reply.read():
            raise RuntimeError("The printable archive is cacheable or HEAD rendered the post bodies")
    finally:
        connection.close()
