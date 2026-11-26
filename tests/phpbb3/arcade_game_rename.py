"""Rename a converted game through its old Edit grant, with active native players and retained history."""
from html import unescape
from pathlib import Path
import re
import tempfile
from urllib.parse import parse_qs, urljoin, urlsplit

from arcade import movie, next_play
from delegation import authenticate
from http_board import FormFields, submit_form


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")
    editor, acp = authenticate(base, "ArcadeScoresJunior")
    add, add_acp = authenticate(base, "ArcadeAddJunior")
    mode = "&i=-phpbb2plus-arcade-acp-games_module&mode=manage"
    game = "&game=60"
    form = "arc-game-rename"

    def check(phase):
        return php(fixture, target, phase, cwd=board, capture=True)

    def rename(url, name, opened=None):
        return submit_form(editor, url, opened or editor.fetch(url), form, {"new_name": name})

    def token(source):
        return parse_qs(urlsplit(source).query)["arcade_hash"][0]

    check("prepare")
    try:
        first = next_play(base, editor)
        icon = editor.fetch("app.php/arcade/game/60/icon", raw=True)
        check("pin")
        link = re.search(r'href="([^"]*[?&](?:amp;)?game=60&amp;action=rename)"', editor.fetch(acp + mode + game))
        if not link:
            raise RuntimeError("The existing game editor did not link its technical rename form")
        url = urljoin(acp, unescape(link.group(1)))
        original = editor.fetch(url)
        if FormFields(original, form).values.get("new_name") != "puzzle":
            raise RuntimeError("The technical rename form did not show the converted source name")
        if "Module not accessible" not in add.fetch(add_acp + mode + game + "&action=rename", expected_status=503):
            raise RuntimeError("The isolated Add operator was allowed to rename existing games")
        for value, message in (("timer", "already in use"), ("removed-game", "already in use"), ("old-medal-game", "already in use"),
                               ("not valid", "valid identifier")):
            if message not in rename(url, value, original):
                raise RuntimeError("Rename accepted a conflicting or invalid identifier")
        check("full-list")
        try:
            if "size of a stored historical ranking list" not in rename(url, "longer-puzzle-name", original):
                raise RuntimeError("An overflowing historical ranking list was silently truncated")
        finally:
            check("restore-list")
        check("never")
        closed = submit_form(editor, url, original, form, {"new_name": "must-not-apply"}, status=503)
        if "Module not accessible" not in closed:
            raise RuntimeError("A revoked Edit grant could still rename a loaded game")
        check("allow")
        editor, acp = authenticate(base, "ArcadeScoresJunior")
        url = acp + mode + game + "&action=rename"
        # Reauthentication establishes a new board session; keep this session's current launch for callbacks.
        first = next_play(base, editor)
        check("pin")
        original = editor.fetch(url)
        if "Game settings saved" not in rename(url, "schlüssel&neu", original):
            raise RuntimeError("The authorised rename did not complete")
        check("renamed")
        if "changed after you opened" not in rename(url, "another-name", original):
            raise RuntimeError("Replaying a stale technical rename altered the game again")
        if editor.fetch(first, raw=True) != movie() or editor.fetch("app.php/arcade/game/60/icon", raw=True) != icon:
            raise RuntimeError("An active player lost the original media or its icon while renaming")
        second = next_play(base, editor)
        if "Game settings saved" not in rename(url, "spiel-neu"):
            raise RuntimeError("An intermediate name could not be changed a second time")
        other = acp + mode + "&game=63&action=rename"
        for owned in ("puzzle", "schlüssel&neu"):
            if "already in use" not in rename(other, owned):
                raise RuntimeError("Another game could reuse an embedded name or an active receipt's intermediate name")
        with tempfile.TemporaryDirectory(dir=Path(board).parent) as tmp:
            source = Path(tmp) / "closed.swf"
            source.write_bytes(movie())
            command = ("bin/phpbbcli.php", "phpbb2plus:arcade:stage-game")
            output = php(*command, str(source), cwd=board, capture=True)
            key = re.search(r"Preparation ID: ([a-f0-9]{64})", output).group(1)
            try:
                if "Game settings saved" not in rename(other, "recovered-game") or key not in editor.fetch(acp + mode + "&game=63"):
                    raise RuntimeError("A renamed game with missing media could no longer accept its original prepared file")
            finally:
                php(*command, "--discard=" + key, cwd=board, capture=True)
        if "Game settings saved" not in rename(url, "Spiel-Neu"):
            raise RuntimeError("A case-only technical rename was lost")
        third = next_play(base, editor)
        for src, start_name, callback_name, score in ((first, "puzzle", "puzzle", "991"), (second, "schlüssel&neu", "schlüssel&neu", "992"),
                                                       (third, "Spiel-Neu", "puzzle", "993")):
            grant = token(src)
            setup = parse_qs(editor.fetch("arcade.php", {"sessdo": "sessionstart", "arcade_hash": grant}).lstrip("&"))
            if setup.get("initbar") != [start_name]:
                raise RuntimeError("The game did not receive the identifier of its authorised start")
            callback = urljoin(src, "newscore.php")
            editor.fetch(callback, {"game_name": "timer", "score": score}, expected_status=422)
            values = {"game_name": callback_name, "score": score, "arcade_hash": grant}
            if score not in editor.fetch(callback, values) or score not in editor.fetch("newscore.php", values):
                raise RuntimeError("An authorised play or its identical retry lost its leaderboard after renaming")
        check("verify")
    finally:
        check("cleanup")
