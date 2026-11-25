"""Exercise category policies and direct, inherited and global download grants."""
import os
import re
from html import unescape
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page, admin_url, login, submit_form
from knowledgebase import acp_panel


def administration(owner, member, applicant, verify):
    panel = acp_panel(owner, "Downloadberechtigungen")
    picker = re.search(r'href="([^"]*mode=searchuser[^"]*form=dl-select-u[^"]*)"',owner.fetch(panel))
    if not picker or 'id="search_memberlist"' not in owner.fetch(urljoin(panel,unescape(picker[1]))):
        raise RuntimeError("The native username finder is unavailable from download permissions")
    verify("installed")
    verify("seed")
    guest = Browser(owner.base)

    def grants(kind, identity, category):
        page = owner.fetch(panel)
        field = "username" if kind == "u" else "subject"
        form = submit_form(owner, panel, page, "dl-select-" + kind, {field: identity, "category": str(category)})
        url, _ = Page(form).forms["dl-permissions"]
        return urljoin(panel, url), form

    def save(url, page, changes):
        return submit_form(owner, url, page, "dl-permissions", changes)

    def set_values(url, changes):
        return save(url, owner.fetch(url), changes)

    category = "app.php/downloads/category/9401"
    member.fetch(category)
    applicant.fetch(category, expected_status=404)
    guest.fetch(category, expected_status=404)
    user_url, user_page = grants("u", "HistoricMember", 9401)
    group_url, group_page = grants("g", "71", 9401)
    if ("Photo Club" not in user_page or "Freigabe über aktive Gruppen" not in user_page
            or "HistoricMember" not in group_page or "DepartingFixture" not in group_page
            or "Mitgliedschaft unbestätigt" not in group_page
            or FormFields(group_page,"dl-permissions").values["values[view]"] != "1"):
        raise RuntimeError("The ACL selectors lost direct, inherited or pending membership information")
    save(group_url, group_page, {"values[view]": "0"})
    verify("duplicates")
    member.fetch(category, expected_status=404)
    if "inzwischen geändert" not in save(user_url,user_page,{"values[view]": "1"}):
        raise RuntimeError("Editing a direct grant ignored a concurrently changed inherited grant")
    set_values(group_url, {"values[view]": "1"})
    member.fetch(category)

    applicant_url, applicant_page = grants("u","DepartingFixture",9401)
    save(applicant_url,applicant_page,{"values[view]": "1", "values[read]": "1"})
    applicant.fetch(category)
    set_values(applicant_url,{"values[mod]": "1"})
    # Switching off moderation restores the still-configured direct grants.
    set_values(applicant_url,{"values[mod]": "0"})
    applicant.fetch(category)
    set_values(applicant_url,{"values[read]": "0", "values[view]": "0"})
    applicant.fetch(category, expected_status=404)
    search = "app.php/downloads?q=Zeichen"
    applicant.fetch(search, expected_status=404)
    global_url, global_page = grants("u","DepartingFixture",0)
    save(global_url,global_page,{"values[search]": "1"})
    applicant.fetch(search)
    set_values(global_url,{"values[search]": "0"})
    applicant.fetch(search, expected_status=404)
    # The applicant is a confirmed member of group 74, and has no direct global grant.
    global_group, global_page = grants("g","74",0)
    save(global_group,global_page,{"values[search]": "1"})
    applicant.fetch(search)
    guest.fetch(search, expected_status=404)
    set_values(global_group,{"values[search]": "0"})
    applicant.fetch(search, expected_status=404)

    levels = panel + "&section=edit&category=9401"
    stale = owner.fetch(levels)
    save(levels,stale,{"values[upload]": "1"})
    if "inzwischen geändert" not in save(levels,stale,{"values[view]": "0"}):
        raise RuntimeError("A stale category policy form overwrote newer access levels")
    guest.fetch(category, expected_status=404)

    delegate = login(owner.base, "DownloadGrantDelegate", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    entry = admin_url(delegate)
    page = delegate.fetch(entry)
    challenge = Page(page).forms["login"][1]["credential"]
    submit_form(delegate,entry,page,"login",{"username": "DownloadGrantDelegate", "login": "1",
                                            "password_" + challenge: os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    delegated = acp_panel(delegate,"Downloadberechtigungen")
    listing = delegate.fetch(delegated)
    if "ACL-Kategorie 9410" not in listing or any("ACL-Kategorie " + str(i) in listing for i in (9401,9402,9403)):
        raise RuntimeError("Delegated permission management showed a hidden category or its descendant")
    match = re.search(r'<a href="([^"]+)">ACL-Kategorie 9410</a>',listing)
    limited_url = urljoin(delegated,unescape(match[1]))
    open_form = delegate.fetch(limited_url)
    verify("revoke")
    # The native module loader rejects the now-inaccessible mode before the extension is called.
    rejected = submit_form(delegate,limited_url,open_form,"dl-permissions",{"values[read]": "1"},status=503)
    if "Auf das Modul kann nicht zugegriffen werden" not in rejected:
        raise RuntimeError("The previous delegate reached the permission module after revocation")
    verify("complete")
