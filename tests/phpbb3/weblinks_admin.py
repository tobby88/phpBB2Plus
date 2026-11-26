"""Exercise the original four directory administrators on disposable converted boards."""
from concurrent.futures import ThreadPoolExecutor
import re
from urllib.parse import urlencode, urljoin

from delegation import authenticate
from http_board import Browser, FormFields, Page, submit_form


def administration(base, verify):
    def require(ok, message):
        if not ok:
            raise RuntimeError(message)

    def saved(actor, url, form, changes, page=None):
        reply = submit_form(actor, url, page if page is not None else actor.fetch(url), form, changes)
        require("The website directory has been updated." in reply, f"Directory form {form} did not save")
        return reply

    def changed(body):
        return "changed" in body.lower() and "updated." not in body

    def mode(acp, name):
        return acp + "&i=-phpbb2plus-weblinks-acp-management_module&mode=" + name

    verify("cleanup")
    try:
        verify("setup")
        verify("upgrade")
        names = ("LinkAddJunior", "LinkManageJunior", "LinkCategoryJunior", "LinkSettingsJunior")
        with ThreadPoolExecutor(max_workers=4) as workers:
            sessions = list(workers.map(lambda n: authenticate(base, n), names))
        (creator, add_acp), (editor, edit_acp), (organiser, cat_acp), (operator, settings_acp) = sessions
        add, entries = mode(add_acp, "create"), mode(edit_acp, "entries")
        cats, prefs = mode(cat_acp, "categories"), mode(settings_acp, "settings")
        require("Module not accessible" in organiser.fetch(mode(cat_acp, "entries"), expected_status=503) and
                "Module not accessible" in editor.fetch(mode(edit_acp, "settings"), expected_status=503) and
                "Module not accessible" in operator.fetch(mode(settings_acp, "create"), expected_status=503),
                "Website, category and settings grants did not remain independent")
        require("wl-review-4114" in editor.fetch(edit_acp + "&i=-phpbb2plus-weblinks-acp-review_module&mode=review"),
                "The historical entry editor lost its review queue")
        guest = Browser(base)
        require("Linkverzeichnis" in guest.fetch("app.php/links"), "The German directory disappeared during the ACP upgrade")
        record = entries + "&action=edit&entry=941001"
        old_delete = editor.fetch(entries + "&action=remove&entry=941001")
        form = editor.fetch(record)
        require(FormFields(form, "wl-entry").values["logo"] == "images/links/phpBB_88a.gif", "The imported local logo was missing in the ACP")
        saved(editor, record, "wl-entry", {"description": "Updated & verified café"}, form)
        require(changed(submit_form(editor, entries, old_delete, "wl-confirm", {"confirmed": 1})),
                "A stale single website deletion was accepted")
        verify("edited")
        target = cats + "&action=remove&cat_id=941"
        pick = FormFields(organiser.fetch(target), "wl-category-target").values
        require(pick["target"] == "" and pick.get("sid") and pick.get("i"), "The category chooser lost its ACP location or assumed deletion")
        pick["target"] = "711"
        preview = urljoin(base, "adm/index.php") + "?" + urlencode(pick)
        old_move = organiser.fetch(preview)
        require("its 2 entries" in old_move, "The category confirmation omitted an unpublished website")
        saved(creator, add, "wl-entry", {"title": "ACP submitted without a logo", "description": "Private suggestion from the ACP",
                                          "destination": "https://directory.example.invalid/acp", "cat_id": "941", "status": "0"})
        require(changed(submit_form(organiser, preview, old_move, "wl-confirm", {"confirmed": 1})),
                "A category confirmation silently included a newly added website")
        fresh = organiser.fetch(preview)
        require("its 3 entries" in fresh, "Refreshed move preview did not count all three websites")
        saved(organiser, preview, "wl-confirm", {"confirmed": 1}, fresh)
        verify("moved")
        filtered = editor.fetch(entries + "&q=Updated%20%26%20verified")
        require(re.findall(r'data-weblink="(\d+)"', filtered) == ["941001"], "The private ACP search did not filter by description")
        saved(organiser, cats, "wl-category-new", {"name": "ACP newly created"})
        cat_page = organiser.fetch(cats)
        new = re.search(r'id="wl-category-(\d+)"[^>]*>\s*<fieldset><label>[^<]+<input[^>]*value="ACP newly created"', cat_page)
        require(new, "New category was not listed")
        form_name = "wl-category-" + new.group(1)
        saved(organiser, cats, form_name, {"operation": "up"}, cat_page)
        moved = organiser.fetch(cats)
        require(moved.index(form_name) < moved.index('id="wl-category-942"'), "Move up did not change the category order")
        saved(organiser, cats, form_name, {"operation": "down"}, moved)
        restored = organiser.fetch(cats)
        require(restored.index(form_name) > restored.index('id="wl-category-942"'), "Move down did not restore the order")
        saved(organiser, cats, form_name, {"operation": "rename", "name": "ACP renamed category"}, restored)
        discard = cats + "&action=remove&cat_id=942&target=0"
        prompt = organiser.fetch(discard)
        require("all 2 entries" in prompt and "Permanently" in prompt, "Removing a populated category did not explain permanent removal")
        saved(organiser, discard, "wl-confirm", {"confirmed": 1}, prompt)
        verify("german")
        translated = organiser.fetch(cats)
        require("Kategorien des Linkverzeichnisses" in translated and "Neue Kategorie" in translated,
                "German administrators did not receive the translated category dialog")
        verify("revoke-review")
        form = editor.fetch(record)
        require("status" not in FormFields(form, "wl-entry").values, "A metadata editor was offered publication decisions")
        verify("revoke-edit")
        denied = submit_form(editor, record, form, "wl-entry", {"description": "Should not replace the current description"})
        require("authoris" in denied.lower() and "updated." not in denied, "An open editor could still save after its edit right was removed")
        deletion_list = editor.fetch(entries + "&q=Updated%20%26%20verified")
        require('data-weblink="941001"' in deletion_list and "192.0.2.1" not in deletion_list,
                "The removal-only list disclosed the submitter's IP address")
        single = entries + "&action=remove&entry=941002"
        saved(editor, single, "wl-confirm", {"confirmed": 1})
        verify("removed")
        form = operator.fetch(prefs)
        require("imported submission settings require review" in form, "Invalid legacy settings were not marked for review")
        saved(operator, prefs, "wl-settings", {"lock_submit_site": "0", "allow_no_logo": "1", "linkspp": "3", "display_interval": "9000"}, form)
        require(changed(submit_form(operator, prefs, form, "wl-settings", {"width": "99"})), "An old settings form overwrote newer choices")
        verify("settings")
        form = operator.fetch(prefs)
        invalid = submit_form(operator, prefs, form, "wl-settings", {"display_interval": "200"})
        require("allowed ranges" in invalid, "A rotation interval below the imported minimum was accepted")
        saved(operator, prefs, "wl-settings", {"site_logo": "images/smilies/icon_e_biggrin.gif"}, form)
        require(guest.fetch("app.php/links/logo/0", raw=True).startswith(b"GIF"), "The selected public board logo was not served")
        saved(operator, prefs, "wl-settings", {"site_logo": ""})
        require(guest.fetch("app.php/links/logo/0", raw=True).startswith(b"GIF"), "Clearing the logo did not restore the bundled board button")
    finally:
        verify("cleanup")
