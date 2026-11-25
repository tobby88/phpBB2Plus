"""Use the native Arcade control panel and propose a tournament as a converted member."""
from html import unescape
import re
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url, submit_form as form


def panel_for(owner, title):
    entry = admin_url(owner)
    menu = owner.fetch(entry + "&i=acp_board&mode=settings")
    links = re.findall(r'<a href="([^"]+)"[^>]*>\s*<span>' + re.escape(title) + r'</span>', menu)
    if len(links) != 1:
        raise RuntimeError("Missing or duplicate Arcade module in the native ACP: " + title)
    return urljoin(entry, unescape(links[0]))


def exercise(owner, member, verify):
    panel = panel_for(owner, "Spielhallen-Einstellungen")
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


def control(owner, member, other, verify):
    panel = panel_for(owner, "Spielhallen-Turniere")
    settings = panel_for(owner, "Spielhallen-Einstellungen")
    section = "arc-settings-tournaments"
    original = FormFields(owner.fetch(settings), section).values
    listing = owner.fetch(panel)

    def link(page, title):
        matches = re.findall(r'<a href="([^"]+)">' + re.escape(title) + '</a>', page)
        if len(matches) != 1:
            raise RuntimeError("Expected one event link for " + title)
        return urljoin(panel, unescape(matches[0]))

    def choices(page):
        return {token.split(":")[0]: token for token in re.findall(r'name="games\[\]" type="checkbox" value="([^"]+)"', page)}

    edit = link(listing, "Früh &amp;amp; Spät")
    event_id = re.search(r'[?&]event=(\d+)', edit)[1]
    public = "app.php/arcade/tournament/" + event_id
    first = owner.fetch(edit)
    if FormFields(first, "arc-event-edit").values["state"] != "0":
        raise RuntimeError("The native ACP did not list the member's proposal for approval")
    selected = choices(first)
    filtered = owner.fetch(edit, {"game_search": "Stoppuhr"})
    if set(choices(filtered)) != {"61"} or not {"60", "62"}.issubset(selected) or "63" in selected:
        raise RuntimeError("The searchable game picker changed available games")
    form(owner, settings, owner.fetch(settings), section, {"games_tournament_games": "1"})
    batch = {"games[]": [selected["60"], selected["62"]]}
    refused = form(owner, edit, first, "arc-event-games", batch)
    if "Höchstzahl der Spiele" not in refused or "60" not in choices(refused):
        raise RuntimeError("An over-capacity batch partially changed the event")
    form(owner, settings, owner.fetch(settings), section, {"games_tournament_games": original["games_tournament_games"]})
    for _ in range(2):
        added = form(owner, edit, first, "arc-event-games", batch)
        if "60" in choices(added) or "62" in choices(added) or "<li>private</li>" not in added:
            raise RuntimeError("Assigning or retrying a game batch changed the wrong list")
    form(owner, edit, first, "arc-event-edit", {"name": "Früh & Spät", "state": "2", "description": "Zwei Zeilen\nmit Grüße"})
    stale = form(owner, edit, first, "arc-event-edit", {"name": "Veraltet"})
    if "zwischenzeitlich geändert" not in stale or FormFields(stale, "arc-event-edit").values["name"] != "Früh & Spät":
        raise RuntimeError("A stale tournament editor replaced the current terms")
    game = member.fetch(public)
    if "arcade-turn-60" not in Page(game).forms or "arcade-turn-62" not in Page(game).forms:
        raise RuntimeError("Opening the tournament did not release its games to the registered founder")
    visible = other.fetch(public)
    if "arcade-join-tour" not in Page(visible).forms or "Vertrauliche Spielprobe" in visible:
        raise RuntimeError("The released event lost its registration or game audience")
    form(other, public, visible, "arcade-join-tour", {})
    form(owner, public, owner.fetch(public), "arcade-join-tour", {})
    full = owner.fetch(edit)
    refused = form(owner, edit, full, "arc-event-edit", {"players": "2"})
    if "bereits angemeldeten" not in refused or "arcade-join-tour" in Page(other.fetch(public)).forms:
        raise RuntimeError("Reducing capacity forgot registered members")
    form(owner, edit, full, "arc-event-edit", {"state": "1"})
    if "arcade-turn-60" in Page(member.fetch(public)).forms:
        raise RuntimeError("A paused tournament still offered playable turns")
    old = owner.fetch(link(listing, "Archivpokal"))
    if "Abgeschlossen" not in old and "Beendet" not in old:
        raise RuntimeError("The ACP no longer exposes completed tournament history")
    if "arc-event-edit" in Page(old).forms:
        raise RuntimeError("A completed championship offered the ordinary edit form")
    new = link(listing, "Turnier anlegen")
    first = owner.fetch(new)
    for _ in range(2):
        form(owner, new, first, "arc-event-edit", {"name": "Herbstpokal", "state": "2"})
    created = link(owner.fetch(panel), "Herbstpokal")
    if "arcade-join-tour" not in Page(owner.fetch("app.php/arcade/tournament/" + re.search(r'[?&]event=(\d+)', created)[1])).forms:
        raise RuntimeError("Creating a tournament as administrator unexpectedly took a player's place")
    verify()
    return new, first


def closure(owner, member, other, original_creation, verify):
    panel = panel_for(owner, "Spielhallen-Turniere")
    listing = owner.fetch(panel)

    def path(label):
        href = re.search(r'<a href="([^"]+)">' + re.escape(label) + r'</a>', listing)
        if not href:
            raise RuntimeError("Missing closure fixture " + label)
        edit = urljoin(panel, unescape(href[1]))
        return edit, "app.php/arcade/tournament/" + re.search(r'[?&]event=(\d+)', edit)[1]

    def action(edit, operation):
        return form(owner, edit, owner.fetch(edit), "arc-event-actions", {"operation": operation})

    def score(actor, public, value):
        play = form(actor, public, actor.fetch(public), "arcade-turn-60", {})
        source = re.search(r'id="plus-arc-player" data-source="([^"]+)"', play)
        if not source:
            raise RuntimeError("The candidate champion could not play a tournament turn")
        actor.fetch(urljoin(urljoin(actor.base, unescape(source[1])), "newscore.php"), {"score": value, "game_name": "puzzle"})

    empty, empty_public = path("Herbstpokal")
    tie, _ = path("Offene Runde")
    if "noch keinen gewerteten Sieger" not in action(empty, "end") or "gleich viele Spiele" not in action(tie, "end"):
        raise RuntimeError("A tied or unscored tournament accepted a champion")
    edit, public = path("Früh &amp; Spät")
    form(owner, edit, owner.fetch(edit), "arc-event-edit", {"state": "2"})
    score(owner, public, "0.05")
    prompt = action(edit, "end")
    if "ModernMember als Sieger" not in prompt:
        raise RuntimeError("The first recorded score failed to produce the leading player")
    score(member, public, "0.125")
    stale = form(owner, edit, prompt, "confirm", {"confirm": "Ja"})
    if "zwischenzeitlich geändert" not in stale:
        error = re.search(r'<div class="(?:errorbox|phpbb_panel)"[^>]*>(.*?)</div>', stale, re.S)
        heading = re.search(r'<h1[^>]*>(.*?)</h1>', stale, re.S)
        labels = [re.sub(r'<[^>]+>', ' ', part[1]).strip()[:180] for part in (heading, error) if part]
        raise RuntimeError("A stale closure did not return the revised event: " + " ".join(labels))
    if "arcade-turn-60" not in Page(member.fetch(public)).forms:
        raise RuntimeError("Rejecting a stale confirmation consumed an extra turn")
    prompt = action(edit, "end")
    if "HistoricMember als Sieger" not in prompt:
        raise RuntimeError("The second confirmation did not name the new leader")
    form(owner, edit, prompt, "confirm", {"confirm": "Ja"})
    archive = member.fetch(public)
    if (not re.search(r'Champion · [^\n]*HistoricMember', archive) or "0.125" not in archive
            or "arcade-turn-60" in Page(archive).forms or "Früh &amp; Spät" not in member.fetch("app.php/arcade/tournaments/champions")):
        raise RuntimeError("Closing the tournament lost its records or still admitted plays")
    verify("notices")
    inbox = member.fetch("ucp.php?i=pm&folder=inbox")
    message = re.search(r'<a href="([^"]+)"[^>]*>Tournament result</a>', inbox)
    if not message:
        raise RuntimeError("The participating member did not receive the native result PM")
    body = member.fetch(urljoin(member.base + "ucp.php", unescape(message[1])))
    if "Früh &amp; Spät" not in body or public.split("app.php", 1)[1] not in body:
        raise RuntimeError("The result PM lost the tournament or its permanent link")
    if ">Turnierergebnis<" in other.fetch("ucp.php?i=pm&folder=inbox"):
        raise RuntimeError("A member who disabled Arcade messages received a result PM")
    prompt = action(edit, "delete")
    form(owner, edit, prompt, "confirm", {"cancel": "Nein"})
    member.fetch(public)
    for target, audience in ((empty, empty_public), (edit, public)):
        prompt = action(target, "delete")
        form(owner, target, prompt, "confirm", {"confirm": "Ja"})
        member.fetch(audience, expected_status=404)
    new, first = original_creation
    repeated = form(owner, new, first, "arc-event-edit", {"name":"Herbstpokal", "state":"2"})
    if "bereits gesendet" not in repeated or "Herbstpokal</a>" in owner.fetch(panel):
        raise RuntimeError("Replaying its creation form recreated a withdrawn tournament")
    verify("cleanup")
