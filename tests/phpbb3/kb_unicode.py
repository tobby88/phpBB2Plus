"""Verify plain UTF-8 text and literal character references through the native MySQLi connection."""
from html import unescape
from pathlib import Path
import re
from urllib.parse import urlencode

from delegation import authenticate
from http_board import Browser, FormFields, submit_form


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
