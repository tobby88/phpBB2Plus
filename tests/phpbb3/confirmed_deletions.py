"""Confirm Plus erasure at native author and MCP entry points and keep moderation reversible."""
from urllib.error import HTTPError
from urllib.parse import urljoin
from http_board import Browser, Page


def exercise(member, moderator, fixture):
    def submit(actor, path, caption):
        page = actor.fetch(path)
        action, fields = Page(page).forms["confirm"]
        actor.fetch(urljoin(urljoin(actor.base, path), action), dict(fields, **caption))

    fixture("upgrade")
    fixture("seed")
    author = "posting.php?mode=delete&p=965402"
    submit(member, author, {"cancel": "1"})
    fixture("unchanged")
    submit(member, author, {"confirm": "Yes"})
    fixture("author")
    submit(moderator, "mcp.php?i=main&quickmod=1&action=delete_post&p=965420", {"confirm": "Ja"})
    fixture("mcp-post")
    if moderator.fetch("download/file.php?id=965400", raw=True) != b"GIF8":
        raise RuntimeError("Deleting a native attachment made its other, healthy topic copy inaccessible")
    for reader in (member, moderator):
        try:
            with reader.opener.open(urljoin(reader.base, "download/file.php?id=965420")) as response:
                if response.read(4) == b"GIF8":
                    raise RuntimeError("The removed native attachment still served its content")
        except HTTPError as error:
            if error.code not in (403, 404):
                raise
    submit(moderator, "mcp.php?i=main&mode=topic_view&f=15&t=965520&action=delete_post"
           "&post_id_list%5B0%5D=965521&post_id_list%5B1%5D=965522", {"confirm": "Ja"})
    fixture("multiple")
    submit(moderator, "mcp.php?i=main&quickmod=1&action=delete_topic&t=965420", {"confirm": "Ja"})
    fixture("allow")
    submit(member, "posting.php?mode=delete&p=965440", {"confirm": "Yes"})
    submit(moderator, "mcp.php?i=main&quickmod=1&action=delete_topic&t=965460", {"confirm": "Ja"})
    fixture("topics")
    fixture("restore")
    guest = Browser(member.base)
    for topic in (965420, 965440):
        page = guest.fetch(f"viewtopic.php?t={topic}")
        if 'class="notice">Gelöschter Beitrag' not in page or f"Contents {topic}" in page:
            raise RuntimeError("A newly erased topic did not retain its neutral readable positions")
    if "Contents 965461" in guest.fetch("viewforum.php?f=15"):
        raise RuntimeError("A private moderator record escaped from a mixed erased topic")
    fixture("native-policy")
    submit(member, "posting.php?mode=delete&p=965481", {"confirm": "Yes"})
    fixture("native")
    fixture("transaction")
    fixture("resume")
