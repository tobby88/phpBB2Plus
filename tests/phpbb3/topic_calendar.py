"""Read multi-day events, restricted topics and private birthdays through the calendar."""
from http_board import Browser
import re


def browse(base, founder, member):
    guest = Browser(base)
    day = "app.php/calendar?view=day&date=2008-03-31"
    month = "app.php/calendar?date=2008-03-01"
    private = re.compile(r'viewtopic\.php\?t=7(?:&|"|#)')
    public = re.compile(r'viewtopic\.php\?t=1(?:&|"|#)')
    for actor, permitted in ((guest, False), (member, False), (founder, True)):
        for url in (day, month, "calendar.php?start=20080301", "calendar_scheduler.php?d=1206964800"):
            page = actor.fetch(url)
            if not public.search(page) or bool(private.search(page)) != permitted:
                raise RuntimeError("The calendar lost a multi-day event or ignored a forum permission")
    guest.fetch(day + "&forum=7", expected_status=404)
    if not private.search(founder.fetch(day + "&forum=7")) or public.search(founder.fetch(day + "&forum=7")):
        raise RuntimeError("Forum-filtered calendar includes unrelated events")
    guest.fetch("app.php/calendar?date=2008-02-30", expected_status=404)
    if not public.search(guest.fetch("calendar_scheduler.php?mode=hour&d=1206964800&fid=f1")):
        raise RuntimeError("Historical scheduler link did not select the matching hour")
    birthdays = "app.php/calendar?view=day&date=2009-03-01"
    if 'data-cal-birthday' in guest.fetch(birthdays) or 'data-cal-birthday' not in member.fetch(birthdays):
        raise RuntimeError("The calendar lost the historical leap-day rule or guest birthday privacy")
    if "/calendar" not in guest.fetch("app.php/portal"):
        raise RuntimeError("The portal lacks calendar navigation")
