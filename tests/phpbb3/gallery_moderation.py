"""Read and submit the gallery's category moderation forms on the disposable board."""
from html import unescape
import json
import re
from urllib.parse import parse_qs, urlsplit

from http_board import Page, submit_form


def browse(administrator, member, applicant, guest, check):
    check("seed")
    category = "app.php/album/category/760"
    panel = category + "/moderate"

    def state():
        return {int(key): row for key, row in json.loads(check("state")).items()}

    def ids(page):
        return [int(value) for value in re.findall(r'name="pic_id\[\]"[^>]* value="(\d+)"', page)]

    def preview(actor, url, page, action, selected, destination=0, status=200):
        result = submit_form(actor, url, page, "album-mod-select",
                             {"operation": action, "pic_id[]": selected, "destination": destination}, status)
        if selected and status == 200:
            fields = Page(result).forms["album-mod-confirm"][1]
            if sorted(selected) != list(map(int, fields["selection"].split(","))) or any(
                    f"<li>Panel photo {pic}</li>" not in result for pic in selected):
                raise RuntimeError("Moderation confirmation did not list exactly the chosen pictures")
        return result

    def confirm(actor, url, page, status=200):
        return submit_form(actor, url, page, "album-mod-confirm", {}, status)

    try:
        for reader in (guest, applicant):
            if "/album/category/760/moderate" in reader.fetch(category):
                raise RuntimeError("A guest or pending group applicant received the moderation link")
            reader.fetch(panel, expected_status=404)
            reader.fetch("album_modcp.php?cat_id=760", expected_status=404)
        if "/album/category/760/moderate" not in member.fetch(category):
            raise RuntimeError("The active moderator has no entry point from the category")
        first = member.fetch(panel)
        if ids(member.fetch("album_modcp.php?cat_id=760")) != ids(first):
            raise RuntimeError("The old moderation bookmark opened a different selection of pictures")
        if ids(first) != [26007, 26006, 26005]:
            raise RuntimeError(f"Unexpected moderation page size or order: {ids(first)}")
        links = [unescape(href) for href in re.findall(r'href="([^"]+)"', first)]
        page_two = next(href for href in links if urlsplit(href).path.endswith("/760/moderate")
                        and parse_qs(urlsplit(href).query).get("start") == ["3"])
        second = member.fetch(page_two)
        if ids(second) != [26004, 26003, 26002] or 'value="761"' not in second or any(
                f'value="{cat}"' in second for cat in (760, 762, 763, 764, 766)):
            raise RuntimeError("A moderation page repeated a row or offered an unavailable destination")
        if ids(member.fetch(panel + "?sort_method=pic_title&sort_order=ASC")) != [26000, 26001, 26002]:
            raise RuntimeError("The category moderation sorting form did not change the visible page")
        for method in ("username", "pic_user_id", "rating", "comments", "new_comment", "pic_view_count"):
            if len(ids(member.fetch(panel + "?sort_method=" + method))) != 3:
                raise RuntimeError(f"Missing legacy panel sorting method {method}")

        review_only = category.replace("/760", "/765") + "/moderate"
        limited = member.fetch(review_only)
        if ids(limited) != [26011] or 'value="approve"' in limited or 'value="unapprove"' in limited:
            raise RuntimeError("Admin-only approval leaked a pending picture or an approval button")
        if ids(administrator.fetch(review_only)) != [26011, 26010]:
            raise RuntimeError("The album administrator cannot review pending pictures")
        no_delete = category.replace("/760", "/767") + "/moderate"
        if 'value="delete"' in member.fetch(no_delete):
            raise RuntimeError("A moderator without the category delete grant has a bulk delete button")
        private = category.replace("/760", "/764") + "/moderate"
        personal = applicant.fetch(private)
        if ids(personal) != [26013] or 'value="760"' in personal or 'value="766"' not in personal:
            raise RuntimeError("A personal album moderator was offered a different owner's gallery")
        member.fetch(private, expected_status=404)
        confirm(applicant, private, preview(applicant, private, personal, "move", [26013], 766))
        if state()[26013]["comments"] != [766]:
            raise RuntimeError("The owner's personal album move did not include its comment")
        confirm(administrator, review_only, preview(administrator, review_only, administrator.fetch(review_only), "approve", [26010]))
        if ids(member.fetch(review_only)) != [26011, 26010]:
            raise RuntimeError("An administrator's approval did not make the picture available")

        before = state()
        preview(member, panel, first, "lock", [], status=422)
        pending = preview(member, page_two, second, "move", [26004, 26003], 761)
        check("deny_destination")
        confirm(member, page_two, pending, 404)
        check("allow_destination")
        if state() != before:
            raise RuntimeError("A destination policy change caused a partial transfer")
        moved = confirm(member, page_two, preview(member, page_two, member.fetch(page_two), "move", [26004, 26003], 761))
        if "album-mod-select" not in Page(moved).forms:
            raise RuntimeError("The move did not return to the source category moderation page")
        # A second tab still displays the original second page, which has shifted after the move.
        stale = preview(member, page_two, second, "lock", [26002], status=409)
        if "album-mod-select" not in Page(stale).forms:
            raise RuntimeError("A stale moderation page did not offer the refreshed selection")
        after = state()
        for pic in (26003, 26004):
            expected = {**before[pic], "photo": {**before[pic]["photo"], "cat_id": 761, "legacy_uncategorized": 0}, "comments": [761]}
            if after[pic] != expected:
                raise RuntimeError("A moved picture lost a rating, comment, stored original or approval state")
        if any(after[pic] != value for pic, value in before.items() if pic not in (26003, 26004)):
            raise RuntimeError("A move changed an unselected row from another page")

        selected = [26007, 26006]
        reviewed = preview(member, panel, member.fetch(panel), "lock", selected)
        check("revoke")
        confirm(member, panel, reviewed, 404)
        check("restore")
        if state() != after:
            raise RuntimeError("The form retained a revoked category moderation grant")
        for action, field, value in (("lock", "locked", 1), ("unlock", "locked", 0),
                                     ("unapprove", "approved", 0), ("approve", "approved", 1)):
            confirm(member, panel, preview(member, panel, member.fetch(panel), action, selected))
            current = state()
            if any(current[pic]["photo"][field] != value for pic in selected) or current[26005] != after[26005]:
                raise RuntimeError(f"Bulk {action} did not update exactly the selection")
            if action in ("unapprove", "approve"):
                guest.fetch("app.php/album/picture/26007", expected_status=200 if value else 404)

        selected = [26007, 26005]
        reviewed = preview(member, panel, member.fetch(panel), "delete", selected)
        single = "app.php/album/picture/26005/manage/lock"
        one = administrator.fetch(single)
        submit_form(administrator, single, one, "album-manage", {})
        after_lock = state()
        if not after_lock[26005]["photo"]["locked"]:
            raise RuntimeError("The individual moderation form did not lock the picture")
        conflict = confirm(member, panel, reviewed, 409)
        if "album-mod-select" not in Page(conflict).forms or state() != after_lock:
            raise RuntimeError("A changed photo caused the remaining selection to be deleted")
        fresh = preview(member, panel, member.fetch(panel), "delete", selected)
        if state() != after_lock:
            raise RuntimeError("Displaying the deletion confirmation changed a picture")
        confirm(member, panel, fresh)
        deleted = state()
        for pic, row in deleted.items():
            if (pic in selected and row != {"photo": None, "comments": [], "ratings": 0, "queued": True, "file": True}
                    or pic not in selected and row != after_lock[pic]):
                raise RuntimeError("Bulk deletion did not preserve the other pictures or queue the selected originals")
        check("receipts")
    finally:
        check("cleanup")
