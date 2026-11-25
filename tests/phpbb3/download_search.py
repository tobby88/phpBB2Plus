"""Search public download text and authorized comments, then revisit scoped results in the same session."""
import re
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from urllib.request import Request
from http_board import Browser, FormFields, Page, submit_form


def browse(base, member, ordinary, verify):
    guest = Browser(base)
    root = "app.php/downloads"
    path = root + "/search"
    verify("seed")
    verify("terms")
    form = guest.fetch(path)
    if ("Erweiterte Download-Suche" not in guest.fetch(root) or "Clubbibliothek" in form or "Clubdateien" in form or
            "Advanced download search" not in member.fetch(path) or "Clubdateien" not in member.fetch(path)):
        raise RuntimeError("The native search form lost its language or category ACL")
    defaults = FormFields(form, "dl-advanced").values
    if defaults["comments_search"] != "YES":
        raise RuntimeError("The historical include-comments choice was not offered")
    if "Clubbibliothek</a>" in guest.fetch(root + "?q=Clubbibliothek") or "Clubbibliothek</a>" not in member.fetch(root + "?q=Clubbibliothek"):
        raise RuntimeError("The original q entry stopped following its category permissions")

    def ids(page):
        return list(map(int, re.findall(r'<a href="[^"]*/downloads/file/(1084\d)(?:\?[^"]*)?">', page)))

    def search(actor=guest, **query):
        return actor.fetch(path + "?" + urlencode(query))

    def post(**query):
        request = Request(urljoin(base, path), data=urlencode(dict(defaults, **query)).encode())
        with guest.opener.open(request, timeout=30) as response:
            target = response.geturl()
            params = parse_qs(urlsplit(target).query)
            if not params.get("search_id", [""])[0].isdigit() or "search_keywords" in params or "search_author" in params:
                raise RuntimeError("A private search did not return a session-local numeric bookmark")
            if "no-store" not in response.headers["Cache-Control"] or response.headers["Referrer-Policy"] != "no-referrer":
                raise RuntimeError("Personal download results were reusable by a shared cache or referrer")
            return target, response.read().decode()

    saved, first = post(search_keywords="Atlas Farbplan", search_terms="all", cat_id="21", sort_method="file_name", sort_order="ASC")
    if ids(first) != [10841] or "2 Downloads gefunden" not in first or ids(guest.fetch(saved + "&start=1")) != [10843]:
        raise RuntimeError("The subtree, Boolean match, sort or next page did not use the complete result set")
    if "aktuell" not in Browser(base).fetch(saved).lower() or ids(Browser(base).fetch(saved)):
        raise RuntimeError("A result bookmark belonged to a different guest session")
    for text, expected in (("🎨", [10841]), ("Polarstern", [10841]), ("Himbeerfarbe", [10842]),
                           ("InternalPathFixture", []), ("Farbplan NOT Polarstern", [10843])):
        if ids(search(search_keywords=text, comments_search="YES", cat_id="21")) != expected:
            raise RuntimeError(f"Incorrect match in a readable file or comment for {text}")
    if ids(search(search_keywords="Himbeerfarbe", comments_search="NO")):
        raise RuntimeError("A comment matched with comments disabled")
    if ids(search(search_keywords="Atlas", search_author="Hist*Member", cat_id="10830")) != [10841]:
        raise RuntimeError("The author wildcard did not use the current submitting account")
    if (ids(search(search_author="HistoricMember", cat_id="10830")) != [10841] or
            ids(search(search_keywords="aber Polarstern", search_terms="all", cat_id="10830")) != [10841] or
            ids(search(search_keywords="Atlas", cat_id="10830", sort_method="rating", sort_order="DESC")) != [10842]):
        raise RuntimeError("Author-only matching, board-language stop words or a historical sort mode was lost")
    if ids(search(search_keywords="Werkstatt", search_author="Team", cat_id="21")):
        raise RuntimeError("The submitting account and the creator credit were mixed")
    if ids(search(search_keywords="SucheLabor")) or ids(search(ordinary, search_keywords="SucheLabor")):
        raise RuntimeError("Hidden parent, pending group or private files leaked through search")
    if (ids(search(member, search_keywords="SucheLabor", cat_id="22")) != [10844] or
            ids(search(member, search_keywords="SucheLabor", cat_id="24")) != [10846]):
        raise RuntimeError("A private subtree or personal grant vanished from search")
    item = urljoin(base, root + "/file/10841")
    submit_form(member, item, member.fetch(item), "dl-comment", {"subject": "Lesebuch", "message": "Ein [b]Suchkommentar[/b]."})
    edit = item + "/edit"
    submit_form(member, edit, member.fetch(edit), "dl-edit", {"description": "Neuer Inhaltsvermerk", "author": "Kartenredaktion"})
    if (ids(search(search_keywords="Suchkommentar", comments_search="YES")) != [10841] or
            ids(search(search_keywords="Kartenredaktion Inhaltsvermerk", search_terms="all")) != [10841] or
            ids(search(search_keywords="Polarstern", cat_id="10830"))):
        raise RuntimeError("A posted comment or a file edit left the previous searchable text behind")
    comment_id, _ = post(search_keywords="Himbeerfarbe", comments_search="YES")
    link_id, _ = post(search_keywords="Wegweiser", comments_search="YES")
    verify("narrow")
    if ids(guest.fetch(saved)) != [10843] or ids(guest.fetch(comment_id)) or ids(guest.fetch(link_id)):
        raise RuntimeError("A saved result added a later file, kept a draft, or reused revoked comment visibility")
    if not ids(search(member, search_keywords="Himbeerfarbe", comments_search="YES")) or ids(search(member, search_keywords="Wegweiser", comments_search="YES")):
        raise RuntimeError("Changing the link policy suppressed readable prose or exposed a hidden link label")
    verify("expire")
    if "aktuellen Sitzung" not in guest.fetch(saved):
        raise RuntimeError("A saved search remained usable after the session lifetime")
    verify("restricted")
    if "dl-advanced" in Page(guest.fetch(path)).forms or "Erweiterte Download-Suche" in guest.fetch(root):
        raise RuntimeError("The public catalogue offered a restricted advanced search")
    ordinary.fetch(path, expected_status=403)
    member.fetch(path)
    verify("restore")
