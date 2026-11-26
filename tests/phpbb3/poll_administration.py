import re
from html import unescape
from urllib.parse import urljoin

from http_board import admin_url


def browse(owner):
    backup = admin_url(owner) + "&i=acp_database&mode=backup"
    menu = owner.fetch(backup)
    link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Übernommene Umfragen prüfen</span>', menu)
    if not link:
        raise RuntimeError("The poll review module is missing from native database maintenance")
    panel = urljoin(backup, unescape(link.group(1)))
    listing = owner.fetch(panel)
    if 'id="plus-held-polls"' not in listing or "Doppelte Antwort" in listing or "Mehrere Antworten tragen dieselbe Nummer." not in listing:
        raise RuntimeError("The ACP poll overview lost its reasons or displayed the private question in the list")
    def detail(source):
        target = re.search(r'<a href="([^"]+&amp;poll=' + str(source) + r')">', listing)
        if not target:
            raise RuntimeError("The poll details link is missing")
        return urljoin(panel, unescape(target.group(1)))
    duplicate = owner.fetch(detail(14401))
    if ("Doppelte Antwort 📷" not in duplicate or "<td>Erste &lt;Gruppe&gt; &amp; 📷</td><td>7</td>" not in duplicate
            or "<td>Zweite</td><td>19</td>" not in duplicate or "192.0.2.3" in duplicate):
        raise RuntimeError("The ACP did not display the original ballot safely with both distinct options and their totals")
    large = detail(14407)
    first, last = owner.fetch(large), owner.fetch(large + "&start=120")
    if "<td>Option 30</td>" not in first or "<td>Option 31</td>" in first or "<td>Option 127</td>" not in last or "<td>Option 30</td>" in last:
        raise RuntimeError("A large held ballot lost its native ACP pagination")
