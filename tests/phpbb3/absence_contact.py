"""Send ordinary native private messages and profile emails only on the isolated board."""
from time import sleep, time
from urllib.parse import urljoin
from html import unescape
import re

from download_mail import mailbox
from http_board import Page, submit_form


def browse(base, member, admin, verify):
    direct = "ucp.php?i=pm&mode=compose&u=2"
    group = "ucp.php?i=pm&mode=compose&g=947210"
    compose = "ucp.php?i=pm&mode=compose"
    email = "memberlist.php?mode=email&u=2"
    personal = "Direkter Hinweis &amp; &lt;privat&gt;"
    collective = "One or more recipients cannot receive this private message"
    email_denied = "cannot receive profile email"

    def message(actor, path, page, label, **changes):
        action, fields = Page(page).forms["postform"]
        fields.update(subject="Away check / " + label, message="Disposable message for the availability check.")
        fields.update(changes)
        sleep(max(0, int(fields["creation_time"]) + 1.05 - time()))
        return actor.fetch(urljoin(urljoin(base, path), action), fields)

    with mailbox() as sink:
        verify("seed:" + str(sink.server_address[1]))
        try:
            pm_form = member.fetch(direct)
            mail_form = member.fetch(email)
            if personal in pm_form or "post" not in Page(mail_form).forms:
                raise RuntimeError("An available member's native contact forms are missing")
            verify("away")
            direct_page = member.fetch(direct)
            if personal not in direct_page or "Off sick" not in direct_page:
                raise RuntimeError("An eligible direct sender did not see the recipient's exact absence notice")
            refused = message(member, direct, pm_form, "stale personal", post="1")
            if personal not in refused or "Away check / stale personal" not in refused:
                raise RuntimeError("A change of availability did not retain the blocked sender's editable text")
            denied_email = submit_form(member, email, mail_form, "post",
                                       {"subject":"Away email test", "message":"Unavailable target.", "submit":"1"})
            if email_denied not in denied_email or personal in denied_email:
                raise RuntimeError("An old profile email form sent mail to an absent member or disclosed their note")
            group_page = member.fetch(group)
            grouped = message(member, group, group_page, "unavailable group", post="1")
            if collective not in grouped or "Privates Gruppenmitglied" in grouped or personal in grouped or "Off sick" in grouped:
                raise RuntimeError("An unavailable group member was disclosed or the native group was partially delivered")
            combined = message(member, direct, direct_page, "blind selection",
                               **{"username_list":"ModerationFixture", "add_bcc":"1"})
            if 'name="address_list[u][89]" value="bcc"' not in combined:
                raise RuntimeError("Native BCC selection was lost")
            bulk = message(member, direct, combined, "blind selection", post="1")
            if collective not in bulk or personal in bulk or "Off sick" in bulk:
                raise RuntimeError("A multi-recipient refusal exposed an individual's status")
            if sink.mail:
                raise RuntimeError("A refused delivery unexpectedly sent a notification")
            verify("group_available")
            message(member, group, member.fetch(group), "group", post="1")
            verify("mods")
            if personal not in member.fetch(direct):
                raise RuntimeError("A partial forum moderator acquired the historical absence override")
            verify("promote")
            ready = member.fetch(direct)
            if personal in ready or "post" not in Page(member.fetch(email)).forms:
                raise RuntimeError("The explicit moderator permission and enabled board policy did not allow direct contact")
            message(member, direct, ready, "moderator", post="1")
            permitted_mail = member.fetch(email)
            verify("revoke")
            denied = message(member, direct, ready, "revoked", post="1")
            if personal not in denied:
                raise RuntimeError("Revoked moderator permission still allowed delivery from a previous compose form")
            if email_denied not in submit_form(member, email, permitted_mail, "post",
                                               {"subject":"Revoked permission", "message":"Member email.", "submit":"1"}):
                raise RuntimeError("Revoked moderator permission still allowed a profile email")
            verify("promote")
            permitted_mail = member.fetch(email)
            submit_form(member, email, permitted_mail, "post",
                        {"subject":"Away email test", "message":"Permitted isolated profile email.", "submit":"1", "cc_sender":None})
            delivered_mail = [item for item in sink.mail if "Permitted isolated profile email." in item.get_body().get_content()]
            if len(delivered_mail) != 1 or "modern@example.invalid" not in str(delivered_mail[0]["To"]):
                raise RuntimeError("The permitted profile email did not arrive exactly once at the local recipient")
            verify("private_email")
            if "post" in Page(member.fetch(email)).forms:
                raise RuntimeError("An absence moderator bypassed the member's native profile email preference")
            verify("founder_target")
            addressed = message(admin, compose, admin.fetch(compose), "founder",
                                **{"username_list":"HistoricMember", "add_to":"1"})
            message(admin, compose, addressed, "founder", post="1")
            verify("outcome")
            inbox = member.fetch("ucp.php?i=pm&folder=inbox")
            incoming = re.search(r'href="([^"]+)"[^>]*>\s*Away check / founder\s*</a>',inbox)
            if not incoming:
                raise RuntimeError("The founder's message did not reach the absent member's inbox")
            viewed = member.fetch(unescape(incoming[1]))
            if ("Disposable message" not in viewed or "Off sick" not in viewed or personal in viewed or
                    'class="contact-icon pm-icon"' in viewed or re.search(r'action=(?:reply|quote)(?:&amp;|")',viewed)):
                raise RuntimeError("Reading existing private messages lost the content, exposed the note or offered an unavailable reply")
        finally:
            verify("restore")
