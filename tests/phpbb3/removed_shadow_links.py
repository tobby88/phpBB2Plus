from urllib.parse import urljoin
from http_board import Browser, Page


def exercise(moderator, fixture):
    fixture("source")
    fixture("upgrade")
    fixture("seed")
    guest = Browser(moderator.base)
    listing = "viewforum.php?f=14"
    if "Fresh move link to remove" not in guest.fetch(listing):
        raise RuntimeError("The synthetic move link was not publicly visible before confirmation")
    path = "mcp.php?i=main&quickmod=1&action=delete_topic&t=965610"
    action, fields = Page(moderator.fetch(path)).forms["confirm"]
    moderator.fetch(urljoin(urljoin(moderator.base,path),action), dict(fields,confirm="Ja"))
    fixture("removed")
    page = guest.fetch(listing)
    if "Fresh move link to remove" in page or "Independent move link to keep" not in page or "Old erased move link" in page:
        raise RuntimeError("A forum retained a removed caption or hid an independent move link")
