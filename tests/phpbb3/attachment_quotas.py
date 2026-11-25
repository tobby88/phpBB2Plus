"""Exercise the native attachment editor on a board with converted per-user allowances."""
import re
import time
from urllib.parse import urljoin

from attachments import GIF, PNG
from http_board import Page


def browse(member):
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
