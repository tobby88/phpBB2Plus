"""Exercise the native attachment editor on a board with converted per-user allowances."""
import re
import time
from urllib.parse import urljoin

from attachments import GIF
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
