"""Check the native ACP setting, public notice and acknowledgement from a previous Plus visit."""
from copy import copy

from http_board import Browser, FormFields, admin_url, submit_form


def browse(admin):
    url = admin_url(admin) + "&i=acp_board&mode=cookie"
    page = admin.fetch(url)
    field = "config[cookie_notice]"
    if FormFields(page, "acp_board").values.get(field) != "1":
        raise RuntimeError("The converter did not enable the native cookie notice from the source")
    guest = Browser(admin.base)
    marker = "cookieconsent.initialise("
    if marker not in guest.fetch("index.php"):
        raise RuntimeError("A first-time visitor did not receive the native notice")
    cookie = copy(next(iter(guest.cookies)))
    cookie.name, cookie.value = "cookie_consent", "1"
    guest.cookies.set_cookie(cookie)
    if marker in guest.fetch("index.php"):
        raise RuntimeError("The board asked an existing Plus visitor to acknowledge the same notice again")
    cookie.value = "0"
    guest.cookies.set_cookie(cookie)
    if marker not in guest.fetch("index.php"):
        raise RuntimeError("A cookie with no acknowledgement hid the notice")
    try:
        submit_form(admin, url, page, "acp_board", {"submit": "Submit", field: "0"})
        if marker in guest.fetch("index.php"):
            raise RuntimeError("The native cookie setting did not disable the notice")
    finally:
        submit_form(admin, url, admin.fetch(url), "acp_board", {"submit": "Submit", field: "1"})
