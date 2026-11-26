"""Open private birthday greetings through a native notification on a converted board."""
from concurrent.futures import ThreadPoolExecutor

from http_board import Browser, FormFields, submit_form


def browse(base, member, other, fixture):
    guest = Browser(base)
    url = "birthday_popup.php?u=2"
    guest.fetch(url, expected_status=403)
    fixture("due")
    try:
        # Two tabs can arrive on the first eligible visit.
        with ThreadPoolExecutor(max_workers=2) as workers:
            visits = [workers.submit(member.separate_client().fetch, "index.php") for _ in range(2)]
            for visit in visits:
                visit.result()
        fixture("delivered")
        notifications = "ucp.php?i=ucp_notifications&mode=notification_list"
        if "Birthday wishes from the board" not in member.fetch(notifications) or "Happy birthday! You turn " not in member.fetch(url):
            raise RuntimeError("The native birthday notification or its private greeting was missing")
        if "Geburtstagsgrüße vom Forum" in other.fetch(notifications) or "Happy birthday! You turn " in other.fetch(url):
            raise RuntimeError("A different member received the birthday owner's notification or message")
        settings = "ucp.php?i=ucp_notifications&mode=notification_options"
        options = member.fetch(settings)
        preference = "phpbb2plus.community.notification.birthday_notification.method.board"
        if preference not in FormFields(options, "ucp").values:
            raise RuntimeError("The member's notification preferences lacked an enabled birthday option")
        submit_form(member, settings, options, "ucp", {preference: None, "submit": "1"})
        fixture("opt-out")
        member.fetch("index.php")
        fixture("silent")
        fixture("off")
        member.fetch(url, expected_status=403)
    finally:
        fixture("clean")
