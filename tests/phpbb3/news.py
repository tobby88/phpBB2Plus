"""Read migrated CMX news through the board, archive, portal and RSS."""
from html import unescape
from pathlib import Path
import re
from urllib.parse import urljoin
from xml.etree import ElementTree

from http_board import Browser


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
        if (not feed.findtext("image/url", "").endswith("/images/plusnews/topicnews.gif")
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
