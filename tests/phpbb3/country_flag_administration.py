"""Edit the imported country dropdown with its own converted Junior Admin grant."""
import json
import re
from html import unescape

from delegation import authenticate
from http_board import Browser, FormFields, admin_url, submit_form


def exercise(base, german, fixture):
    junior, entry = authenticate(base, "CountryFlagJunior")
    panel = entry + "&i=-phpbb2plus-community-acp-flags_module&mode=manage"
    edit = "ucp.php?i=ucp_profile&mode=profile_info"
    profile = "memberlist.php?mode=viewprofile&u=61"
    form = "plus-country-flag"

    def choice(actor, label):
        body = actor.fetch(edit)
        select = re.search(r'<select[^>]+name="pf_plus_country"[^>]*>(.*?)</select>', body, re.S)
        if not select:
            raise RuntimeError("Missing native country selector")
        matches = re.findall(r'<option value="(\d+)"[^>]*>\s*([^<]+?)\s*</option>', select[1])
        return next((int(value) for value, text in matches if unescape(text) == label), None)

    def save(url, fields):
        return submit_form(junior, url, junior.fetch(url), form, fields)

    def select_country(value):
        # The synthetic source has a required multiple-choice member field.
        response = submit_form(junior, edit, junior.fetch(edit), "ucp", {
            "pf_plus_country":str(value), "pf_plus_f54[]":["2"], "submit":"1"})
        if int(fixture("selection")) != value:
            error = re.search(r'class="error">(.+?)</', response, re.S)
            raise RuntimeError("Native UCP country selection failed: " + (unescape(error[1]) if error else "no form error"))

    listing = junior.fetch(panel)
    imported = json.loads(fixture("initial"))
    north = imported["source"]
    if "Country flags" not in listing or "Nordlicht" not in listing or "Abendrot" in listing:
        raise RuntimeError("Imported flags or preserved, unlisted legacy selections were mixed in the ACP")
    denied = junior.fetch(entry + "&i=acp_profile&mode=profile", expected_status=503)
    if "Module not accessible" not in denied:
        raise RuntimeError("The flag delegate acquired general profile field administration")
    create = panel + "&action=create"
    if int(fixture("selection")) != imported["orphan"]:
        raise RuntimeError("Missing profile-only country fixture")
    save(create, {"label":"Re-listed fixture", "filename":"relisted.png"})
    relisted = choice(junior, "Re-listed fixture")
    if not relisted or choice(junior, "Profile-only fixture") is not None or int(fixture("selection")) != relisted:
        raise RuntimeError("Re-registering a former catalogue image left its original members on a separate choice")
    save(panel + "&action=delete&flag=" + str(relisted), {"confirm":"1"})
    if int(fixture("selection")) != 1:
        raise RuntimeError("The removed re-registered image remained selected on its old member")
    previous = junior.fetch(create)
    alias = "Färöer & Inseln"
    save(create, {"label": alias, "filename": "nordlicht.png"})
    current = choice(junior, alias)
    if not current or choice(german, alias) != current:
        raise RuntimeError("Adding a flag did not populate the native German and English choices")
    stale = submit_form(junior, create, previous, form, {"label":"Stale duplicate", "filename":""})
    if "catalogue changed" not in stale or choice(junior, "Stale duplicate") is not None:
        raise RuntimeError("An old creation form changed the catalogue twice")
    select_country(current)
    if alias not in unescape(german.fetch(profile)) or alias in unescape(Browser(base).fetch(profile)):
        raise RuntimeError("A selected flag lost its normal native profile visibility")
    alias_url = panel + "&action=edit&flag=" + str(current)
    invalid = save(alias_url, {"label": alias, "filename": "missing-flag.png"})
    if "existing flag filename" not in invalid or FormFields(junior.fetch(alias_url), form).values["filename"] != "nordlicht.png":
        raise RuntimeError("A missing replacement left a partial flag edit")
    save(alias_url, {"label":"New entry", "filename":""})
    if int(fixture("selection")) != north:
        raise RuntimeError("Changing a duplicate image detached a member from the surviving original")
    fixture("native-label")
    original = panel + "&action=edit&flag=" + str(north)
    blocked = save(original, {"label":"Overwrite", "filename":"nordlicht.png"})
    if "translation was changed" not in blocked or "Eigene Übersetzung" not in german.fetch(edit):
        raise RuntimeError("The delegate replaced a separately maintained native translation")
    fixture("restore-label")
    # Only the converted source option is reused here. Leave its text and image intact for other suites.
    save(alias_url, {"label": "Staged raster", "filename": "stage-flag.png"})
    select_country(current)
    if "images/plusflags/stage-flag.png" not in german.fetch(profile):
        raise RuntimeError("The native member profile lost the locally staged raster")
    remove = panel + "&action=delete&flag=" + str(current)
    confirmation = junior.fetch(remove)
    if "Staged raster" not in confirmation or int(fixture("selection")) != current:
        raise RuntimeError("Opening the removal confirmation changed a native selection")
    submit_form(junior, remove, confirmation, form, {"confirm":"1"})
    if choice(junior,"Staged raster") is not None or int(fixture("selection")) != 1:
        raise RuntimeError("Removing the last occurrence of an image left a selected flag")
    save(create, {"label":"New identity", "filename":""})
    replacement = choice(junior, "New identity")
    if not replacement or replacement <= current:
        raise RuntimeError("A retired option ID was reassigned")
    native = admin_url(german) + "&i=acp_profile&mode=profile&action=edit&field_id=" + str(imported["field"])
    native_form = "add_profile_field"
    flags_url = panel + "&action=edit&flag=" + str(replacement)
    old_flags = junior.fetch(flags_url)
    original_label = FormFields(german.fetch(native), native_form).values["lang_name"]
    for label in ("Native field fixture", original_label):
        submit_form(german, native, german.fetch(native), native_form, {"lang_name":label, "save":"1"})
        if (FormFields(german.fetch(native), native_form).values["lang_name"] != label or
                choice(junior, "New identity") != replacement or choice(german, "New identity") != replacement):
            raise RuntimeError("Saving country field metadata in the native ACP renumbered remaining options")
    refused = submit_form(junior, flags_url, old_flags, form, {"label":"Outdated", "filename":""})
    if "catalogue changed" not in refused:
        raise RuntimeError("A native field save did not invalidate an earlier flag form")
    old_native = german.fetch(native)
    save(flags_url, {"label":"Updated identity", "filename":""})
    refused = submit_form(german, native, old_native, native_form, {"lang_name":"Outdated", "save":"1"})
    if "Katalog wurde inzwischen geändert" not in refused or choice(junior, "Updated identity") != replacement:
        raise RuntimeError("An earlier native form removed a newer catalogue edit")
    pending = junior.fetch(flags_url)
    fixture("never")
    try:
        response = submit_form(junior, flags_url, pending, form, {"label":"Denied change", "filename":""}, status=403)
        if "not authorised" not in response or choice(german,"Updated identity") != replacement:
            raise RuntimeError("An explicit native Never failed to revoke an already-open flag form")
    finally:
        fixture("restore-grant")
    save(panel + "&action=delete&flag=" + str(replacement), {"confirm":"1"})
