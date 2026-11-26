"""Read the current and archived portal calendars through native phpBB searches."""
from datetime import date, timedelta
from html import unescape
import re
from urllib.parse import urljoin

from http_board import Browser, FormFields, submit_form


def exercise(base, founder, member, fixture):
    guest = Browser(base)
    portal = "app.php/portal"

    def widget(actor, path=portal):
        page = actor.fetch(path)
        panel = re.search(r'<section id="plus-mini-calendar".*?</section>', page, re.S)
        if not panel:
            raise RuntimeError("The portal mini-calendar is missing")
        return panel.group(0)

    def has(ok, reason):
        if not ok:
            raise RuntimeError(reason)

    def cell(panel, day):
        match = re.search(r'<td data-date="' + str(day) + r'"([^>]*)>(.*?)</td>', panel, re.S)
        return match.groups() if match else ("", "")

    def day_link(panel, day):
        return unescape(re.search(r'href="([^"]+)"', cell(panel, day)[1]).group(1))

    def results(page):
        return {int(n) for n in re.findall(r'>Calendar post ([0-9]+)</a>', page)}

    current = date.fromisoformat(fixture("seed").strip())
    try:
        live = widget(guest)
        has('aria-current="date"' in cell(live, current)[0] and "search_id=mini_cal" in cell(live, current)[1],
            "Today's date or its post search is missing")
        future = current + timedelta(days=1)
        future_page = widget(guest, portal + "?cal_month=" + future.strftime("%Y-%m"))
        has(cell(future_page, future)[1] and "href=" not in cell(future_page, future)[1], "A future day links to a post search")
        march = widget(guest, portal + "?cal_month=2024-03")
        has(re.search(r'<abbr[^>]+>Mo\w*\.?</abbr>', march) and "2024-02" in march and '2024-04' in march and
            re.search(r"<tr>\s*(?:<td>\s*</td>\s*){4}<td data-date=\"2024-03-01\"", march),
            "March no longer starts on Friday in the Monday-first seven-column grid")
        next_url = unescape(re.search(r'<a href="([^"]+)" rel="next"', march).group(1))
        has('data-date="2024-04-30"' in widget(guest, urljoin(base, next_url)), "Next month did not keep the user on the portal")
        last_month = current.replace(day=1) - timedelta(days=1)
        has('data-date="' + str(last_month) + '"' in widget(guest, "portal.php?month=-1") and "/calendar?" in march,
            "The old portal bookmark or full calendar link is missing")
        ids = lambda body: [int(value) for value in re.findall(r'data-cal-topic="(912[0-9]+)"', body)]
        has(ids(live) == [912001,912004,912005,912006,912007] and ids(march) == ids(live), "The five upcoming events depend on the browsed month or expose restricted events")
        has(ids(widget(founder))[:3] == [912001,912002,912003], "An authorized moderator cannot see its additional calendar topics")
        has("data-cal-birthday" not in live and "data-cal-birthday" in widget(member), "The portal ignored member-only birthday visibility")
        fixture("hide-birthdays")
        has("data-cal-birthday" not in widget(member), "The portal ignored the member's calendar preference")
        form = FormFields(widget(member), "plus-mini-new-event").values
        has(form.get("mode") == "post" and 'plus_cal_present' in member.fetch("posting.php?mode=post&f=" + form["f"]), "The calendar forum picker did not reach the native event editor")
        spring = day_link(march, date(2024, 3, 31))
        first = guest.fetch(spring)
        has(results(first) == {13,14} and "RetiredFixture" not in first and results(member.fetch(spring)) == {13,14} and
            "RetiredFixture" in member.fetch(spring), "A 23-hour day, post approval, or retired byline was incorrect")
        page2 = next(unescape(link) for link in re.findall(r'href="([^"]+)"', first) if 'start=2' in link and 'mini_cal' in link)
        has(results(guest.fetch(page2)) == {12} and "date=2024-03-31" in page2,
            "Paginating the March search changed its date or boundary")
        has(results(guest.fetch("search.php?search_id=mini_cal&d=1711839600")) == {13,14},
            "The original timestamp bookmark lost the local calendar date")
        has(not results(guest.fetch(spring + "&st=7")) and results(guest.fetch(spring + "&sd=a")) == {12,13},
            "The native result age or sort controls did not apply to the selected day")
        has(results(founder.fetch(spring)) == {14,24} and results(founder.fetch(spring + "&start=2")) == {22,23},
            "The native moderator's search skipped its restricted topic or pending post")
        autumn = day_link(widget(guest, portal + "?cal_month=2024-10"), date(2024, 10, 27))
        has(results(guest.fetch(autumn)) == {19,20} and results(guest.fetch(autumn + "&start=2")) == {17,18},
            "The 25-hour day lost a repeated hour or included a neighbouring date")
        guest.fetch("search.php?search_id=mini_cal&date=2024-02-30", expected_status=404)
        fixture("password")
        has(not results(guest.fetch(spring)) and not ids(widget(guest)), "A password-protected forum leaked calendar entries before admission")
        gate = "viewforum.php?f=1"
        submit_form(guest, gate, guest.fetch(gate), "login_forum", {"password": "calendar-local-fixture", "login": "1"})
        has(results(guest.fetch(spring)) == {13,14} and ids(widget(guest)) == ids(live) and
            not ids(widget(Browser(base))), "Unlocking a password forum affected the wrong calendar session")
    finally:
        fixture("cleanup")
