"""Submit real paFileDB email forms to a disposable loopback SMTP receiver."""
import email.policy
import re
import socketserver
import threading
import time
from contextlib import contextmanager
from email.parser import BytesParser
from html import unescape
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page


@contextmanager
def mailbox():
    class Receiver(socketserver.StreamRequestHandler):
        def handle(self):
            def reply(line):
                self.wfile.write(line + b"\r\n")

            reply(b"220 localhost disposable SMTP")
            while True:
                line = self.rfile.readline(8192)
                if not line:
                    return
                command = line.split(b" ", 1)[0].strip().upper()
                if command in (b"HELO", b"EHLO", b"MAIL", b"RSET", b"NOOP"):
                    reply(b"250 localhost")
                elif command == b"RCPT" and b"@example.invalid>" in line.lower():
                    reply(b"250 accepted")
                elif command == b"DATA":
                    reply(b"354 end with a dot")
                    data = bytearray()
                    while True:
                        line = self.rfile.readline(16384)
                        if not line or line == b".\r\n":
                            break
                        data.extend(line[1:] if line.startswith(b"..") else line)
                    if self.server.reject:
                        reply(b"451 disposable transport temporarily unavailable")
                    else:
                        self.server.mail.append(BytesParser(policy=email.policy.default).parsebytes(bytes(data)))
                        reply(b"250 accepted")
                elif command == b"QUIT":
                    reply(b"221 closing")
                    return
                else:
                    reply(b"550 not available")

    with socketserver.ThreadingTCPServer(("127.0.0.1", 0), Receiver) as server:
        server.mail = []
        server.reject = False
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            yield server
        finally:
            server.shutdown()
            thread.join()


def browse(base, member, ordinary, verify):
    guest = Browser(base)
    root = "app.php/downloads/file/"
    public, shared, guest_file = root + "50", root + "51", root + "11050"

    def form(actor, item):
        file_id = item.rsplit("/", 1)[-1]
        address = "dload.php?action=email&file_id=" + file_id
        page = actor.fetch(address)
        target = urljoin(urljoin(base, address), Page(page).forms["dl-recommend"][0])
        return page, target, FormFields(page, "dl-recommend").values

    def send(actor, target, fields, **changes):
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(target, dict(fields, **changes))

    with mailbox() as sink:
        verify("seed:" + str(sink.server_address[1]))
        try:
            if ("/recommend" in guest.fetch(public) or "/recommend" not in member.fetch(public) or
                    "login" not in Page(guest.fetch(public + "/recommend")).forms or "/recommend" in member.fetch(root + "52")):
                raise RuntimeError("Recommendation links ignored membership, approval or category email permissions")
            guest.fetch(shared + "/recommend", expected_status=404)
            if "Recommend this file" not in member.fetch(shared + "/recommend"):
                raise RuntimeError("An inherited category grant did not permit the recommendation")
            page, target, fields = form(member, public)
            if any(key in fields for key in ("sname", "semail")) or not re.search(r'[\w.+-]+@[\w.-]+', page):
                raise RuntimeError("The member's own sender identity was not bound to the account")
            bad = send(member, target, fields, femail="missing-address")
            if "valid email address" not in bad or sink.mail:
                raise RuntimeError("The missing recipient did not return the editable recommendation")
            values = dict(femail="friend@example.invalid", fname="Fixture Friend", subject="Hinweis & Grüße 🎨", message="Lesetipp &amp;\nZweite Zeile.")
            delivered = send(member, target, fields, **values)
            if "Your recommendation was sent" not in delivered or len(sink.mail) != 1:
                raise RuntimeError("The native messenger did not hand the member's recommendation to the local receiver")
            mail = sink.mail[0]
            content = mail.get_content()
            if (str(mail["Subject"]) != values["subject"] or mail["To"].addresses[0].addr_spec != values["femail"] or
                    mail["From"].addresses[0].addr_spec != "download-board@example.invalid" or
                    mail["Reply-To"].addresses[0].addr_spec not in unescape(page) or "Download-Empfehlung von HistoricMember" not in content or
                    "/downloads/file/50" not in content or "sid=" in content or "> Lesetipp &amp;" not in content):
                raise RuntimeError("The recommendation lost the account address, literal message, board language or public file link")
            send(member, target, fields, **values)
            if "dl-recommend" not in Page(ordinary.fetch(public + "/recommend?sent=" + fields["nonce"])).forms:
                raise RuntimeError("A recommendation receipt was acknowledged for a different member")
            _, target, fresh = form(member, public)
            if "Please wait" not in send(member, target, fresh, **values) or len(sink.mail) != 1:
                raise RuntimeError("A repeated form or a second recommendation bypassed the converted cooldown")
            verify("english")
            _, target, fields = form(guest, guest_file)
            values.update(sname="Briefgast", semail="visitor@example.invalid", subject="Archive recommendation")
            send(guest, target, fields, **values)
            if (len(sink.mail) != 2 or "Download recommendation from Briefgast" not in sink.mail[1].get_content() or
                    sink.mail[1]["Reply-To"].addresses[0].addr_spec != "visitor@example.invalid"):
                raise RuntimeError("The enabled guest category did not send the English visitor recommendation")
            second = Browser(base)
            _, target, fields = form(second, guest_file)
            repeat = send(second, target, fields, **values)
            if not any(label in repeat for label in ("Please wait", "Bitte warte")) or len(sink.mail) != 2:
                raise RuntimeError("A second guest session on the same host discarded the recommendation cooldown")
            verify("invalid")
            declined = send(second, target, fields, **values)
            if not any(label in declined for label in ("could not be sent", "konnte nicht versendet")) or len(sink.mail) != 2:
                raise RuntimeError("An incomplete stored policy allowed a recommendation")
            verify("minimum")
            repeat = send(second, target, fields, **values)
            if not any(label in repeat for label in ("Please wait", "Bitte warte")) or len(sink.mail) != 2:
                raise RuntimeError("An out-of-range historical duration lost its one-minute minimum")
            verify("board_interval")
            repeat = send(second, target, fields, **values)
            if not any(label in repeat for label in ("Please wait", "Bitte warte")) or len(sink.mail) != 2:
                raise RuntimeError("Turning off the optional cooldown also removed the native board interval")
            verify("elapsed")
            send(second, target, fields, **values)
            if len(sink.mail) != 3:
                raise RuntimeError("The disabled CrackerTracker cooldown was still active after the board interval")
            # A different ordinary member receives an honest result when this local receiver declines delivery.
            _, target, retry = form(ordinary, public)
            sink.reject = True
            refused = send(ordinary, target, retry, **values)
            if not any(label in refused for label in ("could not be sent", "konnte nicht versendet")) or len(sink.mail) != 3:
                raise RuntimeError("A failed handoff to the local receiver was reported as delivered")
            verify("revoked")
            ordinary.fetch(target, dict(retry, **values), expected_status=404)
            verify("disabled")
            if "/recommend" in guest.fetch(guest_file):
                raise RuntimeError("Disabled board email still advertised file recommendations")
            guest.fetch(guest_file + "/recommend", expected_status=404)
            verify("outcome")
        finally:
            verify("restore")
