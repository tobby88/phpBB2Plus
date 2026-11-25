"""Check the converted Junior Admin's own, reauthenticated native ACP session."""
import json
import os
import shutil
import time
from urllib.parse import urljoin

from http_board import Browser, Page, admin_url, login


def source_modules(source, target):
    # Junior Admin also supports operators who renamed the ACP directory.
    helper = "includes/functions_jr_admin.php"
    (target / helper).write_text((source / helper).read_text(encoding="utf-8").replace("'admin/'", "'staff/'", 1), encoding="utf-8")
    directory = target / "staff"
    directory.mkdir(exist_ok=True)
    for file in (source / "admin").glob("admin_*.php"):
        shutil.copyfile(file, directory / file.name)
    with (directory / "admin_disallow.php").open("ab") as changed:
        changed.write(b"\n/* Locally customised controller */\n")


def verify_report(php, web):
    output = php("bin/phpbbcli.php", "phpbb2plus:delegation:report", cwd=web, capture=True)
    records = {entry["source_user"]: entry for entry in map(json.loads, output.splitlines())}
    if records[1888]["account"] != "missing-account" or "Private source note" in output:
        raise RuntimeError("The operator report lost source account status or exposed private notes")


def browse(base):
    password = os.environ["PLUS_TEST_MODERN_PASSWORD"]
    junior = login(base, "JuniorFixture", password)
    entry = admin_url(junior)
    action, fields = Page(junior.fetch(entry)).forms["login"]
    fields.update(login="1", username="JuniorFixture")
    fields["password_" + fields["credential"]] = password
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    junior.fetch(urljoin(entry, action), fields)
    entry = admin_url(junior)
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
