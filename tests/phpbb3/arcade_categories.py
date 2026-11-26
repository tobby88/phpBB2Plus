"""Check the category overview through the converted board and its ordinary routes."""
import http.client
import re
from html import unescape
from urllib.parse import parse_qs, urljoin, urlsplit

from gallery import png
from http_board import Browser


def category_row(page, category):
    match = re.search(r'<tr\b[^>]*\bid="arc-cat-' + str(category) + r'"[^>]*>(.*?)</tr>', page, re.S)
    if not match:
        raise RuntimeError("Missing Arcade category row " + str(category))
    return match[1]


def visit_link(base, method):
    target = urlsplit(urljoin(base, "app.php/arcade/category/902002"))
    if target.hostname != "127.0.0.1":
        raise ValueError("The test server must be on loopback")
    connection = http.client.HTTPConnection(target.hostname, target.port, timeout=15)
    try:
        connection.request(method, target.path)
        response = connection.getresponse()
        if (response.status != 302 or response.getheader("Location") != "https://example.invalid/archive?x=1&y=2"
                or response.getheader("Referrer-Policy") != "no-referrer" or "no-store" not in response.getheader("Cache-Control", "")):
            raise RuntimeError("The stored Arcade category link did not resolve as configured")
        response.read()
    finally:
        connection.close()


def charts(guest, member, directory, change):
    def section(page, key):
        match = re.search('<section[^>]*id="arc-' + key + r'"[^>]*>(.*?)</section>', page, re.S)
        if not match:
            raise RuntimeError("Missing Arcade statistics section " + key)
        return match[1]

    change("charts")
    public = guest.fetch(directory)
    club = member.fetch(directory)
    popular = section(public, "chart-popular")
    recent = section(public, "chart-recent")
    if (popular.count("<li>") != 2 or popular.index("Open fixture game") > popular.index("Second public fixture")
            or recent.index("Second public fixture") > recent.index("Open fixture game") or '/game/902010' in popular
            or '/game/902014' not in popular or "Private fixture game" in popular or "Offline fixture game" in popular):
        raise RuntimeError("Public game charts lost their configured limit, order or guest availability")
    if "Private fixture game" not in section(club, "chart-popular"):
        raise RuntimeError("The group member cannot see the popular game from the private child category")
    category = directory + "/category/902000"
    direct = member.fetch(category)
    if ("Private fixture game" in section(direct, "chart-popular") or "Second public fixture" not in section(direct, "chart-recent")
            or "arc-record-current" in direct):
        raise RuntimeError("The category charts included another category or the board-wide podiums")
    for period in ("current", "all"):
        member_records = section(club, "record-" + period)
        public_records = section(public, "record-" + period)
        if "DepartingFixture" not in member_records or "4 first places" not in member_records:
            raise RuntimeError("Incorrect member-visible " + period + " leader: " + re.sub(r'<[^>]+>', '', member_records).strip())
        if "DepartingFixture" in public_records or "/awards" in public_records:
            raise RuntimeError("Public podiums included private games or a personal link")
        links = [urlsplit(unescape(url)) for url in re.findall(r'href="([^"]+)"', member_records)]
        suffix = r'/awards(?:/current)?$' if period == "current" else r'/awards/all$'
        places = [parse_qs(url.query).get("place") for url in links if re.search(suffix, url.path)]
        if places != [["1"], ["2"], ["3"]]:
            raise RuntimeError("Incorrect personal podium links for " + period + ": " + repr(places))
    change("least")
    least = guest.fetch(directory)
    if "Open fixture game" in section(least, "chart-least") or "arc-chart-recent" in least or "arc-record-all" in least:
        raise RuntimeError("The alternative least-played list or all-time switch was ignored")
    if "<li>" in section(member.fetch(category), "chart-least"):
        raise RuntimeError("Child games or an offline game satisfied the category threshold")
    change("eligible-least")
    if section(member.fetch(category), "chart-least").count("<li>") != 2:
        raise RuntimeError("A category above its least-played threshold did not show its games")
    change("stats-off")
    if "arc-chart-popular" in member.fetch(directory):
        raise RuntimeError("The Arcade statistics switch left its homepage panels enabled")


def browse(base, founder, member, pending, change):
    guest = Browser(base)
    directory = "app.php/arcade"
    route = directory + "/category/"
    location = lambda visitor: category_row(visitor.fetch(directory), 902000)
    change("seed")
    try:
        public = guest.fetch(directory)
        global_row = category_row(public, -1)
        if "Alle Spiele" not in global_row or 'href="#arc-games"' not in global_row or "25</td>" not in global_row:
            raise RuntimeError("The imported all-games summary is missing")
        row = category_row(public, 902000)
        if ("18.446.744.073.709.551.615" not in row or "HistoricMember" not in row or "17</td>" not in row
                or "Private fixture game" in row or "arc-cat-902003" in public or "arc-cat-902004" in public
                or 'href="https://example.invalid/archive' in public):
            raise RuntimeError("Incorrect public category totals, moderator or visibility")
        if ("Private fixture game" not in location(member) or "Private fixture game" in location(pending)
                or "Members subcategory" not in member.fetch(route + "902000")
                or "Members subcategory" in guest.fetch(route + "902000")):
            raise RuntimeError("Inherited category membership was lost from the last play or child directory")
        if "18,446,744,073,709,551,615" not in location(member):
            raise RuntimeError("The English Arcade play count lost precision or its separators")
        if (guest.fetch(route + "10/icon", raw=True) != png(16, 12, b"\x40\x80\xa0")
                or member.fetch(route + "12/icon", raw=True) != png(11, 13, b"\x31\x80\x50")):
            raise RuntimeError("Original category artwork was not imported")
        guest.fetch(route + "12/icon", expected_status=404)
        pending.fetch(route + "12/icon", expected_status=404)
        founder.fetch(route + "13/icon", expected_status=404)
        guest.fetch(route + "902003", expected_status=404)
        guest.fetch(route + "902004", expected_status=404)
        visit_link(base, "HEAD")
        visit_link(base, "GET")
        change("verify-visits")
        visit_link(base, "GET")
        if "18.446.744.073.709.551.615" not in category_row(guest.fetch(directory), 902002):
            raise RuntimeError("The link counter exceeded its original unsigned range")
        change("public")
        row = location(guest)
        if ("Open fixture game" not in row or "/arcade/game/902010" not in row or "Verborgener Spieler" not in row
                or "DepartingFixture" in row or "DepartingFixture" not in location(founder) or "DepartingFixture" not in location(pending)):
            raise RuntimeError("Hidden player visibility changed in the category overview")
        change("former")
        if "Ehemaliger Nutzer" not in location(guest) or "Former member" not in location(member):
            raise RuntimeError("A removed last player was presented as a regular guest")
        change("guest")
        row = location(guest)
        if ("Offline fixture game" not in row or "Gast" not in row or "/arcade/game/902012" in row
                or "/arcade/game/902012" not in location(founder)):
            raise RuntimeError("An offline game or guest play lost its original presentation")
        charts(guest, member, directory, change)
        for phase, title in (("incomplete", "Offline fixture game"), ("moved", "Öffentliches Denkspiel"), ("duplicate", "Open fixture game")):
            change(phase)
            if title in location(guest) or title in location(founder):
                raise RuntimeError("An unresolved historical last play was attributed to the category: " + phase)
    finally:
        change("cleanup")
