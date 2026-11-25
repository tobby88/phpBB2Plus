"""Exercise imported Statistics MOD modules with disjoint native audiences."""
from html import unescape
from http.client import HTTPConnection
import re
import shutil
from urllib.parse import urlsplit

from attachments import GIF
from http_board import Browser
from legacy_links import link


def source_modules(source, target):
    directory = target / "stat_modules"
    for name in ("forum_index", "most_active_topics", "most_viewed_topics", "top_posters",
                 "site_hist_week_top_posters", "site_hist_month_top_posters", "top_smilies",
                 "most_used_languages", "top_attachments"):
        (directory / name).mkdir(parents=True, exist_ok=True)
        for file in ("module.php", "module.tpl", "info.txt"):
            shutil.copyfile(source / "stat_modules" / name / file, directory / name / file)


def panels(body):
    return {int(key): unescape(contents) for key, contents in re.findall(r'<section class="panel" id="([0-9]+)">(.*?)</section>', body, re.S)}


def languages(panel):
    return {name.strip(): int(amount) for name, amount in re.findall(r'<tr><td>\d+</td><td>([^<>]+)</td><td>(\d+)</td>', panel)}


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
    before = languages(panels(guest.fetch(path))[22])
    fixture("setup")
    try:
        fixture("extension-cache")
        link(base, "statistics.php", path, {})
        if "/statistics" not in guest.fetch("portal.php"):
            raise RuntimeError("The portal no longer links to board statistics")
        public = panels(guest.fetch(path))
        ordinary = panels(member.fetch("statistics.php"))
        private = panels(admin.fetch(path))
        if (list(public) not in ([16, 11, 22, 23], [16, 11, 13, 22, 23]) or list(ordinary) != [16, 11, 13, 14, 12, 22, 23]
                or list(private) != [16, 11, 13, 14, 12, 15, 22, 23]):
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
        fixture("hide-profiles")
        try:
            if list(panels(member.fetch(path))) != [16, 11, 12, 22, 23]:
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
