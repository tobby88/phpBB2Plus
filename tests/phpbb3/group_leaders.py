"""Exercise a converted group leader's normal forms without granting group membership."""
from time import sleep, time
from urllib.parse import urljoin
import re
from http_board import Browser, Page


def browse(base, owner, designated, waiting, check):
    group = "app.php/groups/77/lead"
    bookmark = "groupcp.php?g=77&validate=true"
    native = "ucp.php?i=ucp_groups&mode=manage&action=list&g=77"

    def submit(actor, page, form, changes, status=200):
        url, fields = Page(page).forms[form]
        fields = {name: value for name, value in fields.items() if name != "members[]"}
        fields.update(changes)
        if "creation_time" in fields:
            sleep(max(0, int(fields["creation_time"]) + 1.05 - time()))
        return actor.fetch(urljoin(base, url), fields, expected_status=status)

    def waiting_member(page, expected):
        label = re.search(r'name="members\[\]" value="87">\s*DepartingFixture\s*\(([^)]+)\)', page)
        if (label[1] if label else None) != expected:
            raise AssertionError("The native member action did not show the new roster state")

    check("prepare")
    try:
        # This founder also holds a separately appointed leadership. Native permission already opens the full roster.
        roster = owner.fetch("memberlist.php?mode=group&g=71")
        if not re.search(r'<h2 class="group-title"[^>]*>\s*Photo Club\s*</h2>', roster) or "plus-group-roster" in Page(roster).forms:
            raise AssertionError("A group administrator lost the native Photo Club member list")
        # Both forms of leadership existed on the source. Neither was an approved member.
        for actor, url, label in ((designated, bookmark, "Designated leader"), (designated, native, "Designated leader"),
                                  (designated, "memberlist.php?mode=group&g=77", "Designated leader"),
                                  (waiting, "groupcp.php?g=78", "Waiting leader")):
            page = actor.fetch(url)
            if label not in page or "plus-group-roster" not in Page(page).forms:
                raise AssertionError("A designated group leader cannot open the membership roster at " + url)
        Browser(base).fetch(group, expected_status=404)
        owner.fetch(group, expected_status=404)
        waiting.fetch(bookmark, expected_status=404)
        page = designated.fetch(group)
        page = submit(designated, page, "plus-group-roster", {"action": "deny", "members[]": "87"})
        waiting_member(page, None)
        page = submit(designated, page, "group-settings", {"action": "type", "type": "0"})
        if '<option value="0" selected>Moderiert</option>' not in page:
            raise AssertionError("The original open group must require approval of new members")
        application = waiting.fetch("ucp.php?i=ucp_groups&mode=membership")
        confirm = submit(waiting, application, "ucp", {"action": "join", "submit": "1", "selected": "77"})
        submit(waiting, confirm, "confirm", {"confirm": "Ja"})
        page = designated.fetch(group)
        waiting_member(page, "Antrag ausstehend")
        page = submit(designated, page, "plus-group-roster", {"action": "approve", "members[]": "87"})
        waiting_member(page, "Mitglied")
        page = submit(designated, page, "plus-group-roster", {"action": "remove", "members[]": "87"})
        waiting_member(page, None)
        page = submit(designated, page, "plus-group-add", {"action": "add", "username": "DepartingFixture"})
        waiting_member(page, "Mitglied")
        page = submit(designated, page, "group-settings", {"action": "type", "type": "1"})
        if "Designated leader" not in waiting.fetch("groupcp.php?g=77") or 'value="1" selected' not in page:
            raise AssertionError("The designated leader could not change the historical group's type")
        submit(designated, page, "plus-group-add", {"action": "add", "username": "ModerationFixture"}, status=422)
        check("approved")
        check("founders")
        if "Members and applications" not in waiting.fetch("groupcp.php?g=78&validate=true"):
            raise AssertionError("The independent group's designated leader lost the English controls")
        designated.fetch(group, expected_status=404)
        submit(designated, page, "group-settings", {"action": "type", "type": "2"}, status=404)
    finally:
        check("restore")
