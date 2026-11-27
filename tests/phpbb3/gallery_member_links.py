"""Follow personal albums from the ordinary phpBB member and group lists."""
from html import unescape
import re


def member_links(administrator, member, applicant, guest, check, owners):
    def links(page):
        return {int(owner): (unescape(url), unescape(label), caption) for url, owner, label, caption in re.findall(
            r'<td class="plus-member-album"><a href="([^"]*/album/member/(\d+)(?:\?[^"]*)?)" aria-label="([^"]+)">([^<]*)</a>', page)}

    def headings(page):
        return re.findall(r'<th scope="col" class="plus-member-album">([^<]+)</th>', page)

    empty = 41000 + owners
    restricted = empty + 1
    inactive = empty + 2
    group = "memberlist.php?mode=group&g=71"
    for actor, heading, caption, label in ((member, "Photo album", "Personal album", "Photo album of "),
                                            (administrator, "Fotoalbum", "Persönliches Album", "Fotoalbum von ")):
        page = actor.fetch(group)
        rows = links(page)
        if headings(page) != [heading, heading] or not {41000, empty} <= rows.keys():
            raise RuntimeError("A group leader or an ordinary group member lost their personal album column")
        if rows[41000][1] != label + f"ArchiveAlbum{owners + 3:03} & Grüße":
            raise RuntimeError("A member's album link double-escaped their name")
        if inactive in rows or (restricted in rows) != (actor is administrator):
            raise RuntimeError("The group list exposes an unavailable album")
        url, name, text = rows[empty]
        if (text != caption or name != label + "ArchiveAlbum003" or
                "Member gallery " + str(owners) not in actor.fetch(url)):
            raise RuntimeError("The member list did not open the named member's visible empty album")

    listing = "memberlist.php?first_char=a&sk=a&sd=a"
    page = member.fetch(listing)
    if len(headings(page)) != 1 or empty not in links(page) or restricted in links(page):
        raise RuntimeError("The ordinary member list did not apply the group list's album rules")
    # ModernMember's personal root allows the Photo Club. One viewer is current, the other pending.
    modern = "memberlist.php?first_char=m&sk=a&sd=a"
    if 2 not in links(member.fetch(modern)) or 2 in links(applicant.fetch(modern)):
        raise RuntimeError("An unconfirmed group membership affected a personal album link")
    public = guest.fetch(listing)
    if headings(public) or links(public):
        raise RuntimeError("The public member list advertised a personal album to a signed-out reader")
    selector = member.fetch("memberlist.php?mode=searchuser&form=postform&field=username&first_char=a")
    if 'id="memberlist"' not in selector or headings(selector) or links(selector):
        raise RuntimeError("The native member selection popup gained an unrelated gallery column")
    check("hide-member-links")
    disabled = member.fetch(group)
    if headings(disabled) or links(disabled):
        raise RuntimeError("The native member list ignored the disabled personal gallery links")
