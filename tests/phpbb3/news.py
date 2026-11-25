"""Read migrated CMX news through the board, archive, portal and RSS."""
from html import unescape
from pathlib import Path
import re
import time
from urllib.parse import urljoin
from xml.etree import ElementTree

from http_board import Browser, Page, admin_url


def source_media(board):
    from attachments import GIF
    (board / "templates/fisubsilversh/images/news/Unused fixture.gif").write_bytes(GIF)


def browse(base, founder):
    guest = Browser(base)
    for actor, private in ((guest, False), (founder, True)):
        for page in ("app.php/news", "news_index.php", "portal.php"):
            html = actor.fetch(page)
            topics = set(re.findall(r'data-news-topic="(\d+)"', html))
            if topics != ({"1", "2", "7"} if private else {"1", "2"}):
                raise RuntimeError("The news archive or portal classified the wrong topics: " + str(topics))
            if "Grüße" not in html or not re.search(r'<strong[^>]*>Forum</strong>', html):
                raise RuntimeError("News lost native BBCode or Unicode in the first post")
        cats = actor.fetch("news_cats.php")
        if "Announcements" not in cats or ("Private desk" in cats) != private:
            raise RuntimeError("News category counts ignored forum visibility")
        feed = ElementTree.fromstring(actor.fetch("news_rss.php")).find("channel")
        titles = [item.findtext("title") for item in feed.findall("item")]
        if (len(titles) != (3 if private else 2) or ("Restricted topic fixture" in titles) != private
                or "Sample News Post in Portal" not in titles or feed.findtext("language") != "en-us"):
            raise RuntimeError("The RSS feed lost articles, locale or visibility")
        for item in feed.findall("item"):
            if (item.findtext("link") != item.findtext("guid") or "sid=" in (item.findtext("link") + item.findtext("description"))
                    or not item.findtext("pubDate")):
                raise RuntimeError("The RSS permalink or publication date is unstable")
        if (not re.search(r'/images/plusnews/[0-9a-f]{64}\.gif$', feed.findtext("image/url", ""))
                or feed.findtext("image/description") != "Forum & News"):
            raise RuntimeError("The RSS channel lost its configured artwork")
    if 'data-news-topic="7"' not in founder.fetch("news_index.php?cat_id=74"):
        raise RuntimeError("A restricted news category is missing from its reader's archive")
    guest.fetch("app.php/news/category/74", expected_status=404)
    if ElementTree.fromstring(guest.fetch("news_rss.php?cat_id=74")).findall("channel/item"):
        raise RuntimeError("A restricted category escaped its RSS scope")
    archive = guest.fetch("portal.php?news=archives&year=2004&month=5&key=Sample")
    if re.findall(r'data-news-topic="(\d+)"', archive) != ["2"] or "Announcements" not in archive:
        raise RuntimeError("The old archive link lost its civil date, category or title filter")
    if 'data-news-topic=' in guest.fetch("app.php/news?q=UnmatchedFixture"):
        raise RuntimeError("The news title filter returned unrelated articles")
    guest.fetch("app.php/news?year=2008&month=2&day=30", expected_status=404)
    html = guest.fetch("app.php/news/category/2")
    image = re.search(r'<img src="([^"]+)" alt="Announcements"', html)
    original = Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images/news/announcements.gif"
    if not image or guest.fetch(urljoin(urljoin(base, "app.php/news/category/2"), unescape(image.group(1))), raw=True) != original.read_bytes():
        raise RuntimeError("The news category artwork did not survive conversion")


def administration(base, owner):
    guest = Browser(base)
    entry = admin_url(owner)
    menus = owner.fetch(entry + "&i=acp_icons&mode=icons")
    link = re.search(r'<a[^>]+href="([^"]+)"[^>]*>\s*<span>Nachrichtenkategorien</span>\s*</a>', menus)
    if not link:
        raise RuntimeError("The news category editor is missing from the native posting ACP")
    panel = urljoin(entry, unescape(link.group(1)))

    def submit(url, html, form, values):
        action, fields = Page(html).forms[form]
        fields.update(values)
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(url, unescape(action)), fields)

    def edit_url(label):
        match = re.search(r'<a\b[^>]*\bhref="([^"]+)"[^>]*>' + re.escape(label) + r'</a>', owner.fetch(panel))
        if not match:
            raise RuntimeError("Missing news category in ACP: " + label)
        return urljoin(panel, unescape(match.group(1)))

    post = entry + "&i=acp_board&mode=post"
    feed = entry + "&i=acp_board&mode=feed"
    settings = owner.fetch(post)
    if 'config[plus_news_page_size]' not in settings or 'config[plus_news_feed_limit]' not in owner.fetch(feed):
        raise RuntimeError("Missing native administration of news and RSS settings")
    if any(name not in settings for name in ("config[plus_announce_default]", "config[plus_announce_sticky]")):
        raise RuntimeError("The posting ACP is missing the announcement expiry policy")
    submit(feed, owner.fetch(feed), "acp_board", {"submit": "1", "config[plus_news_feed]": "0"})
    guest.fetch("news_rss.php", expected_status=404)
    submit(feed, owner.fetch(feed), "acp_board", {"submit": "1", "config[plus_news_feed]": "1"})
    submit(post, owner.fetch(post), "acp_board", {"submit": "1", "config[plus_news_publish]": "0", "config[plus_announce_default]":"13"})
    editor = owner.fetch("posting.php?mode=post&f=1")
    if "Standarddauer von 13 Tagen" not in editor:
        raise RuntimeError("Changing the ACP announcement duration did not update the editor")
    if "plus_news_category" in editor or 'data-news-topic="2"' not in guest.fetch("app.php/news"):
        raise RuntimeError("Disabling new publications changed existing articles or left the selector active")
    submit(post, owner.fetch(post), "acp_board", {"submit": "1", "config[plus_news_publish]": "1", "config[plus_announce_default]":"7"})
    form = "plus-news-category"
    path = edit_url("Announcements")
    html = owner.fetch(path)
    art = re.search(r'name="category_image" value="([^"]+)" checked', html)
    if not art or "Unused fixture.gif" not in html:
        raise RuntimeError("The news editor lost assigned or unassigned artwork")
    values = dict(save="1", category_name="Mitteilungen & Grüße", category_image=art.group(1))
    submit(path, html, form, values)
    guest.fetch("app.php/news/category/2", expected_status=404)
    stale = submit(path, html, form, dict(values, active="1"))
    if "Nachrichtenkategorie wurde inzwischen geändert" not in stale:
        raise RuntimeError("A stale category form overwrote the current publication state")
    submit(path, owner.fetch(path), form, dict(values, active="1"))
    if "Mitteilungen &amp; Grüße" not in guest.fetch("app.php/news/category/2"):
        raise RuntimeError("The updated category name was lost or double encoded")
    new = edit_url("Nachrichtenkategorie anlegen")
    page = owner.fetch(new)
    values = dict(save="1", category_name="Rundschreiben", category_image=art.group(1), active="1")
    submit(new, page, form, values)
    submit(new, page, form, values)
    if owner.fetch(panel).count(">Rundschreiben</a>") != 1:
        raise RuntimeError("A repeated category creation added a duplicate")
    # Remove the migrated category that still has an article and retain its native topic.
    html = submit(path, owner.fetch(path), form, {"remove": "1"})
    confirm = Page(html).forms
    confirmation = next((key for key, (_, data) in confirm.items() if "confirm_uid" in data), None)
    if confirmation not in confirm or 'data-news-topic="2"' not in guest.fetch("app.php/news"):
        raise RuntimeError("Removing a news category skipped the native confirmation")
    submit(path, html, confirmation, {"confirm": "Ja"})
    if ('data-news-topic="2"' in guest.fetch("app.php/news") or "Sample News Post in Portal" not in guest.fetch("viewtopic.php?t=2")
            or "Mitteilungen &amp; Grüße" in owner.fetch(panel)):
        raise RuntimeError("Deleting a news category damaged its native topic or kept it published")
