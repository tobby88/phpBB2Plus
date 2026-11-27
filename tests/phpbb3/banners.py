"""Follow converted portal banners and inspect external redirects without leaving loopback."""
from http.client import HTTPConnection
from html import unescape
from pathlib import Path
import re
import time
from urllib.parse import urljoin, urlsplit
from urllib.request import HTTPCookieProcessor, Request
from arcade import PUZZLE_MOVIE
from http_board import Browser, Page, FormFields, admin_url
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
    if founder.fetch("app.php/banners/23/media", raw=True) != PUZZLE_MOVIE:
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


def editor(page):
    return FormFields(page, "plus-banner-edit").values


def administration(base, owner, web):
    guest = Browser(base)
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_board&mode=settings")
    match = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Banner und Portal-Links</span>', menu)
    if not match:
        raise RuntimeError("Banner and portal link administration is missing from board configuration")
    panel = urljoin(entry, unescape(match.group(1)))
    art = (Path(__file__).resolve().parents[2] / "phpBB2/images/banner/phpbb2_de_logo.gif").read_bytes()
    library = web / "files/plus_banner_artwork"
    library.mkdir(exist_ok=True)
    (library / "Partner Grüße.gif").write_bytes(art)

    def link_to(label):
        link = re.search(r'<a\b[^>]*\bhref="([^"]+)"[^>]*>' + re.escape(label) + r'</a>', owner.fetch(panel))
        if not link:
            raise RuntimeError("Missing placement in banner administration: " + label)
        return urljoin(panel, unescape(link.group(1)))

    def submit(url, page, form, changes):
        action, hidden = Page(page).forms[form]
        fields = editor(page) if form == "plus-banner-edit" else hidden
        fields.update(changes)
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(url, unescape(action)), fields)

    form = "plus-banner-edit"
    new = link_to("Banner oder Portal-Link anlegen")
    initial = owner.fetch(new)
    title = "Archivgruppe &amp; Gäste"
    values = dict(save="1", display_name="Archivgruppe & Gäste", spot="6", target_url="https://example.invalid/new",
                  views="9007199254740993", owner="HistoricMember")
    submit(new, initial, form, values)
    submit(new, initial, form, values)
    if owner.fetch(panel).count(">" + title + "</a>") != 1:
        raise RuntimeError("Submitting the same creation form twice did not create exactly one placement")
    edit = link_to(title)
    first = owner.fetch(edit)
    if editor(first)["owner"] != "HistoricMember" or title not in guest.fetch("index.php"):
        raise RuntimeError("The editor lost the owner or the new header link")
    submit(edit, first, form, {"save":"1", "notes":"Interner Vermerk"})
    page = owner.fetch(edit)
    fields = editor(page)
    if fields["views"] != "9007199254740994" or fields["notes"] != "Interner Vermerk":
        raise RuntimeError("Saving the placement overwrote a view recorded while the form was open")
    guest.fetch("index.php")
    conflict = submit(edit, page, form, {"save":"1", "views":"55"})
    if "zwischenzeitlich geändert" not in conflict or editor(owner.fetch(edit))["views"] != "9007199254740995":
        raise RuntimeError("A conflicting manual counter change was accepted")
    submit(edit, owner.fetch(edit), form, {"save":"1","media_type":"0","artwork":"Partner Grüße.gif","description":"Partnerbild",
           "time_mode":"6","date_begin":"1998-01-01","date_end":"1998-01-02","time_begin":"00:00","time_end":"23:59"})
    if "Partnerbild" in guest.fetch("index.php"):
        raise RuntimeError("A retired banner continued to appear")
    page = owner.fetch(edit)
    if editor(page).get("artwork") != "keep" or editor(page)["date_end"] != "1998-01-02":
        raise RuntimeError("The ACP did not retain the selected artwork or dates")
    submit(edit, page, form, {"save":"1","time_mode":"2","time_begin":"00:00","time_end":"23:59"})
    header = guest.fetch("index.php")
    image = re.search(r'<a data-plus-spot="6"[^>]*>.*?<img src="([^"]+)" alt="Partnerbild"', header, re.S)
    if not image or guest.fetch(unescape(image.group(1)), raw=True) != art or "Interner Vermerk" in header:
        raise RuntimeError("The assigned local artwork or its public description changed")
    prompt = submit(edit, owner.fetch(edit), form, {"remove":"1"})
    confirm = next((key for key, (_, fields) in Page(prompt).forms.items() if "confirm_uid" in fields), None)
    if not confirm or "Partnerbild" not in guest.fetch("index.php"):
        raise RuntimeError("Removing a placement skipped confirmation")
    submit(edit, prompt, confirm, {"confirm":"Ja"})
    submit(new, initial, form, values)
    if "Partnerbild" in owner.fetch(panel) or title in owner.fetch(panel):
        raise RuntimeError("A removed placement reappeared after replaying the original form")
    switch = "plus-banner-display"
    submit(panel, owner.fetch(panel), switch, {"display":"1"})
    if 'class="panel plus-portal-links"' in guest.fetch("app.php/portal"):
        raise RuntimeError("The display switch left portal banners active")
    submit(panel, owner.fetch(panel), switch, {"display":"1", "enabled":"1"})
    if 'class="panel plus-portal-links"' not in guest.fetch("app.php/portal"):
        raise RuntimeError("The display switch lost existing portal placements")
