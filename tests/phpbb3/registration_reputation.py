"""Run native signup with a local transport fixture and the two real registration settings."""
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from secrets import token_hex
from urllib.parse import urlsplit

from http_board import Browser, FormFields, Page, admin_url, submit_form


def browse(base, admin, web, target, php):
    if urlsplit(base).hostname != "127.0.0.1" or not target.startswith("plus3_test_"):
        raise RuntimeError("Use a disposable loopback board")
    tests = Path(__file__).parent
    ext = web / "ext/phpbb2plus/community"
    definitions = ext / "config/services.yml"
    client = ext / "service/fixture_reputation_http.php"
    calls = web / "store/plus-test-reputation-calls"
    original = definitions.read_bytes()
    definition = b"class: GuzzleHttp\\Client"
    if client.exists() or calls.exists() or original.count(definition) != 1:
        raise RuntimeError("Unexpected registration transport fixture")
    path = "ucp.php?mode=register"
    settings = admin_url(admin) + "&i=acp_board&mode=registration"
    enable, closed = "config[plus_sfs_enable]", "config[plus_sfs_fail_closed]"
    password = "Az!95" + token_hex(10)
    armed = False

    def save(on, strict):
        page = admin.fetch(settings)
        fields = FormFields(page, "acp_board").values
        if enable not in fields or closed not in fields:
            raise RuntimeError("Native registration settings lack their two reputation options")
        submit_form(admin, settings, page, "acp_board", {enable: str(on), closed: str(strict), "submit": "1"})

    def prepare(enabled):
        guest = Browser(base)
        form = submit_form(guest, path, guest.fetch(path), "agreement", {"agreed": "1"})
        if ("Stop Forum Spam" in form) != enabled:
            raise RuntimeError("The applicant did not see the active data-sharing notice")
        return guest, form

    def register(prepared, name, email, expected_calls, blocked, mismatch=False):
        guest, form = prepared
        calls.write_bytes(b"")
        page = submit_form(guest, path, form, "register", {"username": name, "email": email, "new_password": password,
                           "password_confirm": password + ("x" if mismatch else ""), "submit": "1"})
        if ("register" in Page(page).forms) != blocked or calls.read_text().splitlines() != expected_calls:
            raise RuntimeError("Unexpected signup outcome or reputation query sequence")

    try:
        client.write_bytes((tests / "registration_reputation_transport.php").read_bytes())
        calls.touch()
        definitions.write_bytes(original.replace(definition, b"class: phpbb2plus\\community\\service\\fixture_reputation_http"))
        php("bin/phpbbcli.php", "cache:purge", cwd=web)
        php(tests / "registration_reputation.php", target, "setup", cwd=web)
        armed = True
        save(1, 1)
        # Prepare independent guest sessions together. Account writes and call assertions stay in order.
        with ThreadPoolExecutor(max_workers=4) as workers:
            forms = list(workers.map(prepare, [True] * 7))
        register(forms[0], "SfsTestLocal", "local@example.invalid", [], True, mismatch=True)
        register(forms[1], "SfsTestListed", "listed-user@example.invalid", ["ip", "email"], True)
        register(forms[2], "SfsTestListed", "plain@example.invalid", ["ip", "email", "username"], True)
        register(forms[3], "SfsTestDown", "invalid-reply@example.invalid", ["ip", "email"], True)
        register(forms[4], "SfsTestDown", "unavailable-site@example.invalid", ["ip", "email"], True)
        register(forms[5], "SfsTestClean", "clean@example.invalid", ["ip", "email", "username"], False)
        save(1, 0)
        register(forms[6], "SfsTestDown", "unavailable-site@example.invalid", ["ip", "email"], False)
        save(0, 0)
        register(prepare(False), "SfsTestOff", "listed-unused@example.invalid", [], False)
    finally:
        try:
            if armed:
                php(tests / "registration_reputation.php", target, "restore", cwd=web)
        finally:
            definitions.write_bytes(original)
            client.unlink(missing_ok=True)
            calls.unlink(missing_ok=True)
            php("bin/phpbbcli.php", "cache:purge", cwd=web)
