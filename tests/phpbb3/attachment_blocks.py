"""Use both native attachment type forms and a converted operator's separate blocked-list form."""
import json
from pathlib import Path
import re
import time
from urllib.parse import urljoin

from attachments import GIF
from delegation import authenticate
from http_board import Page, submit_form


def browse(base, board, target, php):
    def stage(phase):
        return php(Path(__file__).with_suffix(".php"), target, phase, cwd=board, capture=True)
    def state():
        return json.loads(stage("state"))
    fixture = json.loads(stage("prepare"))
    form = "plus-attachment-blocks"
    panel = "&i=-phpbb2plus-community-acp-attachment_blocks_module&mode=manage"
    try:
        junior, entry = authenticate(base, "AttachmentBlockJunior")
        url = entry + panel
        page = junior.fetch(url)
        if form not in Page(page).forms or "Built-in protection" not in page or "früh.test" not in page or page.count("New uploads are blocked.") != 2:
            raise RuntimeError("The delegated list lost built-in rules, archived spelling or duplicate allow-list conflicts")
        fixed = re.search(r'<td>php</td>(.*?)</tr>', page, re.S)
        if not fixed or 'Built-in protection' not in fixed[1] or 'checkbox' in fixed[1]:
            raise RuntimeError("A built-in rule appeared as a removable custom rule")
        if "Module not accessible" not in junior.fetch(entry + "&i=acp_attachments&mode=extensions", expected_status=503):
            raise RuntimeError("The converted forbidden-list operator acquired the wider native attachment ACP")
        owner, home = authenticate(base, "ModernMember")
        native = home + "&i=acp_attachments&mode=extensions"
        if "Verbotene Dateiendungen für Anhänge" not in owner.fetch(native) or "Fester Schutz" not in owner.fetch(home + panel):
            raise RuntimeError("The German native ACP did not link to the forbidden extensions")
        review = home + "&i=-phpbb2plus-community-acp-delegations_module&mode=manage&account=" + str(fixture["user"])
        if "Zugewiesen wurde die Sperrliste" not in owner.fetch(review):
            raise RuntimeError("The original additional allow-list and group powers were missing from the manual-review note")
        if "already configured in the native extension list" not in submit_form(junior, url, page, form, {"extension":"GIF", "save":1}):
            raise RuntimeError("A native allowed extension could also be added to the custom block list")
        submit_form(junior, url, page, form, {"extension":"Pencase", "save":1})
        if "pencase" not in state()["custom"] or "settings have changed" not in submit_form(junior, url, page, form, {"extension":"othercase", "save":1}):
            raise RuntimeError("The block editor lost case normalization or accepted a stale mutation")
        native_page = owner.fetch(native)
        def add_allowed(name):
            return submit_form(owner, native, native_page, "add_ext", {"add_extension":name, "add_extension_check":1, "add_group_select":0})
        if "Dateiendung ist für Anhänge gesperrt" not in add_allowed("Blocknote") or "blocknote" in state()["native"]:
            raise RuntimeError("The native new extension form accepted a forbidden type")
        add_allowed("paperfixture")
        if "paperfixture" not in state()["native"]:
            raise RuntimeError("A legitimate native registration in the unassigned group failed")
        if "already configured in the native extension list" not in submit_form(junior, url, junior.fetch(url), form, {"extension":"paperfixture", "save":1}):
            raise RuntimeError("An unassigned native extension could be added to both lists")
        # Assigning a historical conflict to a different group is also an allow-list edit.
        groups = home + "&i=acp_attachments&mode=ext_groups&action=add"
        result = submit_form(owner, groups, owner.fetch(groups), "extgroups", {"group_name":"Attachment block fixture",
            "extensions[]":[str(fixture["extension"])], "allow_group":"1", "submit":"1"})
        if "Dateiendung ist für Anhänge gesperrt" not in result or int(state()["native"]["qcart"]) != fixture["group"]:
            raise RuntimeError("The native group editor reassigned a forbidden extension")
        if owner.fetch("download/file.php?id=" + str(fixture["attachment"]), raw=True) != GIF:
            raise RuntimeError("The historical conflict changed the download of an existing attachment")
        def upload(path):
            page = owner.fetch(path)
            action, fields = Page(page).forms["postform"]
            fields.update(subject="Fixture", message="Attachment block fixture", add_file="1")
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
            return owner.fetch(urljoin(urljoin(base, path), action), fields, files=[("fileupload", "Fixture.qcart", "text/plain", b"Local fixture")])
        for path in ("posting.php?mode=reply&t=1", "ucp.php?i=pm&mode=compose&u=2"):
            body = upload(path)
            if "qcart" not in body or "nicht erlaubt" not in body or re.search(r'data-attach-id="[1-9]', body) or state()["uploads"]:
                raise RuntimeError("A forbidden type reached a new post or PM attachment")
        page = junior.fetch(url)
        rid = str(state()["custom"]["pencase"])
        if not re.search(r'name="remove\[\]" value="' + rid + '"', page):
            raise RuntimeError("A custom rule cannot be removed through the list")
        submit_form(junior, url, page, form, {"remove[]":[rid], "save":1})
        if "pencase" in state()["custom"]:
            raise RuntimeError("The block editor kept the selected rule")
        submit_form(junior, url, junior.fetch(url), form, {"remove[]":[fixture["rules"]["251"]], "save":1})
        path = "posting.php?mode=reply&t=1"
        if "nicht erlaubt" not in upload(path):
            raise RuntimeError("Removing one duplicate also disabled the remaining historical block")
        submit_form(junior, url, junior.fetch(url), form, {"remove[]":[fixture["rules"]["252"]], "save":1})
        accepted = upload(path)
        if not re.search(r'data-attach-id="[1-9]', accepted) or state()["uploads"] != 1:
            raise RuntimeError("Removing the final custom block did not restore the native upload rule")
        action, fields = Page(accepted).forms["postform"]
        fields["delete_file[0]"] = "Entfernen"
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        owner.fetch(urljoin(urljoin(base, path), action), fields)
        if state()["uploads"]:
            raise RuntimeError("The normal composer did not discard its test attachment")
        open_form = junior.fetch(url)
        stage("revoke")
        denied = submit_form(junior, url, open_form, form, {"extension":"nevercase", "save":1}, status=403)
        if "not authorised" not in denied or "nevercase" in state()["custom"]:
            raise RuntimeError("An open form survived native revocation")
    finally:
        stage("clean")
