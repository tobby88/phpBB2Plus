"""Visit the ordinary board and its independent CrackerTracker administration pages."""
from html import escape
from urllib.error import HTTPError
from urllib.parse import urljoin, urlsplit
from urllib.request import Request

from delegation import authenticate
from http_board import Browser, FormFields, Page, admin_url, submit_form


def source_media(legacy):
    log = legacy / "ctracker/logfiles/logfile_blocklist.txt"
    log.parent.mkdir(parents=True, exist_ok=True)
    lines = ["1|||1700000000|||null|||null|||null|||null|||null|||rotated|||3500"]
    lines += [f"0|||170000000{n}|||/viewtopic.php?t=47|||https://example.invalid/notes?fixture=omitted"
              f"|||Archiv <Leser> {n}|||192.0.2.{n}|||reader.fixture.invalid" for n in (1, 2, 3)]
    log.write_text("\n".join(lines) + "\n", encoding="utf-8")


def visit(actor, agent, refused, path="index.php?ct_fixture=1"):
    address = urljoin(actor.base, path)
    if urlsplit(address).hostname not in ("127.0.0.1", "localhost"):
        raise ValueError("Use the disposable loopback board")
    try:
        with actor.opener.open(Request(address, headers={"User-Agent": agent}), timeout=15) as response:
            if refused or response.status != 200:
                raise RuntimeError("The configured request rule was not applied")
    except HTTPError as exc:
        text = exc.read().decode()
        if (not refused or exc.code != 403 or "no-store" not in exc.headers.get("Cache-Control", "")
                or not any(message in text for message in ("blocked by the board", "Zugriffsregeln dieses Boards"))):
            raise RuntimeError(f"Unexpected response to a normal loopback request ({exc.code}, {text[:150]})") from None


def browse(admin, member, verify):
    verify("converted")
    base = admin_url(admin)
    module = base + "&i=-phpbb2plus-community-acp-request_blocks_module&mode="
    rules = module + "rules"
    history = module + "history"
    settings = base + "&i=-phpbb2plus-community-acp-tracker_settings_module&mode=manage"
    config_form = "plus-tracker-settings"
    guest = Browser(admin.base)
    agent = "Archive Reader/1.0"
    original = admin.fetch(rules)
    if ("plus-ct-add" not in Page(original).forms or escape("Bücher & Ablage [1]?") not in original or
            not any(label in original for label in ("inaktiver Alteintrag", "inactive legacy entry"))):
        raise RuntimeError("The scoped rules page lost its imported patterns or state")
    entries = admin.fetch(history)
    if escape("Archiv <Leser> 3") not in entries or "3501" not in entries or "fixture=omitted" in entries or "plus-ct-add" in Page(entries).forms:
        raise RuntimeError("History was rendered in the wrong scope or included unneeded URL information")
    def setting(changes, form):
        return submit_form(admin, settings, form, config_form, {f"config[plus_ct_block_{key}]": val for key, val in changes.items()})
    initial_settings = admin.fetch(settings)
    values = FormFields(initial_settings, config_form).values
    if (values.get("config[plus_ct_block_enabled]"), values.get("config[plus_ct_block_limit]")) != ("0", "2"):
        raise RuntimeError("The imported policy was not visible in the global settings")
    visit(guest, agent, False)
    setting({"enabled": "1"}, initial_settings)
    enabled_settings = admin.fetch(settings)
    verify("matching")
    visit(guest, agent, True)
    verify("rotated")
    rotated_history = admin.fetch(history)
    setting({"logging": "0"}, enabled_settings)
    paused_settings = admin.fetch(settings)
    visit(member, agent, True, "ucp.php")
    verify("paused")
    verify("log-clearer")
    try:
        reader, read_acp = authenticate(admin.base, "CTLogReviewer")
        logs = read_acp + "&i=-phpbb2plus-community-acp-request_blocks_module&mode=history"
        confirmation = submit_form(reader, logs, reader.fetch(logs), "plus-ct-clear", {})
        verify("log-reader")
        submit_form(reader, logs, confirmation, "plus-ct-confirm", {"confirm_clear": "1"}, status=403)
        limited = reader.fetch(logs)
        if ("CrackerTracker blocked requests" not in limited or agent not in limited or
                "plus-ct-clear" in Page(limited).forms or "CrackerTracker blocking rules" in limited):
            raise RuntimeError("Read-only access to the English native log did not respect current permissions")
    finally:
        verify("log-done")
    invalid = submit_form(admin, rules, original, "plus-ct-add", {"entry": "Fixture*********"})
    if not any(label in invalid for label in ("höchstens 200 Bytes", "up to 200 bytes")):
        raise RuntimeError("A rule outside the source limits was accepted")
    page = submit_form(admin, rules, original, "plus-ct-add", {"entry": "Temporary Reader"})
    entry = next((key for key in Page(page).forms if key.startswith("plus-ct-delete-") and int(key.rsplit("-", 1)[1]) > 67), None)
    if not entry:
        raise RuntimeError("The ACP did not add a new rule alongside the imported identifiers")
    visit(guest, "Temporary Reader", True)
    stale = submit_form(admin, rules, original, "plus-ct-delete-61", {})
    if not any(label in stale for label in ("Sperrregeln haben sich geändert", "blocking rules have changed")):
        raise RuntimeError("An old rules form changed the current list")
    remaining = submit_form(admin, rules, page, entry, {})
    visit(guest, "Temporary Reader", False)
    setting({"logging": "1"}, paused_settings)
    old_clear = submit_form(admin, history, rotated_history, "plus-ct-clear", {})
    visit(guest, agent, True)
    stale = submit_form(admin, history, old_clear, "plus-ct-confirm", {"confirm_clear": "1"})
    if not any(label in stale for label in ("Protokoll hat sich geändert", "blocked requests have changed")):
        raise RuntimeError("A clear request discarded newer events without review")
    confirmed = submit_form(admin, history, admin.fetch(history), "plus-ct-clear", {})
    submit_form(admin, history, confirmed, "plus-ct-confirm", {"confirm_clear": "1"})
    verify("cleared")
    submit_form(admin, rules, remaining, "plus-ct-delete-61", {})
    visit(guest, agent, False)
    verify("replay")
