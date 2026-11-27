"""Check mailbox, membership and profile references on the existing maintenance fixture."""
from urllib.parse import parse_qs, urljoin, urlsplit


def browse(admin, reviewer, panel, limited, fixture, report_type):
    n = 640000
    missing = n+999

    def report(actor, path):
        body = actor.fetch(path)
        if any(marker in body for marker in ("HiddenRefOwner", "Private mailbox subject", "Private mailbox text", "Private mailbox folder",
                                             "Private fixture rank", "Private fixture style", "bcc_address")):
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
        if "Konten, Gruppen und Postfächer prüfen" not in admin.fetch(panel) or "Check accounts, groups and mailboxes" not in reviewer.fetch(limited):
            raise AssertionError("A translated account reference panel is missing from native database maintenance")
        preferences = {n+1: {"style_inactive"}, n+2: {"rank_automatic"}, n+3: {"rank_missing", "style_missing", "language_missing"},
                       n+4: {"language_files"}, n+5: {"language_unregistered"}, n+8: {"language_missing"}, n+204: {"style_missing"}}
        if scan("profiles", n-1, n+204) != (205, preferences) or scan("profiles", 0, 1) != (1, {}):
            raise AssertionError("Account preferences, board defaults, installed packs or the anonymous account were misclassified")
        german = admin.fetch(panel + f"&section=profiles&after={n}&through={n+5}")
        if "Rang, Sprache und Style" not in german or "wurde aber für die Auswahl im Profil deaktiviert" not in german or "Im Sprachverzeichnis fehlt common.php." not in german:
            raise AssertionError("Profile findings were not explained in German on the ACP page")
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
        # Forcing a single board style changes what is displayed, not what remains stored on each account.
        if scan("profiles", n-1, n+8) != (9, {record: issues for record, issues in preferences.items() if record <= n+8}):
            raise AssertionError("A board style override masked unresolved stored profile preferences")
        if report(admin, next_link).counts != (3, 0) or scan("mailboxes", high, f"{n}.{n+204}.0.2") != (1, {}):
            raise AssertionError("Returning to the old bookmark unexpectedly repeated or skipped a recipient")
        fixture("account-verify")
    finally:
        fixture("account-restore")
