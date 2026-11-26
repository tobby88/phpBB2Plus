"""Exercise the converted renewal policy through native login, recovery and account settings."""
import os
import re
import secrets
from html import unescape

from download_mail import mailbox
import password_rules
from http_board import Browser, FormFields, Page, admin_url, login, submit_form
from warning_cards import check_restricted_login


def exercise(base, owner, fixture, restrictions):
    password_rules.exercise(base, owner, fixture)
    with mailbox() as sink:
        check_board(base, owner, fixture, restrictions, sink)


def check_board(base, owner, fixture, restrictions, sink):
    password = os.environ["PLUS_TEST_MODERN_PASSWORD"]
    settings = admin_url(owner) + "&i=acp_board&mode="
    field = "config[chg_passforce]"
    marker = 'id="plus-password-renewal"'

    def interval(mode, value=None, enabled=None):
        url = settings + mode
        page = owner.fetch(url)
        if value is not None:
            changes = {"submit": "1", field: str(value)}
            if enabled is not None:
                changes["config[plus_pw_age_policy]"] = str(enabled)
            submit_form(owner, url, page, "acp_board", changes)
            page = owner.fetch(url)
        return FormFields(page, "acp_board").values.get(field)

    def attempt(remember=False):
        actor = Browser(base)
        path = "ucp.php?mode=login"
        posted = submit_form(actor, path, actor.fetch(path), "login",
                             {"login": "1", "username": "RenewalMember", "password": password,
                              "autologin": "on" if remember else None})
        return actor, posted

    def refused(recovery_enabled=True):
        actor, page = attempt()
        if not re.search(r"Passwort ist abgelaufen|password has expired", page) or "mode=logout" in actor.fetch("index.php"):
            raise RuntimeError("An expired password did not return the native recovery path as a guest")
        recovery = re.search(r'href="([^"]*(?:forgot_password|mode=sendpassword)[^"]*)"', page)
        if not recovery_enabled:
            if recovery or not re.search(r"wende dich an die Administration|contact the board administrator", page):
                raise RuntimeError("Disabled self-service recovery did not direct the member to an administrator")
        elif not recovery:
            raise RuntimeError("The expired member could not open the native password recovery form")
        else:
            path = unescape(recovery[1])
            form = actor.fetch(path)
            if "reset_password" not in Page(form).forms:
                raise RuntimeError("The recovery link did not open the native reset form")
            return actor, path, form

    if interval("registration") != "50000" or interval("security") != "50000":
        raise RuntimeError("One native ACP page truncated the original renewal interval")
    try:
        if interval("registration", 1200) != "1200" or interval("security", 7) != "7":
            raise RuntimeError("One native ACP page did not save the converted renewal setting")
        fixture("restricted")
        check_restricted_login(base, "ImportedFounderFixture", password)
        fixture("restricted")
        restrictions("cards-exempt")
        try:
            released = login(base, "ImportedFounderFixture", password)
            if "new_password" not in released.fetch("change_password.php"):
                raise RuntimeError("A reinstated founder could not renew a password of unknown age")
            fixture("released")
        finally:
            restrictions("cards-unexempt")
        fixture("rehash-marked")
        refused(False)
        fixture("check-rehashed-marker")
        fixture("expired")
        refused(False)
        fixture("mail-on:" + str(sink.server_address[1]))
        guest, path, form = refused()
        submit_form(guest, path, form, "reset_password", {"submit": "1", "email": "renewal@example.invalid"})
        if len(sink.mail) != 1 or "renewal@example.invalid" not in str(sink.mail[0]["To"]):
            raise RuntimeError("The native reset email did not arrive for the expired fixture account")
        reset = re.search(r"https?://[^\s<>]+/reset_password\?[^\s<>]+", sink.mail[0].get_body().get_content())
        if not reset:
            raise RuntimeError("The synthetic mailbox did not receive the native password reset link")
        link = unescape(reset[0])
        form = guest.fetch(link)
        password_rules.has_hint(form)
        refused_reset = submit_form(guest, link, form, "reset_password",
                                    {"submit": "1", "new_password": "RenewalMember", "new_password_confirm": "RenewalMember"})
        password_rules.rejected(refused_reset, "name")
        replacement = "R2-" + secrets.token_hex(14)
        submit_form(guest, link, refused_reset, "reset_password",
                    {"submit": "1", "new_password": replacement, "new_password_confirm": replacement})
        password = replacement
        _, page = attempt()
        if "mode=logout" not in page:
            raise RuntimeError("A member who completed native email recovery could not sign in")
        fixture("sentinel")
        refused()

        fixture("unknown")
        first, first_page = attempt()
        fixture("consumed")
        if "mode=logout" not in first_page or "new_password" not in first_page:
            raise RuntimeError("An unknown password date did not allow one login to native account settings")
        refused()
        native = "change_password.php"
        replacement = "A1-" + secrets.token_hex(14)
        submit_form(first, native, first.fetch(native), "ucp", {"submit": "1", "cur_password": password,
                    "new_password": replacement, "password_confirm": replacement})
        password = replacement
        _, page = attempt()
        if "mode=logout" not in page or marker in page:
            raise RuntimeError("A native password change did not start a new renewal interval")

        fixture("two-days")
        _, page = attempt()
        if "mode=logout" not in page or marker in page:
            raise RuntimeError("A short password interval warned too early")
        fixture("last-day")
        remembered, page = attempt(remember=True)
        if marker not in page or "expires in one day" not in page or marker in remembered.fetch("index.php"):
            raise RuntimeError("The native session did not show the last-day notice exactly once")
        fixture("expired")
        fixture("expire-session")
        if "mode=logout" in remembered.fetch("index.php") or any(c.name.endswith("_k") and c.value for c in remembered.cookies):
            raise RuntimeError("The remembered session retained an expired password or its key")
        fixture("fresh")
        remembered, _ = attempt(remember=True)
        fixture("unknown")
        fixture("expire-session")
        if "mode=logout" in remembered.fetch("index.php"):
            raise RuntimeError("A remember-me cookie consumed the first explicit login for an unknown date")
        if interval("registration", 30) != "30":
            raise RuntimeError("Could not select the longer notice window")
        fixture("before-window")
        if marker in attempt()[1]:
            raise RuntimeError("A long interval warned more than fourteen days in advance")
        fixture("long-window")
        if "expires in 14 days" not in attempt()[1]:
            raise RuntimeError("The fourteen-day renewal notice did not appear at its boundary")
        fixture("sentinel")
        if interval("registration", 7, enabled=0) != "7" or "mode=logout" not in attempt()[1]:
            raise RuntimeError("Selecting native renewal did not allow the signed-in account to change its password")
        if interval("registration", 7, enabled=1) != "7":
            raise RuntimeError("Could not enable the Plus login policy on an existing board")
        refused()
        if interval("security", 0) != "0" or "mode=logout" not in attempt()[1]:
            raise RuntimeError("Disabling the renewal interval did not restore ordinary login")
    finally:
        fixture("mail-off")
        if interval("registration", 50000) != "50000":
            raise RuntimeError("Could not restore the original five-digit board interval")
