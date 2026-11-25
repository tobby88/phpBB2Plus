"""Exercise imported Statistics MOD modules with disjoint native audiences."""
from html import unescape
from http.client import HTTPConnection
import re
import shutil
from urllib.parse import urlsplit

from http_board import Browser
from legacy_links import link


def source_modules(source, target):
    directory = target / "stat_modules"
    for name in ("forum_index", "most_active_topics", "most_viewed_topics", "top_posters",
                 "site_hist_week_top_posters", "site_hist_month_top_posters", "top_smilies"):
        (directory / name).mkdir(parents=True, exist_ok=True)
        for file in ("module.php", "module.tpl", "info.txt"):
            shutil.copyfile(source / "stat_modules" / name / file, directory / name / file)


def panels(body):
    return {int(key): unescape(contents) for key, contents in re.findall(r'<section class="panel" id="([0-9]+)">(.*?)</section>', body, re.S)}


def browse(base, admin, member, fixture):
    fixture("setup")
    try:
        guest = Browser(base)
        path = "app.php/statistics"
        link(base, "statistics.php", path, {})
        if "/statistics" not in guest.fetch("portal.php"):
            raise RuntimeError("The portal no longer links to board statistics")
        public = panels(guest.fetch(path))
        ordinary = panels(member.fetch("statistics.php"))
        private = panels(admin.fetch(path))
        if list(public) not in ([16, 11], [16, 11, 13]) or list(ordinary) != [16, 11, 13, 14, 12] or list(private) != [16, 11, 13, 14, 12, 15]:
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
        fixture("hide-profiles")
        try:
            if list(panels(member.fetch(path))) != [16, 11, 12]:
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
