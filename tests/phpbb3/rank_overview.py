"""Browse imported and native ranks through the historical portal link."""
import re

from http_board import Browser, Page


def browse(base, owner, member, fixture):
    guest = Browser(base)
    if "login" not in Page(guest.fetch("ranks.php")).forms or "app.php/ranks" in guest.fetch("app.php/portal"):
        raise RuntimeError("The rank directory should ask guests to log in")
    if "app.php/ranks" not in member.fetch("app.php/portal"):
        raise RuntimeError("A registered reader cannot reach the ranks from the portal")

    def row(page, rank):
        found = re.search(rf'<tr id="rank-{rank}".*?</tr>', page, re.S)
        if not found:
            raise RuntimeError(f"Missing rank {rank}")
        return found[0]

    fixture("prepare")
    try:
        for actor, locale in ((owner, "de"), (member, "en")):
            page = actor.fetch("ranks.php")
            special = row(page, 77)
            if "Sprachabzeichen" not in special or "ModernMember" not in special or "RankFixture28" not in special or \
                    not re.search(r'images/ranks/plus-rank-77-[0-9a-f]{16}-' + locale + r'\.png', special):
                raise RuntimeError("The imported special rank lost its members or localized badge")
            standard = row(page, 98101)
            if "(27)" not in standard or "Standard &amp; Fixture" not in standard or "RankFixture09" not in standard or \
                    "RankFixture10" in standard or "rank=98101" not in standard or "RankFixture27" not in row(page, 98102):
                raise RuntimeError("The rank directory did not respect thresholds, active accounts or the overview limit")
        first = member.fetch("app.php/ranks?rank=98101")
        last = member.fetch("app.php/ranks?rank=98101&start=25")
        if "RankFixture24" not in row(first,98101) or "RankFixture25" in row(first,98101) or \
                "RankFixture25" not in row(last,98101) or "RankFixture26" not in row(last,98101) or "RankFixture27" in last:
            raise RuntimeError("Pagination changed which members belong to the rank")
        member.fetch("app.php/ranks?rank=98499", expected_status=404)
    finally:
        fixture("cleanup")
