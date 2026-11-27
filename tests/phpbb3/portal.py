"""Exercise the migrated three-column portal and its native ACP settings."""
from html import unescape
from pathlib import Path
import re
import time
from urllib.parse import urljoin

from http_board import Browser, Page, admin_url


def browse(base, owner):
    guest = Browser(base)
    public = guest.fetch("app.php/portal")
    private = owner.fetch("app.php/portal")
    for html in (public, private):
        if ('class="plus-welcome-center"' not in html or '<strong>Archiv</strong>' not in html or "Grüße" not in html
                or "ftopic1.html" not in html or html.count("data-forum=") != 2 or 'data-forum="14"' in html):
            raise RuntimeError("The welcome message, recent topics limit or excluded forum diverged")
        if 'id="plus-portal-poll"' not in html or 'class="panel plus-stats"' not in html or 'class="panel plus-top"' not in html:
            raise RuntimeError("The original poll, top posters or board statistics disappeared from the portal")
    origin = urljoin(base, "app.php/portal")
    img = re.search(r'<img src="([^"]+)" alt="Briefkopf"', public)
    if not img or "/images/plusportal/" not in img.group(1):
        raise RuntimeError("The welcome block still depends on artwork on the source board")
    image_file = Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images/news/topicnews.gif"
    if guest.fetch(urljoin(origin, unescape(img.group(1))), raw=True) != image_file.read_bytes():
        raise RuntimeError("The portal's migrated artwork has different bytes")
    if ('class="plus-shout"' in public or 'class="plus-shout"' not in private
            or 'id="plus-portal-login"' not in public or 'id="plus-portal-login"' in private):
        raise RuntimeError("The sidebar ignored the visitor or the original members-only Shoutbox setting")
    if ('id="plus-shout-panel"' in owner.fetch("index.php") or 'id="plus-shout-quick"' not in private
            or "/shout_panel.css" not in private):
        raise RuntimeError("The converted portal-only placement or member's quick shout form is missing")
    for html, visible in ((public, False), (private, True)):
        block = re.search(r'<section class="panel plus-seen">(.+?)</section>', html, re.S)
        if not block:
            raise RuntimeError("The enabled last-seen portal block is missing")
        if ("DepartingFixture" in block.group(1)) != visible:
            audience = "administrator" if visible else "guest"
            raise RuntimeError(f"The recently signed-in hidden member has the wrong visibility for the {audience}")


def media(base, founder, member, pending):
    guest = Browser(base)
    for visitor, club in ((guest, False), (pending, False), (member, True), (founder, True)):
        page = visitor.fetch("app.php/portal")
        downloads = re.search(r'<section class="panel plus-portal-downloads".*?</section>', page, re.S)
        gallery = re.search(r'<section class="panel plus-portal-album".*?</section>', page, re.S)
        if not downloads or not gallery:
            raise RuntimeError("An enabled media block disappeared from the converted portal")
        files = [int(value) for value in re.findall(r'<li><a href="[^"]*/downloads/file/(\d+)', downloads[0])]
        if files != ([54, 53, 51] if club else [53, 50]):
            raise RuntimeError(f"Portal downloads have the wrong approval, access, order or limit: {files}")
        pics = [int(value) for value in re.findall(r'<img src="[^"]*/album/picture/(\d+)/small', gallery[0])]
        if pics != ([105, 104, 101, 100] if club else [105, 100]) or "--plus-preview-size: 137px" not in gallery[0]:
            raise RuntimeError(f"Portal album filters, sorting or preview size changed: {pics}")
        expected_rating = "Rating: 7.00" if visitor is member else "Bewertung: 7,00"
        if expected_rating not in gallery[0] or not re.search(r'(Zugriffe|Views): 14\b', gallery[0]) or "Öffentliche Fotoprobe" not in gallery[0]:
            raise RuntimeError("The portal album lost the converted rating, views or picture title")
        if visitor is guest:
            image = re.search(r'<img src="([^"]*/album/picture/100/small[^"]*)"', gallery[0])
            if not image or not guest.fetch(urljoin(urljoin(base, "app.php/portal"), unescape(image[1])), raw=True).startswith(b"\x89PNG"):
                raise RuntimeError("The album block did not link to a working protected thumbnail")


def administration(base, owner):
    guest = Browser(base)
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_board&mode=settings")
    link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Portal</span>', menu)
    if not link:
        raise RuntimeError("Board configuration lacks the portal ACP page")
    path = urljoin(entry, unescape(link.group(1)))
    first = owner.fetch(path)
    welcome = unescape(re.search(r'<textarea[^>]+name="welcome"[^>]*>(.*?)</textarea>', first, re.S).group(1))
    fields = dict(welcome="<p>Neuer <i>Text</i> &amp; Grüße</p>", recent="0", last_seen="0", top_posters="0", stats="0",
                  shouts="0", save="1", downloads="0", pictures="0", picture_size="120", picture_personal="0", picture_random="0",
                  picture_categories="", **{"poll_forums[]": [14]})

    def save(page, values):
        action, body = Page(page).forms["plus-portal-settings"]
        body.update(values)
        time.sleep(max(0, int(body["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(path, unescape(action)), body)

    save(first, fields)
    page = guest.fetch("app.php/portal")
    if ('<em>Text</em> &amp; Grüße' not in page or any(x in page for x in
            ('class="plus-recent"', 'class="panel plus-seen"', 'class="panel plus-top"', 'class="panel plus-stats"', 'class="plus-shout"',
             'class="panel plus-portal-downloads"', 'class="panel plus-portal-album"'))
            or not re.search(r'id="plus-portal-poll"><summary>Ballot 602</summary>', page)):
        raise RuntimeError("Saving the portal settings did not update the welcome, sections or selected poll")
    if "Portal-Einstellungen wurden inzwischen geändert" not in save(first, dict(fields, recent="1")):
        raise RuntimeError("A stale portal settings form overwrote the saved layout")
    save(owner.fetch(path), dict(fields, shouts="1", pictures="1", picture_random="1", picture_categories="8"))
    public = guest.fetch("app.php/portal")
    if "Grüße aus dem Archiv" not in public or "FormerShouter" in public or "Masked shout fixture" in public:
        raise RuntimeError("A public portal preview lost visible shouts or disclosed a private byline or message")
    if 'id="plus-shout-quick"' in public or 'id="plus-shout-panel"' in guest.fetch("index.php"):
        raise RuntimeError("A portal-only public panel exposed a write form or appeared on the index")
    restricted = owner.fetch("app.php/portal")
    if ('class="panel plus-portal-album"' in public or not re.search(r'/album/picture/101/small', restricted)
            or re.search(r'/album/picture/(?:100|104|105)/small', restricted)):
        raise RuntimeError("Random portal pictures escaped the chosen category or its visibility")
    save(owner.fetch(path), dict(fields, shouts="6"))
    if 'id="plus-shout-panel"' in guest.fetch("index.php") or 'id="plus-shout-panel"' in owner.fetch("app.php/portal"):
        raise RuntimeError("The members-only forum index placement was visible elsewhere")
    index = owner.fetch("index.php")
    action, quick = Page(index).forms["plus-shout-quick"]
    quick["message"] = "Gruß von der Forenübersicht"
    time.sleep(max(0, int(quick["creation_time"]) + 1.05 - time.time()))
    posted = owner.fetch(urljoin(base, unescape(action)), quick)
    if 'id="plus-shout-panel"' not in posted or 'class="plus-portal"' in posted or quick["message"] not in posted:
        raise RuntimeError("A quick index shout did not return to the forum panel with the saved message")
    save(owner.fetch(path), dict(fields, shouts="3"))
    for route in ("index.php", "app.php/portal"):
        visible = guest.fetch(route)
        if quick["message"] not in visible or "FormerShouter" in visible or "Masked shout fixture" in visible:
            raise RuntimeError("Public shout panels must share the saved message and protect hidden names and texts")
    save(owner.fetch(path), dict(fields, welcome=welcome, recent="2", last_seen="5", top_posters="5", stats="1", shouts="2",
                                 downloads="3", pictures="6", picture_personal="1", picture_size="137",
                                 **{"poll_forums[]": [1, 7], "exclude[]": [14]}))
    if "/album/picture/103/small" not in owner.fetch("app.php/portal") or "/album/picture/103/small" in guest.fetch("app.php/portal"):
        raise RuntimeError("Enabling personal portal albums ignored the owner's album policy")
    browse(base, owner)
