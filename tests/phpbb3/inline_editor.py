"""Verify converted editing preferences, native post handling and the actual Chromium UI."""
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tempfile
import time
from urllib.parse import urljoin, urlsplit

from attachments import GIF
from http_board import Browser, FormFields, Page, admin_url, submit_form


def browser(base, web, topic, post):
    binary = os.environ.get("PLUS_TEST_CHROME")
    if not binary:
        return False
    executable = shutil.which(binary)
    if not executable or urlsplit(base).hostname != "127.0.0.1":
        raise RuntimeError("The inline editing test needs the local disposable board and Chromium")
    probe = Path(web) / ("inline-probe-" + secrets.token_hex(12) + ".php")
    status = Path(web).parent / (probe.stem + ".txt")
    script = Path(__file__).with_name("inline_editor_browser.js").read_text(encoding="utf-8")
    # The random probe exists only inside this disposable loopback installation. The synthetic
    # sign-in is delivered from its test environment and is never embedded in a persisted file.
    probe.write_text("""<?php
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || getenv('PLUS_TEST_DATABASE') !== '1') { http_response_code(404); exit; }
header('Cache-Control: no-store');
if (isset($_POST['result'])) {
    file_put_contents(dirname(__DIR__) . '/' . basename(__FILE__,'.php') . '.txt', substr((string) $_POST['result'],0,360), LOCK_EX);
    exit;
}
?><!doctype html><meta charset=utf-8><iframe id=board></iframe><output></output><script>
const password=<?= json_encode(getenv('PLUS_TEST_HISTORIC_PASSWORD'),JSON_HEX_TAG) ?>;
""" + f"const topic={topic}, post={post};\n" + script + "</script>", encoding="utf-8")
    try:
        with tempfile.TemporaryDirectory(prefix="plus3-inline-browser-") as profile:
            cmd = [executable, "--headless", "--no-first-run", "--no-default-browser-check", "--disable-extensions",
                   "--disable-sync", "--disable-component-update", "--disable-background-networking",
                   "--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1, EXCLUDE localhost",
                   "--user-data-dir=" + profile, "--window-size=1280,840", urljoin(base, probe.name)]
            run = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                   creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            try:
                deadline = time.monotonic() + 50
                result = ""
                while time.monotonic() < deadline:
                    if status.exists():
                        result = status.read_text(encoding="utf-8")
                        if result == "OK" or result.startswith("FAIL "):
                            break
                    if run.poll() is not None:
                        break
                    time.sleep(.1)
                if result != "OK":
                    raise RuntimeError("Chromium inline editing: " + (result or "No loopback result"))
            finally:
                if run.poll() is None:
                    run.terminate()
                    run.wait(timeout=10)
    finally:
        probe.unlink(missing_ok=True)
        status.unlink(missing_ok=True)
    return True


def browse(base, owner, member, check, web):
    cp = "ucp.php?i=ucp_prefs&mode=view"
    check("prepare")
    try:
        check("upgrade")
        acp = admin_url(owner) + "&i=acp_board&mode=post"
        admin = FormFields(owner.fetch(acp), "acp_board").values
        if admin.get("config[plus_inline_edit]") != "0" or admin.get("config[plus_inline_edit_over]") != "1":
            raise RuntimeError("Both source editing settings did not reach the native posting ACP")
        if 'name="plus_inline_edit"' in member.fetch(cp):
            raise RuntimeError("A board-enforced editor preference offered a personal override")
        check("open")
        if FormFields(member.fetch(cp), "ucp").values.get("plus_inline_edit") != "1":
            raise RuntimeError("The personal source choice was not imported")
        create = "posting.php?mode=post&f=1"
        page = member.fetch(create)
        path, _ = Page(page).forms["postform"]
        fields = FormFields(page, "postform").values
        fields.pop("disable_bbcode", None)
        fields.update(subject="Inline editor fixture", message="Original [b]Ärger[/b] & Grüße.", plus_description="Inline description",
                      filecomment="Native attachment", add_file="1")
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        image = GIF
        page = member.fetch(urljoin(urljoin(base, create), path), fields, files=[("fileupload", "inline-edit-fixture.gif", "image/gif", image)])
        if not re.search(r'data-attach-id="[1-9][0-9]*"', page):
            error = re.search(r'<[^>]+class="error"[^>]*>(.*?)</(?:p|div)>', page, re.S)
            raise RuntimeError("The native fixture upload did not attach the file: " + (re.sub(r"<[^>]*>", " ", error[1])[:250] if error else "no attachment control"))
        submit_form(member, create, page, "postform", {"post": "1", "disable_bbcode": None})
        topic, post = map(int, check("locate").strip().split(","))
        thread = f"viewtopic.php?t={topic}"
        edit = f"posting.php?mode=edit&p={post}"
        if ('class="plus-inline-slot"' not in member.fetch(thread) or 'class="plus-inline-slot"' in Browser(base).fetch(thread) or
                'class="plus-inline-slot"' in owner.fetch(thread) or "postform" not in Page(member.fetch(edit)).forms):
            raise RuntimeError("The inline offer did not follow the native edit link and personal preference")
        submit_form(member, cp, member.fetch(cp), "ucp", {"plus_inline_edit": "0", "submit": "1"})
        if 'class="plus-inline-slot"' in member.fetch(thread):
            raise RuntimeError("The disabled preference still replaced the native post editor")
        submit_form(member, cp, member.fetch(cp), "ucp", {"plus_inline_edit": "1", "submit": "1"})
        earlier = member.fetch(edit)
        check("lock")
        if 'class="plus-inline-slot"' in member.fetch(thread) or "postform" in Page(submit_form(member, edit, earlier, "postform",
                {"post": "1", "message": "Locked draft"})).forms:
            raise RuntimeError("A locked post still offered editing or accepted its previously issued editor")
        check("unlock")
        draft = {"message": "Umlaut [b]Öl[/b] & 📘\nWörtlich &amp; <3", "subject": "Voller Editor", "plus_inline_expand": "1"}
        full = submit_form(member, edit, member.fetch(edit), "postform", draft)
        native = FormFields(full, "postform").values
        if native.get("message") != draft["message"] or native.get("subject") != draft["subject"] or "inline-edit-fixture.gif" not in full:
            raise RuntimeError("Expanding an unsaved draft lost markup, characters or its existing attachment")
        expired = submit_form(member, edit, full, "postform", dict(draft, form_token="expired"))
        if not re.search(r'<[^>]+class="error"', expired) or FormFields(expired, "postform").values.get("message") != draft["message"]:
            raise RuntimeError("An expired editor lost the native validation error or discarded the unsaved draft")
        check("intact")
        if browser(base, web, topic, post):
            review = submit_form(member, edit, earlier, "postform", draft)
            if f'id="ppr{post}"' not in review or FormFields(review, "postform").values.get("message") != draft["message"]:
                raise RuntimeError("Switching a stale inline draft to the full editor lost the native edit review")
            check("changed")
    finally:
        check("cleanup")
