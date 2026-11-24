"""Read multi-day events, restricted topics and private birthdays through the calendar."""
from http_board import Browser, Page
from html import unescape
import re
import time
from urllib.parse import urljoin


def browse(base, founder, member, pending):
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
    edit_event(base, founder, member, pending)


def edit_event(base, owner, member, pending):
    new = "posting.php?mode=post&f=1"
    if "plus_cal_present" in pending.fetch(new) or "plus_cal_present" not in member.fetch(new):
        raise RuntimeError("The native post editor did not respect the imported calendar grant")
    title = "Fiktiver Kalendertermin"
    subject = dict(subject=title, message="Lokaler Test des Kalendereditors.", post="1")

    def form(path):
        action, fields = Page(owner.fetch(path)).forms["postform"]
        fields.update(subject, plus_cal_start_date="2008-04-07", plus_cal_start_time="", plus_cal_end_date="2008-04-08", plus_cal_end_time="")
        return urljoin(urljoin(base, path), unescape(action)), fields

    def submit(action, fields):
        time.sleep(max(0, int(fields["creation_time"]) + 2.05 - time.time()))
        return owner.fetch(action, fields)

    action, fields = form(new)
    invalid = submit(action, dict(fields, plus_cal_end_date="2008-04-06"))
    if "Prüfe Datum und Uhrzeit" not in invalid:
        raise RuntimeError("Calendar validation did not return the first-post editor")
    submit(action, fields)
    agenda = "app.php/calendar?view=day&date=2008-04-"
    first = owner.fetch(agenda + "08")
    topic = re.search(r'href="([^"]*viewtopic\.php\?t=\d+[^"]*)"[^>]*>' + title + '<', first)
    if not topic or title in owner.fetch(agenda + "09"):
        raise RuntimeError("The native new-topic form did not store the inclusive all-day interval")
    topic_page = owner.fetch(unescape(topic.group(1)))
    edit = re.search(r'href="([^"]*posting\.php\?[^"]*mode=edit[^"]*)"', topic_page)
    if not edit or 'class="plus-cal-topic"' not in topic_page:
        raise RuntimeError("The calendar event is missing from its native topic")
    editor = unescape(edit.group(1))
    action, old = form(editor)
    change = dict(old, plus_cal_start_date="2008-04-10", plus_cal_start_time="12:15:22", plus_cal_end_date="")
    submit(action, change)
    if title in owner.fetch(agenda + "08") or title not in owner.fetch(agenda + "10"):
        raise RuntimeError("Editing the first post did not move the event")
    stale = submit(action, old)
    if "Termin wurde inzwischen geändert" not in stale:
        raise RuntimeError("A second editor could overwrite a later calendar edit")
    action, current = form(editor)
    submit(action, dict(current, plus_cal_remove="1"))
    if title in owner.fetch(agenda + "10") or title not in owner.fetch(unescape(topic.group(1))):
        raise RuntimeError("Removing the date did not preserve the forum topic")
