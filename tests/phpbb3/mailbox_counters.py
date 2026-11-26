"""Check current, stale and empty mailboxes using the native ACP on a converted board."""
from delegation import authenticate
from http_board import FormFields, Page, admin_url, submit_form


def browse(base, admin, fixture):
    suffix = "&i=-phpbb2plus-community-acp-mailbox_module&mode=counts"
    form = "mailbox-44"
    panel = admin_url(admin) + suffix
    delegate, entrance = authenticate(base, "ArcadeCategoryJunior")
    denied = delegate.fetch(entrance + suffix, expected_status=503)
    if form in Page(denied).forms:
        raise RuntimeError("A category-only administrator could review mailbox counters")
    fixture("seed")
    try:
        page = admin.fetch(panel)
        if "Zähler für private Nachrichten" not in page or not FormFields(page, form).values.get("revision"):
            raise RuntimeError("The ACP did not present the converted account's mismatched counters")
        fixture("read")
        response = submit_form(admin, panel, page, form, {"repair": "44"})
        if "hat sich geändert" not in response:
            raise RuntimeError("A stale count preview was accepted")
        fixture("stale")
        response = submit_form(admin, panel, response, form, {"repair": "44"})
        if form in Page(response).forms or "Konto 44 wurden aktualisiert" not in response:
            raise RuntimeError("The native ACP did not acknowledge the current counters")
        fixture("correct")
        fixture("remove-mappings")
        submit_form(admin, panel, admin.fetch(panel), form, {"repair": "44"})
        fixture("empty")
    finally:
        fixture("clean")
