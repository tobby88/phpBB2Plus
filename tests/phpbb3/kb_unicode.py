"""Verify plain UTF-8 text and literal character references through the native MySQLi connection."""
from html import unescape
from pathlib import Path
import re
from urllib.parse import urlencode

from delegation import authenticate
from http_board import Browser, FormFields, submit_form
from unicode_forms import UnicodeControls


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")
    def stage(phase):
        php(fixture, target, phase, cwd=board)
    path = "app.php/knowledge/article/26001"
    stage("prepare")
    try:
        public = Browser(base)
        body = unescape(public.fetch(path))
        if not all(value in body for value in ("Unicodebogen 🧭", "Ärmel 𠜎", "Gast 🐚", "Fächer 🌺", "Mond 🌙", "🛰️", "東京", "&#128512;")):
            raise RuntimeError("The public converted article lost text or reinterpreted a literal character reference")
        def hit(term, mode=""):
            page = public.fetch("app.php/knowledge?" + urlencode({"q":term, "match":mode}))
            return re.search(r'href="[^"]*/article/26001(?:[?"][^>]*)', page) is not None
        for term in ("🛰️", "🌺", "𠜎", "&#128512;", "Zeile_41%"):
            if not hit(term):
                raise RuntimeError("The KB did not match an exact Unicode substring: " + term)
        for term in ("kbzeile_41%", "ZeileX41%", "😀"):
            if hit(term):
                raise RuntimeError("A case, wildcard or encoded search spuriously matched: " + term)
        if not hit("KbZ*41%", "any"):
            raise RuntimeError("The historical KB wildcard no longer matched a Unicode article")
        editor, _ = authenticate(base, "ModernMember")
        edit = path + "/edit"
        page = editor.fetch(edit)
        fields = FormFields(page, "kb-editor").values
        if fields["title"] != "Unicodebogen 🧭" or "[b]東京[/b]" not in fields["body"] or "Feld &#128512;" not in fields["body"]:
            raise RuntimeError("The editable source did not round trip through conversion")
        title = "🧩" * 255
        description = "𠜎" * 255
        full_body = "𠜎" * 65535
        controls = UnicodeControls(page)
        for field, maximum in (("title", title), ("description", description), ("body", full_body)):
            controls.accepts("kb-" + field, maximum)
        preview_fields = {"title": title, "description": description, "body": full_body, "preview": 1}
        preview = submit_form(editor, edit, page, "kb-editor", preview_fields)
        preview_values = FormFields(preview, "kb-editor").values
        if unescape(preview).count(full_body) < 2 or any(preview_values[k] != preview_fields[k] for k in ("title", "description", "body")):
            raise RuntimeError("Preview truncated the formatted article or editable Unicode source")
        for key, invalid in (("title", title + "a"), ("description", description + "a"), ("body", full_body + "a")):
            attempted = dict(preview_fields, **{key: invalid})
            bad = submit_form(editor, edit, page, "kb-editor", attempted, 422)
            if 'class="error"' not in bad or FormFields(bad, "kb-editor").values[key] != invalid:
                raise RuntimeError("The editor did not retain an overlong " + key + " with its validation error")
        if FormFields(editor.fetch(edit), "kb-editor").values["title"] != fields["title"]:
            raise RuntimeError("A preview or rejected edit persisted instead of displaying the article form")
        guest_url = "app.php/knowledge/new"
        guest_page = public.fetch(guest_url)
        guest_name = "Gast 🐚"
        rejected = submit_form(public, guest_url, guest_page, "kb-editor", {
            "title": "Unicode-Gast", "description": "Beschreibung", "body": "Beispiel", "category": 8, "type": 2,
            "guest": guest_name, "save": 1}, 422)
        if FormFields(rejected, "kb-editor").values["guest"] != guest_name or not any(
                error in rejected for error in ("zulässigen Gastnamen", "available guest name")):
            raise RuntimeError("The native guest-name rule or its error feedback changed")
        changes = {"title":title, "description":"Neu 😀 und &#128512;", "body":"Kerze 🕯️[toc] Licht 🪔 &#128512; [b]Frühling[/b]", "save":1}
        saved = submit_form(editor, edit, page, "kb-editor", changes)
        if "Licht" not in unescape(saved) or not hit("🪔"):
            raise RuntimeError("The edited KB article was missing from the page or Unicode search")
        latest = FormFields(editor.fetch(edit), "kb-editor").values
        if any(latest[field] != changes[field] for field in ("title", "description", "body")):
            raise RuntimeError("Saving and reopening the native KB editor changed the UTF-8 source")
        stage("saved")
    finally:
        stage("clean")
