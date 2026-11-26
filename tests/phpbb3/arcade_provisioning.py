"""Provide synthetic local media via CLI, then use the two original, independent ACP registrations."""
from html import unescape
from pathlib import Path
import os
import re
import subprocess
import tempfile
from urllib.parse import urljoin

from arcade import movie
from delegation import authenticate
from gallery import png
from http_board import Browser, FormFields, submit_form


def browse(base, board, target, php):
    board = Path(board)
    fixture = Path(__file__).with_suffix(".php")
    add, add_acp = authenticate(base,"ArcadeAddJunior")
    edit, edit_acp = authenticate(base,"ArcadeScoresJunior")
    add_mode = "&i=-phpbb2plus-arcade-acp-add_game_module&mode=manage"
    edit_mode = "&i=-phpbb2plus-arcade-acp-games_module&mode=manage"
    panel = add_acp + add_mode
    closed = edit_acp + edit_mode + "&game=63"
    guest = Browser(base)

    def check(phase):
        return php(fixture,target,phase,cwd=board,capture=True)

    def stage(*args):
        output = php("bin/phpbbcli.php","phpbb2plus:arcade:stage-game",*args,cwd=board,capture=True)
        return re.search(r"Preparation ID: ([0-9a-f]{64})",output).group(1)

    def discard(key):
        php("bin/phpbbcli.php","phpbb2plus:arcade:stage-game","--discard="+key,cwd=board,capture=True)

    def rejected(*arguments, reason):
        result=subprocess.run([os.environ.get("PLUS_TEST_PHP","php"),"-d","disable_functions=mail","bin/phpbbcli.php",
                               "phpbb2plus:arcade:stage-game",*arguments],cwd=board,text=True,encoding="utf-8",
                              errors="replace",stdout=subprocess.PIPE,stderr=subprocess.STDOUT)
        if result.returncode==0 or reason not in " ".join(result.stdout.split()):
            raise RuntimeError("The staging command did not explain the refused operation")

    check("prepare")
    try:
        with tempfile.TemporaryDirectory(dir=board.parent,prefix="sample-game-") as tmp:
            local=Path(tmp)
            main=local/"fixture-stage-play.swf"
            main.write_bytes(movie())
            (local/"levels").mkdir()
            (local/"levels"/"two.txt").write_text("Eigenes Testlevel",encoding="utf-8")
            (local/"unselected.txt").write_text("Separate fixture",encoding="utf-8")
            (local/"settings.ini").write_text("colour=blue",encoding="utf-8")
            (local/"icon.png").write_bytes(png(8,8,b"\x44\x22\x66"))
            for name in ("closed","timer","withdrawn","cancelled"):
                (local/(name+".swf")).write_bytes(movie())
            rejected(str(main),"--asset=settings.ini",reason="not a supported game medium")
            key=stage(str(main),"--asset=levels/two.txt","--icon=icon.png")
            if stage(str(main),"--icon=icon.png","--asset=levels/two.txt")!=key:
                raise RuntimeError("Repeating the same local preparation created a different identity")
            missing=stage(str(local/"closed.swf"))
            existing=stage(str(local/"timer.swf"))
            orphan=stage(str(local/"withdrawn.swf"))
            cancelled=stage(str(local/"cancelled.swf"))
            empty=add.fetch(panel)
            defaults=FormFields(empty,"arc-game-create").values
            if defaults.get("game_desc")!="" or defaults.get("instructions")!="" or defaults.get("reverse_list")!="1" or defaults.get("highscore_limit")!="10":
                raise RuntimeError("Creation lost the configured source game's defaults or copied its identity")
            if "Add an Arcade game" not in empty or "Module not accessible" not in edit.fetch(edit_acp+add_mode,expected_status=503):
                raise RuntimeError("Editing existing games conferred creation rights")
            if "Module not accessible" not in add.fetch(add_acp+edit_mode+"&game=63",expected_status=503):
                raise RuntimeError("The original Add grant allowed editing someone else's game")
            def create(page, value, **fields):
                return submit_form(add,panel,page,"arc-game-create",dict(preparation=value,**fields))
            for colliding in (existing,orphan):
                if "already in use" not in create(empty,colliding):
                    raise RuntimeError("A new game adopted an existing game or an orphaned historical name")
            discard(cancelled)
            if "no longer available" not in create(empty,cancelled):
                raise RuntimeError("A withdrawn local preparation could still be assigned")
            check("never")
            denied=submit_form(add,panel,empty,"arc-game-create",{"preparation":key},status=403)
            if "not authorised to administer" not in denied:
                raise RuntimeError("The Add-only delegate retained access after a native Never")
            check("allow")
            add, add_acp=authenticate(base,"ArcadeAddJunior")
            panel=add_acp+add_mode
            original=add.fetch(panel)
            check("hide-file")
            try:
                if "incomplete or has changed" not in create(original,key,game_desc="Partial fixture"):
                    raise RuntimeError("A preparation with an absent level created a partial Arcade game")
            finally:
                check("restore-file")
            created=create(original,key,game_desc="Lokales Testspiel",game_avail="1",game_show_score="1",
                           allow_guest="1",cat_id="10",game_charge="0",win_width="320",win_height="180")
            game=int(re.search(r"Created game #([0-9]+)\.",created).group(1))
            if "no longer available" not in create(original,key):
                raise RuntimeError("Re-submitting a completed game form could reuse the preparation")
            recovered=submit_form(edit,closed,edit.fetch(closed),"arc-game-edit",{"preparation":missing})
            if "Game settings saved" not in recovered:
                raise RuntimeError("The separate Edit grant could not assign matching media to a previously missing game")
            active=edit_acp+edit_mode+"&game=61"
            before=edit.fetch(active)
            no_replace=submit_form(edit,active,before,"arc-game-edit",{"preparation":existing})
            if "prevents this change" not in no_replace:
                raise RuntimeError("The editor could replace an already playable game's media")
            page=f"app.php/arcade/game/{game}"
            player=submit_form(guest,page,guest.fetch(page),"arcade-play",{})
            url=unescape(re.search(r'id="plus-arc-player" data-source="([^"]+)"',player).group(1))
            if guest.fetch(url,raw=True)!=movie() or guest.fetch(urljoin(url,"levels/two.txt"))!="Eigenes Testlevel":
                raise RuntimeError("The native player did not serve the prepared main and relative level files")
            guest.fetch(urljoin(url,"unselected.txt"),expected_status=404)
            if guest.fetch(page+"/icon",raw=True)!=(local/"icon.png").read_bytes():
                raise RuntimeError("The prepared icon was not served by the existing protected artwork route")
            already=php("bin/phpbbcli.php","phpbb2plus:arcade:stage-game",str(main),"--asset=levels/two.txt","--icon=icon.png",
                        cwd=board,capture=True)
            if f"Already assigned to Arcade game #{game}." not in already:
                raise RuntimeError("Local re-staging did not identify the game already using these files")
            rejected("--discard="+key,reason="Only an unassigned preparation")
            discard(existing)
            discard(orphan)
            check("verify")
    finally:
        check("cleanup")
