"""Read the converted count, issue a real phpBB warning and clear only the old cards."""
from html import unescape
import re
from urllib.parse import urljoin

from http_board import Browser, Page, admin_url, login, submit_form


def check_restricted_login(base, username, password):
    guest = Browser(base)
    login_page = "ucp.php?mode=login"
    response = submit_form(guest, login_page, guest.fetch(login_page), "login",
                           {"username": username, "password": password, "login": "1"})
    # phpBB may offer its founder recovery login form on the login route.
    if ("banned from this board" not in response and "gesperrt" not in response and "login" not in Page(response).forms
            or "mode=logout" in guest.fetch("index.php")):
        raise RuntimeError(f"The imported restriction did not block login for {username}")


def exercise(owner, historic, password, fixture):
    # The native DB login normally does not apply email bans to existing users,
    # and the upstream converter promotes legacy administrators to founders.
    for username in ("EmailCardFixture", "ImportedFounderFixture"):
        check_restricted_login(owner.base, username, password)
    fixture("cards-exempt")
    try:
        email_session = login(owner.base, "EmailCardFixture", password)
        founder_session = login(owner.base, "ImportedFounderFixture", password)
    finally:
        fixture("cards-unexempt")
    for session in (email_session, founder_session):
        denied = session.fetch("index.php")
        if "banned from this board" not in denied or "mode=logout" in session.fetch("index.php"):
            raise RuntimeError("Restoring an imported ban left an admitted session active")
    profile = f"memberlist.php?mode=viewprofile&u={historic}"
    guest = Browser(owner.base).fetch(profile)
    front = owner.fetch(profile)
    if "Card count from phpBB2 Plus" in guest or "Kartenstand aus phpBB2 Plus" in guest:
        raise RuntimeError("The historical disciplinary record was shown to a guest")
    if not re.search(r"Kartenstand aus phpBB2 Plus.*?</dt>\s*<dd>5</dd>", front):
        raise RuntimeError("The moderator's profile does not distinguish the historical card count")
    link = next(unescape(url) for url in re.findall(r'href="([^"]+)"', front) if "mode=warn_user" in url)
    page = owner.fetch(link)
    submit_form(owner, link, page, "mcp", {"warning": "Native warning fixture", "action[add_warning]": "1", "notify_user": None})

    def reset(target, name, count):
        url = admin_url(owner) + f"&i=users&mode=overview&u={target}"
        page = owner.fetch(url)
        if "Die frühere Sperrschwelle lag bei 3." not in page or "Historischen Kartenstand zurücksetzen" not in page:
            raise RuntimeError("Missing legacy threshold or reset on the native ACP account overview")
        quick = next(form for form in Page(page).forms if "quick" in form)
        confirmation = submit_form(owner, url, page, quick, {"action": "plus_reset_cards", "update": "1"})
        if f"Den historischen Kartenstand von {count} für {name} zurücksetzen?" not in confirmation:
            raise RuntimeError("Clearing historical discipline did not identify the account and count for confirmation")
        action, fields = Page(confirmation).forms["confirm"]
        fields["confirm"] = "Ja"
        if "Der historische Kartenstand wurde zurückgesetzt." not in owner.fetch(urljoin(url, action), fields):
            raise RuntimeError("The confirmed historical card reset did not finish")
        if "Historischen Kartenstand zurücksetzen" in owner.fetch(url):
            raise RuntimeError("An empty historical counter was offered for reset")

    reset(historic, "HistoricMember", 5)
    reset(64, "PermanentCardFixture", 2)
    if "Kartenstand aus phpBB2 Plus" in owner.fetch(profile):
        raise RuntimeError("Cleared historical cards remained visible")
    fixture("cards-reset")
