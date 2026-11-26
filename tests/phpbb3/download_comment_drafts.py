"""Preview and revise a download comment, then open the published comment on its actual page."""
import re
from http_board import FormFields, Page, submit_form
from legacy_links import link


def browse(base, owner, member, verify):
    page = "app.php/downloads/file/11145"
    endpoint = page + "/comment"
    subject = "Rhythmus & Bühne 🎷"
    message = "Grüße [b]Bühne[/b] & Rhythmus\nZweite Zeile 🎷"
    verify("seed")
    try:
        initial = member.fetch(page)
        if ("Up to 96 characters." not in initial or 'class="button button-icon-only bbcode-b"' not in initial
                or "bbcode-img" in initial or "Maximal 96 Zeichen." not in owner.fetch(page)):
            raise RuntimeError("The native formatting controls or translated limit ignored the download settings")
        link(base, "dload.php?action=post_comment&file_id=11145", page, {}, fragment="dl-comment")
        first = submit_form(member, page, initial, "dl-comment", {"subject": subject, "message": message, "preview": "1"})
        form = FormFields(first, "dl-comment").values
        if (form["subject"] != subject or form["message"] != message or not re.search(r"<strong[^>]*>Bühne</strong>", first)
                or form["nonce"] != FormFields(initial, "dl-comment").values["nonce"]):
            raise RuntimeError("The preview lost formatting, literal text or the submission identity")
        long_text = "ä" * 97
        for button in ({"preview": "1"}, {}):
            invalid = submit_form(member, endpoint, first, "dl-comment", {"message": long_text, **button}, status=422)
            fields = FormFields(invalid, "dl-comment").values
            if fields["subject"] != subject or fields["message"] != long_text or "configured length limit" not in invalid:
                raise RuntimeError("A rejected comment lost its editable draft")
        final = submit_form(member, endpoint, invalid, "dl-comment", {"message": message + " Fertig.", "preview": "1"})
        if 'id="dl-comment-preview"' not in final or "Fertig." not in final:
            raise RuntimeError("The corrected comment could not be previewed")
        verify("close")
        submit_form(member, endpoint, final, "dl-comment", {"preview": "1"}, status=404)
        submit_form(member, endpoint, final, "dl-comment", {}, status=404)
        verify("unpublished")
        verify("reopen")
        for _ in range(2):
            posted = submit_form(member, endpoint, final, "dl-comment", {})
            marker = re.search(r'id="comment-(\d+)"', posted)
            if (not marker or "Earlier entry 19" in posted or "Fertig." not in posted
                    or not re.search(r"<strong[^>]*>Bühne</strong>", posted)):
                raise RuntimeError("Publication or its retry opened a page without the newly stored comment")
        verify("published")
        comment = marker[1]
        legacy = f"dload.php?action=post_comment&file_id=11145&cid={comment}&delete=do"
        link(base, legacy, page + "/comment/" + comment + "/delete", {})
        if "dl-remove" not in Page(owner.fetch(legacy)).forms:
            raise RuntimeError("The historical removal link no longer opens the native confirmation")
        verify("shift")
        shifted = member.fetch(f"{page}?comment={comment}#comment-{comment}")
        if "Earlier entry 19" not in shifted or f'id="comment-{comment}"' not in shifted:
            raise RuntimeError("A comment bookmark no longer finds the target after an earlier removal")
    finally:
        verify("cleanup")
