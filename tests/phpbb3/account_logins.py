"""Read the migrated member's private history and exercise the two independent board settings."""
import os
import re
from html import unescape

from http_board import Browser, FormFields, Page, admin_url, login, submit_form


BOOKMARK = "ct_login_history.php"


def addresses(body):
    table = re.search(r'<table[^>]*id="plus-account-logins".*?</table>', body, re.S)
    return [unescape(value) for value in re.findall(r'<td>[^<]*</td><td>([^<]*)</td>', table[0])] if table else []


def disabled(base, member):
    page = member.fetch(BOOKMARK)
    if "Login history is turned off" not in page or addresses(page) or "plus-login-preferences" in Page(page).forms:
        raise RuntimeError("A disabled source collection policy changed on the private account page")
    if 'id="plus-login-notice"' in member.fetch("index.php"):
        raise RuntimeError("The disabled network notice became visible")


def browse(base, member, admin, fixture):
    guest_page = Browser(base).fetch(BOOKMARK)
    if "login" not in Page(guest_page).forms or addresses(guest_page):
        raise RuntimeError("A guest bookmark did not lead to the native login")
    page = member.fetch(BOOKMARK + "?u=2")
    if addresses(page) != ["127.0.0.1", "2001:db8:11::82", "2001:db8:11::81"]:
        raise RuntimeError("The portal login, per-member retention or ordering of tied legacy logins changed")
    admin_history = addresses(admin.fetch(BOOKMARK))
    if admin_history != ["127.0.0.1", "127.0.0.1", "Alt & <Netz> 📷"]:
        raise RuntimeError("The native login and ACP reauthentication did not each record one login for their own account")
    banner = member.fetch("index.php")
    if ('id="plus-login-notice"' not in banner or "2001:db8:33::/48" not in banner or
            "127.0.x.x" not in banner or "2001:db8:33::4" in banner or 'id="plus-login-notice"' in admin.fetch("index.php")):
        raise RuntimeError("The network notice did not respect the account preference and masked networks")
    f = "plus-login-preferences"
    ack = "plus-login-acknowledge"
    if FormFields(page, f).values.get("network_warning") != "1" or ack not in Page(page).forms:
        raise RuntimeError("The historical preference and network change are missing in the UCP")
    fixture("network-change")
    stale = submit_form(member, BOOKMARK, page, ack, {})
    if "The notice or setting has changed" not in stale or ack not in Page(member.fetch(BOOKMARK)).forms:
        raise RuntimeError("An old acknowledgement dismissed a later login notice")
    submit_form(member, BOOKMARK, member.fetch(BOOKMARK), ack, {})
    if 'id="plus-login-notice"' in member.fetch("index.php") or ack in Page(member.fetch(BOOKMARK)).forms:
        raise RuntimeError("The current network notice was not acknowledged")
    submit_form(member, BOOKMARK, member.fetch(BOOKMARK), f, {"network_warning": "0"})
    fixture("another-network")
    page = member.fetch(BOOKMARK)
    if FormFields(page, f).values.get("network_warning") != "0" or ack in Page(page).forms or 'id="plus-login-notice"' in member.fetch("index.php"):
        raise RuntimeError("The member's own preference did not suppress the notice")
    submit_form(member, BOOKMARK, page, f, {"network_warning": "1"})
    if ack not in Page(member.fetch(BOOKMARK)).forms:
        raise RuntimeError("The member could not re-enable the notice")

    panel = admin_url(admin) + "&i=acp_board&mode=security"
    form = "acp_board"
    def settings(history, network):
        page = admin.fetch(panel)
        fields = FormFields(page, form).values
        if fields.get("config[plus_login_limit]") != "3":
            raise RuntimeError("The native ACP security form lost the imported login limit")
        submit_form(admin, panel, page, form, {"submit": "1", "config[plus_login_history]": str(history),
                                              "config[plus_login_network_check]": str(network)})
    try:
        settings(0, 1)
        fixture("network-only")
        page = member.fetch(BOOKMARK)
        if "Login history is turned off" not in page or addresses(page) or ack not in Page(page).forms:
            raise RuntimeError("The network-only policy did not hide the history and keep the notice")
        settings(1, 0)
        fixture("history-only")
        page = member.fetch(BOOKMARK)
        if not addresses(page) or addresses(page)[0] != "198.51.100.41" or f in Page(page).forms or ack in Page(page).forms:
            raise RuntimeError("The history-only policy did not preserve the log and disable network comparison")
    finally:
        settings(1, 1)

    remembered = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"], remember=True)
    before = addresses(remembered.fetch(BOOKMARK))
    if before[:2] != ["127.0.0.1", "198.51.100.41"] or not any(c.name.endswith("_k") and c.value for c in remembered.cookies):
        raise RuntimeError("The explicit remembered login did not record exactly one new entry")
    sid = next(c for c in remembered.cookies if c.name.endswith("_sid"))
    remembered.cookies.clear(sid.domain, sid.path, sid.name)
    if addresses(remembered.fetch(BOOKMARK)) != before:
        raise RuntimeError("Resuming the native remember-me cookie added a second login or lost the account session")
