"""Follow converted portal banners and inspect external redirects without leaving loopback."""
from http.client import HTTPConnection
from pathlib import Path
import re
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, Request
from arcade import movie
from http_board import Browser
from legacy_links import link


def click(actor, path, method):
    origin = urlsplit(actor.base)
    if origin.hostname != "127.0.0.1":
        raise ValueError("Banner fixture requires loopback")
    request = Request(urljoin(actor.base, path), method=method)
    jar = next(handler.cookiejar for handler in actor.opener.handlers if isinstance(handler, HTTPCookieProcessor))
    jar.add_cookie_header(request)
    connection = HTTPConnection(origin.hostname, origin.port, timeout=15)
    try:
        connection.request(method, urlsplit(request.full_url).path, headers=dict(request.header_items()))
        response = connection.getresponse()
        jar.extract_cookies(response, request)
        if response.status != 302 or response.getheader("Location") != "https://example.invalid/partner?a=1&b=2":
            raise RuntimeError("The portal partner did not redirect to its configured destination")
    finally:
        connection.close()


def browse(base, founder, member):
    guest = Browser(base)
    page = guest.fetch("app.php/portal")
    links = re.search(r'<section class="panel plus-portal-links">.*?</section>', page, re.S)
    if not links or re.findall(r'data-plus-spot="(\d+)"', links[0]) != ["16","17","18","19"] or "Archiv &amp; Freunde" not in links[0]:
        raise RuntimeError("The four original portal links or their labels are missing")
    for actor, can_see in ((guest, False), (member, True)):
        header = actor.fetch("index.php")
        if ("Mitgliederlink" in header) != can_see or "Abgelaufener Hinweis" in header or "Clubpartner" in header:
            raise RuntimeError("The banner audience, schedule or forum placement changed")
    if "Clubpartner" not in founder.fetch("viewforum.php?f=7") or "Clubpartner" in member.fetch("viewforum.php?f=7", expected_status=403):
        raise RuntimeError("A banner was shown under the wrong forum permission")
    private = founder.fetch("viewtopic.php?t=7")
    if "Clubfilm" not in private or 'class="plus-banner-movie"' not in private:
        raise RuntimeError("The topic banner lost its Flash presentation")
    if founder.fetch("app.php/banners/23/media", raw=True) != movie():
        raise RuntimeError("The local banner movie changed during conversion")
    for hidden in ("20/visit", "21/visit", "23/media"):
        guest.fetch("app.php/banners/" + hidden, expected_status=404)
    image = Path(__file__).resolve().parents[2] / "phpBB2/images/banner/phpbb2_de_logo.gif"
    if guest.fetch("app.php/banners/2/media", raw=True) != image.read_bytes():
        raise RuntimeError("The protected portal image differs from its source")
    link(base, "redirect.php?banner_id=5", "app.php/banners/5/visit", {})
    target = "app.php/banners/5/visit"
    click(guest, target, "HEAD")
    if "Archiv &amp; Freunde" not in guest.fetch("app.php/portal"):
        raise RuntimeError("A link preview consumed the banner click filter")
    click(guest, target, "GET")
    click(guest, target, "GET")
    if "Archiv &amp; Freunde" in guest.fetch("app.php/portal") or "Archiv &amp; Freunde" not in member.fetch("app.php/portal"):
        raise RuntimeError("Clicking a filtered link did not hide it for exactly that viewer")
