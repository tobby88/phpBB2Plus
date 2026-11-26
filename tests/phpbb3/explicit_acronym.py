"""Read and edit explicit CMX abbreviations using native topic, PM and KB pages."""
from html.parser import HTMLParser
from http_board import FormFields, submit_form


class Abbreviations(HTMLParser):
    def __init__(self, page):
        super().__init__(convert_charrefs=True)
        self.entries = []
        self.active = []
        self.feed(page)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag in ("abbr", "em"):
            entry = [tag, attrs["title"], ""] if attrs.get("title") else None
            if entry: self.entries.append(entry)
            self.active.append((tag, entry))

    def handle_endtag(self, tag):
        if self.active and tag == self.active[-1][0]:
            self.active.pop()

    def handle_data(self, data):
        for _, entry in self.active:
            if entry: entry[2] += data


def browse(admin, member, phase):
    phase("converted")
    topic, edit = "viewtopic.php?t=1", "posting.php?mode=edit&p=1"
    kb, pm = "app.php/knowledge/article/214", "ucp.php?i=pm&mode=view&p=111"
    sample = '[acronym="Schüler & Archiv"]Abi[/acronym]'
    for actor, page in ((admin, topic), (member, pm), (member, kb)):
        if ["abbr", "Schüler & Archiv", "Abi"] not in Abbreviations(actor.fetch(page)).entries:
            raise RuntimeError("The imported explicit acronym did not render at " + page)
    phase("older")
    try:
        for path in (topic, pm):
            entries = Abbreviations(member.fetch(path)).entries
            if ["abbr", "Alte Grüße & Rat", "ABC"] not in entries or any(a[1] == "Beispiel" for a in entries):
                raise RuntimeError("An earlier preview lost its formatted abbreviation or changed a code example at " + path)
        if ["abbr", "Schüler & Archiv", "Abi"] not in Abbreviations(member.fetch(kb)).entries:
            raise RuntimeError("An earlier Knowledge Base page lost its explicit abbreviation")
        if sample not in FormFields(admin.fetch(kb + "/edit"), "kb-editor").values["body"]:
            raise RuntimeError("An earlier KB preview offered a source UID as editable BBCode")
        editor = admin.fetch(edit)
        text = FormFields(editor, "postform").values["message"]
        if '[acronym="Alte Grüße & Rat"]A[i]B[/i]C[/acronym]' not in text or "[b]Vorher[/b]" not in text:
            raise RuntimeError("Editing a historical acronym lost surrounding or nested formatting")
        pm_compose = "ucp.php?i=pm&mode=compose&u=2"
        editors = ((admin, edit, "postform", "message", editor), (admin, kb + "/edit", "kb-editor", "body", admin.fetch(kb + "/edit")),
                   (member, pm_compose, "postform", "message", member.fetch(pm_compose)))
        for actor, path, form_id, field, page in editors:
            preview = submit_form(actor, path, page, form_id, {field: sample, "subject": "Acronym fixture", "preview": "1"})
            if ["abbr", "Schüler & Archiv", "Abi"] not in Abbreviations(preview).entries:
                raise RuntimeError("The native composer did not preview its own BBCode at " + path)
        disabled = submit_form(admin, edit, editor, "postform", {"message": sample, "preview": "1", "disable_bbcode": "on"})
        if ["abbr", "Schüler & Archiv", "Abi"] in Abbreviations(disabled).entries:
            raise RuntimeError("Disabling BBCode in the editor still generated an abbreviation")
        phase("off")
        if ["abbr", "Alte Grüße & Rat", "ABC"] in Abbreviations(member.fetch(topic)).entries:
            raise RuntimeError("An explicitly disabled archived post acquired abbreviation markup")
        phase("custom")
        custom = Abbreviations(member.fetch(topic)).entries
        if ["abbr", "Alte Grüße & Rat", "ABC"] in custom or ["em", "Alte Grüße & Rat", "ABC"] not in custom:
            raise RuntimeError("The board's native BBCode did not take precedence for an earlier preview")
        native = submit_form(admin, edit, admin.fetch(edit), "postform", {"message": sample, "preview": "1"})
        if ["em", "Schüler & Archiv", "Abi"] not in Abbreviations(native).entries:
            raise RuntimeError("Saving new text ignored the board's existing native BBCode")
    finally:
        phase("restore")
