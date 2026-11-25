"""Use separately authorised native ACP sessions to configure the statistics page."""
import re
from board_statistics import panels
from delegation import authenticate
from http_board import Browser, FormFields, submit_form


def exercise(base, fixture):
    form = "plus-stats-admin"
    front = "app.php/statistics"
    guest = Browser(base)
    fixture("rights")
    try:
        manager, modules_entry = authenticate(base, "DownloadCategoryJunior")
        operator, settings_entry = authenticate(base, "DownloadAccessJunior")
        module = "&i=-phpbb2plus-community-acp-statistics_module&mode="
        modules = modules_entry + module + "manage"
        settings = settings_entry + module + "settings"
        for browser, url in ((manager, modules_entry + module + "settings"), (operator, settings_entry + module + "manage")):
            if "Module not accessible" not in browser.fetch(url, expected_status=503):
                raise RuntimeError("The two native statistics administration rights were not separate")
        page = manager.fetch(modules)
        other = operator.fetch(settings)
        for html, expected, forbidden in ((page, "Statistikmodule verwalten|Manage statistics modules", "Statistik konfigurieren|Configure statistics"),
                                           (other, "Statistik konfigurieren|Configure statistics", "Statistikmodule verwalten|Manage statistics modules")):
            if (not re.search(r'<a [^>]*href="[^"]+"[^>]*><span>(?:' + expected + r')</span>', html)
                    or re.search(r'<a [^>]*href="[^"]+"[^>]*><span>(?:' + forbidden + r')</span>', html)):
                raise RuntimeError("The ACP navigation did not show exactly the separately granted statistics module")
        if "custom_fixture" not in page or "action=edit&amp;item=20" in page:
            raise RuntimeError("The imported custom report lost its read-only registration")
        edit = modules + "&action=edit&item=22"
        initial = manager.fetch(edit)
        submit_form(manager, edit, initial, form, {"state": "2", "audience": "1", "position": "1"})
        if 22 in panels(guest.fetch(front)) or next(iter(panels(manager.fetch(front)))) != 22:
            raise RuntimeError("A changed native report audience or order did not take effect")
        conflict = submit_form(manager, edit, initial, form, {"state": "0"})
        if "zwischenzeitlich geändert" not in conflict and "changed while" not in conflict:
            raise RuntimeError("A stale report form was able to overwrite someone else's change")
        current = other
        submit_form(operator, settings, current, form, {"limit": "3", "ttl": "0", "clear": "1"})
        saved = FormFields(operator.fetch(settings), form).values
        if saved.get("limit") != "3" or saved.get("ttl") != "0":
            raise RuntimeError("The settings-only operator could not save the actual reporting limits")
        fixture("original")
        fixture("fresh")
        add = modules + "&action=add"
        blank = manager.fetch(add)
        defaults = FormFields(blank, form).values
        if defaults.get("state") != "1" or defaults.get("audience") != "5" or panels(guest.fetch(front)):
            raise RuntimeError("A new board or newly selected report became public without a decision")
        submit_form(manager, add, blank, form, {"name": "forum_index", "audience": "0"})
        if panels(guest.fetch(front)):
            raise RuntimeError("Installation activated a report that was saved as inactive")
        new = modules + "&action=edit&item=1"
        submit_form(manager, new, manager.fetch(new), form, {"state": "2"})
        if list(panels(guest.fetch(front))) != [1]:
            raise RuntimeError("A freshly installed native report cannot be displayed")
        submit_form(manager, new, manager.fetch(new), form, {"state": "0"})
        if panels(guest.fetch(front)):
            raise RuntimeError("An uninstalled report remained visible")
        displayed = manager.fetch(new)
        fixture("revoke")
        denied = submit_form(manager, new, displayed, form, {"state": "2"}, status=503)
        if "Module not accessible" not in denied or panels(guest.fetch(front)):
            raise RuntimeError("A previously open form saved after revocation")
    finally:
        fixture("restore")
