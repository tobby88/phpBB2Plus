"""Read, confirm and cancel normal Arcade diagnostics operations on a disposable board."""
from html import unescape
import os
import re
from urllib.parse import urljoin
from delegation import authenticate
from http_board import FormFields, Page, admin_url, submit_form


def browse(base, founder, check):
    junior, acp = authenticate(base, "ArcadeLogJunior")
    panel = acp + "&i=-phpbb2plus-arcade-acp-diagnostics_module&mode=manage"
    ordinary = admin_url(founder)
    front = founder.fetch(ordinary)
    if "login" in Page(front).forms:
        token = Page(front).forms["login"][1]["credential"]
        submit_form(founder, ordinary, front, "login", {"login":"1", "username":"ModernMember",
            "password_" + token:os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    admin_panel = admin_url(founder) + "&i=-phpbb2plus-arcade-acp-diagnostics_module&mode=manage"

    def ids(body):
        return [int(n) for n in re.findall(r'data-arc-log="([0-9]+)"', body)]

    def selected(path, numbers):
        return submit_form(junior,path,junior.fetch(path),"arc-log-selected",{"operation":"selected","records[]":numbers})

    def finish(path, body):
        return submit_form(junior,path,body,"arc-log-confirm",{"confirm":"1"})

    check("prepare")
    try:
        menu = junior.fetch(acp)
        if "Arcade error log" not in menu or "Arcade categories" in menu:
            raise RuntimeError("The delegated diagnostic menu granted unrelated Arcade administration")
        other = acp + "&i=-phpbb2plus-arcade-acp-categories_module&mode=manage"
        if "Module not accessible" not in junior.fetch(other,expected_status=503):
            raise RuntimeError("The diagnostic-only delegate reached the category manager")
        if "Fehlerprotokoll der Spielhalle" not in founder.fetch(admin_panel):
            raise RuntimeError("The German founder's ACP did not expose the diagnostics module")
        index = junior.fetch(panel)
        if ids(index) != [780007,780006] or "Arcade error log" not in index:
            raise RuntimeError("Arcade diagnostics ignored the configured page size or descending record IDs")
        expected = {1:(780001,"HistoricMember"),2:(780007,"third"),3:(780003,"No matching account"),
                    4:(780004,"Guest"),5:(780005,"Not recorded")}
        for category, (number, text) in expected.items():
            body = junior.fetch(panel + "&sort=" + str(category))
            if not ids(body) or ids(body)[0] != number or text not in body:
                raise RuntimeError("Arcade diagnostic filter or current account identification failed for filter " + str(category))
        if "&amp;lt;literal&amp;gt; &lt;game&gt;" not in junior.fetch(panel + "&sort=1"):
            raise RuntimeError("The native diagnostic view changed literal entity text")
        old = junior.fetch(panel + "&start=100000000")
        if (91 not in ids(old) or "Historisches Ereignis 🛰 &amp;lt;Quelle&amp;gt; &lt;Café&gt;" not in old or "HistoricMember" not in old):
            raise RuntimeError("The source log lost its remapped author, Unicode, literal text or last-page clamp")
        errors = panel + "&sort=2"
        first = junior.fetch(errors)
        links = [unescape(url) for url in re.findall(r'href="([^"]+)"', first)]
        second = next((urljoin(errors,u) for u in links if "sort=2" in u and "start=2" in u), None)
        if not second or ids(junior.fetch(second)) != [780002]:
            raise RuntimeError("The configured native pagination lost its diagnostic filter")
        # Cancelled review is an ordinary link back to the current list.
        reviewed = selected(second,[780002])
        form = FormFields(reviewed,"arc-log-confirm").values
        if (str(form.get("last")) != "780002" or ids(junior.fetch(second)) != [780002] or "780002" not in reviewed):
            raise RuntimeError("Previewing a selection removed it or lost its record number")
        page = finish(second,reviewed)
        if ids(page) != [780007,780006] or "Log records deleted: 1" not in page:
            raise RuntimeError("Deleting the last page did not return to the remaining filtered records")
        reviewed = submit_form(junior,errors,page,"arc-log-filtered",{"operation":"filtered"})
        if FormFields(reviewed,"arc-log-confirm").values.get("total") != "2":
            raise RuntimeError("The filter confirmation counted another diagnostic type")
        check("append")
        if ids(finish(errors,reviewed)) != [780008]:
            raise RuntimeError("Filtered cleanup removed an entry that arrived after confirmation was opened")
        if "selection has changed" not in finish(errors,reviewed):
            raise RuntimeError("Reusing a completed confirmation displaced an unrelated record")
        saved = selected(errors,[780008])
        check("never")
        if "not authorised to administer" not in submit_form(junior,errors,saved,"arc-log-confirm",{"confirm":"1"},status=403):
            raise RuntimeError("The effective native Never did not suspend the diagnostic ACP session")
        check("verify")
    finally:
        check("cleanup")
