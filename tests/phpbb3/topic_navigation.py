"""Follow the fiSubSilver topic links as a normal guest, skipping pending neighbours."""
import re
from html import unescape


def browse(guest, fixture):
    fixture("seed")
    try:
        center = guest.fetch("viewtopic.php?t=985702")
        for direction, label, destination in (("previous", "Vorheriges Thema", 0), ("next", "Nächstes Thema", 4)):
            links = re.findall(r'<a href="([^"]*\bview=' + direction + r'[^"]*)" rel="(?:prev|next)">(.+?)</a>', center)
            if len(links) != 2 or any(label.casefold() not in text.casefold() for _, text in links):
                raise RuntimeError(f"The fiSubSilver topic lacks the {direction} link above or below its posts")
            page = guest.fetch(unescape(links[0][0]))
            if not re.search(r'<h2 class="topic-title">\s*<a[^>]*>Topic navigation ' + str(destination) + r'</a>', page):
                raise RuntimeError(f"Following {direction} did not open the adjacent visible topic")
    finally:
        fixture("clean")
