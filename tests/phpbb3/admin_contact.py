"""Follow old contact bookmarks to native guest and member forms without sending mail."""
from http.client import HTTPConnection
from urllib.parse import parse_qs, urljoin, urlsplit

from http_board import FormFields, Page, submit_form


def browse(guest, member, phase):
    local = urlsplit(guest.base)
    if local.hostname != "127.0.0.1" or member.base != guest.base:
        raise RuntimeError("The contact check requires one disposable loopback board")
    native = "memberlist.php?mode=contactadmin"
    entries = ("kontakt.php", "kontakt_post.php")
    phase("open")
    try:
        for entry in entries:
            connection = HTTPConnection(local.hostname, local.port, timeout=10)
            try:
                connection.request("HEAD", urljoin(local.path, entry + "?start=3&betreff=Fixture"))
                result = connection.getresponse()
                if result.status != 302 or result.getheader("Location") != "./" + native or result.read():
                    raise RuntimeError("An old contact bookmark did not open a fresh native form")
            finally:
                connection.close()
            guest.fetch(entry, {"betreff": "Old form", "textfeld": "Unused"}, expected_status=405)

        for actor, entry, title, signed_in in (
            (guest, entries[0], "Kontakt mit der Board-Administration aufnehmen", False),
            (member, entries[1], "Contact a Board Administrator", True),
        ):
            page = actor.fetch(entry)
            form = FormFields(page, "post").values
            route = urlsplit(urljoin(guest.base, Page(page).forms["post"][0]))
            if (route.netloc != local.netloc or route.path != urljoin(local.path, "memberlist.php") or
                    parse_qs(route.query).get("mode") != ["contactadmin"] or title not in page or
                    not form.get("form_token") or not {"subject", "message"} <= form.keys() or
                    ("email" in form) == signed_in or ("name" in form) == signed_in):
                raise RuntimeError("The native contact form lost its language, sender or action")
            if signed_in:
                # An empty native submission can only display validation errors.
                result = submit_form(actor, native, page, "post", {"submit": "1", "message": "", "subject": ""})
                if "You must specify a subject for the email." not in result or "post" not in Page(result).forms:
                    raise RuntimeError("The native contact form did not validate the incomplete message")

        for setting, actor, expected in (
            ("contact_off", guest, "Die Kontaktseite für die Board-Administration wurde deaktiviert."),
            ("mail_off", member, "Sorry but all email related functions have been disabled."),
        ):
            phase(setting)
            for entry in (*entries, native):
                page = actor.fetch(entry)
                if expected not in page or "post" in Page(page).forms:
                    raise RuntimeError("An old or native contact address ignored the disabled board setting")
    finally:
        phase("restore")
