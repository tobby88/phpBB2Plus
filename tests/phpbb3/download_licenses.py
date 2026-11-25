"""Follow a download agreement from its displayed document to the selected file."""
from html import unescape
import re
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page, submit_form
from downloads import PAYLOAD, MIRROR
from knowledgebase import acp_panel


def agreements(member, verify):
    verify("agreements")
    original = "app.php/downloads/file/9611/get/0"
    mirror = "app.php/downloads/file/9611/get/9612"
    opened = [(path,member.fetch(path)) for path in (original,mirror)]
    for _, page in opened:
        if (not all(value in page for value in ("<strong>aus dem Archiv</strong>", "<ol><li>", 'colspan="2"',
                                                 "Text &lt;Beispiel&gt;", "faq.php", "Probe &amp;amp; Regeln"))
                or "📜" not in unescape(page) or "license_revision" not in Page(page).forms["dl-license"][1]):
            raise RuntimeError("The converted formatted licence or literal characters were lost")
    verify("revised")
    current = []
    for path, previous in opened:
        page = submit_form(member,path,previous,"dl-license",{"accept":"1"},status=409)
        if "<p>Neue Bedingungen</p>" not in page or "dl-license" not in Page(page).forms:
            raise RuntimeError("Changed terms did not request fresh explicit consent")
        current.append((path,page))
    verify("unaccepted")
    for (path, page), content in zip(current,(PAYLOAD,MIRROR)):
        if submit_form(member,path,page,"dl-license",{"accept":"1"}).encode() != content:
            raise RuntimeError("Accepting a current licence returned the wrong file")
    if "dl-license" not in Page(member.fetch(original)).forms:
        raise RuntimeError("A previous download silently accepted another licence prompt")
    verify("accepted")


def administration(owner, member, verify):
    verify("installed")
    verify("admin")
    panel = acp_panel(owner, "Downloadlizenzen verwalten")
    guest = Browser(owner.base)

    def link(action, ids=""):
        return panel + "&action=" + action + ("&ids=" + ids if ids else "")

    def save(url, changes, page=None):
        return submit_form(owner,url,page or owner.fetch(url),"dl-license-admin",changes)

    create = link("create")
    opened = owner.fetch(create)
    if "Prüfe den Lizenznamen" not in save(create,{"name":"","terms":"Entwurf"},opened):
        raise RuntimeError("An incomplete licence did not retain its form")
    listing = save(create,{"name":"Entwurf 📚","terms":""},opened)
    found = re.search(r'<a href="[^"]+&amp;ids=(\d+)">Entwurf 📚</a>',listing)
    if not found or "inzwischen geändert" not in save(create,{"name":"Entwurf 📚","terms":""},opened):
        raise RuntimeError("Repeating the same create form duplicated the licence")
    upload = "app.php/downloads/category/21/submit"
    chooser = owner.fetch(upload)
    if f'<option value="{found[1]}">Entwurf 📚</option>' not in chooser:
        raise RuntimeError("A newly created licence was unavailable when submitting a file")
    save(link("delete",found[1]),{"confirm":"1"})
    expired = submit_form(owner,upload,chooser,"dl-submit",{"title":"Überholte Lizenzwahl","description":"Probe",
                          "url":"https://example.invalid/download.txt","license":found[1]},status=422)
    if "Wähle eine vorhandene Lizenz." not in expired:
        raise RuntimeError("An open submission could still assign a removed licence")

    interim = link("edit","9711")
    save(interim,{"terms":"Bedingungen ohne Altmetadaten"})
    if FormFields(owner.fetch(interim),"dl-license-admin").values["terms"] != "Bedingungen ohne Altmetadaten":
        raise RuntimeError("A licence from an intermediate installation could not be edited")

    original = "app.php/downloads/file/9720/get/0"
    first = member.fetch(original)
    edit = link("edit","9710")
    form = owner.fetch(edit)
    values = FormFields(form,"dl-license-admin").values
    if values["name"] != "Alte &amp; Lizenz 📜" or values["terms"] != "<p>Archivtext &lt;Original&gt;</p>":
        raise RuntimeError("The licence editor changed imported literal entities or formatting")
    name = "Neu &amp; &#128512; 📜"
    terms = "<p>Neuer <em>Text</em> &lt;Beispiel&gt;</p>\nZeile zwei 📜"
    save(edit,{"name":name,"terms":terms},form)
    updated = FormFields(owner.fetch(edit),"dl-license-admin").values
    if updated["name"] != name or updated["terms"] != terms:
        raise RuntimeError("Edited licence source did not round-trip")
    if "inzwischen geändert" not in save(edit,{"name":"Überholt","terms":"Alt"},form):
        raise RuntimeError("An outdated ACP form overwrote revised licence terms")
    verify("edited")
    revised = submit_form(member,original,first,"dl-license",{"accept":"1"},status=409)
    if "<em>Text</em>" not in revised or name not in unescape(revised):
        raise RuntimeError("The published download agreement did not follow the administrative edit")

    file_editor = "app.php/downloads/file/9720/edit"
    existing = owner.fetch(file_editor)
    if FormFields(existing,"dl-edit").values["license"] != "9710":
        raise RuntimeError("The old assignment was absent before its removal")
    listing = owner.fetch(panel)
    selected = ["9711","9710"] + [str(i) for i in range(9740,9764)]
    removal = submit_form(owner,panel,listing,"dl-license-list",{"remove[]":selected})
    target = urljoin(panel,Page(removal).forms["dl-license-admin"][0])
    if name not in unescape(removal) or "Zusatzvereinbarung" not in removal or "Anlage 9763" not in removal:
        raise RuntimeError("Confirmation omitted one of the selected licences")
    if "errorbox" not in save(target,{},removal):
        raise RuntimeError("Licence removal proceeded without confirmation")
    verify("attached")
    if "inzwischen geändert" not in save(target,{"confirm":"1"},removal):
        raise RuntimeError("A new file assignment was silently included in licence removal")
    save(target,{"confirm":"1"})
    stale = submit_form(owner,file_editor,existing,"dl-edit",{},status=409)
    if "Der Download wurde inzwischen bearbeitet" not in stale:
        raise RuntimeError("An old file editor could reattach a removed licence")
    if guest.fetch(original,raw=True) != PAYLOAD:
        raise RuntimeError("A public file could no longer be downloaded after its licence was detached")
    guest.fetch("app.php/downloads/file/9721/get/0",expected_status=404)
    guest.fetch("app.php/downloads/file/9722/get/0",expected_status=404)
    verify("removed")
