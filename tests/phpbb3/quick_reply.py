"""Exercise the native topic reply form and the Plus controls across real phpBB settings."""
from html import unescape
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from urllib.parse import parse_qs, urljoin, urlsplit

from http_board import FormFields, Page, submit_form


def browse(admin, member, guest, phase, source_enabled=True):
    topic = "viewtopic.php?t=1"
    form = "qr_postform"
    phase("setup" if source_enabled else "setup-off")
    try:
        if form in Page(guest.fetch(topic)).forms:
            raise RuntimeError("A guest received the signed-in quick reply form")
        # The moderator sees an older deleted row before post 1 in this fixture.
        # Use an actual page offset, as the native direct link to the first post opens page one.
        german_url = topic + "&start=1"
        german = admin.fetch(german_url)
        member_page = member.fetch(topic)
        if "Markierten Text zitieren" not in german or "Quote selected text" not in member_page:
            raise RuntimeError("The normal topic form is missing the localized quick reply tools")
        for page, expected in ((admin.fetch(topic), None), (german, 1), (member_page, 1),
                               (member.fetch(topic + "&start=1"), 32), (member.fetch(topic + "&start=2"), 45)):
            link = re.search(r'data-qr-last href="([^"]+)"', page)
            quoted = parse_qs(urlsplit(unescape(link[1])).query).get("p") if link else None
            rendered = re.findall(r'id="p(\d+)"\s+class="post\b', page)
            if (quoted != ([str(expected)] if expected else None) or
                    rendered != [str(expected or 31)]):
                raise RuntimeError(f"The last quote does not match its visible row (expected {expected}, displayed {rendered}, quoted {quoted})")
        inputs = FormFields(german, form).values
        if not {"topic_cur_post_id", "topic_id", "forum_id", "form_token", "creation_time", "message"}.issubset(inputs):
            raise RuntimeError("The native topic, message or posting token was lost")
        for name in ("attach_sig", "notify"):
            if german.count('name="' + name + '"') != 1 or ('type="checkbox" name="' + name + '"') not in german:
                raise RuntimeError(f"The native {name} control was duplicated or missing")
        full = submit_form(admin, german_url, german, form, {"message": "Kurzer Entwurf 😀", "preview": "1", "attach_sig": "1", "notify": None})
        draft = FormFields(full, "postform").values
        if draft.get("message") != "Kurzer Entwurf 😀" or "attach_sig" not in draft or "notify" in draft:
            raise RuntimeError("The native full editor did not receive the unscripted quick reply and its choices")
        for page in (guest.fetch("quick_reply.php?mode=smilies"), member.fetch("quick_reply.php", {"mode": "smilies"})):
            if not re.search(r'<a href="#" onclick="initInsertions\(\); insert_text\(', page):
                raise RuntimeError("The historical smiley link did not open the native picker")
        guest.fetch("quick_reply.php", expected_status=404)
        engine = os.environ.get("PLUS_TEST_CHROME")
        if engine:
            chrome, node = shutil.which(engine), shutil.which("node")
            if not chrome or not node or urlsplit(admin.base).hostname != "127.0.0.1":
                raise RuntimeError("Chromium, Node and a loopback board are required for the quick reply interaction")
            session = {"cookies": [(c.name, c.value) for c in admin.cookies], "agent": dict(admin.opener.addheaders)["User-agent"]}
            with tempfile.TemporaryDirectory(prefix="plus3-quick-reply-") as profile:
                child = subprocess.run([node, "--experimental-websocket", str(Path(__file__).with_name("gallery_progress_browser.cjs")),
                                        chrome, profile, urljoin(admin.base, german_url), "quick-reply"],
                                       input=json.dumps(session), text=True, encoding="utf-8", capture_output=True, timeout=65)
                if child.returncode:
                    raise RuntimeError("The Chromium quick reply failed: " + child.stderr[-350:])
        phase("disabled-tools")
        disabled = member.fetch(topic)
        settings = FormFields(disabled, form).values
        if (not {"disable_bbcode", "disable_smilies"}.issubset(settings) or
                any(item in disabled for item in ('data-qr-smilies', 'data-qr-selection', 'data-qr-last',
                                                  'type="checkbox" name="notify"', 'type="checkbox" name="attach_sig"'))):
            raise RuntimeError("The controls ignored the native formatting, signature or notification settings")
        # Post in the admin-only fixture with email disabled, using the same form that works without JavaScript.
        private_topic = "viewtopic.php?t=7"
        submit_form(admin, private_topic, admin.fetch(private_topic), form,
                    {"subject": "Plus quick reply submission fixture", "message": "Eine gesendete Kurzantwort 😀", "post": "1", "notify": None})
        phase("posted")
        phase("locked")
        if form in Page(member.fetch(topic)).forms or form not in Page(admin.fetch(topic)).forms:
            raise RuntimeError("A locked topic lost its native distinction between members and moderators")
        for switch in ("forum-off", "board-off"):
            phase(switch)
            if form in Page(admin.fetch(topic)).forms:
                raise RuntimeError("The native switch " + switch + " did not disable quick reply")
    finally:
        phase("restore")
