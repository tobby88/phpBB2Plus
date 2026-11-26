"""Use the actual registration, personal account and administrator forms on a converted board."""
import re
import secrets
import string
from hashlib import sha256
from os import environ

from http_board import Browser, FormFields, admin_url, login, submit_form


def rejected(page, reason):
    errors = re.findall(r'<(dd|p|div)\s+class="(?:error|errorbox)"[^>]*>(.*?)</\1>', page, re.S)
    phrases = {
        "name": ("must differ from the account name", "muss sich vom Kontonamen unterscheiden"),
        "digits": ("one letter and one decimal digit", "einen Buchstaben und eine Dezimalziffer"),
        "bytes": ("outside the allowed length", "außerhalb der zulässigen Länge"),
    }
    if not any(phrase in body for _, body in errors for phrase in phrases[reason]):
        raise RuntimeError("The native form did not report the expected Plus rule for " + reason)


def has_hint(page):
    if not re.search(r'(?:6 to 72 UTF-8 bytes|6 bis 72 UTF-8-Bytes)', page):
        raise RuntimeError("The native password form did not display the converted byte limit")


def exercise(base, owner, fixture):
    settings = admin_url(owner) + "&i=acp_board&mode=registration"
    page = owner.fetch(settings)
    fields = FormFields(page, "acp_board").values
    expected = {"plus_pw_rules": "1", "plus_pw_min_bytes": "6", "plus_pw_no_name": "1",
                "plus_pw_letters_digits": "0", "min_pass_chars": "1", "pass_complex": "PASS_TYPE_ANY"}
    if any(fields.get("config[" + key + "]") != value for key, value in expected.items()):
        raise RuntimeError("The native ACP did not display the original account password settings")
    key = "config[plus_pw_letters_digits]"
    submit_form(owner, settings, page, "acp_board", {"submit": "1", key: "1"})
    try:
        fixture("registration-open")
        fixture("cli-rules")
        cli_password = " Cli4&" + sha256(environ["PLUS_TEST_MODERN_PASSWORD"].encode()).hexdigest()[:18] + " "
        login(base, "CliPolicy9", cli_password)
        guest = Browser(base)
        path = "ucp.php?mode=register"
        form = submit_form(guest, path, guest.fetch(path), "agreement", {"agreed": "1"})
        has_hint(form)
        name = "PolicyMember8"
        registered = " 界界٣ "
        for candidate, reason in ((name, "name"), ("letters²", "digits"), ("界٣", "bytes"),
                                  ("A1" + "\\" * 36, "bytes"), ("e\u0301" * 24 + "1", "bytes"), (registered, None)):
            form = submit_form(guest, path, form, "register", {"submit": "1", "username": name,
                               "new_password": candidate, "password_confirm": candidate, "email": "policy8@example.invalid"})
            if reason:
                rejected(form, reason)
        account = login(base, name, registered)
        personal = "ucp.php?i=ucp_profile&mode=reg_details"
        form = account.fetch(personal)
        has_hint(form)
        # 72 historical bytes, including an ampersand and one double-counted quote.
        replacement = " A1&" + "x" * 65 + "' "
        for candidate, reason in ((name, "name"), (replacement + "o", "bytes"), (replacement, None)):
            form = submit_form(account, personal, form, "ucp", {"submit": "1", "cur_password": registered,
                               "new_password": candidate, "password_confirm": candidate})
            if reason:
                rejected(form, reason)
        login(base, name, replacement)
        manage = admin_url(owner) + "&i=acp_users&mode=overview&username=" + name
        form = owner.fetch(manage)
        has_hint(form)
        replacement = "A4-" + secrets.token_hex(12) + "  "
        for candidate, reason in ((name, "name"), (replacement, None)):
            form = submit_form(owner, manage, form, "user_overview", {"update": "1", "new_password": candidate,
                               "password_confirm": candidate})
            if reason:
                rejected(form, reason)
        login(base, name, replacement)
        check_existing(base, owner, name, manage, settings)
    finally:
        fixture("registration-restore")
        submit_form(owner, settings, owner.fetch(settings), "acp_board", {"submit": "1", key: "0",
                    "config[chg_passforce]": fields["config[chg_passforce]"], "config[pass_complex]": fields["config[pass_complex]"]})


def check_existing(base, owner, name, manage, settings):
    def configure(digits, days, complexity="PASS_TYPE_ANY"):
        submit_form(owner, settings, owner.fetch(settings), "acp_board", {"submit": "1",
                    "config[plus_pw_letters_digits]": str(digits), "config[chg_passforce]": str(days),
                    "config[pass_complex]": complexity})

    def change(candidate):
        return submit_form(owner, manage, owner.fetch(manage), "user_overview",
                           {"update": "1", "new_password": candidate, "password_confirm": candidate})

    def enter(candidate):
        actor = Browser(base)
        path = "ucp.php?mode=login"
        page = submit_form(actor, path, actor.fetch(path), "login", {"login": "1", "username": name, "password": candidate})
        return actor, page

    configure(0, 0, "PASS_TYPE_ALPHA")
    if 'class="errorbox"' not in change("界界٣"):
        raise RuntimeError("Enabling a stricter native complexity rule no longer affects new passwords")
    configure(0, 0)
    typo = submit_form(owner, manage, owner.fetch(manage), "user_overview",
                       {"update": "1", "new_password": " 123456", "password_confirm": "123456"})
    if 'class="errorbox"' not in typo:
        raise RuntimeError("The account form accepted a confirmation with a missing leading space")
    weak = "".join(secrets.choice(string.ascii_lowercase) for _ in range(20))
    change(weak)
    configure(1, 0)
    for _ in range(2):
        actor, page = enter(weak)
        if "mode=logout" not in page or 'id="plus-password-renewal"' not in page or 'id="plus-password-renewal"' in actor.fetch("index.php"):
            raise RuntimeError("With expiry off a verified weak credential must keep working and prompt once per login")
    configure(1, 7)
    _, page = enter(weak)
    if "mode=logout" in page or not re.search(r"password has expired|Passwort ist abgelaufen", page):
        raise RuntimeError("The previous strength prompt was lost when the administrator enabled expiry")
    replacement = "B7-" + secrets.token_hex(12)
    personal = "ucp.php?i=ucp_profile&mode=reg_details"
    submit_form(actor, personal, actor.fetch(personal), "ucp", {"submit": "1", "cur_password": weak,
                "new_password": replacement, "password_confirm": replacement})
    _, page = enter(replacement)
    if "mode=logout" not in page or 'id="plus-password-renewal"' in page:
        raise RuntimeError("The old strength prompt survived setting a qualifying new password")
