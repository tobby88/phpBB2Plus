import re
from html import unescape
from urllib.parse import urljoin

from http_board import Browser, admin_url


def browse(base, owner):
    guest = Browser(base)
    index = guest.fetch("index.php")
    if "Recovered public room" not in index or "Private child fixture" in index or "Private loop fixture" in index:
        raise RuntimeError("The recovered branches did not retain their original guest permissions")
    if "Recovered public room" not in guest.fetch("viewforum.php?f=51"):
        raise RuntimeError("The public forum lost its original native forum ID")
    if "Private child fixture" not in owner.fetch("viewforum.php?f=52") or "Private loop fixture" not in owner.fetch("viewforum.php?f=53"):
        raise RuntimeError("An administrator cannot read the private branches after recovery")
    if "Wiederfundprobe" not in owner.fetch("viewtopic.php?p=14720"):
        raise RuntimeError("The original post bookmark no longer opens the recovered content for an administrator")
    listing = guest.fetch("viewforum.php?f=14")
    if any(title in listing for title in ("Former global fixture", "Held shadow fixture", "Grüße im Archiv")):
        raise RuntimeError("A public forum listed content that lost its original forum")
    if "Wiederhergestellte Themen" in index:
        raise RuntimeError("A guest can see the recovery forum on the index")
    denied = guest.fetch("viewtopic.php?p=14720")
    if "Wiederfundprobe" in denied or "Grüße im Archiv" in denied:
        raise RuntimeError("The native post bookmark disclosed a recovered post to a guest")


def administration(owner, damaged=True):
    backup = admin_url(owner) + "&i=acp_database&mode=backup"
    menu = owner.fetch(backup)
    link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Reparierte Forenstruktur</span>', menu)
    if not link:
        raise RuntimeError("Database maintenance does not offer the structure review")
    panel = urljoin(backup, unescape(link.group(1)))
    forums = owner.fetch(panel)
    if 'id="plus-structure"' not in forums:
        raise RuntimeError("The structure review did not open")
    if not damaged:
        if "Für diesen Bereich sind keine Reparaturen verzeichnet." not in forums:
            raise RuntimeError("An intact conversion reported reassigned forums")
        return
    if "Kategorie 800" not in forums or "Kreis in der Forenstruktur" not in forums:
        raise RuntimeError("The ACP does not show the missing category and the broken cycle")
    topics = owner.fetch(panel + "&section=topics")
    if "Forum 60002 (Globale Bekanntmachung)" not in topics or "Thema 14880" not in topics:
        raise RuntimeError("A recovery lost its original announcement scope or topic number in the ACP")
    posts = owner.fetch(panel + "&section=posts")
    if ("Forum 14, Thema 14880" not in posts or "#p14720" not in posts or "#p14723" in posts or
            "Beitrag 14723" not in posts or "Wiederfundprobe" in posts or "Erased recovery fixture" in posts):
        raise RuntimeError("The ACP post review lost a mapping or displayed private or erased contents")
