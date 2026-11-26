"""Read the converted dictionary on its original pages and manage it through a delegated ACP."""
from html.parser import HTMLParser
import re

from delegation import authenticate
from http_board import FormFields, admin_url, submit_form


class Definitions(HTMLParser):
    def __init__(self, page):
        super().__init__()
        self.found = {}
        self.term = None
        self.feed(page)

    def handle_starttag(self, tag, attrs):
        if tag == "abbr":
            self.term = ""
            self.meaning = dict(attrs).get("title")

    def handle_data(self, data):
        if self.term is not None:
            self.term += data

    def handle_endtag(self, tag):
        if tag == "abbr" and self.term is not None:
            self.found[self.term] = self.meaning
            self.term = None


def browse(base, admin, member):
    topic = "viewtopic.php?t=1"
    for path in (topic, "app.php/knowledge/article/214", "app.php/news"):
        values = Definitions(admin.fetch(path)).found
        if values.get("KöFo AG") != 'KöFo & "Arbeitsgruppe" 🧭' or values.get("KöFo") != "Kölner Forum":
            raise RuntimeError("Missing an imported acronym in " + path)
    actor, entry = authenticate(base, "AcronymJunior")
    mode = "&i=-phpbb2plus-community-acp-acronyms_module&mode=manage"
    panel = entry + mode
    if "Abkürzungen" not in admin.fetch(admin_url(admin) + mode) or "Add an acronym" not in actor.fetch(panel):
        raise RuntimeError("The native acronym editor is missing in one language")
    create = panel + "&action=add"
    form = "plus-acronym"
    term = "Lexikonprobe"
    definition = 'Bücher & "Zitate" 📚'
    first = actor.fetch(create)
    fields = {"term": term, "meaning": definition}
    created = submit_form(actor, create, first, form, fields)
    ident = re.search(r"<td>Lexikonprobe</td>.*?action=edit&amp;acronym=(\d+)", created, re.S)
    if not ident or Definitions(member.fetch(topic)).found.get(term) != definition:
        raise RuntimeError("A new acronym did not reach an existing topic")
    duplicate = submit_form(actor, create, actor.fetch(create), form, fields)
    if "already in the dictionary" not in duplicate:
        raise RuntimeError("The editor accepted a duplicate acronym")
    edit = panel + "&action=edit&acronym=" + ident[1]
    delete = panel + "&action=delete&acronym=" + ident[1]
    old_confirmation = actor.fetch(delete)
    original = actor.fetch(edit)
    if FormFields(original, form).values.get("meaning") != definition:
        raise RuntimeError("The acronym editor did not preserve its original Unicode and punctuation")
    submit_form(actor, edit, original, form, {"term": term, "meaning": "Neue Bedeutung"})
    stale = submit_form(actor, delete, old_confirmation, form, {"confirm": "1"})
    if "entry has changed" not in stale or Definitions(member.fetch(topic)).found.get(term) != "Neue Bedeutung":
        raise RuntimeError("An old delete form removed or obscured the edited definition")
    submit_form(actor, delete, actor.fetch(delete), form, {"confirm": "1"})
    if term in Definitions(member.fetch(topic)).found:
        raise RuntimeError("A deleted acronym remained in the rendered dictionary cache")
