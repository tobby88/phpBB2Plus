"""Open an imported member's attachment panel from both the old URL and the profile."""
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit

from http_board import Browser, Page


class PanelLink(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.href = None
        self.panel = ""
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self.href = dict(attrs).get("href", "")

    def handle_data(self, data):
        if self.href and data.strip() in ("Manage attachments", "Dateianhänge verwalten"):
            self.panel = self.href

    def handle_endtag(self, tag):
        if tag == "a":
            self.href = None


def browse(base, admin, member):
    guest = Browser(base)
    profile = "profile.php?mode=viewprofile&u=1"
    foreign = "profile.php?mode=viewprofile&u=2"
    own = "uacp.php?u=1&mode=download_count&order=DESC"
    if "login" not in Page(guest.fetch(own)).forms:
        raise RuntimeError("A historical attachment panel did not request a member session")
    page = member.fetch(profile)
    link = PanelLink(page).panel
    if not link or "Post attachments use " not in page or parse_qs(urlsplit(link).query).get("mode") != ["attachments"]:
        raise RuntimeError("The owner's converted profile did not show their attachment storage and native UCP link")
    if PanelLink(member.fetch(foreign)).panel or PanelLink(guest.fetch("memberlist.php?mode=viewprofile&u=2")).panel:
        raise RuntimeError("Another member or a guest could see a private storage panel")
    member.fetch("uacp.php?u=2", expected_status=403)
    member.fetch(own, {"delete": "1"}, expected_status=405)
    body = member.fetch(own)
    if "ucp" not in Page(body).forms or "This is a list of attachments you have made in posts to this board." not in body:
        raise RuntimeError("The legacy attachment panel did not open the native owner listing")
    data = urlencode({"u": "1", "mode": "filetime", "order": "DESC", "submit": "Sort"}).encode()
    with member.opener.open(urljoin(base, "uacp.php"), data) as response:
        listing = urlsplit(response.geturl())
        body = response.read().decode("utf-8")
    sort = parse_qs(listing.query)
    if not listing.path.endswith("/ucp.php") or sort.get("sk") != ["f"] or sort.get("sd") != ["d"] or "ucp" not in Page(body).forms:
        raise RuntimeError("The original attachment sorting form did not preserve the member's selection")
    other = PanelLink(admin.fetch(profile)).panel
    query = parse_qs(urlsplit(other).query)
    if not other or query.get("mode") != ["attach"] or len(query.get("u", [])) != 1 or int(query["u"][0]) <= 2:
        raise RuntimeError("The administration link lost the remapped account that used to be numbered 1")
    listed = admin.fetch(urljoin(base, other))
    if not set(Page(listed).forms).intersection(("login", "user_attachments")):
        raise RuntimeError("The administrator's link did not open native account attachment administration")
