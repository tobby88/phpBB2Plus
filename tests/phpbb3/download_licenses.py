"""Follow a download agreement from its displayed document to the selected file."""
from html import unescape
from http_board import Page, submit_form
from downloads import PAYLOAD, MIRROR


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
