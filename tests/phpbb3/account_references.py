"""Check the native mailbox and membership relations, including large, partially delivered messages."""
from urllib.parse import parse_qs, urljoin, urlsplit


def browse(admin, reviewer, panel, limited, fixture, report_type):
    n = 640000
    missing = n+999

    def report(actor, path):
        body = actor.fetch(path)
        if any(marker in body for marker in ("HiddenRefOwner", "Private mailbox subject", "Private mailbox text", "Private mailbox folder", "bcc_address")):
            raise AssertionError("The account reference report exposed private message or account details")
        return report_type(body)

    def scan(section, after, through, actor=reviewer):
        path = (limited if actor is reviewer else panel) + f"&section={section}&after={after}&through={through}"
        found = {}
        checked = 0
        for _ in range(4):
            page = report(actor, path)
            if page.counts is None or page.counts[1] != len(page.findings) or found.keys() & page.findings.keys():
                raise AssertionError("A reference was miscounted or appeared twice across the selected pages")
            found.update(page.findings)
            checked += page.counts[0]
            if "next" not in page.links:
                return checked, found
            path = urljoin(path, page.links["next"])
        raise AssertionError("The bounded synthetic reference scan never reached its end")

    fixture("account-seed")
    try:
        if "Postfach- und Gruppenverweise prüfen" not in admin.fetch(panel) or "Check mailbox and group references" not in reviewer.fetch(limited):
            raise AssertionError("A translated account reference panel is missing from native database maintenance")
        if scan("messages", n-1, n+5) != (6, {n+2: {"pm_sender"}}):
            raise AssertionError("The report treated an intentionally unassigned or anonymous message as damaged")
        if scan("folders", n-1, n+3) != (4, {n+2: {"mail_owner"}, n+3: {"mail_owner"}}):
            raise AssertionError("The report missed an unavailable personal-folder owner")
        if scan("defaults", n-1, n+204) != (205, {n+203: {"member_group"}, n+204: {"default_member"}}):
            raise AssertionError("Default group resolution did not require an existing approved membership")
        if scan("memberships", f"{n-1}.4294967295", f"{n+204}.4294967295") != (208, {f"{n+1}.{missing}": {"member_group"}}):
            raise AssertionError("A pending member or designated leader was reported as an invalid group assignment")
        if scan("memberships", f"{missing-1}.4294967295", f"{missing}.4294967295") != (1, {f"{missing}.71": {"member_user"}}):
            raise AssertionError("A membership referring to a missing account was overlooked")
        expected = {
            f"{n+2}.{n}.0.{missing}": {"pm_sender"},
            f"{n+5}.{n}.{n+1}.2": {"foreign_folder"},
            f"{n+5}.{n+1}.{missing}.2": {"mail_folder"},
            f"{n+5}.{n+2}.-17.2": {"mail_folder"},
            f"{n+5}.{missing}.0.2": {"mail_owner"},
            f"{n+5}.1.0.2": {"mail_owner"},
            f"{n+5}.{n+5}.0.{n}": {"mail_sender"},
            f"{n+6}.{n}.0.2": {"mail_message"},
        }
        checked, found = scan("mailboxes", f"{n}.4294967295.2147483647.4294967295", f"{n+6}.4294967295.2147483647.4294967295")
        if (checked, found) != (15, expected):
            raise AssertionError(f"Unexpected message, sender or folder references: {checked}, {found}")
        high = f"{n}.{n+202}.0.2"
        start = panel + f"&section=mailboxes&after={n}.{n-1}.0.2&through={high}"
        first = report(admin, start)
        if first.counts != (200, 0) or first.findings or "next" not in first.links:
            raise AssertionError("A single mass message or an identical mailbox reference broke page sizing")
        next_link = urljoin(start, first.links["next"])
        query = parse_qs(urlsplit(next_link).query)
        if query.get("after") != [f"{n}.{n+199}.0.2"] or query.get("through") != [high]:
            raise AssertionError("A mailbox bookmark did not preserve the last recipient and upper bound")
        # Earlier deliveries may disappear or new ones arrive between two visits to the ACP.
        fixture("account-deliver")
        if report(admin, next_link).counts != (3, 0) or scan("mailboxes", high, f"{n}.{n+204}.0.2") != (1, {}):
            raise AssertionError("Returning to the old bookmark unexpectedly repeated or skipped a recipient")
        fixture("account-verify")
    finally:
        fixture("account-restore")
