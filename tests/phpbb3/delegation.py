"""Check the converted Junior Admin's own, reauthenticated native ACP session."""
import json
import os
import shutil
import time
from urllib.parse import urljoin

from http_board import Page, admin_url, login


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
    junior.fetch(entry + "&i=acp_board&mode=settings", expected_status=503)
