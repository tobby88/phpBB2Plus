"""Follow native MCP report views, closure/deletion and subsequent ordinary reporting."""
import json
import re
import time
from urllib.error import HTTPError
from urllib.parse import urljoin

from http_board import FormFields, Page, submit_form


def browse(admin, member, check):
    large = "18446744073709551615"
    old, private = json.loads(check("prepare"))

    def detail(report):
        return f"mcp.php?i=reports&mode=report_details&r={report}"

    def contains(page, *expected):
        for text in expected:
            if text not in page:
                title = re.search(r"<title>(.*?)</title>", page, re.S)
                raise RuntimeError(f"Missing Blue Card marker {text!r} on {title[1] if title else 'native page'}")

    def decide(report, action, page=None):
        url = detail(report)
        if page is None:
            page = admin.fetch(url)
        action_name = f"action[{action}]"
        contains(page, f'name="{action_name}"')
        action_url, _ = Page(page).forms["mcp_report"]
        fields = FormFields(page, "mcp_report").values
        fields[action_name] = "1"
        prompt = admin.fetch(urljoin(urljoin(admin.base, url), action_url), fields)
        contains(prompt, 'id="confirm"')
        action_url, _ = Page(prompt).forms["confirm"]
        fields = FormFields(prompt, "confirm").values
        fields["confirm"] = re.search(r'<input\b[^>]*name="confirm"[^>]*value="([^"]+)"', prompt)[1]
        if "creation_time" in fields:
            time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        admin.fetch(urljoin(urljoin(admin.base, url), action_url), fields)

    try:
        for path in ("mcp.php?i=main&mode=front", "mcp.php?i=reports&mode=reports&f=1",
                     "mcp.php?i=main&mode=post_details&p=746210"):
            contains(admin.fetch(path), large, "Zeitpunkt unbekannt", "Offene Meldungen im phpBB2-Plus-Altbestand")
        german = admin.fetch(detail(old))
        contains(german, large, "Zeitpunkt unbekannt", "Eine Kopie des Beitrags", "Current body 746210")
        byline = re.search(r'<div id="report".*?<p class="author">(.*?)</p>', german, re.S)
        if not byline or "Unbekannt" not in byline[1] or re.search(r"1970|Anonymous|\bGast\b", byline[1]):
            raise RuntimeError("The import attributed an unknown report to a guest or an epoch date")
        scoped_list = member.fetch("mcp.php?i=reports&mode=reports")
        contains(scoped_list, large, "Blue source 746210")
        if "Blue source 746220" in scoped_list:
            raise RuntimeError("The report queue included a forum outside the moderator's assignment")
        try:
            with member.opener.open(urljoin(member.base, detail(private)), timeout=30) as response:
                denied = response.read().decode("utf-8")
        except HTTPError as error:
            denied = error.read().decode("utf-8")
            if error.code not in (403, 404):
                raise RuntimeError("Unexpected private MCP response: " + " ".join(re.sub("<[^>]+>", " ", denied).split())[-800:]) from error
        if "Blue source 746220" in denied or 'id="mcp_report"' in denied:
            raise RuntimeError("Native report details were visible to a member outside the moderator audience")
        check("en")
        page = admin.fetch(detail(old))
        contains(page, "Time unknown", "Open reports in the phpBB2 Plus source: " + large,
                 "No copy of the post", "The reporters, dates and reasons")
        decide(old, "close", page)
        check("closed")
        contains(admin.fetch("mcp.php?i=reports&mode=reports_closed&f=1"), large, "Time unknown")
        for post in (746210, 746211):
            path = f"app.php/post/{post}/report"
            page = member.fetch(path)
            contains(page, 'id="report"')
            submit_form(member, path, page, "report", {"submit": "1", "notify": "0", "report_text": f"Native report {post}"})
        later, mixed = json.loads(check("native"))
        page = admin.fetch(detail(later))
        contains(page, "HistoricMember", "Native report 746210", "Current body 746210")
        if "Open reports in the phpBB2 Plus source" in page:
            raise RuntimeError("A later native report inherited the historical count")
        page = admin.fetch(detail(mixed))
        contains(page, "HistoricMember", "Native report 746211", "Open reports in the phpBB2 Plus source: 13", "Current body 746211")
        if "Time unknown" in page or "No copy of the post" in page:
            raise RuntimeError("Adding a source count replaced a native report's date or content")
        decide(private, "delete")
        check("deleted")
    finally:
        check("cleanup")
