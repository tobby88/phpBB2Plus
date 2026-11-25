"""Use the original board policy in native author and moderator deletion forms."""
from urllib.parse import urljoin

from http_board import FormFields, Page, submit_form
from delegation import authenticate


def exercise(member, moderator, verify):
    topic = 965200
    own = f"posting.php?mode=delete&p={topic + 2}"
    mcp_post = f"mcp.php?i=main&quickmod=1&action=delete_post&p={topic + 11}"
    mcp_topic = f"mcp.php?i=main&quickmod=1&action=delete_topic&t={topic + 10}"

    def confirm(actor, path, permanent):
        page = actor.fetch(path)
        if "confirm" not in Page(page).forms or ('name="delete_permanent"' in page) != permanent:
            raise RuntimeError(f"The native confirmation offered the wrong deletion options at {path}")
        action, fields = Page(page).forms["confirm"]
        return urljoin(urljoin(actor.base, path), action), fields

    verify("seed")
    confirm(member, own, False)
    confirm(moderator, mcp_post, False)
    confirm(moderator, mcp_topic, False)
    admin, entry = authenticate(moderator.base, "ModernMember")
    settings = entry + "&i=acp_board&mode=post"
    option = "config[plus_post_delete_policy]"

    def change_policy(previous, value):
        page = admin.fetch(settings)
        if FormFields(page, "acp_board").values.get(option) != previous:
            raise RuntimeError("Native Posting settings did not show the current Plus deletion policy")
        submit_form(admin, settings, page, "acp_board", {"submit": "1", option: value})
        if FormFields(admin.fetch(settings), "acp_board").values.get(option) != value:
            raise RuntimeError("The administrator's new deletion policy did not persist")

    change_policy("0", "1")
    confirm(moderator, mcp_topic, True)
    action, fields = confirm(member, own, True)
    change_policy("1", "0")
    stale = member.fetch(action, dict(fields, confirm="Yes", delete_permanent="1"))
    if "Permanent removal is disabled" not in stale:
        raise RuntimeError("An open deletion form did not follow the updated board setting")
    verify("unchanged")
    action, fields = confirm(member, own, False)
    member.fetch(action, dict(fields, confirm="Yes"))
    verify("kept")
    verify("allow")
    # The native moderator can now request final removal of the selected marker.
    permanent = f"posting.php?mode=delete&p={topic + 2}"
    action, fields = confirm(moderator, permanent, True)
    moderator.fetch(action, dict(fields, confirm="Ja", delete_permanent="1"))
    verify("removed")
    verify("restrict")
