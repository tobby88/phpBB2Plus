"""Verify the visible games and native rankings for different viewers."""
import os
import re
import struct
import time
from html import unescape
from urllib.parse import urljoin, urlsplit
from http_board import Browser, Page, login


def movie():
    # A single Flash frame with a coloured background. No ActionScript or third-party content.
    rect = "01100" + "".join(f"{coordinate:012b}" for coordinate in (0, 1600, 0, 900))
    rect += "0" * (-len(rect) % 8)
    body = int(rect, 2).to_bytes(len(rect) // 8, "big") + struct.pack("<HH", 24 << 8, 1)
    body += struct.pack("<H", 9 << 6 | 3) + b"\x22\x77\xaa" + struct.pack("<HH", 1 << 6, 0)
    return b"FWS\x09" + struct.pack("<I", len(body) + 8) + body


def source_media(legacy):
    folder = legacy / "games/puzzle"
    (folder / "levels").mkdir(parents=True, exist_ok=True)
    (folder / "club").mkdir(exist_ok=True)
    (folder / "puzzle.swf").write_bytes(movie())
    (folder / "levels/two.txt").write_text("Spielstufe zwei", encoding="utf-8")
    (folder / "club/private.swf").write_bytes(movie())


def browse(base, administrator, member):
    guest = Browser(base)
    pending = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    root = "app.php/arcade"
    if "Spielhalle" not in guest.fetch("app.php/portal"):
        raise RuntimeError("Arcade is missing from the portal")
    catalogue = guest.fetch(root)
    privileged = member.fetch(root)
    if ("Öffentliches Denkspiel" not in catalogue or "Vertrauliche Spielprobe" in catalogue or "Abgeschaltetes Spiel" in privileged
            or "Vertrauliche Spielprobe" not in privileged or "Persönliche Spielprobe" not in privileged
            or "Abgeschaltetes Spiel" not in administrator.fetch(root)):
        raise RuntimeError("Game availability or Arcade category membership was lost")
    for path in ("/category/12", "/game/62", "/game/62/scores/current", "/game/64"):
        guest.fetch(root + path, expected_status=404)
        pending.fetch(root + path, expected_status=404)
    public = guest.fetch(root + "/game/60")
    if "Aktuelle Bestenliste" not in public or "Ewige Bestenliste" not in public or "Steine &amp; Farben" not in public:
        raise RuntimeError("Distinct scoreboards or decoded instructions missing")
    equal = guest.fetch(root + "/game/61")
    if "Bestenliste</a>" not in equal or "Aktuelle Bestenliste" in equal or "Ewige Bestenliste" in equal:
        raise RuntimeError("Equal scoreboards should share one link")
    current = guest.fetch(root + "/game/60/scores/current")
    if ("95,1234" not in current or "-12,3751" not in current or "HistoricMember" not in current or "AlterSpielname" in current
            or "EditorFixture" in current or "Ehemaliger Nutzer" not in current or current.index("95,1234") > current.index("-12,3751")):
        raise RuntimeError("Ranking order, precision, renamed player or public byline is wrong")
    if "EditorFixture" not in member.fetch(root + "/game/60/scores/current"):
        raise RuntimeError("Members cannot see the retained historical player")
    if "9999999999,9999" not in guest.fetch(root + "/game/60/scores/all") or "2008-05" not in guest.fetch(root + "/game/60/scores/month"):
        raise RuntimeError("Missing all-time or historical monthly score")
    timed = guest.fetch(root + "/game/61/scores/current")
    if timed.index("0,0001") > timed.index("9,75"):
        raise RuntimeError("Lower-is-better games use the wrong ranking")
    return player(base, guest, member, root)


def player(base, guest, member, root):
    path = root + "/game/60"
    action, fields = Page(member.fetch(path)).forms["arcade-play"]
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    target = urljoin(urljoin(base, path), action)
    sources = []
    for _ in range(2):
        html = member.fetch(target, fields)
        obj = re.search(r'id="plus-arc-player" data-source="([^"]+)"', html)
        if not obj or "ruffle/ruffle.js" not in html:
            raise RuntimeError("The game did not load the self-hosted player")
        sources.append(unescape(obj.group(1)))
    if sources[0] != sources[1] or member.fetch(sources[0], raw=True) != movie():
        raise RuntimeError("A repeated launch created a new game session or returned different media")
    asset = urljoin(base, sources[0])
    if member.fetch(urljoin(asset, "levels/two.txt")) != "Spielstufe zwei":
        raise RuntimeError("Relative game assets are unavailable")
    member.fetch(urljoin(asset, "club/private.swf"), expected_status=404)
    guest.fetch(asset, expected_status=404)
    if "arcade-play" in Page(guest.fetch(root + "/game/61")).forms:
        raise RuntimeError("Missing SWFs should keep their rankings without offering a broken launch")
    return urlsplit(asset).path
