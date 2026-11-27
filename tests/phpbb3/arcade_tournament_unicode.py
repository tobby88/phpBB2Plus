"""Exercise migration-read, new ACP input and a subsequent edit of both tournament text fields."""
from html import unescape
from pathlib import Path

from delegation import authenticate
from http_board import FormFields, submit_form
from unicode_forms import UnicodeControls


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")
    def stage(phase):
        return php(fixture, target, phase, cwd=board, capture=True)
    stage("prepare")
    try:
        owner, entry = authenticate(base, "ModernMember")
        old = unescape(owner.fetch("app.php/arcade/tournament/28"))
        if "Museum 🏺 &#128512;" not in old or "Grüße 𠜎 und &#128512;" not in old:
            raise RuntimeError("A converted tournament's rendered name or description reinterpreted the source")
        acp = entry + "&i=-phpbb2plus-arcade-acp-tournaments_module&mode=manage"
        new = acp + "&new=1"
        name = "🎲" * 25
        description = "Neue Runde 🔭 und &#128512;"
        form = "arc-event-edit"
        page = owner.fetch(new)
        UnicodeControls(page).accepts("event-name", name)
        changes = {"name": name, "description": description, "players": 2, "turns": 1, "state": 2}
        invalid = submit_form(owner, new, page, form, dict(changes, name=name+"a"))
        if 'class="errorbox"' not in invalid or FormFields(invalid, form).values["name"] != name+"a":
            raise RuntimeError("The ACP did not reject and retain a tournament name of 26 characters")
        submit_form(owner, new, page, form, changes)
        ident = stage("new").strip()
        edit = acp + "&event=" + ident
        page = owner.fetch(edit)
        fields = FormFields(page, form).values
        if fields["name"] != name or fields["description"] != description:
            raise RuntimeError("The native tournament editor did not reopen the original 25 characters")
        changed = "Küstenpokal 🪼 &#128512;"
        submit_form(owner, edit, page, form, {"name":changed, "description":description})
        stage("edited")
        public = unescape(owner.fetch("app.php/arcade/tournament/" + ident))
        values = FormFields(owner.fetch(edit), form).values
        if values["name"] != changed or changed not in public or description not in public:
            raise RuntimeError("The saved tournament text or literal reference changed on its next read")
        proposal = "app.php/arcade/tournaments/propose"
        page = owner.fetch(proposal)
        proposal_name = "🪐" * 25
        proposal_desc = "🔭" * 255
        controls = UnicodeControls(page)
        controls.accepts("arc-name", proposal_name)
        controls.accepts("arc-description", proposal_desc)
        fields = {"name": proposal_name, "description": proposal_desc, "players": 2, "turns": 1}
        invalid = submit_form(owner, proposal, page, "arc-proposal", dict(fields, description=proposal_desc+"a"), 422)
        if FormFields(invalid, "arc-proposal").values["description"] != proposal_desc+"a":
            raise RuntimeError("The proposal validation lost a description of 256 characters")
        accepted = unescape(submit_form(owner, proposal, page, "arc-proposal", fields))
        if proposal_name not in accepted or proposal_desc not in accepted:
            raise RuntimeError("The member proposal did not accept the full Unicode name and description")
    finally:
        stage("clean")
