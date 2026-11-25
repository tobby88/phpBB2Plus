"""Read the migrated member's private history and exercise the two independent board settings."""
import os
import re
from html import unescape

from delegation import authenticate
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

    route = "&i=-phpbb2plus-community-acp-tracker_settings_module&mode=manage"
    panel = admin_url(admin) + route
    form = "plus-tracker-settings"
    def settings(history, network):
        page = admin.fetch(panel)
        fields = FormFields(page, form).values
        if fields.get("config[plus_login_limit]") != "3":
            raise RuntimeError("The CrackerTracker settings form lost the imported login limit")
        submit_form(admin, panel, page, form, {"submit": "1", "config[plus_login_history]": str(history),
                                              "config[plus_login_network_check]": str(network)})
    try:
        delegate, entry = authenticate(base, "TrackerSettingsJunior")
        delegated_panel = entry + route
        initial = delegate.fetch(delegated_panel)
        denied = delegate.fetch(entry + "&i=acp_board&mode=security", expected_status=503)
        if form not in Page(initial).forms or "Module not accessible" not in denied or "acp_board" in Page(denied).forms:
            raise RuntimeError("The historical tracker module was lost or opened the general server settings")
        submit_form(delegate, delegated_panel, initial, form, {"config[plus_login_limit]": "4"})
        submit_form(delegate, delegated_panel, initial, form, {"config[plus_login_limit]": "2"})
        current = delegate.fetch(delegated_panel)
        if FormFields(current, form).values.get("config[plus_login_limit]") != "4":
            raise RuntimeError("A stale tracker settings form replaced a later policy")
        submit_form(delegate, delegated_panel, current, form, {"config[plus_login_limit]": "61", "config[plus_login_history]": "0"})
        fields = FormFields(delegate.fetch(delegated_panel), form).values
        if fields.get("config[plus_login_limit]") != "4" or fields.get("config[plus_login_history]") != "1":
            raise RuntimeError("One invalid tracker setting partially changed the board policy")
        submit_form(delegate, delegated_panel, delegate.fetch(delegated_panel), form, {"config[plus_login_limit]": "3"})
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
    previous_session = next(c.value for c in remembered.cookies if c.name.endswith("_sid"))
    fixture("expire-remembered-session")
    resumed = addresses(remembered.fetch(BOOKMARK))
    if resumed != before or previous_session == next(c.value for c in remembered.cookies if c.name.endswith("_sid")):
        raise RuntimeError("Resuming the native remember-me cookie added a second login or lost the account session")
