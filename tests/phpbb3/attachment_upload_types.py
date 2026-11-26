"""Check the converted new-file policy through both native composers with inert text fixtures."""
import json
import re
import time
from urllib.parse import urljoin

from attachment_rules import Rules
from http_board import FormFields, Page, admin_url, submit_form


def browse(admin, moderator, member, phase):
    post = "posting.php?mode=reply&t=1"
    pm = "ucp.php?i=pm&mode=compose"
    settings = admin_url(admin) + "&i=attachments&mode=attach"
    option = "config[plus_attach_upload_types]"

    def check_chooser(actor, address, forum, permitted, maximum):
        page = actor.fetch(address)
        filebox = re.search(r'<input\b[^>]*\bid="fileupload"[^>]*>', page)
        file_types = re.search(r'\baccept="([^"]*)"', filebox[0])[1].split(",")
        filters = re.search(r'mime_types:\s*(\[.*?\])\s*,\s*mime_types_max_file_size:', page, re.S)
        groups = {entry["title"]: entry for entry in json.loads(filters[1])}
        expected = {".rnote", ".py"} if permitted else {".rnote"}
        notes = groups["Classroom notes"]
        rules = Rules(actor.fetch("attach_rules.php?f=" + str(forum))).groups["Classroom notes"][0]
        if (rules != expected or set(file_types).intersection({".py", ".rnote"}) != expected or
                {"." + ext for ext in notes["extensions"].split(",")} != expected or notes["max_file_size"] != maximum):
            raise RuntimeError("The editor, JavaScript limits and attachment rules disagreed")
        return page

    def attach(actor, address, page, name):
        action, fields = Page(page).forms["postform"]
        fields.update(subject="Attachment fixture", message="A disposable note.")
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        url = urljoin(urljoin(actor.base, address), action)
        if name is None:
            fields["delete_file[0]"] = "1"
            return actor.fetch(url, fields)
        fields["add_file"] = "1"
        return actor.fetch(url, fields, files=[("fileupload", name, "text/plain", b"A reading note.")])

    def choose(before, after):
        form = admin.fetch(settings)
        if FormFields(form, "attachsettings").values.get(option) != str(before) or "Dateiendungen für neue Anhänge" not in form:
            raise RuntimeError("The native attachment settings did not show the active policy")
        submit_form(admin, settings, form, "attachsettings", {option: str(after), "submit": "1"})
        if FormFields(admin.fetch(settings), "attachsettings").values.get(option) != str(after):
            raise RuntimeError("The native administrator could not change the attachment policy")

    def download():
        if member.fetch("download/file.php?id=30412", raw=True) != b"Archived classroom note.\n":
            raise RuntimeError("The Plus upload restriction blocked or changed a stored post attachment")

    def allowed(actor, address, page, name):
        draft = attach(actor, address, page, name)
        if not re.search(r'data-attach-id="[1-9][0-9]*"', draft) or name not in draft:
            raise RuntimeError("The native editor did not retain a permitted test attachment")
        if re.search(r'data-attach-id="[1-9][0-9]*"', attach(actor, address, draft, None)):
            raise RuntimeError("Unable to remove the temporary attachment from the draft")

    phase("seed")
    try:
        download()
        for address, forum in ((post, 1), (pm, 0)):
            page = check_chooser(moderator, address, forum, False, 0)
            rejected = attach(moderator, address, page, "class-note.py")
            if "The extension py is not allowed." not in rejected or 'data-attach-id="' in rejected:
                raise RuntimeError("A newly uploaded note did not receive the native extension rejection")
        allowed(member, post, check_chooser(member, post, 1, False, 400), "class-note.rnote")
        choose(1, 0)
        allowed(moderator, pm, check_chooser(moderator, pm, 0, True, 0), "class-note.py")
        choose(0, 1)
        check_chooser(member, post, 1, False, 400)
        download()
        phase("existing-only")
        if 'id="fileupload"' in member.fetch(post):
            raise RuntimeError("The editor offered an upload even though no new post attachment type was allowed")
        edit_url = "posting.php?mode=edit&p=32"
        edit = admin.fetch(edit_url)
        for page in (edit, submit_form(admin, edit_url, edit, "postform", {"preview": "1"})):
            fields = FormFields(page, "postform").values
            archived = any(key and key.endswith("[attach_id]") and value == "30412" for key, value in fields.items())
            row = 'data-attach-id="30412"' in page
            existing_only = "attachment_existing.css" in page
            if not (archived and row and existing_only):
                raise RuntimeError(f"Existing-only attachment form lost the ID ({archived}), row ({row}) or controls ({existing_only})")
        download()
    finally:
        phase("clean")
