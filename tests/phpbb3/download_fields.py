"""Exercise paFileDB custom file fields against a converted board."""
from html import unescape
import re
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page, submit_form
from knowledgebase import acp_panel


def runtime(owner, member, verify):
    verify("runtime")
    choice, retired = "Archiv &amp; 📗", "Vergriffen &amp; 📗"
    item = "app.php/downloads/file/9830"
    edit = item + "/edit"
    guest = Browser(owner.base)
    shown = guest.fetch(item)
    if (not re.search(r"Erste &lt;Zeile&gt;<br\s*/?>\s*Zweite",shown)
            or not all(name in shown for name in ("Kennzeichen","Kommentarzeilen","Variante","Ausgabe","Merkmale","Ausstattung"))
            or unescape(shown).count(retired) != 2):
        raise RuntimeError("The converted file did not display all field types, literal choices or multiline text")
    guest.fetch(edit,expected_status=404)
    page = owner.fetch(edit)
    values = FormFields(page,"dl-edit").values
    if (values["field[82]"] != "PA-21" or values["field[83]"] != "Erste <Zeile>\nZweite 📗 &amp;"
            or re.search(r'<textarea[^>]+name="description"[^>]+required',page) or "Änderungen speichern" not in page):
        raise RuntimeError("Editing lost the converted single-value fields")
    # The native form orders current options before retired options. Browsers
    # send checked controls in document order, regardless of their stored order.
    data = {"field[81][]":None,"field[84]":choice,"field[85]":"Aktuell",
            "field[86][]":[choice,"Aktuell",retired],"field[87][]":[choice,"Aktuell",retired]}
    submit_form(owner,edit,page,"dl-edit",data)
    verify("unchanged")
    page = owner.fetch(edit)
    data["field[86][]"] = [retired,"Weitere"]
    result = submit_form(owner,edit,page,"dl-edit",data)
    if "Vergriffen &amp;amp; 📗, Weitere" not in result:
        raise RuntimeError("Editing a multiple choice could not retain one previously withdrawn option")
    new = "app.php/downloads/category/21/submit"
    form = member.fetch(new)
    if retired in unescape(form):
        raise RuntimeError("A retired option from another file was offered for new submissions")
    fields = {"title":"Prüfling","description":"Dateiangaben","url":"https://example.invalid/sample.txt","field[82]":"kein Kennzeichen"}
    if "dl-submit" not in submit_form(member,new,form,"dl-submit",fields,status=422):
        raise RuntimeError("The converted text rule did not validate a new submission")
    fields.update({"field[81][]":None,"field[82]":"PA-22","field[83]":"Zwei\nZeilen","field[84]":choice,
                   "field[85]":"Aktuell","field[86][]":[choice,"Weitere"],"field[87][]":["Aktuell"]})
    submit_form(member,new,form,"dl-submit",fields)
    verify("completed")


def administration(owner, member, verify):
    verify("installed")
    verify("admin")
    guest = Browser(owner.base)
    panel = acp_panel(owner,"Download-Zusatzfelder verwalten")
    base = "app.php/downloads/"

    def edit(ident):
        return panel + "&action=edit&ids=" + str(ident)

    def save(url, changes, page=None):
        return submit_form(owner,url,page or owner.fetch(url),"dl-field-admin",changes)

    listing = owner.fetch(panel)
    kinds = re.findall(r'href="[^"]+&amp;action=create&amp;type=(\d)"',listing)
    if sorted(kinds) != list("012345"):
        raise RuntimeError("The ACP did not offer all six original download field types")
    create = panel + "&action=create&type=5"
    blank = owner.fetch(create)
    if "mindestens eine Auswahlmöglichkeit" not in save(create,{"name":"Neue Ausstattung","choices":""},blank):
        raise RuntimeError("An empty choice list was saved")
    data = {"name":"Neue Ausstattung","choices":"Einfach\nPlus &amp; 📗"}
    listing = save(create,data,blank)
    match = re.search(r'href="[^"]+&amp;ids=(\d+)">Neue Ausstattung</a>',listing)
    if not match or "inzwischen geändert" not in save(create,data,blank):
        raise RuntimeError("The field create form did not prevent a duplicate submission")
    created = match[1]
    if FormFields(owner.fetch(edit(created)),"dl-field-admin").values["position"] != created:
        raise RuntimeError("A newly created field did not inherit its historical default display position")

    upload = base + "category/21/submit"
    stale = member.fetch(upload)
    text_edit = edit(9810)
    definition = owner.fetch(text_edit)
    if "gültiger regulärer Ausdruck" not in save(text_edit,{"pattern":"["},definition):
        raise RuntimeError("The ACP stored an incomplete text validation expression")
    save(text_edit,{"name":"Zusatzcode 📗","description":"Zweite Kennung","pattern":"^(?:NEU-[0-9]+)?$"},definition)
    if "inzwischen geändert" not in save(text_edit,{"name":"Überholter Titel"},definition):
        raise RuntimeError("Editing with an old definition replaced newer settings")
    options = "Neue Wahl\nArchiv &amp; 📗"
    save(edit(9811),{"name":"Weitere Merkmale","choices":options,"position":"0"})
    if FormFields(owner.fetch(edit(9811)),"dl-field-admin").values["choices"] != options:
        raise RuntimeError("The ACP changed literal option text")
    verify("edited")
    original = guest.fetch(base + "file/9840")
    if (original.find("Weitere Merkmale") > original.find("Kommentarzeilen") or "Vergriffen &amp;amp; 📗" not in original):
        raise RuntimeError("Sorting or changing choices lost a published file value")
    draft = {"title":"Offener Entwurf","description":"Neue Feldregeln","url":"https://example.invalid/draft.txt","field[9810]":"PA-21"}
    updated = submit_form(member,upload,stale,"dl-submit",draft,status=409)
    if "The custom fields have changed." not in updated or FormFields(updated,"dl-submit").values["title"] != "Offener Entwurf":
        raise RuntimeError("A stale submission did not retain its draft for the current definitions")
    invalid = submit_form(member,upload,updated,"dl-submit",draft,status=422)
    if "Check the additional download fields." not in invalid:
        raise RuntimeError("New file values bypassed a changed validation expression")

    file_edit = base + "file/9840/edit"
    opened = owner.fetch(file_edit)
    listing = owner.fetch(panel)
    selection = submit_form(owner,panel,listing,"dl-field-list",{"remove[]":["9811",created,"9810"]})
    target = urljoin(panel,Page(selection).forms["dl-field-admin"][0])
    if any(n not in selection for n in ("Neue Ausstattung","Zusatzcode 📗","Weitere Merkmale","(3)")):
        raise RuntimeError("Removal confirmation omitted a selected field or its use count")
    if "errorbox" not in save(target,{},selection):
        raise RuntimeError("The selected fields could be removed without confirmation")
    verify("late")
    if "inzwischen geändert" not in save(target,{"confirm":"1"},selection):
        raise RuntimeError("Field removal silently included a later file assignment")
    save(target,{"confirm":"1"})
    if "inzwischen bearbeitet" not in submit_form(owner,file_edit,opened,"dl-edit",{},status=409):
        raise RuntimeError("A stale file editor could restore removed field values")
    if "Weitere Merkmale" in guest.fetch(base+"file/9840"):
        raise RuntimeError("The public file retained its removed field")
    guest.fetch(base+"file/9841",expected_status=404)
    guest.fetch(base+"file/9842",expected_status=404)
    verify("removed")
