"""Maintain local and external download mirrors from the issued ACP forms."""
from html import unescape
import re
import time
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin
from urllib.request import build_opener, HTTPCookieProcessor, HTTPRedirectHandler
from http_board import Browser, FormFields, Page, submit_form
from knowledgebase import acp_panel


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def administration(owner, member, verify):
    verify("installed")
    verify("seed")
    panel = acp_panel(owner,"Download-Spiegelserver verwalten")
    guest = Browser(owner.base)
    root = "app.php/downloads/file/"

    def route(action, file=9900, ids=()):
        return panel + "&action=" + action + "&file=" + str(file) + ("&ids=" + ",".join(map(str,ids)) if ids else "")

    def save(url, changes=None, page=None, uploads=None):
        page = page or owner.fetch(url)
        if uploads is None:
            return submit_form(owner,url,page,"dl-mirror-admin",changes or {})
        action, _ = Page(page).forms["dl-mirror-admin"]
        values = {k:v for k,v in FormFields(page,"dl-mirror-admin").values.items() if not k.startswith("upload_")}
        values.update(changes or {})
        time.sleep(max(0,int(values["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(url,action),values,files=uploads)

    listing = submit_form(owner,panel,owner.fetch(panel),"dl-mirror-search",{"q":"9900"})
    if "file=9900" not in listing or "file=9903" in listing:
        raise RuntimeError("Looking up the converted file by ID did not isolate it")
    admin_file = route("file")
    old = owner.fetch(admin_file)
    if "Lokale Kopie &amp;amp; 📡" not in old or "https://example.invalid/club.txt" not in old:
        raise RuntimeError("The ACP omitted the imported local or external source")
    orphan = owner.fetch(route("file",9905))
    if "vollständigem Kategoriepfad" not in orphan or "action=create" in orphan or "action=edit" in orphan:
        raise RuntimeError("A structurally invalid category still offered mirror creation or edits")
    if "action=create" not in owner.fetch(route("file",9903)):
        raise RuntimeError("The ACP could not maintain an otherwise restricted category")
    guest.fetch(root+"9903/get/9908",expected_status=404)
    guest.fetch(root+"9904",expected_status=404)

    create = route("create")
    initial = owner.fetch(create)
    added = save(create,{"location_0":"Nord &amp; 📡","source_0":"local"},initial,
                 [("upload_0","Kopie.txt","text/plain",b"Uploaded mirror copy\n")])
    if "Nord &amp;amp; 📡" not in added:
        raise RuntimeError("Uploading a local mirror did not preserve its label")
    fresh = re.search(r'<tr><td>Nord &amp;amp; 📡</td>.*?&amp;ids=(\d+)',added,re.S)
    if not fresh:
        raise RuntimeError("Could not follow the new mirror's edit link")
    new = int(fresh[1])
    if "inzwischen geändert" not in save(create,{"location_0":"Veraltet","source_0":"remote","url_0":"https://example.invalid/old"},initial):
        raise RuntimeError("Reusing an old creation form added another mirror")

    path = root+f"9900/get/{new}"
    if submit_form(member,path,member.fetch(path),"dl-license",{"accept":"1"}) != "Uploaded mirror copy\n":
        raise RuntimeError("A protected upload did not pass through the file's ordinary licence agreement")
    group = route("edit",ids=(9901,9902))
    edits = owner.fetch(group)
    values = {"location_9901":"Archiv &amp; 📡","source_9902":"local"}
    if "passende Quelle" not in save(group,values,edits):
        raise RuntimeError("A missing replacement upload did not reject the whole edit")
    verify("unchanged")
    save(group,values,edits,[("upload_9902","Alternative.txt","text/plain",b"Replaced remote mirror\n")])
    verify("replaced")
    replaced = root+"9900/get/9902"
    if submit_form(member,replaced,member.fetch(replaced),"dl-license",{"accept":"1"}) != "Replaced remote mirror\n":
        raise RuntimeError("Replacing a remote mirror with a local file returned the wrong bytes")

    forms = owner.fetch(admin_file)
    selected = submit_form(owner,admin_file,forms,"dl-mirror-list",{"operation":"delete","selected[]":["9901","9902",str(new)]})
    target = urljoin(panel,Page(selected).forms["dl-mirror-admin"][0])
    if not all(value in unescape(selected) for value in ("Archiv &amp; 📡","Nord &amp; 📡","Alternative.txt")):
        raise RuntimeError("The batch confirmation did not identify all selected mirrors")
    if "errorbox" not in save(target,{},selected):
        raise RuntimeError("Unconfirmed mirror removal was accepted")
    address = "https://example.invalid/alternative.txt?release=1&lang=en"
    save(route("edit",ids=(new,)),{f"location_{new}":"Extern",f"source_{new}":"remote",f"url_{new}":address})
    # Read the Location header from the local forum and never contact the mirror.
    terms = member.fetch(path)
    fields = FormFields(terms,"dl-license").values
    fields["accept"] = "1"
    time.sleep(max(0,int(fields["creation_time"]) + 1.05 - time.time()))
    cookies = next(h.cookiejar for h in member.opener.handlers if isinstance(h,HTTPCookieProcessor))
    client = build_opener(HTTPCookieProcessor(cookies),NoRedirect())
    try:
        client.open(urljoin(member.base,path),urlencode(fields).encode(),timeout=10).close()
    except HTTPError as reply:
        if reply.code != 302 or reply.headers.get("Location") != address:
            raise RuntimeError("The remote mirror returned a different destination") from None
        reply.close()
    else:
        raise RuntimeError("An external mirror did not redirect after accepting the licence")
    if "inzwischen geändert" not in save(target,{"confirm":"1"},selected):
        raise RuntimeError("Removal discarded a source revised after its confirmation was opened")
    save(target,{"confirm":"1"})
    save(route("delete",9905,(9907,)),{"confirm":"1"})
    remaining = owner.fetch(admin_file)
    if "keine Spiegelserver" not in remaining or "dl-license" not in Page(member.fetch(root+"9900/get/0")).forms:
        raise RuntimeError("Removing the mirrors also removed the licensed original")
    verify("finished")
