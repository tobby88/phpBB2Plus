"""Compare the rules opened from real post and PM composers with their native file inputs."""
from html import unescape
from html.parser import HTMLParser
import re
import time
from urllib.parse import urljoin

from http_board import Page, submit_form


class Rules(HTMLParser):
    def __init__(self, page):
        super().__init__(convert_charrefs=True)
        self.groups = {}
        self.active = False
        self.row = self.cell = None
        self.feed(page)

    def handle_starttag(self, tag, attrs):
        if tag == "table":
            self.active = dict(attrs).get("id") == "plus-attach-rules"
        if not self.active:
            return
        if tag == "tr":
            self.row = []
        elif tag in ("th", "td"):
            self.cell = ""

    def handle_data(self, value):
        if self.cell is not None:
            self.cell += value

    def handle_endtag(self, tag):
        if not self.active:
            return
        if tag in ("th", "td") and self.cell is not None:
            self.row.append(self.cell.strip())
            self.cell = None
        elif tag == "tr" and len(self.row) == 3 and self.row[1].startswith("."):
            self.groups[self.row[0]] = (set(self.row[1].split(", ")), self.row[2])
        elif tag == "table":
            self.active = False


def browse(admin, member, applicant, guest, phase):
    path = "app.php/attachment/rules/"

    def editor(actor, address, forum):
        page = actor.fetch(address)
        link = re.search(r'<a href="([^"]*/attachment/rules/' + str(forum) + r'(?:\?[^"]*)?)"[^>]*>', page)
        file_input = re.search(r'<input\b[^>]*\bid="fileupload"[^>]*>', page)
        accept = re.search(r'\baccept="([^"]*)"', file_input[0]) if file_input else None
        if not link or not accept:
            raise RuntimeError("The native attachment editor did not link its own rules")
        rules = Rules(actor.fetch(urljoin(actor.base, unescape(link[1])))).groups
        if {ext for extensions, size in rules.values() for ext in extensions} != set(accept[1].split(",")):
            raise RuntimeError("The displayed types differed from the native file chooser")
        return rules

    def note(page, address, content=None):
        action, fields = Page(page).forms["postform"]
        fields.update(subject="Attachment limit fixture", message="A disposable attachment draft.")
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        url = urljoin(urljoin(applicant.base, address), action)
        if content is None:
            fields["delete_file[0]"] = "Delete file"
            return applicant.fetch(url, fields)
        fields["add_file"] = "Add file"
        return applicant.fetch(url, fields, files=[("fileupload", "note.frules", "text/plain", content)])

    phase("seed")
    try:
        regular = editor(applicant, "posting.php?mode=reply&t=1", 1)
        private = editor(applicant, "ucp.php?i=pm&mode=compose", 0)
        limited = Rules(member.fetch("attach_rules.php?f=14")).groups
        expected = (
            (regular, "Forum notes", ({".frules"}, "307 Bytes")),
            (limited, "Notes & plans", ({".rpanel"}, "No board size limit")),
            (private, "Message notes", ({".prules"}, "601 Bytes")),
            (private, "Notes & plans", ({".rpanel"}, "77 Bytes")),
            (regular, "Notes & plans", None), (regular, "Message notes", None),
            (private, "Forum notes", None), (private, "Disabled notes", None), (regular, "Disabled notes", None),
        )
        mismatches = [(label, value, groups.get(label)) for groups, label, value in expected if groups.get(label) != value]
        if mismatches:
            raise RuntimeError(f"Unexpected group policies {mismatches!r}")
        german = admin.fetch("attach_rules.php")
        if ("Anhangregeln für private Nachrichten" not in german or
                Rules(german).groups["Notes & plans"][1] != "Kein Größenlimit des Forums"):
            raise RuntimeError("The native administrative size exemption or the German page is missing")
        if Rules(applicant.fetch("attach_rules.php?f=0")).groups != private:
            raise RuntimeError("The historical PM attachment address lost its native types")
        guest.fetch("attach_rules.php", expected_status=403)
        guest.fetch(path + "1", expected_status=404)
        applicant.fetch("attach_rules.php?f=14", expected_status=404)
        member.fetch(path + "999999", expected_status=404)
        member.fetch("attach_rules.php?f=14", {}, expected_status=405)
        address = "posting.php?mode=reply&t=1"
        too_large = note(applicant.fetch(address), address, b"x" * 308)
        if not re.search(r'class="error"[^>]*>[^<]*307\s+Bytes', too_large, re.I):
            raise RuntimeError("The ordinary uploader enforced a different limit from the group rules")
        accepted = note(applicant.fetch(address), address, b"x")
        if not re.search(r'data-attach-id="[1-9][0-9]*"', accepted) or "note.frules" not in accepted:
            raise RuntimeError("The member could not attach a note of the displayed file type")
        if re.search(r'data-attach-id="[1-9][0-9]*"', note(accepted, address)):
            raise RuntimeError("The native attachment editor kept the disposable draft")
        phase("password")
        member.fetch(path + "14", expected_status=404)
        gate = "viewforum.php?f=14"
        submit_form(member, gate, member.fetch(gate), "login_forum", {"password": "local-attachment-room", "login": "1"})
        if Rules(member.fetch(path + "14")).groups != limited:
            raise RuntimeError("Unlocking the forum did not restore the member's rules")
        phase("posts_off")
        member.fetch(path + "1", expected_status=404)
        if Rules(applicant.fetch(path + "0")).groups != private:
            raise RuntimeError("Turning off post uploads unexpectedly disabled PM attachment rules")
        phase("messages_off")
        member.fetch(path + "0", expected_status=403)
        if Rules(applicant.fetch(path + "1")).groups != regular:
            raise RuntimeError("Turning off PM uploads changed an ordinary forum")
        phase("empty")
        empty = member.fetch(path + "0")
        if Rules(empty).groups or "No file types are currently enabled" not in empty:
            raise RuntimeError("The empty PM attachment policy did not render")
    finally:
        phase("cleanup")
