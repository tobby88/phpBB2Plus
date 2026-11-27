"""Read converted Plus tables and preview the native editable source in real board forms."""
from html.parser import HTMLParser
import re
from http_board import FormFields, submit_form


class MessageTables(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.cells = []
        self.styles = []
        self.feed(html)

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "table":
            self.stack.append({"record": attrs.get("style", "").startswith("width: 90%") or bool(self.stack and self.stack[-1]["record"]),
                               "cell": None, "bold": False})
        if not self.stack or not self.stack[-1]["record"]:
            return
        table = self.stack[-1]
        if tag == "table":
            self.styles.append(attrs.get("style", ""))
        elif tag == "td":
            table["cell"] = {"style": attrs.get("style", ""), "text": "", "bold": ""}
            self.cells.append(table["cell"])
        elif tag in ("b", "strong"):
            table["bold"] = True

    def handle_endtag(self, tag):
        if not self.stack:
            return
        if tag == "table":
            self.stack.pop()
        elif tag == "td":
            self.stack[-1]["cell"] = None
        elif tag in ("b", "strong"):
            self.stack[-1]["bold"] = False

    def handle_data(self, text):
        if self.stack and self.stack[-1]["cell"] is not None:
            table = self.stack[-1]
            table["cell"]["text"] += text
            if table["bold"]:
                table["cell"]["bold"] += text


def browse(administrator, member, phase):
    phase("converted")
    topic = "viewtopic.php?t=1"
    edit = "posting.php?mode=edit&p=1"
    sample = ("[table=width: 90%; border-collapse: collapse]\n"
              "[cell=color: navy; padding: 2px 4px; letter-spacing: 3px]Zelle [b]Grüße[/b] ☆[/cell]\n"
              "[cell=text-align: center][table=][cell=]Innen[/cell][/table][/cell][/table]")

    def check(page, older=False):
        tables = MessageTables(page)
        styles = ["width: 90%" if older else "width: 90%; border-collapse: collapse", ""]
        colour = "color: navy" if older else "color: navy; padding: 2px 4px"
        byline = "Zelle Grüße 😀" if older else "Zelle Grüße ☆"
        if (tables.styles != styles or len(tables.cells) != 3 or tables.cells[0] != {"text": byline, "bold": "Grüße", "style": colour}
                or tables.cells[2]["text"] != "Innen"):
            raise RuntimeError("The displayed table lost its structure, a permitted style, nested formatting or Unicode text")

    for actor, url in ((member, topic), (member, "ucp.php?i=pm&mode=view&p=111"), (member, "app.php/knowledge/article/214")):
        check(actor.fetch(url))
    page = administrator.fetch(edit)
    kb_source = FormFields(administrator.fetch("app.php/knowledge/article/214/edit"), "kb-editor").values["body"]
    if sample not in FormFields(page, "postform").values["message"] or sample not in kb_source:
        raise RuntimeError("The post or Knowledge Base editor did not recover the original table source and line breaks")
    check(submit_form(administrator, edit, page, "postform", {"message": sample, "preview": "1"}))
    plain = submit_form(administrator, edit, page, "postform", {"message": sample, "preview": "1", "disable_bbcode": "on"})
    if MessageTables(plain).styles:
        raise RuntimeError("Disabling BBCode in a native editor still produced a formatted table")
    phase("older")
    try:
        check(member.fetch(topic), older=True)
        phase("disabled")
        if MessageTables(member.fetch(topic)).styles:
            raise RuntimeError("A converted post with BBCode switched off gained a table")
        phase("custom")
        historical = member.fetch(topic)
        native = 'class="test-native-table"' in historical
        bold = bool(re.search(r'<(b|strong)\b[^>]*>Grüße</\1>', historical))
        if not native or MessageTables(historical).styles or not bold:
            raise RuntimeError(f"A historical table lost its native definition or children (native={native}, bold={bold})")
        page = administrator.fetch(edit)
        custom = submit_form(administrator, edit, page, "postform", {"message": "[table=Gewählt]Eigene Definition[/table]", "preview": "1"})
        if 'class="test-native-table" title="Gewählt">Eigene Definition</div>' not in custom:
            raise RuntimeError("The editor did not use the board's native replacement for the Plus table")
        check(member.fetch("app.php/knowledge/article/214"))
    finally:
        phase("restore")
