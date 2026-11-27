"""Exercise the native ACP with program files created only inside a disposable build."""
from contextlib import contextmanager
from hashlib import sha256
from html import unescape
from importlib.util import module_from_spec, spec_from_file_location
import json
import os
from pathlib import Path
import re
from tempfile import TemporaryDirectory
from time import time
from urllib.parse import urljoin, urlsplit

from delegation import authenticate
from http_board import Page, admin_url, submit_form


@contextmanager
def fixture_package(web):
    root = Path(__file__).resolve().parents[2]
    spec = spec_from_file_location("plus_program_build", root / "tools/build_phpbb3.py")
    builder = module_from_spec(spec)
    spec.loader.exec_module(builder)
    ext = Path("ext/phpbb2plus/community")
    service = web / ext / "service/program_files.php"
    manifest = web / ext / "data/program-files.php"
    pristine = (root / "phpBB3" / ext / "service/program_files.php").read_bytes()
    original = [(f, f.read_bytes(), f.stat().st_mtime_ns) for f in (manifest, service)]
    signature = sha256(original[0][1]).hexdigest().encode("ascii")
    packaged = json.loads(original[0][1].partition(b"\n")[2])["paths"]
    if (signature not in original[1][1] or not all(builder.program_file(path) for path in packaged)
            or "posting.php" not in packaged or "language/de/common.php" not in packaged):
        raise RuntimeError("The built board lost its matching PHP file list")
    if any(builder.program_file(p) for p in ("config.php", "files/upload.php", "store/archive.php", "includes/example.local.php")):
        raise RuntimeError("The builder included a local configuration or upload")
    with TemporaryDirectory(dir=web / "includes", prefix="pluscheck_") as fixture:
        folder = Path(fixture)
        # Different old mtimes make both package revisions visible to native OPcache.
        stamp = int(time()) - 30

        def install(names):
            nonlocal stamp
            service.write_bytes(pristine)
            builder.program_catalog(web, {(folder / name).relative_to(web).as_posix(): "" for name in names})
            stamp += 1
            os.utime(service, (stamp, stamp))

        try:
            yield folder, install
        finally:
            for path, data, modified in original:
                path.write_bytes(data)
                os.utime(path, ns=(modified, modified))


def browse(admin, member, verify, web):
    if urlsplit(admin.base).hostname not in ("127.0.0.1", "localhost") or os.environ.get("PLUS_TEST_DATABASE") != "1":
        raise ValueError("Use the disposable local phpBB installation")
    url = admin_url(admin) + "&i=-phpbb2plus-community-acp-file_integrity_module&mode=main"
    forms = "plus-ct-files-"
    private_marker = "ProgramFixtureText"
    initial_content = b"<?php // ProgramFixtureText 1\n"
    verify("setup")

    def status():
        return json.loads(verify("status"))

    def click(page, action):
        return submit_form(admin, url, page, forms + action, {})

    def complete(page):
        for _ in range(25):
            if forms + "advance" not in Page(page).forms:
                return page
            page = click(page, "advance")
        raise RuntimeError("The resumable file check did not complete")

    def result(page, name, en, de):
        match = re.search(r'<td>[^<]*/' + re.escape(name) + r'</td>\s*<td>([^<]*)</td>', page)
        if not match or match[1] not in (en, de):
            raise RuntimeError("Incorrect comparison for the synthetic file " + name)

    try:
        verify("labels")
        if ("file_integrity_module" in member.fetch("index.php") or "file_integrity_module" in member.fetch("ucp.php")
                or member.fetch("ext/phpbb2plus/community/data/program-files.php")):
            raise RuntimeError("The file check became available to an ordinary member")
        with fixture_package(web) as (folder, install):
            sample = ["a_edit.php", "b_remove.php", "c_add.php", "d_unreadable.php", "e_retire.php"]
            padding = [f"z{n:03d}.php" for n in range(400)]
            names = sample + padding
            for name in sample:
                if name != "c_add.php":
                    (folder / name).write_bytes(initial_content)
            install(names)
            empty = admin.fetch(url)
            if forms + "start" not in Page(empty).forms or status()["approved"]:
                raise RuntimeError("The ACP did not await its first deliberate check")
            started = click(empty, "start")
            script = re.search(r'<script[^>]+src="([^"]*program_files\.js[^"]*)"', started)
            if not script or "plus-ct-files-advance" not in admin.fetch(urljoin(url, unescape(script[1]))):
                raise RuntimeError("The automatic batch continuation is unavailable")
            partial = click(started, "advance")
            progress = status()
            if not 0 < progress["cursor"] < len(names) or progress["approved"] or forms + "advance" not in Page(partial).forms:
                raise RuntimeError("A single batch approved or exhausted the pending comparison")
            discarded = click(started, "advance")
            if not any(s in discarded for s in ("has changed", "inzwischen geändert")) or status() != progress:
                raise RuntimeError("An already used continuation changed the saved state")
            finished = complete(admin.fetch(url))
            confirm = click(finished, "confirm")
            if forms + "adopt" not in Page(confirm).forms or status()["approved"]:
                raise RuntimeError("Opening the confirmation already replaced the reference")
            if forms + "confirm" not in Page(admin.fetch(url)).forms:
                raise RuntimeError("Closing the confirmation lost the completed report")
            verify("reviewer")
            try:
                reviewer, acp = authenticate(admin.base, "ProgramFileReviewer")
                review = acp + "&i=-phpbb2plus-community-acp-file_integrity_module&mode=main"
                english = reviewer.fetch(review)
                if "CrackerTracker program files" not in english or forms + "confirm" not in Page(english).forms or "CrackerTracker blocking rules" in english:
                    raise RuntimeError("The English reviewer did not receive the intended file management permission")
                preview = submit_form(reviewer, review, english, forms + "confirm", {})
                verify("revoke")
                try:
                    denied = submit_form(reviewer, review, preview, forms + "adopt", {})
                except RuntimeError as exc:
                    reason = str(exc)
                    # The native module loader can refuse an inaccessible module before calling its controller.
                    if "HTTP 403 at /adm/" not in reason and not ("HTTP 503 at /adm/" in reason and "Module not accessible" in reason):
                        raise
                else:
                    if forms + "start" in Page(denied).forms:
                        raise RuntimeError("The revoked reviewer retained the file management form")
                if status()["approved"]:
                    raise RuntimeError("A previously opened approval survived withdrawal of its permission")
            finally:
                verify("remove-reviewer")
            accepted = click(confirm, "adopt")
            baseline = status()
            if not baseline["approved"] or baseline["entries"] != len(names) or forms + "confirm" in Page(accepted).forms:
                raise RuntimeError("The confirmed reference does not cover all package paths")
            initial_report = admin.fetch(url + "&all=1")
            result(initial_report, sample[0], "Unchanged", "Unverändert")
            if private_marker in initial_report or sha256(initial_content).hexdigest() in initial_report:
                raise RuntimeError("The report exposed contents or stored hashes")
            # Normal installation of a revised package, including removal of one old program.
            (folder / sample[0]).write_bytes(initial_content.replace(b"1", b"2"))
            (folder / sample[1]).unlink()
            (folder / sample[2]).write_bytes(initial_content)
            (folder / sample[3]).unlink()
            (folder / sample[3]).mkdir()
            install([n for n in names if n != sample[4]])
            new_package = admin.fetch(url)
            if not any(s in new_package for s in ("earlier package", "früheren Paket")):
                raise RuntimeError("An old scan was presented as current after the package changed")
            compared = complete(click(new_package, "start"))
            expected = [("Contents changed", "Inhalt geändert"), ("Missing since the reference", "Seit der Referenz fehlend"),
                        ("Newly present", "Neu vorhanden"), ("Could not be read reliably", "Nicht zuverlässig lesbar"),
                        ("No longer listed in the package", "Im neuen Paket nicht aufgeführt")]
            for name, labels in zip(sample, expected):
                result(compared, name, *labels)
            if (forms + "confirm" in Page(compared).forms or status()["reference"] != baseline["reference"]
                    or private_marker in compared or (folder / sample[0]).read_bytes() != initial_content.replace(b"1", b"2")):
                raise RuntimeError("The comparison approved unreadable files, changed program files or exposed contents")
    finally:
        verify("restore")
