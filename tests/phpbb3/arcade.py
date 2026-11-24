"""Verify the visible games and native rankings for different viewers."""
import re
import struct
import time
from html import unescape
from urllib.parse import parse_qs, urljoin, urlsplit
from http_board import Browser, Page


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


def browse(base, administrator, member, pending):
    guest = Browser(base)
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


def next_play(base, member):
    page = "app.php/arcade/game/60"
    action, fields = Page(member.fetch(page)).forms["arcade-play"]
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    html = member.fetch(urljoin(urljoin(base, page), action), fields)
    match = re.search(r'id="plus-arc-player" data-source="([^"]+)"', html)
    if not match:
        raise RuntimeError("Cannot start the fixture game")
    return urljoin(base, unescape(match.group(1)))


def protocols(base, member, first_source):
    source = urljoin(base, first_source)
    pn = urljoin(source, "pnFlashGames.php")
    ok = "&opSuccess=true&endvar=1"
    saved = parse_qs(member.fetch(pn, {"func": "loadGame", "gid": "60"}))
    if saved.get("gameData") != ["Spielstand ü=7 🎮"]:
        raise RuntimeError("Converted saved game cannot be loaded through pnFlashGames")
    for state in ('  Farben &amp; "ä" = 🎮\n', "0", ""):
        if member.fetch(pn, {"func": "saveGame", "gid": "60", "gameData": state}) != ok:
            raise RuntimeError("Failed to save a game through its original callback")
        data = parse_qs(member.fetch(pn, {"func": "loadGame", "gid": "60"}), keep_blank_values=True)
        if data.get("gameData") != [state]:
            raise RuntimeError("The opaque save was modified")
    token = source.split("/media/", 1)[1].split("/", 1)[0]
    values = {"game_name": "puzzle", "arcade_hash": token, "score": "1.23456789e2"}
    for url in (urljoin(source, "newscore.php"), "newscore.php", "index.php?act=Arcade"):
        if not re.search(r">123[.,]4568<", member.fetch(url, values)):
            raise RuntimeError("The score was not recorded or an identical callback could not be retried")
    member.fetch("newscore.php", dict(values, score="125"), expected_status=422)
    if not re.search(r">123[.,]4568<", member.fetch("app.php/arcade/game/60/scores/month")):
        raise RuntimeError("The monthly championship was not updated")
    source = next_play(base, member)
    pn = urljoin(source, "pnFlashGames.php")
    if member.fetch(pn, {"func": "storeScore", "score": "126.75"}) != ok:
        raise RuntimeError("pnFlashGames did not acknowledge a score")
    source = next_play(base, member)
    ib = urljoin(source, "IBProArcade.php")
    check = parse_qs(member.fetch(urljoin(source, "index.php?autocom=arcade"), {"do": "verifyscore"}))
    a, b = int(check["randchar"][0]), int(check["randchar2"][0])
    if "139" not in member.fetch(ib, {"do": "savescore", "gscore": "139", "enscore": str((139 * a) ^ b)}):
        raise RuntimeError("IBProArcade did not record the completed play")
    source = next_play(base, member)
    token = parse_qs(urlsplit(source).query)["arcade_hash"][0]
    if "initbar=puzzle" not in member.fetch("arcade.php", {"sessdo": "sessionstart", "arcade_hash": token}):
        raise RuntimeError("vBulletin session setup failed")
    permission = parse_qs(member.fetch("arcade.php", {"sessdo": "permrequest", "score": "145.75", "fakekey": "fixture", "arcade_hash": token}))
    if not re.search(r">145[.,]75<", member.fetch("arcade.php", {"sessdo": "burn", "microone": permission["microone"][0], "arcade_hash": token})):
        raise RuntimeError("vBulletin did not complete the play")


def wallets(base, member, administrator):
    path = "app.php/arcade/game/62"
    for actor, expected in ((member, 200), (administrator, 422)):
        detail = actor.fetch(path)
        if "Fixture credits" not in detail:
            raise RuntimeError("The game did not display the member's configured currency")
        action, fields = Page(detail).forms["arcade-play"]
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        for _ in range(2):
            actor.fetch(urljoin(urljoin(base, path), action), fields, expected_status=expected)
