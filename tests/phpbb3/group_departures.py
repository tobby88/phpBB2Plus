"""Leave an originally named group through the actual UCP selection and native confirmation."""
from time import sleep, time
from urllib.parse import urljoin
from http_board import Browser, Page


def browse(base, member, applicant, leader, check):
    memberships = "ucp.php?i=ucp_groups&mode=membership"
    leave = "app.php/groups/125/leave"
    room = "Sitzungen des Schülerrats"

    def send(actor, page, form, values, status=200):
        action, fields = Page(page, checked=True).forms[form]
        fields.update(values)
        if "creation_time" in fields:
            sleep(max(0, int(fields["creation_time"]) + 1.05 - time()))
        return actor.fetch(urljoin(base, action), fields, expected_status=status)

    def select(actor):
        page = actor.fetch(memberships)
        if "Schülerrat" not in page:
            raise AssertionError("The converted school group was lost from the UCP")
        return send(actor, page, "ucp", {"selected": "125", "action": "resign", "submit": "1"})

    def confirm(actor, text):
        page = select(actor)
        if text not in page or "Schülerrat" not in page or "confirm" not in Page(page).forms:
            raise AssertionError("Selecting resign did not explain which group and membership are affected")
        return page

    check("prepare")
    try:
        if room not in member.fetch("index.php") or room in applicant.fetch("index.php"):
            raise AssertionError("The converted course forum does not follow approved school-group membership")
        Browser(base).fetch(leave, expected_status=404)
        native = select(member)
        if "confirm" not in Page(native).forms or "/groups/125/leave" in Page(native).forms["confirm"][0]:
            raise AssertionError("The ordinary open-group resignation stopped using the native form")
        send(member, native, "confirm", {"cancel": "No"})
        if "Gib die Gruppenleitung ab" not in select(leader):
            raise AssertionError("The ordinary resignation removed a currently appointed group leader")

        check("close")
        page = confirm(applicant, "Beitrittsantrag")
        send(applicant, page, "confirm", {"cancel": "Nein"})
        page = confirm(applicant, "Beitrittsantrag")
        check("hide")
        send(applicant, page, "confirm", {"confirm": "Ja"})
        applicant.fetch(leave, expected_status=404)
        if room in applicant.fetch("index.php"):
            raise AssertionError("Withdrawing an application granted the closed forum to its applicant")

        check("pending-again")
        stale = confirm(applicant, "Beitrittsantrag")
        roster = leader.fetch("ucp.php?i=ucp_groups&mode=manage&action=list&g=125")
        if 'name="mark[]" value="87"' not in roster or 'value="approve"' not in roster:
            raise AssertionError("The actual group leader could not select the pending applicant")
        send(leader, roster, "ucp", {"mark[]": ["87"], "action": "approve", "update": "1"})
        send(applicant, stale, "confirm", {"confirm": "Ja"}, status=409)
        if room not in applicant.fetch("index.php"):
            raise AssertionError("A stale cancellation removed a newly approved membership")

        stale = confirm(member, "Leave the group")
        check("direct-never")
        send(member, stale, "confirm", {"confirm": "Yes"}, status=404)
        check("role-never")
        member.fetch(leave, expected_status=404)
        check("clear-native")
        page = confirm(member, "Leave the group")
        send(member, page, "confirm", {"confirm": "Yes"})
        member.fetch(leave, expected_status=404)
        if room in member.fetch("index.php") or room not in applicant.fetch("index.php"):
            raise AssertionError("Leaving the hidden school group changed the wrong member's forum access")
    finally:
        check("restore")
