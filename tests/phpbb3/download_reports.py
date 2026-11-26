"""Read paFileDB statistics, dated lists and rankings as viewers with different category grants."""
import re
from html import unescape
from urllib.parse import urljoin
from http_board import Browser, Page, submit_form


def browse(base, member, ordinary, verify):
    root = "app.php/downloads"
    stats, top = root + "/stats", root + "/toplist"
    guest = Browser(base)

    def figures(page):
        return {name: int(value) for name, value in re.findall(r'<dd id="dl-stat-([a-z_]+)">(\d+)(?: / 10)?</dd>', page)}

    def clients(page, family):
        row = re.search(r'<th scope="row">' + family + r'</th><td>(\d+)</td><td>(\d+)</td>', page)
        return tuple(map(int, row.groups()))

    verify("open")
    baseline = guest.fetch(stats)
    original, old_os = figures(baseline), clients(baseline, "Unix")
    verify("seed")
    verify("calendar")
    home = guest.fetch(root)
    for route, label in (("stats", "Download-Statistik"), ("toplist", "Download-Bestenlisten")):
        if not re.search(r'<a href="[^"]*/downloads/' + route + r'(?:\?[^"]*)?"[^>]*><img [^>]*alt="' + label + r'"></a>', home):
            raise RuntimeError("The guest did not find the labelled report link: " + route)
    guest_stats = guest.fetch(stats)
    now = figures(guest_stats)
    if (now["files"] != original["files"] + 3 or now["downloads"] != original["downloads"] + 177000 or
            now["categories"] != original["categories"] + 1 or clients(guest_stats, "Unix") != (old_os[0], old_os[1] + 3) or
            "Archiv &amp; Übersicht" not in guest_stats or any(s in guest_stats for s in ("Club record", "Personal record", "Waiting record"))):
        raise RuntimeError("The statistics mixed hidden files, pending submissions or ratings into the public totals")
    if "Personal record" not in member.fetch(stats) or "Highest rated file" not in member.fetch(stats):
        raise RuntimeError("The English statistics ignored an active personal or group grant")
    downloads = top + "?mode=downloads"
    page = guest.fetch(downloads)
    if ("Archiv &amp; Übersicht" not in page or "Recent sample" in page or "Gast" not in page or
            any(name in page for name in ("Club record", "Personal record", "Waiting record"))):
        raise RuntimeError("The default ranked length, historical display name, or access scope changed")
    first = guest.fetch(downloads + "&most_num=3")
    second = guest.fetch(downloads + "&most_num=3&start=2")
    if ("Recent sample" not in first or "Old sample" in first or "Old sample" not in second or "Recent sample" in second):
        raise RuntimeError("The ranked window or pagination repeated or exposed the wrong file")
    rated = guest.fetch(top + "?mode=rating&most_type=per&most_num=1")
    if "Archiv &amp; Übersicht" not in rated or "9,5 / 10" not in rated or "Recent sample" in rated or "Old sample" in rated:
        raise RuntimeError("Percentage rankings included an unrated file or lost the per-file average")
    private = member.fetch(downloads + "&most_num=3")
    if "Club record" not in private or "Personal record" not in private or "Waiting record" in private:
        raise RuntimeError("Rankings discarded authorized group files or listed an unapproved file")
    dates = guest.fetch(top + "?mode=newest&days=14")
    links = re.findall(r'<td><a href="([^"]*selected_date=[^"]+)">[^<]+</a></td><td>(\d+)</td>', dates)
    if len(links) != 14 or not any("Recent sample" in guest.fetch(urljoin(base, unescape(url))) for url, total in links if int(total)):
        raise RuntimeError("A recent file could not be reached from its visible local-day count")
    dated = guest.fetch(top + "?mode=newest&selected_date=1136034000")
    if "Archiv &amp; Übersicht" not in dated or any(name in dated for name in ("Club record", "Personal record", "Waiting record")):
        raise RuntimeError("The selected historical day did not preserve the result visibility")
    # Exercise ordinary downloads and votes without reusing a converted member's vote or file.
    visitor = Browser(base)
    visitor.opener.addheaders = [("User-Agent", "Mozilla/5.0 (Linux; Android 13) Chrome/109.0 EdgA/109.0")]
    item = root + "/file/10743"
    page = visitor.fetch(item)
    if not visitor.fetch(item + "/get/0", raw=True):
        raise RuntimeError("The report fixture could not download its published file")
    submit_form(visitor, urljoin(base, item), page, "dl-rate", {"points": "7"})
    verify("native")
    verify("restricted")
    if "/downloads/stats" in guest.fetch(root) or "/downloads/toplist" in guest.fetch(root):
        raise RuntimeError("The catalogue retained a hidden report link")
    if "login" not in Page(guest.fetch(top + "?mode=rating&most_num=25")).forms:
        raise RuntimeError("Opening the restricted report did not offer the native login")
    ordinary.fetch(stats)
    ordinary.fetch(top, expected_status=403)
    member.fetch(top)
    verify("restore")
