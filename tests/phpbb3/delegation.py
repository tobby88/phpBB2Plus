"""Check the converted Junior Admin's own, reauthenticated native ACP session."""
import json
import os
import re
import shutil
import time
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import urljoin

from http_board import Browser, FormFields, Page, admin_url, login, submit_form


def source_modules(source, target):
    # Junior Admin also supports operators who renamed the ACP directory.
    helper = "includes/functions_jr_admin.php"
    (target / helper).write_text((source / helper).read_text(encoding="utf-8").replace("'admin/'", "'staff/'", 1), encoding="utf-8")
    directory = target / "staff"
    directory.mkdir(exist_ok=True)
    for file in (source / "admin").glob("admin_*.php"):
        shutil.copyfile(file, directory / file.name)
    for path in ("includes/functions_jr_admin_auth.php", "includes/functions_stats.php", "includes/functions_module.php",
                 "includes/functions_flags_storage.php", "includes/functions_board_config.php", "includes/functions_connection_ownership.php",
                 "includes/functions_arcade_score_storage.php", "includes/functions_arcade_action_storage.php",
                 "includes/functions_arcade_game_storage.php", "includes/functions_arcade_import_storage.php",
                 "includes/functions_moderator_identity.php",
                 "ctracker/admin/acp_module_settings.php", "ctracker/classes/class_ct_adminfunctions.php",
                 "ctracker/admin/acp_module_globalmessage.php", "ctracker/classes/class_admin_actions.php",
                 "ctracker/classes/class_ct_database.php", "ctracker/classes/class_settings_storage.php",
                 "ctracker/classes/class_configuration_storage.php", "ctracker/classes/class_acp_storage.php",
                 "includes/functions_acl_storage.php", "includes/functions_owned_storage.php",
                 "pafiledb/pafiledb_common.php", "pafiledb/includes/pafiledb_constants.php",
                 "pafiledb/includes/functions_pafiledb.php", "pafiledb/includes/functions_file_authority.php",
                 "pafiledb/includes/functions_file_actions.php", "pafiledb/includes/functions_file_coordinator.php",
                 "pafiledb/includes/functions_file_journal.php", "pafiledb/includes/functions_file_reference.php",
                 "pafiledb/includes/functions_file_storage.php", "pafiledb/includes/functions_file_delete.php",
                 "pafiledb/includes/functions_mutation_storage.php", "pafiledb/includes/functions_mirror_storage.php",
                 "pafiledb/includes/functions_settings_storage.php", "pafiledb/includes/functions_file_checker.php",
                 "pafiledb/includes/functions_permission_storage.php", "pafiledb/includes/functions_license_storage.php",
                 "pafiledb/includes/functions_custom_storage.php", "pafiledb/includes/functions_field.php",
                 "album_mod/album_admin_category_storage.php", "album_mod/album_cache_maintenance.php",
                 "album_mod/album_file_storage.php", "album_mod/album_file_coordinator.php",
                 "album_mod/album_category_remove.php", "album_mod/album_lifecycle_authority.php"):
        (target / path).parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source / path, target / path)
    credits = directory / "admin_hacks_list.php"
    credits.write_bytes(credits.read_bytes().replace(b"\r\n", b"\n").replace(b"\r", b"\n").replace(b"\n", b"\r\n"))
    with (directory / "admin_disallow.php").open("ab") as changed:
        changed.write(b"\n/* Locally customised controller */\n")


def verify_report(php, web):
    output = php("bin/phpbbcli.php", "phpbb2plus:delegation:report", cwd=web, capture=True)
    records = {entry["source_user"]: entry for entry in map(json.loads, output.splitlines())}
    if records[1888]["account"] != "missing-account" or "Private source note" in output:
        raise RuntimeError("The operator report lost source account status or exposed private notes")


def authenticate(base, name):
    password = os.environ["PLUS_TEST_MODERN_PASSWORD"]
    junior = login(base, name, password)
    entry = admin_url(junior)
    action, fields = Page(junior.fetch(entry)).forms["login"]
    fields.update(login="1", username=name)
    fields["password_" + fields["credential"]] = password
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    junior.fetch(urljoin(entry, action), fields)
    return junior, admin_url(junior)


def personal_album_rights(browser, url, page):
    form = "album-personal-grants"
    def counts(body):
        match = re.search(r'<tr data-permission="delete" data-assigned="([0-9]+)" data-categories="([0-9]+)"', body)
        if not match:
            raise RuntimeError("The existing membership totals are missing for the personal albums")
        return tuple(map(int, match.groups()))

    previous, total = counts(page)
    if previous != 0 or not total or FormFields(page, form).values.get("scope") != "existing" or '<option value="future">' in page:
        raise RuntimeError("The historical delegate did not start with the original personal album scope")
    submit_form(browser, url, page, form, {"change[delete]": "grant", "scope": "future"}, status=403)
    if counts(browser.fetch(url)) != (previous, total):
        raise RuntimeError("A refused personal album scope changed a group's existing rights")
    for action, wanted in (("grant", total), ("remove", 0)):
        preview = submit_form(browser, url, page, form, {"change[delete]": action})
        confirmation = "album-personal-grants-confirm"
        if confirmation not in Page(preview).forms or counts(browser.fetch(url)) != (previous, total):
            raise RuntimeError("Previewing a personal album grant did not preserve the original rights")
        submit_form(browser, url, preview, confirmation, {})
        page = browser.fetch(url)
        if counts(page) != (wanted, total):
            raise RuntimeError("The converted delegate could not confirm the change to the existing albums")
        previous = wanted


def file_management(browser, entry, fixture):
    panel = entry + "&i=-phpbb2plus-downloads-acp-file_actions_module&mode=manage"
    mirrors = entry + "&i=-phpbb2plus-downloads-acp-mirrors_module&mode=manage&action=file&file=10801"
    edit = panel + "&action=edit&file=10801"
    remove = panel + "&action=delete&ids=10801"
    fixture("setup")
    try:
        for module in ("categories", "permissions", "licenses", "fields", "settings"):
            url = entry + f"&i=-phpbb2plus-downloads-acp-{module}_module&mode=manage"
            if "Module not accessible" not in browser.fetch(url, expected_status=503):
                raise RuntimeError(f"The historical file operator gained the independent {module} panel")
        listing = browser.fetch(panel)
        if any(f'value="{op}"' not in listing for op in ("approve", "withdraw", "delete")):
            raise RuntimeError("The converted file-management panel lost a bulk action")
        confirmation = browser.fetch(remove)
        fixture("deny-delete")
        denied = submit_form(browser, remove, confirmation, "dl-files-confirm", {"confirm": "1"})
        if "dl-files-confirm" in Page(denied).forms or 'value="delete"' in browser.fetch(panel):
            raise RuntimeError("An individual native denial left the delete action available")
        initial = browser.fetch(edit)
        if 'id="dl-editor-mirrors"' not in initial or "dl-mirror-list" not in Page(browser.fetch(mirrors)).forms:
            raise RuntimeError("Revoking deletion closed the file operator's mirror management")
        submit_form(browser, edit, initial, "dl-file-editor",
                    {"summary": "Changed by file operator", "approved": "1", "field[81][]": ["Blau", "Rot"]})
        fixture("verified")
        browser.fetch("app.php/downloads/file/10801", expected_status=404)
    finally:
        fixture("cleanup")


def module_roles(base, file_fixture):
    modules = {
        "DownloadCategoryJunior": ("-phpbb2plus-downloads-acp-categories_module", "action=edit&category=23", "dl-category", "summary", "Delegated category edit"),
        "DownloadAccessJunior": ("-phpbb2plus-downloads-acp-permissions_module", "section=edit&category=21", "dl-permissions", "values[view]", "1"),
        "DownloadLicenseJunior": ("-phpbb2plus-downloads-acp-licenses_module", "action=edit&ids=31", "dl-license-admin", "name", "Delegated licence edit"),
        "DownloadFieldsJunior": ("-phpbb2plus-downloads-acp-fields_module", "action=edit&ids=81", "dl-field-admin", "name", "Delegated field edit"),
        "DownloadSettingsJunior": ("-phpbb2plus-downloads-acp-settings_module", "", "dl-settings", "v[settings_dbname]", "Delegated catalogue title"),
        "AlbumCategoryJunior": ("-phpbb2plus-gallery-acp-categories_module", "action=edit&category=8", "album-public-category", "description", "Delegated album edit"),
        "AlbumAccessJunior": ("-phpbb2plus-gallery-acp-permissions_module", "category=8", "album-category-grants", "grant[71][comment]", "1"),
        "AlbumPersonalJunior": ("-phpbb2plus-gallery-acp-personal_permissions_module", "group=75", "album-personal-grants", None, None),
        "AlbumCacheJunior": ("-phpbb2plus-gallery-acp-cache_module", "", "album-cache-clear", None, None),
        "CreditsJunior": ("-phpbb2plus-community-acp-credits_module", "action=edit&credit=19001", "plus-credit", "title", "Delegated credits edit"),
    }

    def exercise(roles):
        for username in roles:
            browser, acp = sessions[username].result()
            for owner, (module, query, form, field, new_value) in modules.items():
                url = acp + "&i=" + module + "&mode=manage&" + query
                if owner != username:
                    if "Module not accessible" not in browser.fetch(url, expected_status=503):
                        raise RuntimeError(f"{username} could open the separately delegated {module}")
                    continue
                page = browser.fetch(url)
                controls = FormFields(page, form).values
                if "form_token" not in controls:
                    raise RuntimeError(f"{username} could not open its mapped form {form}")
                if username == "AlbumPersonalJunior":
                    personal_album_rights(browser, url, page)
                    continue
                if username == "AlbumCacheJunior":
                    result = submit_form(browser, url, page, form, {"confirm": 1})
                    if "The album will generate fresh previews on the next request." not in result or form in Page(result).forms:
                        raise RuntimeError("The delegated preview cache confirmation failed")
                    continue
                original = controls.get(field)
                if original == new_value:
                    raise RuntimeError(f"Expected a different starting value for {module}")
                for value in (new_value, original):
                    submit_form(browser, url, page, form, {field: value})
                    page = browser.fetch(url)
                    controls = FormFields(page, form).values
                    if "form_token" not in controls or controls.get(field) != value:
                        raise RuntimeError(f"{username} could not save its mapped {field} in {module}")

    # Each member can log in and reauthenticate independently. Edits within
    # one catalogue still run in order because they share its write revision.
    with ThreadPoolExecutor(max_workers=4) as pool:
        sessions = {name: pool.submit(authenticate, base, name) for name in (*modules, "DownloadFilesJunior")}
        list(pool.map(exercise, (("DownloadCategoryJunior", "DownloadAccessJunior", "DownloadLicenseJunior", "DownloadFieldsJunior", "DownloadSettingsJunior"),
                                 ("AlbumCategoryJunior", "AlbumAccessJunior", "AlbumPersonalJunior", "AlbumCacheJunior"), ("CreditsJunior",))))
        file_management(*sessions["DownloadFilesJunior"].result(), file_fixture)


def browse(base):
    junior, entry = authenticate(base, "JuniorFixture")
    home = junior.fetch(entry)
    if "Your delegated administration" not in home or "Fixture &lt;only&gt; &amp; 😀" not in home or "Private source note" in home:
        raise RuntimeError("The delegated ACP home did not preserve the account's visible note")
    words = junior.fetch(entry + "&i=acp_words&mode=words")
    if 'id="acp_words"' not in words:
        raise RuntimeError("A converted Junior Admin could not open native word censoring")
    types = junior.fetch(entry + "&i=-phpbb2plus-knowledgebase-acp-types_module&mode=manage&action=create")
    if 'id="kb-type"' not in types:
        raise RuntimeError("A converted Junior Admin could not open the delegated Knowledge Base module")
    categories = junior.fetch(entry + "&i=-phpbb2plus-downloads-acp-categories_module&mode=manage&action=create")
    if 'id="dl-category"' not in categories:
        raise RuntimeError("The imported download category grant did not open its ACP module")
    denied = junior.fetch(entry + "&i=acp_board&mode=settings", expected_status=503)
    if "Module not accessible" not in denied:
        raise RuntimeError("phpBB did not return its native module-access denial")
    if "Review imported polls" in home:
        raise RuntimeError("The delegated ACP menu offered archived polls without database-backup permission")
    held = junior.fetch(entry + "&i=-phpbb2plus-community-acp-poll_recovery_module&mode=review", expected_status=503)
    if "Module not accessible" not in held or 'id="plus-held-polls"' in held:
        raise RuntimeError("Poll recovery did not follow the native backup permission")
    repaired = junior.fetch(entry + "&i=-phpbb2plus-community-acp-structure_module&mode=review", expected_status=503)
    if "Module not accessible" not in repaired or "Repaired forum structure" in home:
        raise RuntimeError("A delegated ACP account received the structural recovery report without backup access")
    return junior, entry


def suspended(junior, entry):
    if "login" not in Page(Browser(junior.base).fetch("ucp.php?mode=login")).forms:
        raise RuntimeError("The guest login form was lost when Community was disabled")
    page = junior.fetch("index.php")
    if "mode=logout" not in page or "adm/index.php?" in page:
        raise RuntimeError("Disabling Community did not suspend delegation for the signed-in member")
    denied = junior.fetch(entry, expected_status=403)
    if "You are not authorised to administer this board." not in denied:
        raise RuntimeError("phpBB did not return its native ACP entrance denial")


def resumed(junior):
    if "Your delegated administration" not in junior.fetch(admin_url(junior)):
        raise RuntimeError("Community activation did not restore the scoped ACP session")
