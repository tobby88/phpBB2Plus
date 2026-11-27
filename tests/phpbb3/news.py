"""Read migrated CMX news through the board, archive, portal and RSS."""
from html import unescape
from pathlib import Path
import re
import time
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from xml.etree import ElementTree

from http_board import Browser, Page, admin_url


def source_media(board):
    from attachments import GIF
    (board / "templates/fisubsilversh/images/news/Unused fixture.gif").write_bytes(GIF)


def category_links(html):
    links = re.findall(r'<a class="plus-news-category-link" href="([^"]+)">(.*?)</a>', html, re.S)
    return {int(re.search(r'/category/(\d+)', url)[1]): (unescape(url), body) for url, body in links}


def converted_last_post(reader):
    rows = re.findall(r'<li class="row\b[^"]*"[^>]*>(.*?)</li>', reader.fetch("index.php"), re.S)
    room = next((row for row in rows if re.search(r'<a href="[^"]*viewforum\.php\?f=1(?:&amp;[^"]*)?" class="forumtitle"', row)), "")
    details = re.search(r'<dd class="lastpost"[^>]*>(.*?)</dd>', room, re.S)
    link = re.search(r'href="([^"]*viewtopic\.php\?[^"]*#p(\d+))"', details[1] if details else "")
    if not link or link[2] in ("695", "490") or f'id="p{link[2]}"' not in reader.fetch(unescape(link[1])):
        raise RuntimeError("The index last-post link did not reach a readable, current post after conversion")
    if link[2] != "72" and 'id="p72"' not in reader.fetch("viewtopic.php?p=72"):
        raise RuntimeError("The last approved post stored for the forum could not be opened by an ordinary member")


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
        for entry in ("news_cats.php", "portal.php?news=categories"):
            cats = actor.fetch(entry)
            destinations = category_links(cats)
            if set(destinations) != ({1, 2, 74} if private else {1, 2}):
                raise RuntimeError("News category picture links ignored forum visibility")
            url, body = destinations[2]
            picture = re.search(r'<img src="([^"]+)" alt=""', body)
            if not picture or "Announcements (1)" not in body:
                raise RuntimeError("The picture and accessible category name should form one link")
            if not private and entry == "news_cats.php":
                page_url = urljoin(base, "app.php/news/categories")
                source = Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images/news/announcements.gif"
                if (guest.fetch(urljoin(page_url, unescape(picture[1])), raw=True) != source.read_bytes()
                        or 'data-news-topic="2"' not in guest.fetch(urljoin(page_url, url))):
                    raise RuntimeError("The original category picture did not lead to its readable articles")
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
    submit(path, owner.fetch(path), form, dict(values, active="1", category_image=""))
    if "Mitteilungen &amp; Grüße" not in guest.fetch("app.php/news/category/2"):
        raise RuntimeError("The updated category name was lost or double encoded")
    category_link = category_links(guest.fetch("app.php/news/categories")).get(2)
    if not category_link or "Mitteilungen &amp; Grüße" not in category_link[1] or "<img" in category_link[1]:
        raise RuntimeError("A category without an image lost its named link")
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


def archive_dates(base, founder, check):
    """Follow calendar links across UTC midnight, including category, title and access limits."""
    guest = Browser(base)
    home = "app.php/news"

    def dates(html, path):
        nav = re.search(r'<nav class="plus-news-dates"[^>]*>(.*?)</nav>', html, re.S)
        if not nav:
            raise RuntimeError("Missing news archive date navigation")
        links = {}
        for href, attrs, label in re.findall(r'<a href="([^"]+)"([^>]*)>([^<]+)</a>', nav[1]):
            url = urljoin(urljoin(base, path), unescape(href))
            query = parse_qs(urlsplit(url).query)
            period = tuple(int(query[field][0]) for field in ("year", "month", "day") if field in query)
            links[period] = (url, unescape(label), 'aria-current="page"' in attrs)
        return links

    def topics(html):
        return set(re.findall(r'data-news-topic="(\d+)"', html))

    check("setup")
    try:
        for actor, private in ((guest, False), (founder, True)):
            initial = actor.fetch("portal.php?news=archives")
            links = dates(initial, home)
            if (set(links) != {(2004,), (2004, 2), (2004, 3)}
                    or links[(2004,)][1] != ("2004 (3)" if private else "2004 (2)")):
                raise RuntimeError("Unexpected news archive periods: " + repr({p: link[1] for p, link in links.items()}))
            year, feb, leap = (2004,), (2004, 2), (2004, 2, 29)
            year_url = links[year][0]
            year_links = dates(actor.fetch(year_url), year_url)
            if not year_links[year][2]:
                raise RuntimeError("The selected news archive year was not marked")
            month_url = year_links[feb][0]
            month_links = dates(actor.fetch(month_url), month_url)
            if (not month_links[feb][2] or month_links[feb][1] != ("February (2)" if private else "Februar (1)")
                    or month_links[leap][1] != ("February 29 (2)" if private else "29. Februar (1)")):
                raise RuntimeError("Unexpected archive month or day: " + repr({p: link[1] for p, link in month_links.items()}))
            day_url = month_links[leap][0]
            day = actor.fetch(day_url)
            if not dates(day, day_url)[leap][2] or topics(day) != ({"1", "7"} if private else {"1"}):
                raise RuntimeError("Wrong articles on the selected UTC date: " + repr(topics(day)))
            march = actor.fetch("portal.php?news=archives&year=2004&month=3&day=1")
            if topics(march) != {"2"}:
                raise RuntimeError("The first UTC second of March did not belong to the original archive link")

        category = "app.php/news/category/1?" + urlencode({"q": "Archiv & Grüße"})
        path = dates(guest.fetch(category), category)[(2004, 2)][0]
        result = guest.fetch(path)
        if (parse_qs(urlsplit(path).query).get("q") != ["Archiv & Grüße"] or "/category/1" not in urlsplit(path).path
                or topics(result) != {"1"} or (2004, 3) in dates(result, path)):
            raise RuntimeError("A date link lost its news category or changed the title filter")
        all_dates = dates(result, path)[()][0]
        if topics(guest.fetch(all_dates)) != {"1"} or "year" in parse_qs(urlsplit(all_dates).query):
            raise RuntimeError("Resetting archive dates lost the remaining filters")
        guest.fetch(home + "?year=2003&month=2&day=29", expected_status=404)
        for phase in ("locked", "unapproved"):
            check(phase)
            html = founder.fetch(home + "?year=2004&month=2")
            if dates(html, home)[(2004, 2, 29)][1] != "February 29 (1)" or topics(html) != {"1"}:
                raise RuntimeError("The calendar counted an unpublished article or a password-protected forum")
        check("paged")
        listing = guest.fetch(home + "?year=2004")
        next_page = next((unescape(link) for link in re.findall(r'<a[^>]+href="([^"]+)"', listing)
                          if parse_qs(urlsplit(unescape(link)).query).get("start") == ["1"]), None)
        if not next_page or topics(listing) != {"2"} or topics(guest.fetch(urljoin(urljoin(base, home), next_page))) != {"1"}:
            raise RuntimeError("The native news pagination did not follow the selected archive period")
    finally:
        check("restore")
