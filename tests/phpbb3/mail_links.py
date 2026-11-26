"""Follow phpBB2 mail links with two actual, differently numbered accounts."""
from urllib.parse import urljoin
from http_board import Browser, Page
from legacy_links import link


def unwatch(base, owner, other, change):
    path = "topic_watch.php?t=0001"
    link(base, path, "viewtopic.php", {"t": 1, "unwatch": "topic"})
    guest = Browser(base)
    if "login" not in Page(guest.fetch(path)).forms:
        raise RuntimeError("A signed-out reader opening an old notification was not asked to log in")
    if "confirm" in Page(other.fetch(path)).forms:
        raise RuntimeError("An unsubscribed reader was offered another member's subscription")
    first = Page(owner.fetch(path)).forms
    if "confirm" not in first:
        raise RuntimeError("The subscriber's old email link did not open a native confirmation")
    # Opening the bookmark again must still present a confirmation.
    action, fields = Page(owner.fetch(path)).forms["confirm"]
    owner.fetch(urljoin(urljoin(base, "viewtopic.php"), action), dict(fields, confirm="Yes"))
    if "confirm" in Page(owner.fetch(path)).forms:
        raise RuntimeError("Confirming did not remove the reader's subscription")
    change("watch-bookmark-restore")
    guest.fetch("topic_watch.php?t=0", expected_status=404)
    guest.fetch("topic_watch.php?t=16777216", expected_status=404)


def browse(base, owner, historic, historic_id, change):
    unwatch(base, historic, owner, change)
    saved = "privmsg.php?folder=savebox"
    read = "privmsg.php?folder=savebox&mode=read&p="
    if "login" not in Page(Browser(base).fetch(saved)).forms:
        raise RuntimeError("An old private savebox bookmark did not open the guest login")
    for actor, own, other in ((historic, 113, 114), (owner, 114, 113)):
        mailbox = actor.fetch(saved)
        if f"Postfachprobe {own}" not in mailbox or f"Postfachprobe {other}" in mailbox:
            raise RuntimeError("The converted savebox does not belong to the signed-in member")
        if f"Postfachprobe {own}" not in actor.fetch(read + str(own)):
            raise RuntimeError("An owned saved message could not be read through the old URL")
        actor.fetch(read + str(other), expected_status=404)
    compose = owner.fetch("privmsg.php?mode=post&u=1")
    if "HistoricMember" not in compose or f'name="address_list[u][{historic_id}]"' not in compose:
        raise RuntimeError("Composing to the old account one did not select its real converted recipient")
    change("mail-bookmarks-off")
    try:
        historic.fetch(read + "113", expected_status=404)
        if "Postfachprobe 113" in historic.fetch(saved):
            raise RuntimeError("An unrelated native folder was guessed as the missing historical savebox")
    finally:
        change("mail-bookmarks-on")
