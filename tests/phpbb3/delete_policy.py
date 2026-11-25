"""Use the original board policy in native author and moderator deletion forms."""
from urllib.parse import urljoin

from http_board import Page


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
    verify("allow")
    confirm(moderator, mcp_topic, True)
    action, fields = confirm(member, own, True)
    verify("restrict")
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
