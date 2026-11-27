"""Check the converted registration challenge and activation policy on the native public and ACP forms."""
from html import escape, unescape
import re

from http_board import Browser, FormFields, Page, admin_url, submit_form


def browse(admin, check):
    guest = Browser(admin.base)
    signup = "ucp.php?mode=register"
    captcha_acp = admin_url(admin) + "&i=acp_captcha&mode=visual"
    account_acp = admin_url(admin) + "&i=acp_board&mode=registration"

    def activation():
        return FormFields(admin.fetch(account_acp), "acp_board").values["config[require_activation]"]

    def challenge():
        return FormFields(admin.fetch(captcha_acp), "acp_captcha").values["enable_confirm"]

    def public_form():
        return submit_form(guest, signup, guest.fetch(signup), "agreement", {"agreed": "1"})

    def closed_message(language=None):
        page = guest.fetch(signup + ("&language=" + language if language else ""))
        message = re.search(r'id="message".*?<p>(.*?)</p>', page, re.S)
        if not message or "agreement" in Page(page).forms:
            raise RuntimeError("A closed source board accepted registration")
        return message.group(1).strip()

    check("setup")
    try:
        original = "Führung & Bau <2027> " * 9 + "🎓"
        field = "config[plus_registration_notice]"
        check("notice-source")
        for ordinary, alternative, mode in ((0, 0, 0), (0, 1, 1), (1, 0, 2), (1, 1, 0)):
            check(f"policy-{ordinary}{alternative}-0{mode}")
            enabled = ordinary or alternative
            if challenge() != str(enabled) or activation() != str(mode):
                raise RuntimeError("The native ACP did not retain both original registration settings")
            fields = FormFields(public_form(), "register").values
            if ("confirm_code" in fields) != bool(enabled):
                raise RuntimeError("The native sign-up form displays the wrong registration challenge")
        for mode in (0, 1, 2, 3):
            check(f"policy-01-1{mode}")
            if activation() != "3" or closed_message() != escape(original, quote=False):
                raise RuntimeError("The closed board did not show the full original reason as plain text")
        check("policy-xx-xx")
        if activation() != "3" or challenge() != "1":
            raise RuntimeError("Unavailable historical settings replaced a native administrator's choice")
        form = admin.fetch(account_acp)
        if FormFields(form, "acp_board").values[field] != original:
            raise RuntimeError("The original closure message did not reach the native ACP")
        revised = "Sommer & Winter <im Haus> 🎓"
        rejected = submit_form(admin, account_acp, form, "acp_board",
                               {"submit": "1", field: revised, "config[max_name_chars]": "7"})
        if FormFields(rejected, "acp_board").action is None or closed_message() != escape(original, quote=False):
            raise RuntimeError("A rejected ACP settings form replaced the saved message")
        submit_form(admin, account_acp, admin.fetch(account_acp), "acp_board", {"submit": "1", field: revised})
        check("notice-resume")
        if closed_message() != escape(revised, quote=False):
            raise RuntimeError("Saving or resuming conversion lost the ACP's revised closure message")
        submit_form(admin, account_acp, admin.fetch(account_acp), "acp_board", {"submit": "1", field: ""})
        check("notice-resume")
        if (unescape(closed_message("de")) != "Eine Registrierung ist momentan nicht möglich." or
                unescape(closed_message("en")) != "Creating a new account is currently not possible."):
            raise RuntimeError("An empty reason did not restore the native translated registration message")
        submit_form(admin, captcha_acp, admin.fetch(captcha_acp), "acp_captcha",
                    {"main_submit": "1", "enable_confirm": "0"})
        submit_form(admin, account_acp, admin.fetch(account_acp), "acp_board",
                    {"submit": "1", "config[require_activation]": "1"})
        check("policy-xx-xx")
        if activation() != "1" or challenge() != "0" or "confirm_code" in FormFields(public_form(), "register").values:
            raise RuntimeError("Source-free updates did not preserve both changes from the native ACP")
    finally:
        check("restore")
