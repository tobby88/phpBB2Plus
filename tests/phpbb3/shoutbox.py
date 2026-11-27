"""Follow the public Shoutbox, member composer and native moderation confirmation."""
import re
from time import sleep, time
from urllib.parse import urljoin
from http_board import Browser, Page


def submit(browser, page, form_id, values):
    url, fields = Page(page).forms[form_id]
    fields.update(values)
    if "creation_time" in fields:
        sleep(max(0, int(fields["creation_time"]) + 1.05 - time()))
    return browser.fetch(urljoin(browser.base, url), fields)


def exercise(base, moderator, member):
    path = "app.php/shoutbox"
    guest = Browser(base)
    public = guest.fetch(path)
    if ("Grüße aus dem Archiv" not in public or "Masked shout fixture" in public or
            "FormerShouter" in public or "plus-shout-compose" in public):
        raise RuntimeError("Unexpected public Shoutbox history or composer")
    if "FormerShouter" not in member.fetch(path):
        raise RuntimeError("The retained shoutbox byline should be visible to members")
    public_home = guest.fetch("")
    member_home = member.fetch("")
    if ("Grüße aus dem Archiv" in public_home or "Grüße aus dem Archiv" not in member_home or "/shout_panel.js" not in member_home or
            "Masked shout fixture" in member_home):
        raise RuntimeError("The members-only portal preview ignored the audience or disclosed a hidden shout")
    recent = path + "/recent/portal"
    with member.opener.open(urljoin(base, recent), timeout=10) as response:
        fragment = response.read().decode("utf-8")
        if not {"no-store", "private"} <= {word.strip() for word in response.headers["Cache-Control"].split(",")}:
            raise RuntimeError("The viewer-specific shoutbox fragment can be cached")
    if ('id="plus-shout-messages"' not in fragment or 'id="plus-shout-quick"' in fragment or
            "FormerShouter" not in fragment or "Masked shout fixture" in fragment):
        raise RuntimeError("The refreshed messages differ from the member's panel")
    for actor, place in ((guest, "portal"), (guest, "index"), (member, "index")):
        if "FormerShouter" in actor.fetch(path + "/recent/" + place, expected_status=403):
            raise RuntimeError("Refreshing a disabled panel disclosed a byline")
    page = member.fetch(path)
    posted = submit(member, page, "plus-shout-compose", {"message": "[b]Neue Nachricht[/b] ä 😀"})
    matches = re.findall(r'id="shout-(\d+)"', posted)
    if not matches or "Neue Nachricht" not in posted:
        raise RuntimeError("Member shout was not added")
    shout_id = matches[0]
    edited = submit(member, member.fetch(path + "?edit=" + shout_id), "plus-shout-compose",
                    {"message": "Überarbeitete Nachricht"})
    if "Überarbeitete Nachricht" not in edited:
        raise RuntimeError("The author's edit was not saved")
    row = "plus-shout-actions-" + shout_id
    submit(moderator, moderator.fetch(path), row, {"action": "hide"})
    if "Überarbeitete Nachricht" in guest.fetch(path) or row in member.fetch(path):
        raise RuntimeError("A moderated shout must be hidden from guests and its author")
    submit(moderator, moderator.fetch(path), row, {"action": "restore"})
    if "Überarbeitete Nachricht" not in guest.fetch(path):
        raise RuntimeError("A restored shout is missing")
    confirmation = submit(member, member.fetch(path), row, {"action": "delete"})
    if "confirm" not in Page(confirmation).forms or "Überarbeitete Nachricht" not in guest.fetch(path):
        raise RuntimeError("Delete must first show phpBB's confirmation")
    # This member uses the English locale.
    submit(member, confirmation, "confirm", {"confirm": "Yes"})
    if "Überarbeitete Nachricht" in guest.fetch(path):
        raise RuntimeError("The confirmed deletion left a message behind")
    home = submit(moderator, moderator.fetch("app.php/portal"), "plus-shout-quick", {"message": "Gruß direkt aus dem Portal"})
    if 'class="plus-portal"' not in home or "Gruß direkt aus dem Portal" not in home:
        raise RuntimeError("A quick portal shout did not return to the portal with the saved message")
    if "Gruß direkt aus dem Portal" not in member.fetch(recent):
        raise RuntimeError("Another member's refresh missed a newly posted message")
