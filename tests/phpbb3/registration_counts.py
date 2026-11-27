"""Read the two native calendar-day totals through the index, categories and load settings."""
import json
import re
from html import unescape

from http_board import Browser, FormFields, admin_url, submit_form


def browse(admin, member, check, classic=False):
    check("boundaries")
    category = "viewforum.php?f=" + str(json.loads(check("setup-classic" if classic else "setup"))["category"])
    guest = Browser(admin.base)
    url = admin_url(admin) + "&i=acp_board&mode=load"
    field = "config[plus_registration_counts]"
    marker = 'class="plus-registrations"'

    def counts(html):
        found = re.search(r'<span class="plus-registrations">([^<]+)</span>', html)
        if not found:
            raise RuntimeError("The index statistics did not render registration counts")
        return list(map(int, re.findall(r'\d+', unescape(found[1]))))

    try:
        form = admin.fetch(url)
        if FormFields(form, "acp_board").values.get(field) != ("0" if classic else "1"):
            raise RuntimeError("The native ACP did not retain the converted index layout")
        if classic:
            if marker in guest.fetch("index.php"):
                raise RuntimeError("The classic source layout gained an unwanted statistics line")
            submit_form(admin, url, form, "acp_board", {"submit": "Submit", field: "1"})
        expected = json.loads(check("expected"))
        for actor, audience in ((guest, "guest"), (member, "member")):
            target = [expected[audience]["today"], expected[audience]["yesterday"]]
            if counts(actor.fetch("index.php")) != target or counts(actor.fetch(category)) != target:
                raise RuntimeError("Registration counts do not use the viewer's calendar day")
        if marker in member.fetch("app.php/portal") or marker in member.fetch("viewforum.php?f=1"):
            raise RuntimeError("Registration counts appeared outside the original statistics sections")
        check("index-only")
        if marker not in guest.fetch("index.php") or marker in member.fetch(category):
            raise RuntimeError("Index-only statistics were displayed in the wrong locations")
        check("no-statistics")
        if marker in guest.fetch("index.php"):
            raise RuntimeError("Disabled board statistics still displayed registrations")
        check("both-places")
        submit_form(admin, url, admin.fetch(url), "acp_board", {"submit": "Submit", field: "0"})
        if marker in guest.fetch("index.php") or marker in member.fetch(category):
            raise RuntimeError("Native ACP switch did not hide both figures")
    finally:
        check("restore")
