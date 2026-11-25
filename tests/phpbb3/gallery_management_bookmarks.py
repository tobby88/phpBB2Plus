"""Exercise old owner IDs, current-user links and personal category administration forms."""
from urllib.parse import urlsplit

from http_board import Page, submit_form


def browse(admin, member, owner, guest, check):
    mapped = int(check("seed"))
    route = "app.php/album/legacy/member/{}/manage"
    form = "album-personal"

    def destination(actor, url):
        return urlsplit(Page(actor.fetch(url)).forms[form][0]).path

    try:
        for source in (0, 1, 87):
            if "login" not in Page(guest.fetch(route.format(source) + "?action=edit&cat_id=781")).forms:
                raise RuntimeError("The old personal administration entry did not require sign-in")
        current = route.format(0)
        if not destination(owner, current).endswith("/album/member/87/0/start"):
            raise RuntimeError("An owner without an album lost the creation form")
        member.fetch(route.format(87), expected_status=404)
        check("albums")
        for source in (0, 87):
            index = owner.fetch(route.format(source))
            if "/album/member/87/781/edit" not in index or "Personal bookmark 780" not in index:
                raise RuntimeError("The personal administration bookmark did not show its management directory")
        if not destination(owner, current + "?action=edit").endswith("/album/member/87/780/edit"):
            raise RuntimeError("A root edit without cat_id failed to resolve the only root")
        for mode in ("edit", "delete"):
            link = route.format(87) + "?cat_id=781&action=" + mode
            for actor in (owner, admin):
                if not destination(actor, link).endswith("/album/member/87/781/" + mode):
                    raise RuntimeError("A personal subcategory management form resolved to the wrong target")
            member.fetch(link, expected_status=404)
        edit = route.format(1) + "?action=edit&cat_id=782"
        if not destination(member, edit).endswith(f"/album/member/{mapped}/782/edit") or mapped == 1:
            raise RuntimeError("The historical owner's ID was interpreted as the native Anonymous user")
        owner.fetch(current + "?cat_id=782&action=edit", expected_status=404)
        owner.fetch(current + "?cat_id=780&action=delete", expected_status=404)
        admin.fetch(route.format(999999), expected_status=404)
        child_edit = current + "?cat_id=781&action=edit"
        saved = submit_form(owner, child_edit, owner.fetch(child_edit), form, {"description": "Saved through the administration bookmark"})
        if "Saved through the administration bookmark" not in saved:
            raise RuntimeError("The resolved editing form did not save to the expected gallery")
        check("disabled")
        owner.fetch(current, expected_status=404)
        owner.fetch(current + "?action=edit&cat_id=781", expected_status=404)
        if not destination(admin, route.format(87) + "?action=delete&cat_id=780").endswith("/album/member/87/780/delete"):
            raise RuntimeError("The gallery administrator lost the native root confirmation")
    finally:
        check("cleanup")
