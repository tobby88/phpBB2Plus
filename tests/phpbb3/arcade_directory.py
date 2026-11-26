"""Exercise the actual Arcade search and sort forms on an isolated converted board."""
import re
from html import unescape
from urllib.parse import parse_qs, urlencode, urlsplit

from http_board import Browser, Page
from legacy_links import link


def browse(base, member, change):
    guest = Browser(base)
    root = "app.php/arcade"
    category = root + "/category/10"

    def ids(page):
        return [int(value) for value in re.findall(r'<a class="topictitle" href="[^"]*/arcade/game/(\d+)(?:\?[^"]*)?"', page)]

    def search(who, path=category, **query):
        return ids(who.fetch(path + "?" + urlencode(query)))

    limit = int(change("seed"))
    try:
        if (search(guest, search_word="probe & test_100%") != [900101] or search(guest, search_word="%") != [900101]
                or search(guest, search_word="pro", search_type=1) != [900102] or search(guest, search_type=3) != [900101]):
            raise RuntimeError("Arcade title searches lost literal characters, prefixes, digits or the fallback game name")
        if (search(guest, root, search_word="Nur im Verein") or search(member, root, search_word="Nur im Verein") != [900103]
                or search(member, category, search_word="Nur im Verein")):
            raise RuntimeError("Arcade search escaped its category or disagreed with the viewer's group access")
        page = member.fetch(category)
        if "arc-directory-options" not in Page(page).forms or 'value="comments"' not in page or 'value="comments"' in guest.fetch(root):
            raise RuntimeError("The game directory lost its filters or offered a member-only comment count to guests")
        # Compare actual results for all ten original keys, including the two independent aggregates.
        for key, first in {"alphabetical": 900102, "date_added": 900102, "game_played": 900101, "default": 900102,
                           "allow_guest": 900102, "game_instructions": 900102, "game_charge": 900102,
                           "game_bonus": 900101, "rating": 900102, "comments": 900102}.items():
            order = search(member, search_word="Probe", sort_mode=key, order="DESC")
            expected = [900101, 900102] if first == 900101 else [900102, 900101]
            if order != expected:
                raise RuntimeError(f"Incorrect game order for {key}: {order}")
        params = {"search_word": "ListFixture", "sort_mode": "default", "order": "ASC"}
        page = guest.fetch(category + "?" + urlencode(params))
        next_pages = [unescape(url) for url in re.findall(r'href="([^"]+)"', page)
                      if f"start={limit}" in url and "search_word=ListFixture" in url]
        if not next_pages or ids(guest.fetch(next_pages[0])) != [900200 + limit]:
            raise RuntimeError("The second Arcade page lost its scope, search or sort")
        if parse_qs(urlsplit(next_pages[0]).query).get("order") != ["ASC"]:
            raise RuntimeError("The Arcade page link omitted the requested direction")
        link(base, "activity.php?mode=cat&cat_id=10", category, {})
        link(base, "activity.php?mode=search&cat_id=10", category, params, method="POST", form=params, status=303)
        link(base, "activity.php?mode=search&search_type=3&search_word=0-9", root, {"search_type": 3, "search_word": "0-9"})
        if ids(guest.fetch("activity.php?mode=cat&cat_id=10", {"search_word": "probe & test_100%"})) != [900101]:
            raise RuntimeError("The historical search form did not reach the matching game")
    finally:
        change("cleanup")
