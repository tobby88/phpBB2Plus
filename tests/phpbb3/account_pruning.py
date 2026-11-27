"""Use the five Plus activity reports and retain a selected author's post through native account deletion."""
from html import unescape
import json
import os
from pathlib import Path
import re
from urllib.parse import urlencode, urljoin

from delegation import authenticate
from http_board import FormFields, Page, login, submit_form


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")

    def phase(step):
        return php(fixture, target, step, cwd=board, capture=True)

    ids = json.loads(phase("prepare"))
    mode = "&i=-phpbb2plus-community-acp-user_pruning_module&mode=manage"
    names = {value: key for key, value in ids.items()}
    try:
        actor, entry = authenticate(base, "PruneFixtureOperator")
        remembered = json.loads(phase("session_window"))
        url = entry + mode
        start = actor.fetch(url)
        def listings(rule, days=None):
            form = "account-prune-rule-" + str(rule)
            action, _ = Page(start).forms[form]
            fields = FormFields(start, form).values
            fields["after"] = str(min(ids.values()) - 1)
            if days is not None:
                fields["days"] = str(days)
            path = urljoin(url, action) + "?" + urlencode(fields)
            seen = {}
            while path:
                offer = actor.fetch(path)
                for found in re.findall(r'data-prune-user="([0-9]+)"', offer):
                    key = names.get(int(found))
                    if key in seen:
                        raise RuntimeError("Duplicate account while paging through the activity preview")
                    if key:
                        seen[key] = offer
                next_link = re.search(r'<a rel="next" href="([^"]+)"', offer)
                path = urljoin(path, unescape(next_link.group(1))) if next_link else None
            return seen

        if 'id="account-prune-review"' in start or "Prune accounts by activity" not in start:
            raise RuntimeError("The initial view must require a filter")
        for rule in range(5):
            days = 173 + rule
            current = actor.fetch(url + f"&rule={rule}&days={days}")
            legacy = actor.fetch(f"delete_users.php?mode=prune_{rule}&days={days}")
            scope = r"<h2>(.*?)</h2>\s*<p>(.*?)</p>"
            if (not re.search(scope, current, re.S) or re.findall(scope, legacy, re.S) != re.findall(scope, current, re.S)
                    or re.findall(r'name="accounts\[\]" value="(\d+)"', legacy) != re.findall(r'name="accounts\[\]" value="(\d+)"', current)
                    or "account-prune-delete" in Page(legacy).forms):
                raise RuntimeError("An old inactivity bookmark failed to open the original rule and age for review")
        if [FormFields(start, f"account-prune-rule-{i}").values.get("days") for i in range(5)] != ["240", "240", "240", "120", "360"]:
            raise RuntimeError("Original pruning defaults missing")
        reports = [listings(i) for i in range(5)]
        never = {"Never", "Pending", "Manual", "NoKey"}
        visible = {"Never", "Pending", "Manual", "NoKey", "RateEqual", "RateLow", "Recent", "Dormant", "Author"}
        wanted = (never | {"Recent", "Dormant"}, never, {"Pending"}, never | {"RateEqual", "RateLow", "Dormant", "Author"},
                  {"RateLow", "Recent", "Dormant", "Author"})
        for i, report in enumerate(reports):
            protected = {"Founder", "Technical", "Operator", "Junior", "WithAdminOption", "AdminGroup", "PendingWithPosts", "Young"}
            if report.keys() & protected or report.keys() & visible != wanted[i]:
                raise RuntimeError(f"Wrong eligibility for original activity rule {i}: {sorted(report.keys() & (visible | protected))}")
        if "Grace" in reports[0] or ("Remembered" in reports[0]) == remembered or "Expired" not in reports[0]:
            raise RuntimeError("Native normal and remembered session lifetimes did not govern account eligibility")
        if "Page16" not in reports[0] or reports[0]["Never"] == reports[0]["Page16"] or "Young" not in listings(0, 1):
            raise RuntimeError("Activity preview pagination or configurable registration cutoff failed")
        if 'name="accounts[]"' in start or "accounts[]" in FormFields(reports[0]["Never"], "account-prune-review").values:
            raise RuntimeError("Accounts should require explicit selection")
        def review(offer, chosen):
            return submit_form(actor, url, offer, "account-prune-review", {"accounts[]": [str(ids[key]) for key in chosen], "notify": None})

        def finish(page):
            return submit_form(actor, url, page, "account-prune-delete", {"confirm_delete": "1"})

        chosen = ["Never", "SignsIn", "Promoted", "PostsAgain"]
        original_offer = reports[0]["Never"]
        check = review(original_offer, chosen)
        if set(re.findall(r'data-prune-user="([0-9]+)"', check)) != {str(ids[key]) for key in chosen}:
            raise RuntimeError("Confirmation included unselected accounts")
        if "account-prune-delete" not in check:
            raise RuntimeError("Explicit account deletion confirmation is missing")
        actor.fetch(url + "&step=delete&confirm_delete=1")
        phase("intact")
        phase("revoke")
        actor.fetch("delete_users.php?mode=prune_0&days=240", expected_status=403)
        if "Module not accessible" not in submit_form(actor, url, check, "account-prune-delete", {"confirm_delete": "1"}, status=503):
            raise RuntimeError("Revoked user deletion permission was ignored")
        phase("intact")
        phase("allow")
        actor, entry = authenticate(base, "PruneFixtureOperator")
        url = entry + mode
        if 'id="prune-result"' in finish(check):
            raise RuntimeError("The replacement ACP session could submit a confirmation from the previous session")
        offer = actor.fetch(url + f"&rule=0&days=240&after={min(ids.values()) - 1}")
        confirmation = review(offer, chosen)
        login(base, "PruneFixtureSignsIn", os.environ["PLUS_TEST_MODERN_PASSWORD"])
        phase("change")
        result = finish(confirmation)
        if "Accounts deleted: 1. Accounts skipped: 3." not in result or "PruneFixtureNever" not in result:
            raise RuntimeError("A changed activity, newly authorised account or current session was not rechecked")
        if "Accounts deleted: 0. Accounts skipped: 4." not in finish(confirmation):
            raise RuntimeError("Revisiting a confirmed selection did not safely skip its original accounts")
        author = actor.fetch(url + f"&rule=4&days=360&after={ids['Author'] - 1}")
        if "Accounts deleted: 1. Accounts skipped: 0." not in finish(review(author, ["Author"])):
            raise RuntimeError("Account with posts was not removed after explicit review")
        phase("complete")
        phase("german")
        if "Benutzerkonten nach Aktivität bereinigen" not in actor.fetch(url):
            raise RuntimeError("German ACP registration missing")
    finally:
        phase("clean")
