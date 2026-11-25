"""Follow private phpBB2 bookmarks with two actual, differently numbered accounts."""
from http_board import Browser, Page


def browse(base, owner, historic, historic_id, change):
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
