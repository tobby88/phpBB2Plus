"""Use the native Arcade control panel and propose a tournament as a converted member."""
from html import unescape
import re
import time
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url


def form(actor, url, page, name, changes, status=200):
    action, _ = Page(page).forms[name]
    fields = FormFields(page, name).values
    for key, value in changes.items():
        if value is None:
            fields.pop(key, None)
        else:
            fields[key] = value
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    return actor.fetch(urljoin(urljoin(actor.base, url), action), fields, expected_status=status)


def exercise(owner, member, verify):
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_board&mode=settings")
    links = re.findall(r'<a href="([^"]+)"[^>]*>\s*<span>Spielhallen-Einstellungen</span>', menu)
    if len(links) != 1:
        raise RuntimeError("Missing or duplicate Arcade configuration in the native ACP")
    panel = urljoin(entry, unescape(links[0]))
    section = "arc-settings-tournaments"
    page = owner.fetch(panel)
    if {key for key in Page(page).forms if key.startswith("arc-settings-")} != {
            "arc-settings-access", "arc-settings-catalogue", "arc-settings-community", section}:
        raise RuntimeError("The Arcade control panel lost a settings section")
    original = FormFields(page, section).values
    directory = "app.php/arcade/tournaments"
    proposal = directory + "/propose"
    first = member.fetch(proposal)
    if "/arcade/tournaments/propose" not in member.fetch(directory):
        raise RuntimeError("An eligible player could not find the tournament proposal form")
    values = dict(name="Früh &amp; Spät", description="Farben & Formen im März", players="3", turns="2", reserve="1")

    form(owner, panel, page, section, {"games_tournament_user": None})
    if "/arcade/tournaments/propose" in member.fetch(directory):
        raise RuntimeError("Disabling proposals in the ACP left the proposal link active")
    form(member, proposal, first, "arc-proposal", values, 404)
    conflict = form(owner, panel, page, section, {"games_tournament_max": "4"})
    if "zwischenzeitlich geändert" not in conflict or "games_tournament_user" in FormFields(conflict, section).values:
        raise RuntimeError("A stale settings form overwrote a newer change")
    form(owner, panel, conflict, section, {"games_tournament_user": "1", "games_tournament_max": "4", "games_tournament_players": "4"})
    first, other = member.fetch(proposal), member.fetch(proposal)
    invalid = form(member, proposal, first, "arc-proposal", dict(values, players="5"), 422)
    if FormFields(invalid, "arc-proposal").values["name"] != values["name"]:
        raise RuntimeError("An invalid proposal lost the player's input")
    for _ in range(2):
        detail = form(member, proposal, first, "arc-proposal", values)
        if ("<h2>Früh &amp;amp; Spät</h2>" not in detail or "Farben &amp; Formen im März" not in detail
                or "Awaiting approval" not in detail or "HistoricMember" not in detail or "arcade-join-tour" in Page(detail).forms):
            raise RuntimeError("A proposed tournament lost its text, waiting state or founder")
    exhausted = form(member, proposal, other, "arc-proposal", values, 422)
    if "limit for unfinished tournaments" not in exhausted or "/arcade/tournaments/propose" in member.fetch(directory):
        raise RuntimeError("A pending proposal did not count towards the live tournament limit")
    member.fetch(proposal, expected_status=409)
    changed = form(member, proposal, first, "arc-proposal", dict(values, name="Other event"), 422)
    if "already been submitted" not in changed:
        raise RuntimeError("A used submission accepted a second event")
    verify()
    form(owner, panel, owner.fetch(panel), section, {key: original[key] for key in ("games_tournament_max", "games_tournament_players")})
