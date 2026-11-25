"""Manage a specific account through the ordinary native ACP permission module."""
import time
from urllib.parse import urljoin

from http_board import FormFields, Page, admin_url


def manage(owner, junior, junior_entry, fixture):
    fixture("operators")
    panel = admin_url(owner) + "&i=-phpbb2plus-community-acp-delegations_module&mode=manage"
    target = panel + "&account=91"
    lookup, fields = Page(owner.fetch(panel)).forms["jr-lookup"]
    first = owner.fetch(urljoin(panel, lookup), {**fields, "username": "JuniorFixture"})
    if "<h2>JuniorFixture</h2>" not in first or "admin_smilies.php" not in first or "Manuelle Prüfung erforderlich" not in first:
        raise RuntimeError("Account selection or the original conversion decisions are missing")
    original = Page(first, checked=True).forms["jr-manage"][1]["grant[]"]
    original_note = FormFields(first, "jr-manage").values["note"]
    if "keine ausreichende Berechtigung" not in owner.fetch(panel + "&account=2", expected_status=403):
        raise RuntimeError("A permission manager could edit its own access")
    private_note = "Interner Vermerk <nur intern> & 😀"

    def save(page, rights, note, visible=False, url=target):
        action, hidden = Page(page, checked=True).forms["jr-manage"]
        hidden.update(save="1", note=note)
        hidden.pop("grant[]", None)
        hidden.pop("visible", None)
        if rights:
            hidden["grant[]"] = list(rights)
        if visible:
            hidden["visible"] = "1"
        time.sleep(max(0, int(hidden["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(url, action), hidden)

    def denied_module(url):
        if "Module not accessible" not in junior.fetch(url, expected_status=503):
            raise RuntimeError("The delegated session opened an unassigned module")

    denied_module(junior_entry + "&i=-phpbb2plus-community-acp-delegations_module&mode=manage&account=91")
    changed = save(first, ["a_ranks"], private_note)
    if "Die Delegation wurde gespeichert." not in changed or "Fixture &lt;only&gt; &amp; 😀" not in changed:
        raise RuntimeError("The ACP lost the active delegation or the original source note")
    if "Interner Vermerk" in junior.fetch(junior_entry) or 'id="acp_ranks"' not in junior.fetch(junior_entry + "&i=ranks&mode=ranks"):
        raise RuntimeError("A private note was disclosed or a new grant did not reach the signed-in member")
    denied_module(junior_entry + "&i=words&mode=words")
    stale = save(first, original, "Discarded old form", True)
    if "zwischenzeitlich geändert" not in stale or "Discarded old form" in stale:
        raise RuntimeError("A stale ACP edit replaced a newer delegation")
    fixture("never")
    try:
        denied = save(owner.fetch(target), ["a_ranks", "a_words"], private_note)
        if "lässt sich diesem Konto nicht zuweisen" not in denied or "natives „Nie“" not in denied:
            raise RuntimeError("The editor did not retain the member’s explicit native denial")
        changed = save(owner.fetch(target), [], "Keine aktiven Aufgaben")
        if "Die Delegation wurde gespeichert." not in changed:
            raise RuntimeError("Revoking the final module failed")
        fixture("recheck")
        if "You are not authorised to administer this board." not in junior.fetch(junior_entry, expected_status=403):
            raise RuntimeError("The revoked account retained the ACP in its existing session")
    finally:
        fixture("reset")
    restored = save(owner.fetch(target), original, "0", True)
    if 'white-space: pre-wrap">0</p>' not in junior.fetch(junior_entry):
        raise RuntimeError("The reactivated member cannot read the updated personal note")
    save(restored, original, original_note, True)
    new = panel + "&account=87"
    visitor = owner.fetch(new)
    if "Ursprünglicher Konvertierungsbericht" in visitor or "Delegation wurde gespeichert" in visitor:
        raise RuntimeError("An account without a Junior Admin source row gained a historical record")
    created = save(visitor, ["a_names"], "Neue Zuweisung", url=new)
    if "Die Delegation wurde gespeichert." not in created or "a_names" not in Page(created, checked=True).forms["jr-manage"][1]["grant[]"]:
        raise RuntimeError("An ordinary existing member could not receive a scoped delegation")
    save(created, [], "", url=new)
    fixture("audit")
