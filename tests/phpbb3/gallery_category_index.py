"""Read the public and personal album directories through four real browser sessions."""
from html import unescape
import re
from urllib.parse import urljoin
from gallery import category_grid
from http_board import FormFields, admin_url, submit_form


def browse(administrator, member, applicant, guest, check):
    def rows(actor, category=0):
        page = actor.fetch("app.php/album" + (f"/category/{category}" if category else ""))
        section = re.search(r'<section\b[^>]*aria-labelledby="album-categories"[^>]*>(.*?)</section>', page, re.S)
        if not section:
            return {}
        parts = re.split(r'<li data-album-category="(\d+)">', section[1])
        return {int(parts[i]): parts[i + 1] for i in range(1, len(parts), 2)}

    def children(row):
        return {int(cid): body for cid, body in re.findall(r'<li data-album-subcategory="(\d+)">(.*?)</li>', row, re.S)}

    def count(row, key):
        found = re.search(fr'<dd data-album-count="{key}">(\d+)</dd>', row)
        return int(found[1]) if found else None

    def recent(row):
        found = re.search(r'<span[^>]* data-album-new>(\d+)', row.split("<ul", 1)[0])
        return int(found[1]) if found else 0

    def latest(row, kind):
        link = re.search(fr'<a data-album-last-{kind}="(\d+)" href="([^"]+)"[^>]*>([^<]*)</a>', row)
        if not link:
            raise RuntimeError("Missing latest " + kind + " in the directory")
        return int(link[1]), unescape(link[2]), unescape(link[3])

    check("seed")
    try:
        if not 0 < int(check("volume")) <= 14:
            raise RuntimeError("Directory queries did not stay bounded with 510 extra categories")
        for actor in (guest, applicant, member, administrator):
            privileged = actor in (member, administrator)
            branch = rows(actor, 880)
            row = branch[881]
            sub = children(row)
            moderators = re.search(r'<p class="plus-album-moderators">(.*?)</p>', row, re.S)
            if (not moderators or moderators[1].count("Album editors &amp; friends") != 1 or
                    "Staff photographers" not in moderators[1] or moderators[1].index("Album editors") > moderators[1].index("Staff photographers") or
                    "Photo Club" in moderators[1] or "plus-album-moderators" in branch[882]):
                raise RuntimeError("The gallery moderator list included hidden groups or lost a visible group, order or label")
            if actor is administrator:
                link = re.search(r'<a href="([^"]+)">Album editors &amp; friends</a>', moderators[1])
                if not link or "g=28881" not in link[1]:
                    raise RuntimeError("The visible gallery group did not link to its native profile")
                page = actor.fetch(urljoin(urljoin(actor.base, "app.php/album/category/880"), unescape(link[1])))
                if "Album editors &amp; friends" not in page:
                    raise RuntimeError("The linked native group page did not show the named gallery moderators")
            if list(sub) != ([883,884,886] if privileged else [883,886]) or "data-album-subcount" in sub[886]:
                raise RuntimeError("The direct subalbum list included a hidden parent, descendant or zero-picture label")
            expected = 10 if actor is administrator else 9 if privileged else 6
            if [count(row, key) for key in ("pics","total-pics","comments","total-comments")] != [2,expected,1,expected - (2 if privileged else 1)]:
                raise RuntimeError("Picture or comment totals did not follow approval, group membership and picture location")
            if recent(row) != 1 or recent(sub[883]) != (5 if actor is administrator else 4 if privileged else 3):
                raise RuntimeError("Direct and descendant new markers must cover different scopes")
            child_count = re.search(r'data-album-subcount>\((\d+)', sub[883])
            if not child_count or int(child_count[1]) != (6 if actor is administrator else 5 if privileged else 4):
                raise RuntimeError("The child picture label did not include visible grandchildren")
            pic, url, title = latest(row, "picture")
            comment, target, _ = latest(row, "comment")
            if (pic, comment) != ((28008,28506) if actor is administrator else (28007,28505) if privileged else (28004,28503)):
                raise RuntimeError("The latest event did not use permitted timestamps and stable tie-breaks")
            if f"/album/picture/{pic}" not in url or f"/album/legacy/comment/{comment}" not in target:
                raise RuntimeError("The latest picture or comment pointed to a different view")
            if 'plus-album-subcategories-lines' not in row or f'/album/picture/{pic}/small' not in row:
                raise RuntimeError("Configured directory previews or stacked child links are missing")
            if not privileged and (title != "Grüne An…" or "Restricted child" in row or "Restricted grandchild" in row):
                raise RuntimeError("The directory disclosed a restricted album or cut a UTF-8 title incorrectly")
            if ("FormerFixture" in row) != (actor is applicant):
                raise RuntimeError("The directory byline did not follow former member visibility")
            if actor is guest:
                if list(branch) != [882,881,891,892]:
                    raise RuntimeError("Manual directory ordering must retain the original order regardless of the direction setting")
                empty = branch[882]
                if count(empty, "pics") != 0 or count(empty, "comments") != 0 or any(mark in empty for mark in ("data-album-last-", "plus-album-index-thumb")):
                    raise RuntimeError("An empty album displayed a link to a nonexistent picture or comment")
                if f'id="comment-{comment}"' not in actor.fetch(target):
                    raise RuntimeError("The last-comment link did not reach the original approved comment")
                if not actor.fetch(f"app.php/album/picture/{pic}/small", raw=True).startswith(b"\x89PNG"):
                    raise RuntimeError("The directory thumbnail is unavailable")
                actor.fetch("app.php/album/picture/28008/small", expected_status=404)
        root = rows(guest)[880]
        if recent(root) or recent(children(root)[881]) != 4 or 883 in children(root):
            raise RuntimeError("The root directory confused direct children with deeper categories")
        entry = admin_url(administrator)
        menu = administrator.fetch(entry + "&i=acp_board&mode=settings")
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Anzeige im Fotoalbum</span>', menu)
        if not link:
            raise RuntimeError("The gallery directory settings are missing from the native ACP")
        url = urljoin(entry, unescape(link[1]))
        form = "album-display-settings"
        original = administrator.fetch(url)
        fields = FormFields(original, form).values
        if (fields.get("album_category_sorting") != "cat_order" or fields.get("new_pic_check_interval") != "1D" or
                fields.get("last_pic_title_length") != "8" or fields.get("show_index_total_comments") != "1"):
            raise RuntimeError("The ACP did not expose the current album directory settings")
        invalid = submit_form(administrator, url, original, form, {"last_pic_title_length": "4", "new_pic_check_interval": "1X"})
        if ('class="errorbox"' not in invalid or
                FormFields(administrator.fetch(url), form).values.get("last_pic_title_length") != "8"):
            raise RuntimeError("An invalid new-picture interval did not roll back the other changes")
        saved = submit_form(administrator, url, administrator.fetch(url), form, {"new_pic_check_interval": "1H"})
        if 'class="successbox"' not in saved:
            raise RuntimeError("The ACP could not save the hourly new-picture interval")
        if recent(rows(guest, 880)[881]) or recent(children(rows(guest)[880])[881]) != 3:
            raise RuntimeError("The historic hourly new-picture interval was ignored")
        check("month")
        if recent(rows(guest, 880)[881]) != 2 or recent(children(rows(guest)[880])[881]) != 6:
            raise RuntimeError("The historic monthly interval or precedence of month over hours was lost")
        check("title")
        if list(rows(guest, 880)) != [891,892,881,882]:
            raise RuntimeError("Descending titles must retain ascending IDs for identical labels")
        check("id")
        if list(rows(guest, 880)) != [892,891,882,881]:
            raise RuntimeError("Descending category ID sort is missing")
        check("public_only")
        shared, personal = rows(guest)[880], rows(guest, 888)[889]
        if not children(shared) or children(personal) or 'plus-album-subcategories-lines' in shared:
            raise RuntimeError("Shared and personal subalbum preferences or line-break preferences were conflated")
        check("personal_only")
        shared = rows(guest)[880]
        if children(shared) or list(children(rows(guest, 888)[889])) != [890] or 'data-album-last-picture' in shared or 'plus-album-index-thumb' not in shared:
            raise RuntimeError("Personal subalbum previews and latest thumbnails could not be enabled independently")
        check("no_comments")
        row = rows(guest, 880)[881]
        if (count(row, "comments") is not None or count(row, "total-comments") is not None or "data-album-last-comment" in row or
                'plus-album-index-thumb' in row or latest(row, "picture")[0] != 28004):
            raise RuntimeError("Global comment or independent thumbnail and last-picture preferences were ignored")
        current = administrator.fetch(url)
        if FormFields(current, form).values.get("new_pic_check_interval") != "1hm":
            raise RuntimeError("The ACP discarded an imported multi-unit interval")
        saved = submit_form(administrator, url, current, form, {"fullpic_popup": "1", "show_index_thumb": "1"})
        if 'class="successbox"' not in saved or FormFields(saved, form).values.get("new_pic_check_interval") != "1hm":
            raise RuntimeError("Saving full-image links changed the imported new-picture interval")
        row = rows(guest, 880)[881]
        if "/album/picture/28004/full" not in latest(row, "picture")[1] or row.count('target="_blank" rel="noopener noreferrer"') != 2:
            raise RuntimeError("The configured full-size picture links did not open separately")
        grid_url = "app.php/album/category/883"
        popup_grid = category_grid(guest.fetch(grid_url))
        image = re.search(r'<a href="([^"]*/album/picture/28004/full(?:\?[^"]*)?)" target="_blank" rel="noopener noreferrer">', popup_grid)
        if not image or not guest.fetch(unescape(image[1]), raw=True).startswith(b"\x89PNG\r\n\x1a\n"):
            raise RuntimeError("A normal gallery thumbnail did not open its full image")
        check("missing_file")
        row = rows(guest, 880)[881]
        picture, target, _ = latest(row, "picture")
        if (picture != 28004 or "/full" in target or 'target="_blank"' in row or 'plus-album-index-thumb' in row or
                "Grüne Ansicht – fünf" not in guest.fetch(target)):
            raise RuntimeError("An unavailable original did not fall back to its accessible picture page")
        check("minimal")
        inline_grid = category_grid(guest.fetch(grid_url))
        if 'target="_blank"' in inline_grid or not re.search(r'<a href="[^"]*/album/picture/28002(?:\?[^"]*)?">', inline_grid):
            raise RuntimeError("Disabling the full-image popup did not restore the picture page")
        row = rows(guest, 880)[881]
        if children(row) or any(key in row for key in ("plus-album-moderators", "data-album-new", "data-album-count", "plus-album-index-thumb", "data-album-last-")):
            raise RuntimeError("Disabled directory details remained visible")
    finally:
        check("cleanup")
