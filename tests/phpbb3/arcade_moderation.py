"""Exercise original category assignments and native ACP score corrections on the same converted board."""
from html import unescape
import re

from arcade_administration import panel_for
from delegation import authenticate
from http_board import Browser, FormFields, Page, submit_form


def exercise(base, founder, moderator, ordinary, db):
    route = "app.php/arcade/moderation"
    category = route + "?category=12"
    board = category + "&game=901000&board=current"
    remove = board + "&entry=901001&action=delete"
    form_id = "arc-score-review"
    guest = Browser(base)
    db("seed")
    try:
        if (route.split("app.php")[1] not in moderator.fetch("app.php/arcade") or
                "/arcade/moderation" in ordinary.fetch("app.php/arcade") or "/arcade/moderation" in guest.fetch("app.php/arcade")):
            raise RuntimeError("The directory offers score moderation to the wrong players")
        for actor in (ordinary, guest):
            actor.fetch(route, expected_status=404)
            actor.fetch(board, expected_status=404)
        listing = moderator.fetch(route)
        if "Clubturniere" not in listing or "Child without assigned moderator" in listing or "External category" in listing:
            raise RuntimeError("Arcade scope was inherited or applied to an external link")
        for other in ("10", "-1", "0", "901001", "901002"):
            moderator.fetch(route + "?category=" + other, expected_status=404)
        db("grant-global")
        try:
            if "Child without assigned moderator" not in ordinary.fetch(route) or "192.0.2.45" not in ordinary.fetch(board):
                raise RuntimeError("An explicitly assigned native Arcade moderator could not review the leaderboard")
            ordinary.fetch(route + "?category=-1", expected_status=404)
            ordinary.fetch(route + "?category=901002", expected_status=404)
            ordinary.fetch(board + "&entry=901002&action=edit", expected_status=404)
        finally:
            db("revoke-global")
        ordinary.fetch(board, expected_status=404)
        if "Orphaned game" not in founder.fetch(route + "?category=-1"):
            raise RuntimeError("An administrator cannot manage unassigned games")
        if "Review &amp; repair" not in moderator.fetch(category):
            raise RuntimeError("A moderator cannot reach an assigned disabled game")
        picture = category + "&game=62&picture=1"
        expected_icon = moderator.fetch("app.php/arcade/game/62/icon", raw=True)
        if moderator.fetch(picture, raw=True) != expected_icon:
            raise RuntimeError("The moderator's private game thumbnail changed")
        ordinary.fetch(picture, expected_status=404)
        listing = moderator.fetch(board)
        if ("192.0.2.45" not in listing or "2001:db8:abcd:1234::6" not in listing or "0:01:15" not in listing or "Previous username" in listing
                or "HistoricMember" not in listing or "-0.125" not in listing or "action=edit" in listing):
            raise RuntimeError("The moderator leaderboard lost identity, duration, signed score, IP or action boundaries")
        moderator.fetch(board + "&entry=901002&action=edit", expected_status=404)
        old_url = "arcade_modcp.php?mode=mod&cat_id=12&game_id=901000&action=delete_score&player_id=1"
        if form_id in Page(moderator.fetch(old_url)).forms or "192.0.2.45" not in moderator.fetch(
                "arcade_modcp.php", dict(mode="mod", cat_id="12", game_id="901000", action="scores")):
            raise RuntimeError("Legacy list and result bookmarks did not lead to the native leaderboard")
        first = moderator.fetch(remove)
        if form_id not in Page(first).forms or "HistoricMember" not in first or "-0.125" not in first:
            raise RuntimeError("Score removal did not identify its row and ask for confirmation")
        for begin, end, status in (("revoke", "restore", 404), ("moved", "returned", 404), ("duplicate", "unique", 409)):
            db(begin)
            submit_form(moderator, remove, first, form_id, {}, status)
            db(end)
        acp = panel_for(founder, "Spielhallen-Bestenlisten")
        if founder.fetch(acp + "&category=12&game=62&picture=1", raw=True) != expected_icon:
            raise RuntimeError("The ACP served a different game thumbnail")
        acp_board = acp + "&category=12&game=901000&board="
        edit = acp_board + "current&entry=901002&action=edit"
        if "action=edit" not in unescape(founder.fetch(acp_board + "current")):
            raise RuntimeError("The scoped ACP module did not offer score corrections")
        stale_remove = moderator.fetch(board + "&entry=901002&action=delete")
        correction = founder.fetch(edit)
        invalid = submit_form(founder, edit, correction, form_id, {"score": "abc"}, 422)
        if FormFields(invalid, form_id).values.get("score") != "abc" or "Vorkommastellen" not in invalid:
            raise RuntimeError("An invalid entry in the ACP lost its correction or explanation")
        submit_form(founder, edit, invalid, form_id, {"score": "3,2"})
        stale = submit_form(moderator, board, stale_remove, form_id, {}, 409)
        if "has changed" not in stale or form_id in Page(stale).forms:
            raise RuntimeError("A stale confirmation can still remove a changed score")
        junior, entrance = authenticate(base, "ArcadeScoresJunior")
        junior.fetch(route, expected_status=404)
        settings = entrance + "&i=-phpbb2plus-arcade-acp-settings_module&mode=settings"
        if "Module not accessible" not in junior.fetch(settings, expected_status=503):
            raise RuntimeError("The score operator gained general Arcade settings")
        all_edit = entrance + "&i=-phpbb2plus-arcade-acp-scores_module&mode=manage&category=12&game=901000&board=all&entry=901002&action=edit"
        correction = junior.fetch(all_edit)
        db("suspend-delegate")
        try:
            denied = submit_form(junior, all_edit, correction, form_id, {}, 403)
            if form_id in Page(denied).forms:
                raise RuntimeError("A withdrawn delegate retained its score editor")
        finally:
            db("resume-delegate")
        submit_form(junior, all_edit, junior.fetch(all_edit), form_id, {"score": "-4.1234"})
        result = submit_form(moderator, remove, first, form_id, {})
        if form_id in Page(result).forms or not re.search(r'>3\.2</td>', result):
            raise RuntimeError("Removing one result changed its sibling or failed to return to the board")
        submit_form(moderator, remove, first, form_id, {}, 409)
        db("verify")
    finally:
        db("cleanup")
