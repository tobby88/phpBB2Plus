"""Native topic recommendations and the original board-wide recommendation, using only loopback SMTP."""
from html import unescape
import time
from urllib.parse import urlencode, urlsplit

from delegation import authenticate
from download_mail import mailbox
from http_board import Browser, FormFields, Page, submit_form


def browse(base, member, verify):
    guest = Browser(base)
    board = "app.php/recommend"
    topic = "memberlist.php?mode=email&t=1"
    def form(page):
        return "plus_friend_form" in FormFields(page, "post").values

    def send(url, page, status=200, **changes):
        values = {"email": "reader@example.invalid", "name": "Leserin", "message": "Grüße & Tipps.\nZweite Zeile.",
                  "lang": "de", "cc_sender": None, "submit": "1", **changes}
        return submit_form(member, url, page, "post", values, status=status)

    def topic_links(actor, path):
        return "memberlist.php?mode=email&t=1" in unescape(actor.fetch(path))

    with mailbox() as sink:
        verify(f"seed:{sink.server_address[1]}:{urlsplit(base).port}")
        try:
            verify("upgrade")
            if "login" not in Page(guest.fetch("tellafriend.php")).forms or "login" not in Page(guest.fetch(topic)).forms:
                raise RuntimeError("Guests did not receive the scoped login form")
            if (topic_links(guest, "viewtopic.php?t=1") or topic_links(guest, "app.php/news") or
                    not topic_links(member, "viewtopic.php?t=1") or not topic_links(member, "app.php/news")):
                raise RuntimeError("The native topic and news dropdowns did not follow the viewer's mail permissions")
            for old in ("https://old.example.invalid/archive/viewtopic.php?t=1&highlight=x", "ftopic1.html"):
                page = member.fetch("tellafriend.php?" + urlencode({"link": old, "topic": "Old title"}))
                if not form(page) or "mode=email&t=1" not in Page(page).forms["post"][0] or "Old title" in page:
                    raise RuntimeError("The old topic bookmark did not resolve to its native topic form")
            for other in ("", "https://somewhere.example.invalid/viewtopic.php?t=1"):
                page = member.fetch("tellafriend.php?" + urlencode({"link": other}))
                if (not form(page) or "/recommend" not in Page(page).forms["post"][0] or "Recommend this board" not in page
                        or "somewhere.example.invalid" in page):
                    raise RuntimeError("A general recommendation bookmark did not open the board form")
            member.fetch("tellafriend.php", {"submit": "1"}, expected_status=405)
            if form(member.fetch("memberlist.php?mode=email&t=7", expected_status=404)) or form(member.fetch("memberlist.php?mode=email&t=695", expected_status=404)):
                raise RuntimeError("A private or withdrawn topic exposed a recommendation form")
            combined = "memberlist.php?mode=email&t=490"
            if (not form(member.fetch("memberlist.php?mode=email&t=489")) or not form(member.fetch(combined)) or
                    form(member.fetch("memberlist.php?mode=email&t=488",expected_status=403))):
                raise RuntimeError("Read-only recommendations failed to respect single and combined listing and reading grants")
            verify("deny_combined")
            if form(member.fetch(combined)) or "Reading circle fixture 10" not in member.fetch("viewtopic.php?t=490"):
                raise RuntimeError("An explicit native Never for recommendations did not take precedence over the combined reading circles")
            verify("allow_combined")
            b, t = member.fetch(board), member.fetch(topic)
            # Returning to another tab must not reuse a confirmation for a different subject.
            other_topic = dict(FormFields(b,"post").values, email="reader@example.invalid", name="Leserin", submit="1")
            time.sleep(max(0,int(other_topic["creation_time"]) + 1.05 - time.time()))
            member.fetch(topic, other_topic)
            if sink.mail:
                raise RuntimeError("The board form also confirmed a different topic")
            verify("deny_forum")
            if form(send(topic, t)) or topic_links(member, "app.php/news") or sink.mail:
                raise RuntimeError("A revoked forum email permission left the former topic form usable")
            verify("allow_forum")
            verify("lock_forum")
            if form(send(topic,t,status=404)) or topic_links(member,"app.php/news") or sink.mail:
                raise RuntimeError("A previously opened form could still recommend a newly password-protected forum")
            verify("unlock_forum")
            verify("deny_user")
            if form(send(board, b)) or sink.mail or topic_links(member, "viewtopic.php?t=1"):
                raise RuntimeError("Revoking the sender's account permission left a board recommendation active")
            verify("allow_user")
            b = member.fetch(board)
            refused = send(board, b, email="missing")
            if not form(refused) or sink.mail:
                raise RuntimeError("Missing recipient did not leave the recommendation editable")
            send(board, b)
            if len(sink.mail) != 1:
                raise RuntimeError("The board recommendation was not accepted by the local receiver")
            letter = sink.mail[0]
            if (letter["To"].addresses[0].addr_spec != "reader@example.invalid" or letter["Reply-To"].addresses[0].addr_spec != "historic@example.invalid"
                    or letter["From"].addresses[0].addr_spec != "recommend-board@example.invalid" or "Forumsempfehlung" not in str(letter["Subject"])
                    or base not in letter.get_content() or "> Grüße & Tipps." not in letter.get_content() or "sid=" in letter.get_content()):
                raise RuntimeError("Board recommendation lost its configured address, verified sender, language or personal note")
            verify("after_attempt")
            t = member.fetch(topic)
            send(topic, t)
            if len(sink.mail) != 1:
                raise RuntimeError("The separate topic form ignored the successful board recommendation cooldown")
            download = "app.php/downloads/file/50/recommend"
            values = {"femail": "reader@example.invalid", "fname": "Leserin", "subject": "Download", "message": "Eine Datei."}
            file_page = member.fetch(download)
            wait = submit_form(member,download,file_page,"dl-recommend",values)
            if "Please wait" not in wait or len(sink.mail) != 1:
                raise RuntimeError("The file recommendation ignored the member's successful forum recommendation")
            verify("another")
            send(board, b)
            if len(sink.mail) != 1:
                raise RuntimeError("The original, already delivered form repeated delivery")
            submit_form(member,download,file_page,"dl-recommend",values)
            if len(sink.mail) != 2:
                raise RuntimeError("The registered member's permitted download recommendation was not delivered")
            verify("after_attempt")
            send(topic, t)
            if len(sink.mail) != 2:
                raise RuntimeError("The topic recommendation ignored the member's successful download recommendation")
            verify("another")
            t = member.fetch(topic)
            send(topic, t, name="", lang="en", cc_sender="1")
            if len(sink.mail) != 4:
                raise RuntimeError("Native topic recommendation or the requested separate sender copy was not delivered")
            original, copy = sink.mail[2:]
            if (original["To"].addresses[0].addr_spec != "reader@example.invalid" or copy["To"].addresses[0].addr_spec != "historic@example.invalid" or
                    any(item["From"].addresses[0].addr_spec != "recommend-board@example.invalid" for item in (original,copy)) or
                    "Hello reader," not in original.get_content() or "following topic" not in original.get_content() or
                    base + "viewtopic.php?t=1" not in original.get_content() or "sid=" in original.get_content()):
                raise RuntimeError("Native recommendation did not preserve the recipient language, topic link or independent copy")
            verify("another")
            sink.reject = True
            b = member.fetch(board)
            fail = send(board, b, cc_sender="1")
            if "could not be sent" not in fail or len(sink.mail) != 4:
                raise RuntimeError("A local transport refusal was reported as successful")
            delegate, entry = authenticate(base,"TrackerSettingsJunior")
            panel = entry + "&i=-phpbb2plus-community-acp-tracker_settings_module&mode=manage"
            settings = "plus-tracker-settings"
            page = delegate.fetch(panel)
            fields = FormFields(page,settings).values
            if fields.get("config[plus_friend_protection]") != "1" or fields.get("config[plus_friend_minutes]") != "3":
                raise RuntimeError("The CrackerTracker administrator did not receive the board's active recommendation policy")
            submit_form(delegate,panel,page,settings,{"config[plus_friend_protection]": "2", "config[plus_friend_minutes]": "7"})
            after = FormFields(delegate.fetch(panel),settings).values
            if after.get("config[plus_friend_protection]") != "2" or after.get("config[plus_friend_minutes]") != "7":
                raise RuntimeError("The historical CrackerTracker settings operator could not suspend recommendations")
            if form(member.fetch(board)) or topic_links(member, "app.php/news"):
                raise RuntimeError("A suspended board policy continued offering recommendations")
            verify("disable")
            if form(member.fetch(board)) or form(member.fetch(topic)):
                raise RuntimeError("A board that disabled all mail continued offering native or board recommendations")
            verify("outcome")
        finally:
            verify("restore")
