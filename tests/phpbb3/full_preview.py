"""Exercise read-only full-form previews against the converted posting and PM controllers."""
import re

from http_board import FormFields, Page, admin_url, submit_form


def exercise(owner, member, edit, check):
    prefs = "ucp.php?i=ucp_prefs&mode=view"
    pm = "ucp.php?i=pm&mode=compose&action=post&u=2"
    check("preview-upgrade")
    acp = FormFields(owner.fetch(admin_url(owner) + "&i=acp_board&mode=post"), "acp_board").values
    if acp.get("config[plus_full_preview]") != "1" or acp.get("config[plus_full_preview_over]") != "0":
        raise RuntimeError("The independent source preview settings did not reach the posting ACP")
    if FormFields(member.fetch(prefs), "ucp").values.get("plus_full_preview") != "0" or 'id="plus-full-preview"' in member.fetch(edit):
        raise RuntimeError("The member's converted personal choice did not disable in-page previews")
    check("preview-force")
    if 'name="plus_full_preview"' in owner.fetch(prefs) or 'id="plus-full-preview"' in owner.fetch(pm):
        raise RuntimeError("The enforced board setting left a personal preview override")
    check("preview-open")
    submit_form(member, prefs, member.fetch(prefs), "ucp", {"plus_full_preview": "1", "submit": "1"})
    draft = int(check("preview-draft").strip())
    counts = check("preview-counts")
    data = {"subject": "Native preview", "message": "[b]Vorschau mit Öl[/b]", "preview": "1", "disable_bbcode": None}
    for kind, url in (("post", edit), ("pm", pm)):
        page = member.fetch(url)
        if 'id="plus-full-preview"' not in page or f'data-kind="{kind}"' not in page:
            raise RuntimeError("The native " + kind + " editor did not enable previews")
        full = submit_form(member, url, page, "postform", data)
        if "postform" not in Page(full).forms or 'id="preview"' not in full:
            raise RuntimeError("Native full-page preview stopped working for " + kind)
        request = dict(data, plus_full_preview_request="1")
        result = submit_form(member, url, page, "postform", request)
        if (f'data-plus-preview-result="{kind}"' not in result or 'id="preview"' not in result or "postform" in Page(result).forms or
                not re.search(r'<(?:strong|b)\b[^>]*>Vorschau mit Öl</', result)):
            message = re.sub(r"<[^>]+>", " ", result)
            raise RuntimeError("Missing native preview fragment for " + kind + ": " + " ".join(message.split())[:220])
        invalid = submit_form(member, url, page, "postform", dict(request, form_token="expired"))
        if 'class="error"' not in invalid or 'id="preview"' in invalid:
            raise RuntimeError("An expired form escaped native preview validation")
        check("lock" if kind == "post" else "preview-pm-off")
        try:
            denied = submit_form(member, url, page, "postform", request)
            if "data-plus-preview-result=" in denied or 'id="preview"' in denied:
                raise RuntimeError("An earlier " + kind + " request previewed after its access was withdrawn")
        finally:
            check("unlock" if kind == "post" else "preview-pm-on")
    if counts != check("preview-counts"):
        raise RuntimeError("Previewing created a post, PM, draft, recipient copy or attachment")
    return draft, counts
