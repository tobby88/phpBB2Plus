"""Prepare generated files locally, then use the original Import-only administrator's forms."""
from pathlib import Path
import lzma
import re
import struct
import tempfile
import zlib

from arcade import movie
from delegation import authenticate, authenticate_pair
from http_board import FormFields, submit_form


def jpeg():
    """A single grey baseline JPEG pixel, built without external image files or packages."""
    def marker(code, payload):
        return bytes((255, code)) + struct.pack(">H", len(payload) + 2) + payload
    lengths = bytes((1,)) + bytes(15)
    return (b"\xff\xd8" + marker(219, bytes((0,)) + bytes((1,)) * 64)
            + marker(192, bytes((8, 0, 1, 0, 1, 1, 1, 17, 0)))
            + marker(196, bytes((0,)) + lengths + bytes((0, 16)) + lengths + bytes((0,)))
            + marker(218, bytes((1, 1, 0, 0, 63, 0))) + b"\x3f\xff\xd9")


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")
    board = Path(board)
    (actor, acp), (add, add_acp) = authenticate_pair(base, "ArcadeImportJunior", "ArcadeAddJunior")
    mode = "&i=-phpbb2plus-arcade-acp-import_game_module&mode=manage"
    panel = acp + mode
    form = "arc-batch-import"
    label = "Fixture Batch & Test"

    def check(phase):
        return php(fixture, target, phase, cwd=board, capture=True)

    def stage(directory, kind, expected, skipped):
        output = php("bin/phpbbcli.php", "phpbb2plus:arcade:stage-directory", str(directory), "--type=" + kind,
                     "--label=" + label + " " + kind, cwd=board, capture=True)
        if f"Prepared: {expected}; skipped: {skipped}" not in output:
            raise RuntimeError("Unexpected local directory selection for " + kind + " (" + output.strip()[-80:] + ")")
        return re.search(r"Batch ID: ([a-f0-9]{64})", output).group(1)

    def done(body, processed, added, skipped):
        return f"Processed {processed} of {processed} prepared files. Added {added}, skipped {skipped}." in body and "Import complete." in body

    check("prepare")
    try:
        with tempfile.TemporaryDirectory(dir=board.parent, prefix="local-arcade-batch-") as tmp:
            local = Path(tmp)
            root, extra = local / "games", local / "other"
            nested = root / "group"
            (nested / "deeper").mkdir(parents=True)
            extra.mkdir()
            gif = bytes.fromhex("47494638396101000100800000000000ffffff21f90401000000002c00000000010001000002024401003b")

            def pair(folder, name):
                (folder / (name + ".swf")).write_bytes(movie())
                (folder / (name + ".gif")).write_bytes(gif)

            for n in range(10):
                pair(root, f"batch_{n:02}")
            for folder, name in ((root, "timer"), (root, "batch_broken"), (nested, "batch_nested"),
                                 (nested / "deeper", "batch_hidden")):
                pair(folder, name)
            (root / "batch_unpaired.swf").write_bytes(movie())
            (root / "batch_bad.swf").write_bytes(b"invalid movie fixture")
            (root / "batch_bad.gif").write_bytes(gif)
            (root / "unused.php").write_text("not a game", encoding="utf-8")
            package = stage(root, "package", 13, 2)
            if stage(root, "package", 13, 2) != package:
                raise RuntimeError("The same local directory did not retain its import ID")
            swf = movie()
            (extra / "batch_plain.swf").write_bytes(b"CWS" + swf[3:8] + zlib.compress(swf[8:]))
            compressed = lzma.compress(swf[8:], format=lzma.FORMAT_ALONE)
            (extra / "batch_lzma.swf").write_bytes(b"ZWS\x0d" + swf[4:8] + struct.pack("<I", len(compressed) - 13)
                                                   + compressed[:5] + compressed[13:])
            (extra / "batch_image.gif").write_bytes(gif)
            (extra / "batch_photo.jpg").write_bytes(jpeg())
            amounts = {"flash": 2, "gif": 1, "jpg": 1}
            batches = {kind: stage(extra, kind, amount, 0) for kind, amount in amounts.items()}
            listing = actor.fetch(panel)
            if listing.count("Fixture Batch &amp; Test") != 4 or "Import local Arcade games" not in listing or "File selection" not in listing or str(local) in listing:
                raise RuntimeError("The native preparation list lost its imported labels or exposed the staging path")
            if "Module not accessible" not in add.fetch(add_acp + mode, expected_status=503):
                raise RuntimeError("The separate game Add grant also conferred bulk import")
            for other in ("add_game_module", "games_module"):
                if "Module not accessible" not in actor.fetch(acp + "&i=-phpbb2plus-arcade-acp-" + other + "&mode=manage", expected_status=503):
                    raise RuntimeError("Bulk import conferred unrelated manual game management")
            url = panel + "&batch=" + package
            initial = actor.fetch(url)
            fields = FormFields(initial, form).values
            if fields.get("online") != "0" or fields.get("position") != "0" or "batch_00.swf" not in initial or "batch_hidden" in initial:
                raise RuntimeError("Offline import defaults or first-page preview are incorrect")
            invalid = submit_form(actor, url, initial, form, {"category": "9999999"})
            if "no longer available" not in invalid:
                raise RuntimeError("A missing category did not stop the import")
            check("hold")
            try:
                first = submit_form(actor, url, initial, form, {"category": "10", "online": "1", "sizing": "detect"})
                if "Processed 10 of 13 prepared files. Added 10, skipped 2." not in first or "category" in FormFields(first, form).values:
                    raise RuntimeError("The first import step did not freeze options and stop at ten files")
                auto = "document.getElementById('arc-batch-import').requestSubmit()"
                if auto not in first or auto in actor.fetch(url):
                    raise RuntimeError("Automatic continuation requires the previous confirmed step in the same browser")
                repeated = submit_form(actor, url, initial, form, {"category": "-1", "online": "0", "sizing": "default"})
                if "Processed 10 of 13" not in repeated or auto in repeated:
                    raise RuntimeError("Replaying a stale first step advanced the cursor twice")
                check("category-away")
                try:
                    stopped = submit_form(actor, url, first, form, {})
                    if "The previous category is unavailable" not in stopped or auto in stopped:
                        raise RuntimeError("A vanished category silently changed the scope of the remaining files")
                finally:
                    check("category-back")
                check("never")
                denied = submit_form(actor, url, first, form, {}, status=403)
                if "not authorised to administer" not in denied:
                    raise RuntimeError("The delegated operator retained import access after an explicit Never")
                check("allow")
                actor, acp = authenticate(base, "ArcadeImportJunior")
                panel = acp + mode
                url = panel + "&batch=" + package
                result = submit_form(actor, url, actor.fetch(url), form, {"category": "-1", "sizing": "default", "online": "0"})
                if not done(result, 13, 11, 4):
                    raise RuntimeError("Existing names or unavailable prepared files were overwritten, counted or retried")
            finally:
                check("restore")
            for kind, key in batches.items():
                url = panel + "&batch=" + key
                options = {"category": "10", "online": "0", "sizing": "default" if kind == "jpg" else "detect"}
                if not done(submit_form(actor, url, actor.fetch(url), form, options), amounts[kind], amounts[kind], 0):
                    raise RuntimeError("The native bulk import could not store " + kind)
            game = int(check("verify").strip())
            if "Batch 00" not in actor.fetch("app.php/arcade/game/" + str(game)):
                raise RuntimeError("The imported game did not appear in the normal Arcade")
    finally:
        check("cleanup")
