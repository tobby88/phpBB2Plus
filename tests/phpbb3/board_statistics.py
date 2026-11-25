"""Exercise imported Statistics MOD modules with disjoint native audiences."""
from html import unescape
from http.client import HTTPConnection
import re
import shutil
from urllib.parse import urljoin, urlsplit

from attachments import GIF
from http_board import Browser, submit_form
from legacy_links import link
from profile_media import SMILE


def source_modules(source, target):
    directory = target / "stat_modules"
    for name in ("forum_index", "most_active_topics", "most_viewed_topics", "top_posters",
                 "site_hist_week_top_posters", "site_hist_month_top_posters", "top_smilies",
                 "most_used_languages", "top_attachments", "admin_statistics"):
        (directory / name).mkdir(parents=True, exist_ok=True)
        for file in ("module.php", "module.tpl", "info.txt"):
            shutil.copyfile(source / "stat_modules" / name / file, directory / name / file)


def panels(body):
    return {int(key): unescape(contents) for key, contents in re.findall(r'<section class="panel" id="([0-9]+)">(.*?)</section>', body, re.S)}


def languages(panel):
    return {name.strip(): int(amount) for name, amount in re.findall(r'<tr><td>\d+</td><td>([^<>]+)</td><td>(\d+)</td>', panel)}


def smiley_count(panel):
    found = re.search(r':pf:\s*</td><td>([0-9]+)</td>', panel)
    return int(found.group(1)) if found else 0


def check_smilies(guest, member, owner, path, baseline, public, ordinary):
    if (smiley_count(public[21]) != baseline[0] + 4 or smiley_count(ordinary[21]) != baseline[1] + 8
            or '<meter ' not in ordinary[21] or ':pf2:' in ordinary[21]):
        raise RuntimeError(f"Visible parsed aliases, disabled posts, legacy markers or private forums were counted incorrectly: {baseline}, {public[21]}, {ordinary[21]}")
    image = re.search(r'<img class="smilies" src="([^"]+)"', ordinary[21])
    if not image or member.fetch(urljoin(urljoin(member.base, path), image.group(1)), raw=True) != SMILE:
        raise RuntimeError("The statistics image did not open the converted local smiley")
    edit = "posting.php?mode=edit&p=975613"
    submit_form(owner, edit, owner.fetch(edit), "postform",
                {"message": "Fixture :pf: :pf2: [code]:pf:[/code]", "disable_smilies": None, "post": "1"})
    if smiley_count(panels(guest.fetch(path))[21]) != baseline[0] + 6:
        raise RuntimeError("A native edit with two displayed aliases and a literal code example was not counted immediately")


def check_operations(public, ordinary, private):
    if ordinary[24] != ordinary[25] or private[24] != private[25] or private[24] != private[26]:
        raise RuntimeError("The same administrative statistics changed between equivalent registrations")
    for panel in (public[24], ordinary[24], private[24]):
        rows = re.findall(r'<tr><th scope="row">([^<]+)</th><td>(.*?)</td></tr>', panel, re.S)
        if (len(rows) not in (14, 16) or ('/memberlist.php?mode=viewprofile' in panel) != (len(rows) == 16)
                or "87654" not in dict(rows).values() or not any("2015" in value for _, value in rows)
                or any("NOT_AVAILABLE" in value or "PLUS_STATS_" in label for label, value in rows)):
            raise RuntimeError(f"Missing operating statistics, unprotected member information or lost online record: {rows}")
        if not any(re.match(r'[\d,.]+\s*(?:MiB|MB|KiB|KB)', value, re.I) for _, value in rows):
            raise RuntimeError("The source-scoped database size was not displayed")


def check_files(public, ordinary, private, member, path, fixture):
    files = ordinary[23]
    if ("Stats-Public.gif" not in files or "Stats-Scoped-Private.fstat" not in files or "Stats-Scoped-Private.fstat" in public[23]
            or "Fixture & Grüße" not in files or "/viewtopic.php?p=975610#p975610" not in files):
        raise RuntimeError(f"Native attachment scope, comments or parent links changed: {files}")
    hidden = ("Stats-Scoped-Public.fstat", "Stats-Mail.gif", "Stats-Orphan.gif", "Stats-Password.gif",
              "Stats-Deleted.gif", "Stats-Moved.gif", "Stats-Case.GIF")
    if any(name in result[23] for result in (public, ordinary, private) for name in hidden):
        raise RuntimeError("A protected, unrelated or disabled file reached the attachment ranking")
    match = re.search(r'<a href="([^"]*download/file\.php\?id=975901[^"]*)">Stats-Public\.gif</a>', files)
    if not match or member.fetch(match.group(1), raw=True) != GIF:
        raise RuntimeError("The ranked file does not open its real native attachment")
    fixture("hide-downloads")
    try:
        narrowed = panels(member.fetch(path))
        if "Stats private" not in narrowed[11] or "Stats-Scoped-Private.fstat" in narrowed[23] or "Stats-Public.gif" not in narrowed[23]:
            raise RuntimeError("File rankings did not respect a download denial independent of read access")
    finally:
        fixture("restore-downloads")


def browse(base, admin, member, fixture):
    guest = Browser(base)
    path = "app.php/statistics"
    initial = panels(guest.fetch(path))
    before = languages(initial[22])
    baseline = smiley_count(initial[21]), smiley_count(panels(member.fetch(path))[21])
    fixture("setup")
    try:
        fixture("extension-cache")
        link(base, "statistics.php", path, {})
        if "/statistics" not in guest.fetch("portal.php"):
            raise RuntimeError("The portal no longer links to board statistics")
        public = panels(guest.fetch(path))
        ordinary = panels(member.fetch("statistics.php"))
        private = panels(admin.fetch(path))
        if (list(public) not in ([16, 11, 21, 22, 23, 24], [16, 11, 13, 21, 22, 23, 24])
                or list(ordinary) != [16, 11, 13, 14, 12, 21, 22, 23, 24, 25] or list(private) != [16, 11, 13, 14, 12, 15, 21, 22, 23, 24, 25, 26]):
            raise RuntimeError(f"Wrong statistics audiences or active registrations: {list(public)}, {list(ordinary)}, {list(private)}")
        if ("Stats public & Grüße" not in public[11] or "Stats private" in public[11] or "Stats private" not in ordinary[11]
                or public[11].count('<tr><td>') != 2 or "StatsPrivate" not in ordinary[14] or "StatsPublic" not in ordinary[14]):
            raise RuntimeError("The statistics ranking changed its forum scope, member identities or result limit")
        for visible in (public, ordinary, private):
            text = "".join(visible.values())
            if any(marker in text for marker in ("Stats password", "StatsPassword", "Stats hidden topic", "Stats removed first post", "Stats moved", "StatsDeleted", "StatsDormant")):
                raise RuntimeError("A statistics module exposed a hidden post, forum or inactive account")
        if "/memberlist.php?mode=viewprofile" not in ordinary[14] or "/viewtopic.php?t=" not in public[11]:
            raise RuntimeError("A ranking lost its native profile or topic target")
        after = languages(public[22])
        if (not before or not after or sum(after.values()) != sum(before.values()) + 4
                or any(languages(result[22]) != after or "<a href=" in result[22] for result in (public, ordinary, private))):
            raise RuntimeError(f"Language totals included an inactive account, linked a profile or varied by viewer: {before} / {after}")
        check_files(public, ordinary, private, member, path, fixture)
        check_operations(public, ordinary, private)
        check_smilies(guest, member, admin, path, baseline, public, ordinary)
        fixture("hide-profiles")
        try:
            member.fetch("app.php/ranks", expected_status=403)
            narrowed = panels(member.fetch(path))
            if list(narrowed) != [16, 11, 12, 21, 22, 23, 24, 25] or "/memberlist.php?mode=viewprofile" in narrowed[24] or narrowed[24].count('<th scope="row">') != 14:
                raise RuntimeError("A reader denied native profile access received a member ranking")
        finally:
            fixture("restore-profiles")
        def view_count():
            body = guest.fetch(path)
            return int(re.search(r"(?:Statistics page views|Aufrufe der Statistikseite): ([0-9]+)", body).group(1))

        before_head = view_count()
        origin = urlsplit(base)
        conn = HTTPConnection(origin.hostname, origin.port, timeout=30)
        try:
            conn.request("HEAD", "/" + path)
            response = conn.getresponse()
            if response.status != 200 or "no-store" not in response.getheader("Cache-Control", ""):
                raise RuntimeError("Personal board statistics could be reused by a shared cache")
        finally:
            conn.close()
        if view_count() != before_head + 1:
            raise RuntimeError("A HEAD request changed the historical statistics page view counter")
    finally:
        fixture("cleanup")
        fixture("extension-cache")
