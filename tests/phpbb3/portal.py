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
    for html, visible in ((public, False), (private, True)):
        block = re.search(r'<section class="panel plus-seen">(.+?)</section>', html, re.S)
        if not block or ("DepartingFixture" in block.group(1)) != visible:
            raise RuntimeError("Hidden members were shown in the wrong last-seen audience")


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
                  shouts="0", save="1", **{"poll_forums[]": [14]})

    def save(page, values):
        action, body = Page(page).forms["plus-portal-settings"]
        body.update(values)
        time.sleep(max(0, int(body["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(path, unescape(action)), body)

    save(first, fields)
    page = guest.fetch("app.php/portal")
    if ('<em>Text</em> &amp; Grüße' not in page or any(x in page for x in
            ('class="plus-recent"', 'class="panel plus-seen"', 'class="panel plus-top"', 'class="panel plus-stats"', 'class="plus-shout"'))
            or not re.search(r'id="plus-portal-poll"><summary>Ballot 602</summary>', page)):
        raise RuntimeError("Saving the portal settings did not update the welcome, sections or selected poll")
    if "Portal-Einstellungen wurden inzwischen geändert" not in save(first, dict(fields, recent="1")):
        raise RuntimeError("A stale portal settings form overwrote the saved layout")
    save(owner.fetch(path), dict(fields, welcome=welcome, recent="2", last_seen="5", top_posters="5", stats="1", shouts="2",
                                 **{"poll_forums[]": [1, 7], "exclude[]": [14]}))
    browse(base, owner)
