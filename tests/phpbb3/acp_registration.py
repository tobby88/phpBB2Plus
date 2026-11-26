"""Create ordinary accounts through the native ACP session and form on the disposable board."""
from concurrent.futures import ThreadPoolExecutor
from html import unescape
import secrets
from urllib.parse import urljoin

from delegation import authenticate
from http_board import FormFields, Page, admin_url, login, submit_form


def browse(administrator, check):
    form = "plus-account-register"
    module = "&i=-phpbb2plus-community-acp-registration_module&mode=create"
    check("prepare")
    founder_url = admin_url(administrator) + module
    founder_page = administrator.fetch(founder_url)
    if "Benutzerkonto anlegen" not in founder_page or "REGISTRATION_DETAILS" in founder_page:
        raise RuntimeError("Missing localized ACP account creation form")
    junior, entry = authenticate(administrator.base, "RegistrationJunior")
    url = entry + module
    page = junior.fetch(url)
    if "Create member account" not in page or "No email is sent." not in page:
        raise RuntimeError("The converted delegate could not see the dedicated form")
    if "Module not accessible" not in junior.fetch(entry + "&i=acp_users&mode=overview", expected_status=503):
        raise RuntimeError("The historical module unexpectedly opened general member administration")
    values = FormFields(page, form).values
    if not {"username", "email", "lang", "style", "tz", "dateformat", "new_password", "password_confirm", "pf_phpbb_location"} <= set(values):
        raise RuntimeError("Missing account, preference or custom registration fields")
    if {"group_id", "user_type", "user_permissions"} & set(values):
        raise RuntimeError("The create-only account form exposed role or group assignment")
    password = " A9&Ü-" + secrets.token_hex(8) + " "
    good = dict(username="RegistrationMember", email="registration-member@example.invalid", new_password=password, password_confirm=password,
                lang="de", style=values["style"], tz="Europe/Berlin", dateformat="Y-m-d H:i", pf_phpbb_location="Düsseldorf")
    rejected = submit_form(junior, url, page, form, dict(good, new_password="onlyletters", password_confirm="different", pf_phpbb_location=""))
    returned = FormFields(rejected, form).values
    if ("at least one letter" not in rejected or "do not match" not in rejected
            or returned.get("new_password") != "" or returned.get("password_confirm") != ""):
        raise RuntimeError("The password policy, confirmation or empty password fields changed")
    missing = submit_form(junior, url, rejected, form, dict(good, pf_phpbb_location=""))
    if form not in Page(missing).forms or "errorbox" not in missing or password in unescape(missing):
        raise RuntimeError("A required registration field was bypassed or a password redisplayed")
    created = submit_form(junior, url, missing, form, good)
    if "has been created" not in created or password in unescape(created):
        raise RuntimeError("The standard account was not created through its delegated ACP form")
    check("created")
    login(junior.base, good["username"], password)
    duplicate = submit_form(junior, url, junior.fetch(url), form, good)
    if "username you entered is already in use" not in duplicate or "email address is already in use" not in duplicate:
        raise RuntimeError("Native duplicate-name and email rules were not enforced")
    check("reuse")
    reused = submit_form(junior, url, duplicate, form, dict(good, username="RegistrationReused"))
    if "has been created" not in reused:
        raise RuntimeError("Native email reuse was ignored")
    old_form = junior.fetch(url)
    check("revoke")
    # With its only delegated module withdrawn phpBB may reject at the ACP front controller.
    action, _ = Page(old_form).forms[form]
    fields = FormFields(old_form, form).values
    fields.update(good, username="RegistrationDenied", email="registration-denied@example.invalid")
    target = urljoin(url, action)
    try:
        denied = junior.fetch(target, fields, expected_status=403)
    except RuntimeError as exc:
        if "got 503" not in str(exc) and "HTTP 503" not in str(exc):
            raise
        denied = junior.fetch(target, fields, expected_status=503)
    if "has been created" in denied:
        raise RuntimeError("A withdrawn grant could still add an account")
    with ThreadPoolExecutor(max_workers=2) as workers:
        results = sorted(result.strip() for result in workers.map(check, ("worker-left", "worker-right")))
    if results != ["created", "email already in use"]:
        raise RuntimeError("Concurrent registrations did not resolve to exactly one account")
    check("interrupted")
    check("finish")
