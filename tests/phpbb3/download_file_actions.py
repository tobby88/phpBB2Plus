"""Manage mixed, private and orphaned downloads through native, separately delegated ACP actions."""
from html import unescape
import os
import re
from urllib.parse import urljoin
from http_board import Browser, Page, admin_url, login, submit_form
from knowledgebase import acp_panel


def administration(owner, member, verify):
    verify("installed")
    verify("seed")
    panel = acp_panel(owner, "Download-Dateien verwalten")
    # Start with the actual filter form. The native module must retain the query,
    # category and status across searches and page navigation.
    listed = submit_form(owner, panel, owner.fetch(panel), "dl-files-filter",
                         {"q": "Batch document", "filter": "broken", "category": "21"})
    if "Batch document 10103" not in listed or any(f"Batch document {i}" in listed for i in (10101, 10102, 10104)):
        raise RuntimeError("The reported-broken list mixed statuses or category filters")
    private = owner.fetch(panel + "&filter=pending&category=23&q=10102")
    if "Batch document 10102" not in private or "Batch document 10101" in private:
        raise RuntimeError("The pending list did not find the selected private download")
    first = owner.fetch(panel + "&q=Pagination%20fixture&category=21&filter=approved")
    next_page = re.search(r'<a href="([^"]+&amp;start=25)">', first)
    if not next_page or "Pagination fixture 10225" not in first or "Pagination fixture 10200" in first:
        raise RuntimeError("The downloaded file list did not page in upload order")
    second = owner.fetch(urljoin(panel, unescape(next_page[1])))
    if "Pagination fixture 10200" not in second or "Pagination fixture 10201" in second:
        raise RuntimeError("The filtered second page skipped or repeated downloads")
    if "Alle Kategorien" not in second or "{ STATUS }" in second:
        raise RuntimeError("The German file filter labels did not render")

    delegate = login(owner.base, "DownloadFileDelegate", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    entry = admin_url(delegate)
    form = delegate.fetch(entry)
    challenge = Page(form).forms["login"][1]["credential"]
    signed = submit_form(delegate, entry, form, "login", {"username": "DownloadFileDelegate", "login": "1",
                         "password_" + challenge: os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    tab = re.search(r'<a href="([^"]+)">Extensions</a>', signed)
    if not tab:
        tab = re.search(r'<a href="([^"]+)">Extensions</a>', delegate.fetch(entry))
    if not tab:
        raise RuntimeError("The English native Extensions tab is missing for the limited file administrator")
    menu = delegate.fetch(urljoin(entry, unescape(tab[1])))
    match = re.search(r'<a href="([^"]+)"><span>Manage download files</span>', menu)
    if not match:
        raise RuntimeError("The English download file action panel is missing")
    limited = urljoin(entry, unescape(match[1]))
    guest = Browser(owner.base)
    item = "app.php/downloads/file/"

    def choose(operation, ids):
        listing = delegate.fetch(limited + "&q=Batch%20document")
        view = submit_form(delegate, limited, listing, "dl-files-select",
                           {"operation": operation, "selected[]": list(map(str, ids))})
        return urljoin(limited, Page(view).forms["dl-files-confirm"][0]), view

    def confirm(url, view, yes=True):
        return submit_form(delegate, url, view, "dl-files-confirm", {"confirm": "1" if yes else None})

    before = delegate.fetch(limited)
    if 'value="approve"' not in before or 'value="delete"' in before or "All categories" not in before:
        raise RuntimeError("The approval delegate received mixed ACP controls")
    guest.fetch(item + "10101", expected_status=404)
    delegate.fetch(item + "10102", expected_status=404)
    url, view = choose("approve", (10101, 10102, 10103))
    if "changes 2 of the 3" not in view or any(f"Batch document {i}" not in view for i in (10101, 10102, 10103)):
        raise RuntimeError("The confirmation did not identify the affected mixed selection")
    if "Please confirm" not in confirm(url, view, False):
        raise RuntimeError("Approval without confirmation was accepted")
    if "Files changed: 2" not in confirm(url, view):
        raise RuntimeError("Approval did not publish both pending files")
    verify("approved")
    guest.fetch(item + "10101")
    guest.fetch(item + "10102", expected_status=404)
    delegate.fetch(item + "10102", expected_status=404)
    member.fetch(item + "10102")
    if "contents have changed" not in confirm(url, view):
        raise RuntimeError("An older approval form was accepted twice")
    url, view = choose("withdraw", (10101, 10102))
    confirm(url, view)
    verify("withdrawn")
    guest.fetch(item + "10101", expected_status=404)
    url, view = choose("approve", (10101, 10102))
    verify("switch")
    if "dl-files-confirm" in confirm(url, view):
        raise RuntimeError("A revoked approval could still reach the save form")
    after = delegate.fetch(limited + "&q=Batch%20document")
    if 'value="delete"' not in after or 'value="approve"' in after or 'value="withdraw"' in after:
        raise RuntimeError("The deletion-only delegate retained approval controls")

    url, view = choose("delete", (10101, 10102, 10104))
    if "mirrors (1), comments (1), ratings (1), custom field values (1)" not in view or "Batch document 10104" not in view:
        raise RuntimeError("The deletion preview omitted attached data or a selected orphan")
    verify("new_comment")
    if "contents have changed" not in confirm(url, view):
        raise RuntimeError("Removal ignored a contribution added after confirmation")
    verify("rollback")
    fresh = delegate.fetch(url)
    if "comments (2)" not in fresh or "Files changed: 3" not in confirm(url, fresh):
        raise RuntimeError("Current confirmation did not delete all and only the selected documents")
    verify("finished")
    for i in (10101, 10102, 10104):
        owner.fetch(item + str(i), expected_status=404)
