"""Read both cross-game rankings through their native and historical entry points."""
import json
import re
import tempfile
from html import unescape
from pathlib import Path
from urllib.parse import urljoin
from gallery import png
from http_board import Browser, Page


def browse(base, founder, member, pending, change):
    guest = Browser(base)
    archive = "app.php/arcade/monthly"
    points = "app.php/arcade/points"
    directory = "app.php/arcade"

    def table(page):
        rows = {}
        for rank, byline, monthly, records, total in re.findall(
                r"<td>(\d+)</td><td>(.*?)</td><td>(\d+)</td><td>(\d+)</td><td><strong>(\d+)</strong></td>", page, re.S):
            name = unescape(re.sub(r"<[^>]+>", "", byline))
            rows[name] = (int(rank), int(monthly), int(records), int(total))
        return rows

    when = json.loads(change("seed"))
    try:
        public = guest.fetch(directory)
        if "/arcade/monthly" not in public or "/arcade/points" in public or "/arcade/points" not in member.fetch(directory):
            raise RuntimeError("The two Arcade summaries lost their distinct guest and member navigation")
        if "login" not in Page(guest.fetch("arcade_point_scores.php")).forms:
            raise RuntimeError("The registered-only points standings did not lead guests to login")
        may = guest.fetch("arcade_highscore.php", {"month": "2008-05"})
        if ("Mai 2008" not in may or "96,0004" not in may or "HistoricMember" not in may or
                "AlterSpielname" in may or 'value="1999-09"' in may or "/arcade/game/60" not in may):
            raise RuntimeError("The old month selector lost its historical winner, precision, name or scope")
        latest = guest.fetch(archive + "?month=" + when["current"])
        if (latest.count(">Öffentliches Denkspiel</a>") != 1 or "21,0001" not in latest or "0,02" not in latest
                or "Vertrauliche Spielprobe" in latest or "Abgeschaltetes Spiel" in latest):
            raise RuntimeError("The monthly gallery chose the wrong winner, duplicated a game or exposed a closed category")
        previous = guest.fetch("arcade_highscore.php?date=1")
        if (when["previous"] not in previous or "Ehemaliger Nutzer" not in previous or "EditorFixture" in previous
                or "EditorFixture" not in member.fetch(archive + "?month=" + when["previous"])):
            raise RuntimeError("The relative-month bookmark or former champion's byline changed")
        totals = table(pending.fetch("arcade_point_scores.php"))
        if totals != {"ModernMember": (1, 2, 1, 6), "HistoricMember": (2, 1, 1, 5), "EditorFixture": (3, 1, 0, 1)}:
            raise RuntimeError("Unexpected public-category points standings: " + repr(totals))
        privileged = table(founder.fetch(points))
        if privileged.get("ModernMember") != (2, 3, 1, 7) or privileged.get("HistoricMember") != (1, 1, 2, 9):
            raise RuntimeError("Inherited category access, retired all-time scores or tied record ordering changed: " + repr(privileged))
        if 'value="1999-09"' not in member.fetch(archive):
            raise RuntimeError("The category member lost the private historical month in the selector")
        change("duplicate")
        if "Öffentliches Denkspiel" in guest.fetch(archive) or table(pending.fetch(points)).get("ModernMember") != (3, 1, 0, 1):
            raise RuntimeError("Two games sharing a historical score key were attributed to a public game")
        change("hide")
        guest.fetch(archive, expected_status=404)
        if table(founder.fetch(points)) or "/arcade/monthly" in founder.fetch(directory):
            raise RuntimeError("A disabled scoreboard remained in the directory or awarded points")
    finally:
        change("cleanup")


def artwork(base, member, pending, recover, reimport):
    guest = Browser(base)
    path = "app.php/arcade/game/"
    puzzle = png(12, 8, b"\x22\x77\xaa")
    clock = png(10, 10, b"\x44\x88\x33")
    with guest.opener.open(urljoin(base, path + "60/icon"), timeout=15) as response:
        if (response.headers.get_content_type() != "image/png" or "no-store" not in response.headers.get("Cache-Control", "")
                or response.read() != puzzle):
            raise RuntimeError("An imported Arcade picture lost its bytes, content type or access-aware cache policy")
    fallback = member.fetch(path + "64/icon", raw=True)
    if (guest.fetch(path + "61/icon", raw=True) != clock
            or member.fetch(path + "62/icon", raw=True) != png(8, 12, b"\x96\x34\x68")
            or not fallback.startswith(b"GIF8")):
        raise RuntimeError("Shared, restricted or default game artwork did not follow the original paths")
    guest.fetch(path + "62/icon", expected_status=404)
    pending.fetch(path + "64/icon", expected_status=404)
    for relative in ("app.php/arcade", path + "60", "arcade_highscore.php?month=2008-05"):
        if not re.search(r'<img[^>]*src="[^"]*/arcade/game/60/icon', guest.fetch(relative)):
            raise RuntimeError("Game artwork is missing on " + relative)
    recover()
    if guest.fetch(path + "60/icon", raw=True) != puzzle:
        raise RuntimeError("The extension upgrade lost the picture stored by an earlier conversion")
    guest.fetch(path + "61/icon", expected_status=404)
    category = "app.php/arcade/category/"
    guest.fetch(category + "10/icon", expected_status=404)
    for _ in range(2):
        summary = reimport()
        if ("Verified artwork for 5 of 5 games" not in summary or "Verified artwork for 2 of 3 configured category pictures" not in summary
                or guest.fetch(path + "61/icon", raw=True) != clock or guest.fetch(category + "10/icon", raw=True) != png(16, 12, b"\x40\x80\xa0")
                or member.fetch(category + "12/icon", raw=True) != png(11, 13, b"\x31\x80\x50")):
            raise RuntimeError("Restoring missing original artwork from the offline board was not repeatable")
    with tempfile.TemporaryDirectory() as incomplete:
        default = Path(incomplete) / "templates/fisubsilversh/images/games.gif"
        default.parent.mkdir(parents=True)
        default.write_bytes(fallback)
        if ("Verified artwork for 2 of 5 games" not in reimport(incomplete)
                or guest.fetch(path + "60/icon", raw=True) != puzzle or guest.fetch(path + "61/icon", raw=True) != clock
                or member.fetch(path + "62/icon", raw=True) != png(8, 12, b"\x96\x34\x68")
                or member.fetch(category + "12/icon", raw=True) != png(11, 13, b"\x31\x80\x50")):
            raise RuntimeError("An incomplete backup replaced individual game pictures with the shared default")
