"""Review current organisation references on a converted test board without changing any forum record."""
from html.parser import HTMLParser
from concurrent.futures import ThreadPoolExecutor
from urllib.parse import parse_qs, urljoin, urlsplit
from delegation import authenticate
from http_board import admin_url
import account_references


class Report(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.counts = None
        self.findings = {}
        self.row = None
        self.links = {}
        self.refresh = None
        self.feed(body)

    def handle_starttag(self, tag, fields):
        attrs = dict(fields)
        name = attrs.get("id", "")
        if name == "plus-reference-summary":
            self.counts = (int(attrs["data-checked"]), int(attrs["data-affected"]))
        if tag == "tr" and "data-reference-id" in attrs:
            key = attrs["data-reference-id"]
            self.row = int(key) if key.isdecimal() else key
            self.findings[self.row] = set()
        if self.row is not None and "data-reason" in attrs:
            self.findings[self.row].add(attrs["data-reason"])
        if tag == "a" and name.startswith("plus-reference-"):
            self.links[name.removeprefix("plus-reference-")] = attrs["href"]
        if tag == "meta" and attrs.get("http-equiv", "").lower() == "refresh":
            self.refresh = attrs.get("content", "").partition("url=")[2]

    def handle_endtag(self, tag):
        if tag == "tr":
            self.row = None


def browse(base, admin, fixture):
    suffix = "&i=-phpbb2plus-community-acp-post_references_module&mode=check"
    panel = admin_url(admin) + suffix
    reviewer, home = authenticate(base, "ArcadeCategoryJunior")
    limited = home + suffix
    if Report(reviewer.fetch(limited, expected_status=503)).counts is not None:
        raise AssertionError("An unrelated ACP role could read the post reference report")
    accounts = panel.replace("mode=check", "mode=accounts")
    limited_accounts = limited.replace("mode=check", "mode=accounts")
    if Report(reviewer.fetch(limited_accounts, expected_status=503)).counts is not None:
        raise AssertionError("An unrelated ACP role could read private mailbox references")

    n = 630000
    fixture("seed")
    try:
        first = panel + f"&after={n-1}&through={n+13}&section=topics"
        body = admin.fetch(first)
        if "Beitrags- und Themenverweise prüfen" not in body:
            raise AssertionError("The German database panel does not expose the reference report")
        expected = {4: {"forum"}, 5: {"author"}, 6: {"empty_topic"}, 7: {"missing_target"},
                    8: {"self_target"}, 9: {"chained_target"}, 10: {"move_flag"}, 11: {"forum"}}
        topics = Report(body)
        if topics.counts != (14, 8) or topics.findings != {n+i: issues for i, issues in expected.items()}:
            raise AssertionError(f"The topic findings do not match the fixture: {topics.counts}, {topics.findings}")
        fixture("grant")
        english = reviewer.fetch(limited + f"&after={n-1}&through={n+13}&section=topics")
        if "Check post and topic references" not in english or "Author ID 639999 has no matching account." not in english:
            raise AssertionError("The native backup administrator could not review the English report")
        account_references.browse(admin, reviewer, accounts, limited_accounts, fixture, Report)
        start = panel + f"&after={n-1}&section=posts"
        natural = Report(admin.fetch(start))
        watermark = parse_qs(urlsplit(natural.links["next"]).query)["through"]
        if int(watermark[0]) < n+214:
            raise AssertionError("The report did not capture the last existing ID")
        manual = start + f"&through={n+214}"
        first_page = Report(admin.fetch(manual))
        if first_page.counts != (200, 0) or first_page.findings or first_page.refresh or "auto" not in first_page.links:
            raise AssertionError("An ordinary page should count 200 clean rows and wait for operator input")
        first_auto = Report(admin.fetch(manual + "&continue=1"))
        next_query = parse_qs(urlsplit(first_auto.refresh or "").query)
        if (next_query.get("after"), next_query.get("through"), next_query.get("continue")) != ([str(n+199)], [str(n+214)], ["1"]):
            raise AssertionError("The automatic scan did not retain its high-water ID and continue from the last inspected row")
        if "stop" not in first_auto.links or Report(admin.fetch(urljoin(manual, first_auto.links["stop"]))).refresh:
            raise AssertionError("The operator could not pause automatic checking on the same page")
        with_findings = Report(admin.fetch(panel + f"&section=posts&after={n+10}&through={n+214}&continue=1"))
        if not with_findings.findings or with_findings.refresh or "next" not in with_findings.links:
            raise AssertionError("Automatic checking skipped findings even though more records remained")
        last_body = admin.fetch(urljoin(manual, first_auto.refresh))
        final = Report(last_body)
        expected = {200: {"route"}, 201: {"topic"}, 202: {"author"}, 203: {"post_shadow"},
                    207: {"forum"}, 209: {"forum"}, 212: {"forum", "route"}, 214: {"route"}}
        if final.counts != (15, 8) or final.findings != {n+i: reasons for i, reasons in expected.items()} or final.refresh or "next" in final.links:
            raise AssertionError(f"The post findings do not match the fixture: {final.counts}, {final.findings}")
        for html in (body, english, last_body):
            if any(private in html for private in ("Private reference fixture title", "Private reference fixture text", "Private retained guest byline")):
                raise AssertionError("The structural report exposed private content or a historical byline")
        if Report(admin.fetch(panel + f"&section=topics&after={n+214}&through={n+214}")).counts != (0, 0):
            raise AssertionError("The empty range was not reported as complete")
        # Two signed-in operators can pause, resume or start afresh while new forum records arrive.
        high = int(watermark[0])
        resume = panel + f"&section=posts&after={high}&through={high}"
        with ThreadPoolExecutor(max_workers=1) as writer:
            added = writer.submit(fixture, "publish")
            if Report(reviewer.fetch(limited + f"&section=topics&after={n-1}&through={n+13}")).findings != topics.findings:
                raise AssertionError("Another operator's ongoing review changed the topic snapshot")
            added.result()
        if Report(admin.fetch(resume)).counts != (0, 0):
            raise AssertionError("Resuming an earlier scan included posts published after its upper bound")
        latest = Report(admin.fetch(panel + f"&section=posts&after={high}"))
        if latest.counts != (1, 1) or list(latest.findings.values()) != [{"author"}]:
            raise AssertionError("A fresh scan did not see the newly published record")
        fixture("verify")
    finally:
        fixture("restore")
    if Report(reviewer.fetch(limited, expected_status=503)).counts is not None:
        raise AssertionError("A revoked backup permission still opened the report")
    if Report(reviewer.fetch(limited_accounts + "&section=profiles", expected_status=503)).counts is not None:
        raise AssertionError("A revoked backup permission still exposed profile preferences")
