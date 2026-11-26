"""Exercise the native attachment editor on a board with converted per-user allowances."""
import re
import time
from html import unescape
from urllib.parse import parse_qs, urljoin, urlsplit

from attachments import GIF, PNG
from http_board import Browser, FormFields, Page, admin_url


def administration(owner, check):
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_attachments&mode=extensions")
    match = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Speicher für Dateianhänge</span>', menu)
    if not match:
        raise RuntimeError("The allowance editor is missing from native attachment administration")
    panel = urljoin(entry, unescape(match[1]))
    form = "plus-quota-edit"

    def link(page, title):
        found = re.search(r'<a\b[^>]*href="([^"]+)"[^>]*>' + re.escape(title) + '</a>', page)
        if not found:
            raise RuntimeError("Missing attachment allowance link for " + title)
        return urljoin(panel, unescape(found[1]))

    def submit(page, changes, form_id=form):
        action, _ = Page(page).forms[form_id]
        fields = FormFields(page, form_id).values
        fields.update(changes)
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(panel, action), fields)

    home = owner.fetch(panel)
    previous = FormFields(home, form).values
    if (previous.get("base_pm"), previous.get("post_level"), previous.get("pm_level")) != ("257", "1", "9"):
        raise RuntimeError("The ACP did not display the converted board defaults")
    new_url = link(home, "Kontingentstufe anlegen")
    new = owner.fetch(new_url)
    change = {"label":"Bilder & Grüße", "bytes":"18446744073709551615", "save":"1"}
    submit(new, change)
    if "inzwischen geändert" not in submit(new, change):
        raise RuntimeError("Repeating a creation form did not report the stale revision")
    edit = link(owner.fetch(panel), "Bilder &amp; Grüße")
    plan = parse_qs(urlsplit(edit).query)["quota_id"][0]
    if int(plan) <= 34 or FormFields(owner.fetch(edit), form).values["bytes"] != change["bytes"]:
        raise RuntimeError("A new quota lost its exact byte value or reused a dangling historical level")
    edit_page = owner.fetch(edit)
    if "Bitte prüfe" not in submit(edit_page, {"bytes":"18446744073709551616", "save":"1"}):
        raise RuntimeError("An out-of-range byte value was accepted")
    submit(edit_page, {"bytes":"128", "save":"1"})
    submit(owner.fetch(panel), {"base_pm":"4096", "post_level":plan, "pm_level":plan, "save":"1"})

    def group():
        page = owner.fetch(panel)
        if not re.search(r'<option value="74">PM quota circle</option>', page):
            raise RuntimeError("Converted native group unavailable for assignment")
        return submit(page, {"group_id":"74", "find_group":"1"}, "plus-quota-group")

    def member():
        return submit(owner.fetch(panel), {"username":"DepartingFixture", "find_member":"1"}, "plus-quota-member")

    submit(group(), {"post_level":"10", "pm_level":plan, "save":"1"})
    submit(member(), {"post_level":plan, "pm_level":"0", "save":"1"})
    if FormFields(member(), form).values["post_level"] != plan or FormFields(group(), form).values["pm_level"] != plan:
        raise RuntimeError("User and group assignments were not displayed separately")
    check("assigned")
    prompt = submit(owner.fetch(edit), {"remove":"1"})
    confirm = next((key for key, (_, hidden) in Page(prompt).forms.items() if "confirm_uid" in hidden), None)
    if not confirm or not link(owner.fetch(panel), "Bilder &amp; Grüße"):
        raise RuntimeError("Deleting an allowance skipped native confirmation")
    submit(prompt, {"confirm":"Ja"}, confirm)
    check("inherited")
    submit(new, change)
    if "Bilder &amp; Grüße" in owner.fetch(panel):
        raise RuntimeError("An old creation form restored a deleted level")
    submit(group(), {"post_level":"0", "pm_level":"0", "save":"1"})
    submit(owner.fetch(panel), {key: previous[key] for key in ("base_pm", "post_level", "pm_level")} | {"save":"1"})


def help_pages(member):
    english = member.fetch("faq.php")
    german = Browser(member.base).fetch("app.php/help/faq")
    for question in ("How do I add a file to a post or change an attachment?",
                     "How do I send a file in a private message?", "Why can a small file exceed my storage allowance?"):
        if english.count(question) != 2:
            raise RuntimeError("A Plus attachment question is missing from the English FAQ links or answers")
    if ("Wie füge ich einem Beitrag eine Datei hinzu" not in german or "Im Beitrag anzeigen" not in german
            or "PLUS_HELP_FILE_" in german or "How do I add a file" in member.fetch("faq.php?mode=bbcode")):
        raise RuntimeError("The original FAQ bookmark lost German instructions or changed the native BBCode guide")


def browse(member):
    help_pages(member)
    path = "posting.php?mode=reply&t=1"

    def submit(page, filename=None, remove=False):
        if 'name="fileupload"' not in page:
            raise RuntimeError("The fixture member has no attachment form in this forum")
        action, fields = Page(page).forms["postform"]
        fields.update(subject="Quota fixture", message="An attachment quota fixture.")
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        url = urljoin(urljoin(member.base, path), action)
        if remove:
            fields["delete_file[0]"] = "Delete file"
            return member.fetch(url, fields)
        fields["add_file"] = "Add file"
        return member.fetch(url, fields, files=[("fileupload", filename, "image/gif", GIF)])

    first = submit(member.fetch(path), "Quota-first.gif")
    if not re.search(r'data-attach-id="[1-9][0-9]*"', first) or "Quota-first.gif" not in first:
        raise RuntimeError("The native editor did not accept a file within the member allowance")
    second = submit(member.fetch(path), "Quota-second.gif")
    if "exceeds your storage allowance for post attachments" not in second:
        raise RuntimeError("An open draft in another editor did not count towards the member allowance")
    removed = submit(first, remove=True)
    if re.search(r'data-attach-id="[1-9][0-9]*"', removed):
        raise RuntimeError("The native editor kept a removed draft attachment")
    replacement = submit(member.fetch(path), "Quota-replacement.gif")
    if not re.search(r'data-attach-id="[1-9][0-9]*"', replacement):
        raise RuntimeError("Removing a draft did not free the member's attachment allowance")


def messages(owner, member, member_id):
    path = "ucp.php?i=pm&mode=compose&u=" + str(member_id)
    subject = "Anhang mit Kontingent"

    def submit(page, changes, upload=False):
        action, fields = Page(page).forms["postform"]
        fields.update(subject=subject, message="Diese private Nachricht enthält ein Testbild.")
        fields.update(changes)
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(urljoin(owner.base,path),action), fields,
                           files=[("fileupload","Quota-letter.png","image/png",PNG)] if upload else None)

    form = owner.fetch(path)
    if ('name="fileupload"' not in form or f'name="address_list[u][{member_id}]"' not in form
            or 'name="add_bcc"' not in form or 'PM quota circle' not in form):
        raise RuntimeError("The native PM attachment and recipient fields are missing")
    addressed = submit(form, {"group_list[]":[74],"add_bcc":"1"})
    if 'name="address_list[g][74]" value="bcc"' not in addressed:
        raise RuntimeError("The native compose form did not add the blind group recipient")
    ready = submit(addressed, {"add_file":"1"}, upload=True)
    added = re.search(r'data-attach-id="([1-9][0-9]*)"',ready)
    if not added:
        raise RuntimeError("The sender could not attach a file to the native PM draft")
    refused = submit(ready, {"post":"1"})
    if ("Empfänger fehlt Speicherplatz" not in refused or "Quota-letter.png" not in refused
            or 'name="remove_g[74]"' not in refused or subject not in refused):
        raise RuntimeError("A recipient quota should leave the complete editable PM draft on screen")
    without = submit(refused, {"remove_g[74]":"1"})
    corrected = submit(without, {"group_list[]":[71],"add_bcc":"1"})
    if (f'name="address_list[u][{member_id}]"' not in corrected or 'name="address_list[g][71]" value="bcc"' not in corrected
            or 'name="address_list[g][74]"' in corrected):
        raise RuntimeError("The native recipient controls did not replace the recipient")
    submit(corrected, {"post":"1"})
    inbox = member.fetch("ucp.php?i=pm&folder=inbox")
    if subject not in inbox or member.fetch("download/file.php?id=" + added.group(1),raw=True) != PNG:
        raise RuntimeError("The corrected message did not deliver its original attachment")
