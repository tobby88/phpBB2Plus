"""Verify the visible games and native rankings for different viewers."""
import re
import shutil
import struct
import time
from html import unescape
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlsplit
from gallery import png
from http_board import Browser, Page


def tournaments(base, founder, member, pending, verify):
    guest = Browser(base)
    overview = "app.php/arcade/tournaments"
    detail = "app.php/arcade/tournament/32"
    if "/arcade/tournaments" in guest.fetch("app.php/arcade"):
        raise RuntimeError("The guest arcade advertised a members-only tournament")
    if "Offene Runde" in guest.fetch("arcade_tournament.php"):
        raise RuntimeError("A signed-out visitor saw tournament data")
    listing = member.fetch("arcade_tournament.php")
    if "Tournaments" not in member.fetch("app.php/arcade") or "Offene Runde" not in listing or "You are invited" in listing:
        raise RuntimeError("The migrated tournament listing or personal invitation changed")
    champ = founder.fetch("arcade_tournament.php?mode=champions")
    if "Archivpokal" not in champ or "Ehemaliger Nutzer" not in champ or "Offene Runde" in champ:
        raise RuntimeError("The champions directory lost a completed tournament or reassigned its retired winner")
    page = founder.fetch("arcade_tournament.php?mode=tour&id=32")
    if ("Du bist eingeladen" not in page or "HistoricMember" not in page or "Persönliche Spielprobe" not in page
            or ">-2,125<" not in page or ">0<" not in page or "Ein Versuch gespielt" not in page or "-3,1251" not in page):
        raise RuntimeError("Tournament invitation, roster or signed and zero records changed")
    waiting = pending.fetch(detail)
    if "Persönliche Spielprobe" in waiting or "Du bist eingeladen" in waiting or "-3,1251" in waiting or "Ein Versuch gespielt" in waiting:
        raise RuntimeError("Tournament listing revealed a restricted game or another user's invitation")
    if "arcade-join-tour" in member.fetch(detail) or "arcade-join-tour" in founder.fetch("app.php/arcade/tournament/31"):
        raise RuntimeError("An existing participant or a closed tournament offered a new seat")
    action, data = Page(page).forms["arcade-join-tour"]
    old_action, old_data = Page(waiting).forms["arcade-join-tour"]
    many_action, many_data = Page(member.fetch(overview)).forms["arcade-join-tours"]
    time.sleep(max(0, max(int(fields["creation_time"]) for fields in (data, old_data, many_data)) + 1.05 - time.time()))
    for _ in range(2):
        joined = founder.fetch(action, data)
        if "2 von 2 Plätzen belegt" not in joined or "Du nimmst teil" not in joined or "arcade-join-tour" in joined:
            raise RuntimeError("Joining or retrying registration changed the last place twice")
    pending.fetch(old_action, old_data, expected_status=409)
    for expected in ("You have joined the tournament.", "You are participating"):
        bulk = member.fetch(many_action, dict(many_data, **{"tours[]": ["31", "32", "34", "34"]}))
        if expected not in bulk or "Abendrunde" not in bulk or "You are participating" not in bulk:
            raise RuntimeError("Bulk tournament enrolment lost an individual result")
    verify("disable")
    if "/arcade/tournaments" in member.fetch("app.php/arcade"):
        raise RuntimeError("A disabled tournament catalogue was still linked")
    member.fetch(overview, expected_status=404)
    member.fetch(detail, expected_status=404)
    founder.fetch(action, data, expected_status=404)
    verify("enable")


def tournament_play(base, founder, member, pending, verify):
    detail = "app.php/arcade/tournament/32"
    evening = "app.php/arcade/tournament/34"
    old_game = "arcade_tournament.php?mode=game&id=32&game_id=60"
    initial = member.fetch(old_game)
    if "One turn left" not in initial or "Your best score is -3.1251" not in initial:
        raise RuntimeError("The old game bookmark lost the player's imported turns and score")
    unreadable = pending.fetch(evening)
    if "arcade-turn-61" in unreadable or "Deine bisherigen Versuche konnten nicht gelesen werden." not in unreadable:
        raise RuntimeError("Unreadable player history provided a fresh set of tournament turns")
    standard_game = "app.php/arcade/game/60"
    stale, fields = Page(pending.fetch(standard_game)).forms["arcade-play"]
    verify("reserve")
    for outsider in (pending, Browser(base)):
        normal = outsider.fetch(standard_game)
        if "arcade-play" in Page(normal).forms or "für seine Teilnehmer reserviert" not in normal:
            raise RuntimeError("A game reserved by an open event offered a regular launch to a nonparticipant")
    if "arcade-play" not in Page(member.fetch(standard_game)).forms:
        raise RuntimeError("A participant could not use a reserved game")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    pending.fetch(stale, fields, expected_status=404)

    def play(actor, form):
        action, fields = form
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        page = actor.fetch(action, fields)
        source = re.search(r'id="plus-arc-player" data-source="([^"]+)"', page)
        if not source or '/arcade/tournament/' not in page:
            raise RuntimeError("The tournament launch lost its game or return link")
        return urljoin(base, unescape(source[1]))

    last = Page(initial).forms["arcade-turn-60"]
    other_tab = Page(member.fetch(detail)).forms["arcade-turn-60"]
    source = play(member, last)
    if play(member, last) != source or member.fetch(source, raw=True) != movie():
        raise RuntimeError("Retrying the last tournament turn consumed another turn")
    time.sleep(max(0, int(other_tab[1]["creation_time"]) + 1.05 - time.time()))
    member.fetch(other_tab[0], other_tab[1], expected_status=422)
    points = {"score": "81.0123", "game_name": "puzzle"}
    for _ in range(2):
        result = member.fetch(urljoin(source, "newscore.php"), points)
        if ("Your best score is 81.0123" not in result or ">81.0123<" not in result or "arcade-turn-60" in result):
            raise RuntimeError("Tournament score submission lost its player, leaderboard or remaining attempts")
    clock_game = play(member, Page(member.fetch(evening)).forms["arcade-turn-61"])
    response = member.fetch(urljoin(clock_game, "pnFlashGames.php"), {"func": "storeScore", "score": "7.1256"})
    if "opSuccess=true" not in response or ">7.1256<" not in member.fetch(evening):
        raise RuntimeError("A lower-is-better tournament lost a score submitted by the game")
    # The founder has fewer credits than this game's normal admission price.
    private_game = play(founder, Page(founder.fetch(detail)).forms["arcade-turn-62"])
    verify("pause")
    founder.fetch(urljoin(private_game, "newscore.php"), {"game_name": "private", "score": "12"}, expected_status=404)
    if "arcade-play" not in Page(pending.fetch(standard_game)).forms:
        raise RuntimeError("An inactive tournament retained its game reservation")
    verify("turns")


def awards(founder, member, pending):
    root = "app.php/arcade/player/2/awards"

    def placements(page):
        return sorted((int(game), int(place)) for place, game in re.findall(
            r'<tr[^>]*><td>([123])</td><td><a href="[^"]*/arcade/game/(\d+)/scores/', page))

    current = founder.fetch(root)
    if placements(current) != [(60, 2), (61, 2), (64, 3)] or "Platz 2 (2)" not in current or "12 s" not in current:
        raise RuntimeError("Current podium finishes lost a tie, a retired competitor or the time played")
    if placements(member.fetch(root + "/all")) != [(60, 1), (61, 2), (64, 3)]:
        raise RuntimeError("All-time or lower-is-better podium ranks did not match the individual scoreboards")
    limited = pending.fetch(root)
    if placements(limited) != [(60, 2), (61, 2)] or "Persönliche Spielprobe" in limited:
        raise RuntimeError("A podium finish exceeded the viewer's Arcade category access")
    if placements(member.fetch(root + "/all?place=1")) != [(60, 1)] or placements(pending.fetch(root + "?place=3")):
        raise RuntimeError("Filtering by award or restricting a category changed the wrong results")
    link = re.search(r'<a href="([^"]+)">My podium finishes</a>', member.fetch("app.php/arcade"))
    if not link or placements(member.fetch(unescape(link[1]))) != [(60, 1), (61, 1), (64, 2)]:
        raise RuntimeError("The converted member's Arcade link opened another player's results")
    own_firsts = [(60, 1), (61, 1)]
    for old in ("activity.php?mode=game_stats&id=4", "activity.php?mode=game_stats&id=4&user_id=1"):
        if placements(member.fetch(old)) != own_firsts:
            raise RuntimeError("The historical Arcade award link lost its player or medal filter")
    if "9999999999.9999" not in member.fetch("activity.php?mode=at_highscore&id=60"):
        raise RuntimeError("The old all-time game link lost its scoreboard")
    if "awards" not in member.fetch("app.php/arcade/game/60/scores/current"):
        raise RuntimeError("The game leaderboard does not link to its active players' awards")
    member.fetch("app.php/arcade/player/888888/awards", expected_status=404)


def community(base, founder, member, pending):
    guest = Browser(base)
    listing = "app.php/arcade/favourites"
    game = "app.php/arcade/game/60"
    title = "Öffentliches Denkspiel"
    guest.fetch(listing, expected_status=404)
    if ("My favourite games" not in member.fetch("app.php/arcade") or "Vertrauliche Spielprobe" in pending.fetch(listing)
            or member.fetch(listing).count(">" + title + "</a>") != 1 or title in founder.fetch(listing)):
        raise RuntimeError("Private favourites, name-only imports or duplicate rows changed the list")
    page = member.fetch(game)
    if 'id="arcade-rate"' in page or "7.0 out of 10 (1 vote)" not in page or 'id="arcade-favourite"' in guest.fetch(game):
        raise RuntimeError("The original vote receipt, rating precision or guest controls were lost")

    def send(actor, form, values, html=None, status=200):
        action, data = Page(html if html is not None else actor.fetch(game)).forms[form]
        data.update(values)
        time.sleep(max(0, int(data["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(urljoin(urljoin(base,game),action),data,expected_status=status)

    send(member, "arcade-favourite", {}, page)
    if title in member.fetch(listing):
        raise RuntimeError("Removing a favourite left a duplicate or an unrelated historical name")
    add = member.fetch(game)
    send(member, "arcade-favourite", {}, add)
    send(member, "arcade-favourite", {}, add)
    if member.fetch(listing).count(">" + title + "</a>") != 1:
        raise RuntimeError("A repeated favourite form changed the final selection")
    vote = founder.fetch(game)
    send(founder, "arcade-rate", {"value": "11"}, vote, 422)
    send(founder, "arcade-rate", {"value": "9"}, vote)
    send(founder, "arcade-rate", {"value": "2"}, vote)
    if "8,0 von 10 (2 Stimmen)" not in founder.fetch(game) or 'id="arcade-rate"' in founder.fetch(game):
        raise RuntimeError("A repeated vote changed the average or left the voting form active")


def comments(base, founder, member, pending):
    game = "app.php/arcade/game/60"
    path = game + "/comments"
    guest = Browser(base)
    guest.fetch(path, expected_status=404)
    if "/arcade/game/60/comments" not in member.fetch(game) or "/arcade/game/60/comments" in guest.fetch(game):
        raise RuntimeError("The game links to the wrong comment view for its reader")
    original = member.fetch(path)
    def text_with_emoji(html):
        return unescape(re.sub(r'<img\b[^>]*\balt="([^"]*)"[^>]*>',r'\1',html))
    if "Schöne Farben & Formen 🎲" not in text_with_emoji(original) or "AlterSpielname" in original or "HistoricMember" not in original:
        raise RuntimeError("The converted comment text or author is wrong")
    if "arcade-add-comment" in Page(pending.fetch(path)).forms:
        raise RuntimeError("Comment form offered before recording a score")
    pending.fetch(path + "/71/edit", expected_status=404)

    def send(actor, form, values, status=200):
        action, fields = form
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(urljoin(urljoin(base,path),action),dict(fields, **values),expected_status=status)

    add = Page(founder.fetch(path)).forms["arcade-add-comment"]
    send(founder, add, {"body": "ä" * 129}, 422)
    first = send(founder, add, {"body": "Rätsel & Grüße"})
    entries = re.findall(r'<div id="comment-(\d+)" class="post\b[^>]*>.*?<div class="content">(.*?)</div>', first, re.S)
    ids = [cid for cid, body in entries if "Rätsel &amp; Grüße" in body]
    if len(ids) != 1 or ids[0] == "71":
        raise RuntimeError("New comment or its permalink is missing")
    cid = ids[0]
    send(founder, add, {"body": "Rätsel & Grüße"})
    if founder.fetch(path).count("Rätsel &amp; Grüße") != 1 or member.fetch("ucp.php?i=pm&folder=inbox").count(">New arcade comment<") != 1:
        raise RuntimeError("A repeated comment or its privately delivered notification was lost or duplicated")
    edit = Page(founder.fetch(path + "/" + cid + "/edit")).forms["arcade-change-comment"]
    send(founder, edit, {"body": "Zweiter Entwurf 🎮"})
    send(founder, edit, {"body": "Veralteter Entwurf"}, 409)
    listing = member.fetch(path + "?order=desc")
    if listing.index("Zweiter Entwurf") > listing.index("Schöne Farben") or "Zweiter Entwurf 🎮" not in text_with_emoji(listing) or "Edited once by" not in listing:
        raise RuntimeError("Comment order or latest editor is wrong")
    remove = Page(founder.fetch(path + "/" + cid + "/delete")).forms["arcade-change-comment"]
    send(founder, remove, {})
    send(founder, add, {"body": "Rätsel & Grüße"})
    if "Zweiter Entwurf" in member.fetch(path) or "Rätsel &amp; Grüße" in member.fetch(path):
        raise RuntimeError("A deleted comment returned when the original form was retried")
    member.fetch(path + "?focus=" + cid, expected_status=404)

    prefs = "ucp.php?i=ucp_prefs&mode=personal"
    if Page(pending.fetch(prefs),checked=True).forms["ucp"][1].get("plus_arcade_pm") != "0":
        raise RuntimeError("The original arcade notification opt-out was lost")
    def receive(enabled):
        form = Page(member.fetch(prefs),checked=True).forms["ucp"]
        data = dict(form[1], submit="1", plus_arcade_pm=str(int(enabled)))
        time.sleep(max(0, int(data["creation_time"]) + 1.05 - time.time()))
        member.fetch(urljoin(urljoin(base,prefs),form[0]),data)
        selected = Page(member.fetch(prefs),checked=True).forms["ucp"][1]
        if selected.get("plus_arcade_pm") != str(int(enabled)):
            raise RuntimeError("The native UCP did not save the arcade notification preference")
    receive(False)
    send(founder,Page(founder.fetch(path)).forms["arcade-add-comment"],{"body":"Stille Antwort"})
    if member.fetch("ucp.php?i=pm&folder=inbox").count(">New arcade comment<") != 1:
        raise RuntimeError("A disabled game notification still sent a private message")
    receive(True)


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
    (folder.parent / "timer.swf").write_bytes(movie())
    (folder / "levels/two.txt").write_text("Spielstufe zwei", encoding="utf-8")
    (folder / "club/private.swf").write_bytes(movie())
    (folder / "puzzle.png").write_bytes(png(12, 8, b"\x22\x77\xaa"))
    (folder / "club/private.png").write_bytes(png(8, 12, b"\x96\x34\x68"))
    shared = legacy / "images/arcade"
    shared.mkdir(parents=True, exist_ok=True)
    (shared / "clock.png").write_bytes(png(10, 10, b"\x44\x88\x33"))
    (shared / "category.png").write_bytes(png(16, 12, b"\x40\x80\xa0"))
    (shared / "club-category.png").write_bytes(png(11, 13, b"\x31\x80\x50"))
    fallback = Path("templates/fisubsilversh/images/games.gif")
    (legacy / fallback.parent).mkdir(parents=True, exist_ok=True)
    shutil.copyfile(Path(__file__).resolve().parents[2] / "phpBB2" / fallback, legacy / fallback)


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
    missing = member.fetch(root + "/game/64")
    if "arcade-play" in Page(missing).forms or "/game/64/scores/current" not in missing:
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
    own = re.search(r'<a href="([^"]+)">My podium finishes</a>', member.fetch("app.php/arcade"))
    if not own or ">145.75<" not in member.fetch(unescape(own[1])):
        raise RuntimeError("A new score did not update the player's awards")


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
