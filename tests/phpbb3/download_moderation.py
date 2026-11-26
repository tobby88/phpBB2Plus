"""Follow a category moderator through cross-category review, stale selections and a withdrawn group grant."""
from http_board import Browser, Page, submit_form
from legacy_links import link


def browse(base, owner, moderator, ordinary, verify):
    url = "app.php/downloads/moderation"
    pair = [11130, 11131]

    def preview(path, body, group, ids, operation, status=200):
        return submit_form(moderator, path, body, "dl-mod-" + group, {"ids[]": ids, "operation": operation}, status)

    def confirm(path, body, ids, status=200):
        if Page(body).forms["dl-mod-confirm"][1].get("ids[]") != [str(key) for key in ids]:
            raise RuntimeError("The confirmation omitted a selected file")
        return submit_form(moderator, path, body, "dl-mod-confirm", {"confirm": "1"}, status)

    verify("seed")
    try:
        Browser(base).fetch(url, expected_status=404)
        ordinary.fetch(url + "?cat_id=11110", expected_status=404)
        moderator.fetch(url + "?cat_id=11113", expected_status=404)
        moderator.fetch(url + "?cat_id=11114", expected_status=404)
        review = "app.php/downloads/category/11110/review"
        moderator.fetch(review, expected_status=404)
        readonly = moderator.fetch(url)
        if ("B south pending" not in readonly or 'name="operation"' in readonly or
                any(text in readonly for text in ("Unlisted child", "Hidden moderator draft", "Unmoderated draft"))):
            raise RuntimeError("The moderator's read-only catalogue exposed unrelated files or approval controls")
        if "Download-Moderation" not in owner.fetch(url) or "/downloads/moderation" not in moderator.fetch("app.php/downloads"):
            raise RuntimeError("The German page or moderator's catalogue navigation is unavailable")
        link(base, "dload.php?action=mcp&cat_id=11110&mode=approved", url, {"cat_id":"11110", "status":"pending"})
        link(base, "dload.php?action=mcp&cat_id=11111&mode=broken&sort_order=ASC", url, {"cat_id":"11111", "status":"broken", "sort_order":"ASC"})
        link(base, "dload.php?action=mcp", url, {"cat_id": "11111", "status": "published"}, method="POST",
             form={"cat_id": "11110", "mode_js": "file_cat", "cat_js_id": "11111", "go": "Go"}, status=303)
        page = moderator.fetch("dload.php?action=mcp&cat_id=11110&mode=all_file&sort_method=file_name&sort_order=ASC")
        if "C north broken" not in page or "E north published" not in page or "F north published" in page:
            raise RuntimeError("The category's historical published-files view did not keep its sort or page size")
        if "F north published" not in moderator.fetch(url + "?cat_id=11110&status=published&sort_method=file_name&sort_order=ASC&start=2"):
            raise RuntimeError("The next page lost the final scoped download")
        verify("allow")
        if "A north pending" not in moderator.fetch(review):
            raise RuntimeError("The existing category review stopped recognizing its moderator")
        scoped = moderator.fetch(url + "?cat_id=11111&status=broken")
        if "B south pending" not in scoped or "D south broken" not in scoped or "C north broken" in scoped:
            raise RuntimeError("The broken-files filter crossed its category scope")
        overview = moderator.fetch(url)
        prompt = preview(url, overview, "pending", pair, "approve")
        if "Publish 2 selected downloads?" not in prompt:
            raise RuntimeError("The preview did not explain the two changes")
        verify("suspend")
        confirm(url, prompt, pair, status=404)
        verify("untouched")
        verify("resume")
        verify("change")
        stale = confirm(url, prompt, pair, status=409)
        if "has changed" not in stale:
            raise RuntimeError("A changed revision did not reject the entire confirmation")
        prompt = preview(url, moderator.fetch(url), "pending", pair, "approve")
        saved = confirm(url, prompt, pair)
        if "have been updated" not in saved:
            raise RuntimeError("The successful cross-category confirmation did not return to the panel")
        verify("approved")
        # Select one published and one already pending broken file. Only the published file changes.
        path = url + "?cat_id=11111&status=broken"
        broken = moderator.fetch(path)
        unchanged = preview(path, broken, "broken", [11133], "withdraw", status=400)
        if "already have the requested status" not in unchanged:
            raise RuntimeError("A selection with no change did not explain its current status")
        prompt = preview(path, broken, "broken", [11131,11133], "withdraw")
        if "Withdraw one selected download?" not in prompt or "dl-mod-confirm" not in Page(prompt).forms:
            raise RuntimeError("The mixed selection counted an unchanged pending file")
        confirm(path, prompt, [11131,11133])
        verify("withdrawn")
    finally:
        verify("cleanup")
