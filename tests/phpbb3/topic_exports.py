"""Read a complete UTF-8 text download through phpBB2 bookmarks and native topic tools."""
from html import unescape
import re
from urllib.parse import unquote, urljoin
from urllib.request import Request

from http_board import Browser, Page
from legacy_links import link


def downloads(base, guest, owner, member, published):
    path = "app.php/topic/981010/export.txt"
    link(base, "export.php?mode=txt&t=981010&start=32&postdays=1", path, {})
    link(base, "export.php?topic=981010&postorder=DESC", path, {"postorder": "desc"})
    link(base, "export.php?t=&topic=981010", path, {})
    link(base, "export.php?t=7&p=982064", "app.php/post/982064/topic.txt", {})
    page = guest.fetch("viewtopic.php?t=981010&start=20&st=7&sd=d")
    links = re.findall(r'<a href="([^"]*/topic/981010/export\.txt[^"]*)"[^>]*>', page)
    if not links or "Thema als Textdatei speichern" not in page:
        raise RuntimeError("The full text download is missing from the real topic tools")
    with guest.opener.open(urljoin(base, unescape(links[0]))) as reply:
        raw = reply.read()
        disposition = reply.headers.get("Content-Disposition", "")
        if (reply.status != 200 or reply.headers.get_content_type() != "text/plain" or reply.headers.get_content_charset() != "utf-8"
                or len(raw) != int(reply.headers.get("Content-Length", "-1")) or "private" not in reply.headers.get("Cache-Control", "")
                or "no-store" not in reply.headers.get("Cache-Control", "") or reply.headers.get("X-Content-Type-Options") != "nosniff"
                or 'filename="Print-Gruesse-981010.txt"' not in disposition or "filename*=UTF-8''Print & Grüße 981010.txt" not in unquote(disposition)):
            raise RuntimeError(f"The text download lost its length, Encoding, filenames or private headers: {disposition}")
    text = raw.decode("utf-8")
    if (not text.startswith("Forum:\tContributors\r\nThema:\tPrint & Grüße 981010\r\n")
            or "\n" in text.replace("\r\n", "") or "\r" in text.replace("\r\n", "")
            or [int(id_) for id_ in re.findall(r"Print body ([0-9]+)", text)] != published
            or text.count("Gelöschter Beitrag") != 1 or "Print subject" in text):
        raise RuntimeError("Printed page filters, unpublished posts, headings or newline damage reached the text archive")
    for token in ("Weg (https://example.invalid/archive?a=1&b=2)", "a. Alpha", "b. Beta", "x<&\r\n  Keep &amp; 😀", "Zitatprobe", ":pf:"):
        if token not in text:
            sample = text.partition("Print body 982067")[2].split("Gelöschter Beitrag", 1)[0][:240]
            raise RuntimeError(f"The native formatter lost {token!r} in the synthetic post: {sample!r}")
    if any(fragment in text for fragment in ("EditorFixture", "selectCode", "<code>", "<strong>", "sid=")):
        raise RuntimeError("A quote identity, session parameter or presentation control reached an anonymous export")
    if guest.fetch("export.php?t=7&p=982064", raw=True) != raw or guest.fetch("export.php?p=982068", raw=True) != raw:
        raise RuntimeError("A post bookmark did not export the complete accessible topic")
    descending = guest.fetch("export.php?t=981010&postorder=ASC&postdays=1", {"postorder": "dESc", "p": "40"})
    if [int(id_) for id_ in re.findall(r"Print body ([0-9]+)", descending)] != list(reversed(published)):
        raise RuntimeError("The old POST-only direction changed the scope or failed on tied timestamps")
    public = guest.fetch("export.php?p=32")
    private = member.fetch("export.php?t=1")
    if ("RetiredFixture" in public or "EditorFixture" in public or "RetiredFixGuest" not in public or "Ehemaliger Nutzer" not in public
            or "EditorFixture" not in private or "RetiredFixture" not in private or "Former member fixture" not in public):
        raise RuntimeError("A text byline or quote did not apply the reader's identity policy")
    guest.fetch("export.php?p=982070", expected_status=404)
    guest.fetch("export.php?t=981014", expected_status=404)
    for actor, label in ((guest, "Ehemaliger Nutzer"), (member, "Former member")):
        orphan = actor.fetch("export.php?t=981013")
        if f"{label}:\r\nPrint body 981013" not in orphan or "UnknownRetiredWriter" in orphan:
            raise RuntimeError(f"The orphan fixture for {label!r} returned {orphan[-110:]!r}")
    guest.fetch("export.php?p=982069", expected_status=404)
    guest.fetch("export.php?p=99999990&t=1", expected_status=404)
    guest.fetch("export.php?t=511", expected_status=404)
    if ("Private fixture text" in guest.fetch("export.php?p=40") or "Private fixture text" not in owner.fetch("export.php?p=40")
            or "Print body 982070" not in owner.fetch("export.php?p=982070") or "Print body 982068" in owner.fetch(path)):
        raise RuntimeError("A private forum, moderated post or removed body used the wrong export visibility")
    with guest.opener.open(Request(urljoin(base, path), method="HEAD")) as reply:
        if reply.read() or reply.headers.get("Content-Length") != str(len(raw)):
            raise RuntimeError("HEAD returned text or an inaccurate download length")


def password(base, path):
    guest = Browser(base)
    page = guest.fetch(path)
    action, fields = Page(page).forms["login_forum"]
    if "Print body" in page or "Print &amp; Grüße" in page:
        raise RuntimeError("A passworded text export advertised material before authentication")
    result = guest.fetch(urljoin(urljoin(base, path), action), dict(fields, password="local-print-room"))
    if "Print body 982000" not in result or result != guest.fetch(path) or "Print body" in Browser(base).fetch(path):
        raise RuntimeError("The native forum password did not protect this text download per session")
