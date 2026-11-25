"""Exercise retained announcement schedules and the ordinary first-post form."""
from html import unescape
import time
from urllib.parse import urljoin

from http_board import FormFields, Page


def exercise(owner, check):
    def editor(path):
        page = owner.fetch(path)
        if page.count('name="topic_time_limit"') != 1:
            raise RuntimeError("The first-post editor must show exactly one duration field")
        return page, FormFields(page, "postform").values

    def submit(path, page, changes):
        action = Page(page).forms["postform"][0]
        fields = FormFields(page, "postform").values
        fields.update(changes)
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return owner.fetch(urljoin(urljoin(owner.base, path), unescape(action)), fields)

    check("paused")
    if "Duration fixture 701" not in owner.fetch("viewtopic.php?t=701"):
        raise RuntimeError("Founder could not read the scheduled announcement")
    check("resume")
    owner.fetch("viewtopic.php?t=701")
    check("sweep")
    for topic, expected in ((702, "7"), (703, "-1"), (704, "80000")):
        path = f"posting.php?mode=edit&p={topic}"
        if editor(path)[1]["topic_time_limit"] != expected:
            raise RuntimeError("The first-post editor changed a converted duration")
    path = "posting.php?mode=edit&p=704"
    page, _ = editor(path)
    changes = dict(topic_type="2", topic_time_limit="-1", subject="Duration fixture 704", message="Unbefristeter Aushang")
    preview = submit(path, page, changes | {"preview":"1"})
    if FormFields(preview, "postform").values.get("topic_time_limit") != "-1":
        raise RuntimeError("Preview lost the permanent announcement duration")
    submit(path, preview, changes | {"post":"1"})
    check("edited")
    conflict = submit(path, page, changes | {"topic_time_limit":"21", "post":"1"})
    if "Art oder Dauer des Themas wurde inzwischen geändert" not in conflict:
        raise RuntimeError("A second editor could replace a more recent announcement schedule")
    path = "posting.php?mode=post&f=7"
    page, fields = editor(path)
    if fields["topic_time_limit"] != "0":
        raise RuntimeError("A new announcement did not offer the board default")
    submit(path, page, dict(topic_time_limit="0", topic_type="2", subject="Ankündigung mit Laufzeit", message="Ein neuer Aushang", post="1"))
    check("posted")
