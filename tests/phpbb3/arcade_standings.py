"""Read both cross-game rankings through their native and historical entry points."""
import json
import re
from html import unescape
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
