"""Exercise paFileDB custom file fields against a converted board."""
from html import unescape
import re
from http_board import Browser, FormFields, submit_form


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
