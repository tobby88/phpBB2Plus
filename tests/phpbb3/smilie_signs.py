"""Use the ordinary composers, saved BBCode and preserved local sign images."""
from html import unescape
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import shutil
import struct
import subprocess
import tempfile
from urllib.parse import urlencode, urljoin, urlsplit

from http_board import FormFields, submit_form


class Signs(HTMLParser):
    def __init__(self, page):
        super().__init__(convert_charrefs=True)
        self.captions = []
        self.faces = []
        self.feed(page)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if attrs.get("class") == "plus-smilie-sign":
            self.captions.append(attrs.get("aria-label"))
        if attrs.get("class") == "plus-sign-face":
            self.faces.append(attrs["src"].split("/")[-1])


def browse(admin, member, phase):
    topic = "viewtopic.php?t=1"
    posting = "posting.php?mode=reply&t=1"
    knowledge = "app.php/knowledge/article/214/edit"
    phase("converted")
    for path in (topic, "app.php/knowledge/article/214"):
        body = admin.fetch(path)
        signs = Signs(body)
        if "Grüße vom Archiv" not in signs.captions or "smilie19.png" not in signs.faces:
            raise RuntimeError(f"The imported sign is missing at {path} ({len(signs.faces)} faces, "
                               f"caption text present: {'Grüße vom Archiv' in body}, raw markup: {'[schild=19' in body})")
    phase("historical")
    try:
        legacy = member.fetch(topic)
        if Signs(legacy).captions != ["Grüße :) & Mühle"] or "Unberührt" not in legacy or "[schild=3]Literal[/schild]" not in legacy:
            raise RuntimeError("A previously converted sign lost its text or changed adjacent code")
        edit_path = "posting.php?mode=edit&p=1"
        edit_page = admin.fetch(edit_path)
        edit = FormFields(edit_page, "postform").values.get("message", "")
        if "[schild=2 fontcolor=223344 shadowcolor=445566 shieldshadow=0]Grüße :) & Mühle[/schild]" not in edit or "[b]Unberührt[/b]" not in edit:
            raise RuntimeError("Editing an older formatted post did not restore its original sign and surrounding BBCode")
        updated = submit_form(admin, edit_path, edit_page, "postform", {"message": edit.replace("Mühle", "Morgen"), "preview": "1"})
        if "Grüße :) & Morgen" not in Signs(updated).captions:
            raise RuntimeError("A new edit of existing sign markup did not round-trip through the native formatter")
        phase("disabled")
        inactive = member.fetch(topic)
        if Signs(inactive).captions or "[schild=2 fontcolor=" not in inactive:
            raise RuntimeError("A previously converted post with BBCode disabled acquired an image")
    finally:
        phase("cleanup")
    for path, target in ((posting, "message"), (knowledge, "kb-body")):
        if 'data-sign-target="' + target + '"' not in admin.fetch(path):
            raise RuntimeError("The original sign creator is missing from an editor")
    creator = "app.php/smilie-creator"
    old = member.fetch("smilie_creator.php?mode=text2schild")
    if 'id="plus-sign-form"' not in old or "Smiley with a sign" not in old or old.count('type="radio" name="face"') != 21:
        raise RuntimeError("The creator lost an original face option or the English translation")
    german = admin.fetch(creator)
    if "Smiley mit Schild" not in german:
        raise RuntimeError("The sign creator lost its German controls")
    form = {"make": "1", "caption": "Klasse Ä & 茶 🐸", "face": "17", "fontcolor": "#012345", "textshadow": "1", "shadowcolor": "#667788", "shieldshadow": "0"}
    made = member.fetch(creator, form)
    markup = unescape(re.search(r'<textarea readonly id="plus-sign-code"[^>]*>(.*?)</textarea>', made, re.S)[1])
    if Signs(made).captions != [form["caption"]] or Signs(made).faces != ["smilie17.png"] or "shieldshadow=0" not in markup:
        raise RuntimeError("Generating a sign lost the text, face or shade")
    for editor, form_id, field in ((posting, "postform", "message"), (knowledge, "kb-editor", "body")):
        preview = submit_form(admin, editor, admin.fetch(editor), form_id, {field: markup, "preview": "1"})
        if form["caption"] not in Signs(preview).captions:
            raise RuntimeError("The generated markup did not preview in its native editor")
    disabled = submit_form(admin, posting, admin.fetch(posting), "postform", {"message": markup, "preview": "1", "disable_bbcode": "on"})
    if form["caption"] in Signs(disabled).captions:
        raise RuntimeError("The native post editor ignored the user's choice to disable BBCode")
    if Signs(admin.fetch(creator, dict(form, face="random"))).faces[0] not in {f"smilie{i}.png" for i in range(1, 20)}:
        raise RuntimeError("The historical random option did not select a local face")
    member.fetch(creator, dict(form, caption="x" * 397), expected_status=422)
    pictures = []
    for text in ("Grüße", "Gr??e", "Wort " * 220):
        png = member.fetch("text2schild.php?" + urlencode(dict(text=text, smilie="17", fontcolor="012345", shieldshadow=1)), raw=True)
        if png[:8] != b"\x89PNG\r\n\x1a\n" or not (60 <= struct.unpack(">I", png[16:20])[0] <= 204 and 34 < struct.unpack(">I", png[20:24])[0] <= 322):
            raise RuntimeError("The old direct PNG address failed its image or text bounds")
        pictures.append(png)
    if pictures[0] == pictures[1]:
        raise RuntimeError("The preserved direct PNG renderer lost the German glyphs")
    engine = os.environ.get("PLUS_TEST_CHROME")
    if engine:
        chrome, node = shutil.which(engine), shutil.which("node")
        if not chrome or not node or urlsplit(admin.base).hostname != "127.0.0.1":
            raise RuntimeError("The sign popup check requires the requested Chromium, Node.js and a loopback board")
        with tempfile.TemporaryDirectory(prefix="plus3-sign-popup-") as profile:
            # Pass the disposable board session only through stdin to this browser.
            session = {"cookies": [(c.name, c.value) for c in admin.cookies], "agent": dict(admin.opener.addheaders)["User-agent"]}
            child = subprocess.run([node, "--experimental-websocket", str(Path(__file__).with_name("gallery_progress_browser.cjs")),
                                    chrome, profile, urljoin(admin.base, posting), "smilie-creator"], input=json.dumps(session),
                                   text=True, encoding="utf-8", capture_output=True, timeout=55)
            if child.returncode:
                raise RuntimeError("The Chromium sign popup failed: " + child.stderr[-250:])
