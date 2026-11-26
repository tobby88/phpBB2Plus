"""Exercise the converted and native registration-address administration without name service lookups."""
from html import unescape
import json
from pathlib import Path
import re
from urllib.parse import urljoin

from delegation import authenticate
from http_board import Browser, submit_form


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")

    def stage(name):
        return php(fixture, target, name, cwd=board, capture=True)

    ids = json.loads(stage("prepare"))
    mode = "&i=-phpbb2plus-community-acp-registration_network_module&mode=view"
    standard = "&i=users&mode=overview&u="
    historic = str(ids["HistoricMember"])

    def matches(html):
        return [int(i) for i in re.findall(r'data-registration-user="(\d+)"', html)]

    try:
        scoped, entry = authenticate(base, "RegistrationNetworkJunior")
        url = entry + mode
        selection = scoped.fetch(url)
        if "plus-registration-lookup" not in selection or "archived-board" in selection or matches(selection):
            raise RuntimeError("Registration lookup displayed personal values before selecting an account")
        page = submit_form(scoped, url, selection, "plus-registration-lookup", {"username": "HistoricMember"})
        if "archived&lt;&amp;&gt;.example.invalid" not in page or "archived<&>" in page or "historic@example.invalid" not in page:
            raise RuntimeError("The archived hostname or old registration-IP result columns were not displayed correctly")
        wanted = ids["ordered_ipv6"]
        if matches(page) != wanted or re.search(r'<a [^>]*href="[^"]*(?:i=users|i=acp_users)[^"]*mode=overview', page):
            raise RuntimeError("Equivalent IPv6 addresses or isolated administrative scope failed")
        if "Module not accessible" not in scoped.fetch(entry + standard + historic, expected_status=503):
            raise RuntimeError("The original registration grant opened general account management")
        own = scoped.fetch(url + "&u=" + str(ids["RegistrationNetworkJunior"]))
        next_page = re.search(r'<a rel="next" href="([^"]+)"', own)
        if len(matches(own)) != 25 or not next_page or "No hostname preserved" not in own:
            raise RuntimeError("IPv4 results or the unknown-host fallback were not bounded to one page")
        next_url = urljoin(url, unescape(next_page.group(1)))
        expected = sorted([ids["RegistrationNetworkJunior"], ids["WithHost"]] + [ids["V4" + str(i)] for i in range(25)])
        if matches(own) + matches(scoped.fetch(next_url)) != expected:
            raise RuntimeError("Paging lost or duplicated an account, or combined IPv4 with mapped IPv6")
        for name in ("NoAddress", "Zero"):
            if matches(scoped.fetch(url + "&u=" + str(ids[name]))):
                raise RuntimeError("An unspecified address grouped unrelated accounts")
        empty = submit_form(scoped, url, selection, "plus-registration-lookup", {"username": "NoSuchFixtureMember"})
        if "No matching account found" not in empty:
            raise RuntimeError("Unknown account lookup did not return its normal empty state")

        admin, admin_entry = authenticate(base, "ModernMember")
        overview = admin.fetch(admin_entry + standard + historic)
        link = re.search(r'href="([^"]*i=-phpbb2plus-community-acp-registration_network_module[^"]+)"', overview)
        if "archived&lt;&amp;&gt;.example.invalid" not in overview or not link:
            raise RuntimeError("The permitted native account overview lost the historical hostname or link")
        if "Konten mit dieser Registrierungs-IP" not in admin.fetch(urljoin(admin_entry, unescape(link.group(1)))):
            raise RuntimeError("The native account link did not reach the German registration report")
        other, other_entry = authenticate(base, "CountryFlagJunior")
        general_only = other.fetch(other_entry + standard + historic)
        if "archived&lt;&amp;&gt;.example.invalid" in general_only or "U_REG_NETWORK_ACCOUNT" in general_only or "Accounts with this registration IP" in general_only:
            raise RuntimeError("General account management exposed an unassigned registration lookup")
        if "Module not accessible" not in other.fetch(other_entry + mode + "&u=" + historic, expected_status=503):
            raise RuntimeError("An unrelated administrator reached the registration report")
        stage("native")
        if "archived&lt;&amp;&gt;.example.invalid" not in other.fetch(other_entry + mode + "&u=" + historic):
            raise RuntimeError("An explicitly assigned native registration grant did not open the same report")
        for browser in (Browser(base), scoped):
            if "archived&lt;&amp;&gt;.example.invalid" in browser.fetch("memberlist.php?mode=viewprofile&u=" + historic):
                raise RuntimeError("The archived hostname appeared in public or member profile output")

        stage("revoke")
        if "You are not authorised to administer this board" not in scoped.fetch(next_url, expected_status=403):
            raise RuntimeError("An open results link survived withdrawal of the only grant")
        stage("allow")
        stage("delete")
        if ids["WithHost"] in matches(admin.fetch(admin_entry + mode + "&u=" + str(ids["RegistrationNetworkJunior"]))) or matches(admin.fetch(admin_entry + mode + "&u=" + str(ids["WithHost"]))):
            raise RuntimeError("A removed account remained visible in the registration lookup")
    finally:
        stage("clean")
