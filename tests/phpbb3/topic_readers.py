"""Read the converted list through native sessions and forum-password forms."""
from html.parser import HTMLParser
from urllib.parse import urljoin
from urllib.request import Request

from http_board import Browser, Page


class Readers(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.rows = {}
        self.cells = None
        self.text = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "tr" and "data-reader" in attrs:
            self.cells = self.rows.setdefault(int(attrs["data-reader"]), [])
        if self.cells is not None and tag == "td":
            self.text = []

    def handle_data(self, data):
        if self.text is not None:
            self.text.append(data)

    def handle_endtag(self, tag):
        if self.text is not None and tag == "td":
            self.cells.append("".join(self.text))
            self.text = None
        if tag == "tr":
            self.cells = None


def browse(base, founder, member, change):
    path = "app.php/topic/1/readers"
    guest = Browser(base)
    if "login" not in Page(guest.fetch("topic_view_users.php?t=1")).forms:
        raise RuntimeError("Anonymous visits to the old reader list must open the native login")
    page = member.fetch(path)
    rows = Readers(page).rows
    owner = next(key for key, cells in rows.items() if cells[0] == "HistoricMember")
    if "Who has read this topic?" not in page or "Wer hat dieses Thema gelesen?" not in founder.fetch(path):
        raise RuntimeError("Reader lists did not use the member's language")
    before = int(rows[owner][2])
    page = member.fetch("viewtopic.php?t=1")
    if "topic/1/readers" not in page or "topic/1/readers" in guest.fetch("viewtopic.php?t=1"):
        raise RuntimeError("The native topic tools displayed the reader link to the wrong audience")
    with member.opener.open(Request(urljoin(base, "viewtopic.php?t=1"), method="HEAD")) as response:
        if response.status != 200:
            raise RuntimeError("Topic HEAD failed")
    sorted_rows = Readers(member.fetch("topic_view_users.php", {"t": "1", "mode": "topic_count", "order": "DESC"})).rows
    if int(sorted_rows[owner][2]) != before + 1 or list(sorted_rows) != sorted(sorted_rows, key=lambda key: -int(sorted_rows[key][2])):
        raise RuntimeError("Topic refresh, HEAD or the original sorting form changed reader counts incorrectly")
    if "plus-topic-readers" not in member.fetch(path + "?mode=website") or int(Readers(member.fetch(path)).rows[1][2]) != int(rows[1][2]) + 1:
        raise RuntimeError("Guest aggregation or profile-field sorting failed")
    try:
        change("origin")
        if Readers(member.fetch(path)).rows[2][2] != rows[2][2]:
            raise RuntimeError("A public topic disclosed visits from a locked source forum")
        origin = "viewforum.php?f=9"
        action, fields = Page(member.fetch(origin)).forms["login_forum"]
        member.fetch(urljoin(urljoin(base, origin), action), dict(fields, password="original-room-fixture"))
        if int(Readers(member.fetch(path)).rows[2][2]) != int(rows[2][2]) + 30:
            raise RuntimeError("Unlocking the original forum failed to restore its historical visits")
        change("visibility")
        member.fetch("viewtopic.php?t=1")
        visible = Readers(member.fetch(path)).rows
        if 87 in visible or int(visible[owner][2]) != before + 1 or 87 not in Readers(founder.fetch(path)).rows:
            raise RuntimeError("Hidden sessions or the permission to see hidden members were ignored")
        change("password")
        gate = member.fetch(path)
        action, fields = Page(gate).forms["login_forum"]
        if Readers(gate).rows or "plus-topic-readers" not in member.fetch(urljoin(urljoin(base, path), action), dict(fields, password="readers-fixture")):
            raise RuntimeError("Native forum-password protection did not gate reader history")
        if "login_forum" not in Page(founder.fetch(path)).forms:
            raise RuntimeError("Unlocking a forum exposed its readers to another session")
        change("disable")
        member.fetch(path, expected_status=404)
        if "topic/1/readers" in member.fetch("viewtopic.php?t=1"):
            raise RuntimeError("Disabled topic-reader tracking still appeared in the tools")
    finally:
        change("cleanup")
    change("lifecycle")
