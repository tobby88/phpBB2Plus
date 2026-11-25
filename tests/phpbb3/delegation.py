"""Check the converted Junior Admin's own, reauthenticated native ACP session."""
import json
import os
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
    for path in ("includes/functions_jr_admin_auth.php", "pafiledb/includes/functions_file_authority.php",
                 "pafiledb/includes/functions_permission_storage.php", "pafiledb/includes/functions_license_storage.php",
                 "album_mod/album_admin_category_storage.php",
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


def module_roles(base):
    modules = {
        "DownloadCategoryJunior": ("-phpbb2plus-downloads-acp-categories_module", "action=edit&category=23", "dl-category", "summary", "Delegated category edit"),
        "DownloadAccessJunior": ("-phpbb2plus-downloads-acp-permissions_module", "section=edit&category=21", "dl-permissions", "values[view]", "1"),
        "DownloadLicenseJunior": ("-phpbb2plus-downloads-acp-licenses_module", "action=edit&ids=31", "dl-license-admin", "name", "Delegated licence edit"),
        "AlbumCategoryJunior": ("-phpbb2plus-gallery-acp-categories_module", "action=edit&category=8", "album-public-category", "description", "Delegated album edit"),
        "AlbumAccessJunior": ("-phpbb2plus-gallery-acp-permissions_module", "category=8", "album-category-grants", "grant[71][comment]", "1"),
        "CreditsJunior": ("-phpbb2plus-community-acp-credits_module", "action=edit&credit=19001", "plus-credit", "title", "Delegated credits edit"),
    }

    def exercise(roles):
        for username in roles:
            browser, acp = authenticate(base, username)
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
                original = controls.get(field)
                if original == new_value:
                    raise RuntimeError(f"Expected a different starting value for {module}")
                for value in (new_value, original):
                    submit_form(browser, url, page, form, {field: value})
                    page = browser.fetch(url)
                    controls = FormFields(page, form).values
                    if "form_token" not in controls or controls.get(field) != value:
                        raise RuntimeError(f"{username} could not save its mapped {field} in {module}")

    # Each catalogue shares its write lock and revisions. Keep edits within it
    # sequential while testing the independent download, album and credit stores.
    with ThreadPoolExecutor(max_workers=3) as pool:
        list(pool.map(exercise, (("DownloadCategoryJunior", "DownloadAccessJunior", "DownloadLicenseJunior"),
                                 ("AlbumCategoryJunior", "AlbumAccessJunior"), ("CreditsJunior",))))


def browse(base):
    module_roles(base)
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
