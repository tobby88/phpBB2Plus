"""Check the original icon catalogue in native topic, forum and editor views."""
from pathlib import Path
import re
import time
from urllib.parse import urljoin

from http_board import Page


def browse(founder, member, data):
    idea, staff, personal = (data[str(i)] for i in (3, 22, 90))
    if len(data) != 24 or data["91"]["icons_url"] or int(data["91"]["display_on_posting"]):
        raise RuntimeError("Missing custom icons or unavailable image metadata")
    original = Path(__file__).resolve().parents[2] / "phpBB2/images/icon/icon3.gif"
    if founder.fetch("images/icons/" + idea["icons_url"], raw=True) != original.read_bytes():
        raise RuntimeError("The converted native icon differs from the source artwork")
    for actor, caption in ((member, "Idea"), (founder, "Idee")):
        page = actor.fetch("viewtopic.php?t=1")
        if not re.search(r'<img[^>]+src="[^"]*' + re.escape(idea["icons_url"]) + r'"[^>]+alt="' + caption + '"', page):
            raise RuntimeError("The topic lost the actual image or its localized caption: " + caption)
    forum = founder.fetch("viewforum.php?f=1")
    if idea["icons_url"] not in forum or staff["icons_url"] not in forum:
        raise RuntimeError("Explicit and calendar-default topic icons must appear in the forum list")
    path = "posting.php?mode=reply&t=2"
    admin_form, member_form = (actor.fetch(path) for actor in (founder, member))
    def option(item):
        return 'id="icon-' + str(item["icons_id"]) + '"'
    if (option(staff) not in admin_form or option(staff) in member_form or option(personal) not in member_form
            or option(data["91"]) in admin_form or 'alt="Idee"' not in admin_form or 'alt="Idea"' not in member_form):
        raise RuntimeError("The native editor ignored icon visibility, moderator rights or language")
    action, fields = Page(admin_form).forms["postform"]
    fields.update(post="1", icon=staff["icons_id"], subject="Kalendersymbol", message="Ein eigener Beitrag mit Kalendersymbol.")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    founder.fetch(urljoin(urljoin(founder.base,path),action), fields)
    posted = founder.fetch("viewtopic.php?t=2")
    if "Ein eigener Beitrag mit Kalendersymbol." not in posted or staff["icons_url"] not in posted:
        raise RuntimeError("The post editor did not save the selected native icon")
