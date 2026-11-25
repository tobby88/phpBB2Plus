"""Check the eight personal-album group decisions against both old and new collections."""
import os
import re
from html import unescape
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url, login, submit_form


def browse(admin, member, junior, guest, check):
    actions = ("view", "upload", "rate", "comment", "edit", "delete", "moderator")
    form, confirmation = "album-personal-grants", "album-personal-grants-confirm"
    check("personal_rules_start")
    creator = login(guest.base, "ModerationFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    start = "app.php/album/member/89/0/start"
    creator.fetch(start, expected_status=404)

    def module(actor, full):
        acp = admin_url(actor)
        menu = actor.fetch(acp + ("&i=acp_board&mode=settings" if full else ""))
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Gruppenrechte für persönliche Fotoalben</span>', menu)
        if not link or (not full and re.search(r'<span>(Gruppenrechte für Fotokategorien|Fotokategorien|Fotoalbum-Uploads|Persönliche Fotoalben|Anzeige im Fotoalbum)</span>', menu)):
            raise RuntimeError("Native gallery ACP roles did not remain independent")
        return urljoin(acp, unescape(link[1]))

    def preview(actor, url, changes, scope="future", page=None):
        return submit_form(actor, url, page or actor.fetch(url), form,
                           {"scope": scope, **{f"change[{name}]": value for name, value in changes.items()}})

    def commit(actor, url, changes, scope="future"):
        shown = preview(actor, url, changes, scope)
        if confirmation not in Page(shown).forms:
            raise RuntimeError("The personal group change did not offer its confirmation")
        return submit_form(actor, url, shown, confirmation, {})

    full = module(admin, True)
    group = full + "&group=71"
    if "Photo Club</a>" not in admin.fetch(full) or "Single HistoricMember" in admin.fetch(full):
        raise RuntimeError("The personal album ACP did not list the converted named groups")
    check("personal_rules_never")
    denied = admin.fetch(full + "&group=75")
    if "verbieten" not in denied or FormFields(denied, form).values["change[create]"] != "keep":
        raise RuntimeError("The personal ACP did not preserve an explicit native NEVER")
    check("personal_rules_allow")
    check("personal_rules_delegate")
    acp = admin_url(junior)
    reauth = junior.fetch(acp)
    if "login" in Page(reauth).forms:
        key = Page(reauth).forms["login"][1]["credential"]
        submit_form(junior, acp, reauth, "login", {"login": "1", "username": "DepartingFixture",
                    "password_" + key: os.environ["PLUS_TEST_MODERN_PASSWORD"]})
    own = module(junior, False) + "&group=75"
    def totals(page, action):
        marker = re.search(rf'data-permission="{action}" data-assigned="(\d+)" data-categories="(\d+)"', page)
        if not marker:
            raise RuntimeError("Missing existing-category permission counts")
        return tuple(map(int, marker.groups()))
    assigned, total = totals(junior.fetch(own), "delete")
    if assigned or not total:
        raise RuntimeError("The isolated group is not initially free of personal gallery deletion grants")
    applied = commit(junior, own, {"delete": "grant"}, "existing")
    if totals(applied, "delete") != (total, total):
        raise RuntimeError("The existing-only update did not reach all eligible personal categories")
    check("personal_rules_existing_only")
    if totals(commit(junior, own, {"delete": "remove"}, "existing"), "delete") != (0, total):
        raise RuntimeError("The existing-only update could not restore the untouched group")
    grant = preview(junior, own, {"create": "grant"})
    creator.fetch(start, expected_status=404)
    submit_form(junior, own, grant, confirmation, {})
    initial = creator.fetch(start)
    if "album-personal" not in Page(initial).forms:
        raise RuntimeError("The native create grant did not reach an approved member of the group")
    check("personal_creator_pending")
    creator.fetch(start, expected_status=404)
    submit_form(creator, start, initial, "album-personal", {}, 404)
    check("personal_creator_approved")
    initial = creator.fetch(start)
    commit(admin, group, {name: "grant" for name in actions})
    check("personal_rules_future")
    stale = preview(admin, group, {"view": "grant"}, "both")
    directory = submit_form(creator, start, initial, "album-personal", {})
    root = re.search(r'/album/member/89/(\d+)/edit', directory)[1]
    create_child = f"app.php/album/member/89/{root}/add"
    directory = submit_form(creator, create_child, creator.fetch(create_child), "album-personal",
                            {"title": "Group defaults child", "view": "3", "upload": "3", "comment": "3", "rate": "3"})
    child = re.search(r'/album/category/(\d+)[^"]*">Group defaults child</a>', directory)[1]
    for cat in (root, child):
        path = "app.php/album/category/" + cat
        member.fetch(path)
        junior.fetch(path, expected_status=404)  # Group 71 membership is pending.
        guest.fetch(path, expected_status=404)
    if "inzwischen geändert" not in submit_form(admin, group, stale, confirmation, {}):
        raise RuntimeError("A changed album count did not invalidate the bulk confirmation")
    existing = commit(admin, group, {"view": "grant", "rate": "remove"}, "both")
    if form not in Page(existing).forms:
        raise RuntimeError("Confirmed personal album changes did not return to the group's edit form")
    check("personal_rules_existing")
    revoked = preview(junior, own, {"create": "remove"})
    check("personal_rules_revoke")
    submit_form(junior, own, revoked, confirmation, {}, 403)
    commit(admin, full + "&group=75", {"create": "remove"})
    check("personal_rules_finished")
