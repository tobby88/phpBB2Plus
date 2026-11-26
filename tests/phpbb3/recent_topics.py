"""Read the portal's period-based topic list with native permissions and former-member bylines."""
from html import unescape
import re
from http_board import Browser
from legacy_links import link


def browse(base, member, verify):
    root = "app.php/topics/recent"
    guest = Browser(base)
    verify("seed")
    try:
        portal = guest.fetch("app.php/portal")
        if ("/topics/recent" not in portal or portal.count("data-forum=") != 2
                or "RecentFormerMember" in portal or 'data-forum="15"' in portal):
            raise RuntimeError("The portal link, short block size, or public topic visibility diverged")
        link(base, "recent.php?mode=yesterday", root, {"mode": "yesterday"})
        link(base, "recent.php", root, {"mode": "lastXdays", "amount_days": "9"}, method="POST",
             form={"mode": "lastXdays", "amount_days": "9"}, status=303)
        def pages(actor, route):
            opened = set()
            results = []
            while route:
                if route in opened:
                    raise RuntimeError("Recent topic pagination points back to an earlier page")
                opened.add(route)
                page = actor.fetch(route)
                results.append(page)
                following = re.search(r'<li class="arrow next">\s*<a[^>]* href="([^"]+)"', page)
                route = unescape(following[1]) if following else None
            return "".join(results)

        def ids(html):
            return [int(v) for v in re.findall(r'data-activity-topic="(\d+)"', html) if 947821 <= int(v) <= 947841]

        today = pages(guest, root)
        all_rows = ids(today)
        if all_rows != list(range(947833, 947820, -1)) or "HiddenFirstAlias" in today or "RecentFormerMember" in today:
            raise RuntimeError(f"Today exposed a private byline, a restricted topic, or incorrectly paginated {all_rows}")
        signed = pages(member, root + "?mode=last24")
        if "RecentFormerMember" not in signed or "Number of days" not in signed or "Gelöschter Beitrag" not in today:
            raise RuntimeError("Signed-in bylines, the neutral first post, or the second language is missing")
        if "947933#p947933" not in today:
            raise RuntimeError("The newest entry does not link to its actual last post")
        yesterday = ids(pages(guest, "recent.php?mode=yesterday"))
        if 947839 not in yesterday or any(v in yesterday for v in all_rows):
            raise RuntimeError("Yesterday also contains a topic whose latest post is today")
        week = ids(pages(guest, root + "?mode=lastweek"))
        custom = ids(pages(guest, root + "?mode=lastXdays&amount_days=9"))
        if 947840 not in week or 947841 in week or 947841 not in custom:
            raise RuntimeError("The seven-day and custom rolling periods did not select their own topics")
    finally:
        verify("cleanup")
