"""Use configured Plus profile fields through native phpBB3 forms and displays."""
from html import unescape
import re
from urllib.parse import urljoin
from http_board import Browser, admin_url, submit_form


def exercise(base, actor):
    guest = Browser(base)
    profile = "memberlist.php?mode=viewprofile&u=87"
    page = guest.fetch(profile)
    if any(s not in page for s in ("Groß &amp; Klein", "Erste Zeile", "Letzte Zeile", "Musik, Bild &amp; Ton")):
        raise RuntimeError("The public profile lost a configured field, Unicode or multiple choices")
    if "Vertrauliche interne Notiz" in page or "Nur im Thema 42" in page or "Nur im Thema 42" not in guest.fetch("viewtopic.php?t=1"):
        raise RuntimeError("A custom field lost its original topic/profile visibility")
    path = "ucp.php?i=ucp_profile&mode=profile_info"
    edit = actor.fetch(path)
    if ('name="pf_plus_f51"' not in edit or 'name="pf_plus_f52"' not in edit or 'name="pf_plus_f55"' in edit or
            not re.search(r'name="pf_plus_f54\[\]" value="3" checked', edit)):
        raise RuntimeError("Custom profile controls did not preserve editability and selected options")
    def save(values):
        fields = {"pf_plus_f54[]": None, "pf_plus_f54_present": "1", "pf_plus_f51": "Verändert & ganz",
                  "pf_plus_f52": "Zwei\nZeilen", "pf_plus_f53": "3", "pf_plus_f56": "Nur im Thema 42",
                  "bday_day": "0", "bday_month": "0", "bday_year": "0", "submit": "1"}
        fields.update(values)
        return submit_form(actor, path, edit, "ucp", fields)
    save({})
    if "Groß &amp; Klein" not in guest.fetch(profile):
        raise RuntimeError("Submitting a required field without any choice changed other fields")
    result = save({"pf_plus_f54[0]":"1", "pf_plus_f54[1]":"3"})
    changed = guest.fetch(profile)
    missing = [s for s in ("Verändert &amp; ganz", "Grün &amp; Blau", "Lesen, Bild &amp; Ton") if s not in changed]
    if missing or "Vertrauliche interne Notiz" in changed:
        error = re.search(r'class="error">(.+?)</',result,re.S)
        raise RuntimeError(f"The native UCP lost a custom field edit {missing}: " + (unescape(error.group(1)) if error else "no form error"))


def administration(owner):
    panel = admin_url(owner) + "&i=acp_profile&mode=profile"
    fields = owner.fetch(panel)
    for ident in ("plus_f54", "plus_f56"):
        row = re.search(r'<td>' + ident + r'</td>(.+?)</tr>',fields,re.S)
        edit = re.search(r'href="([^"]*action=edit[^"]*field_id=\d+)"',row.group(1)) if row else None
        if not edit:
            raise RuntimeError("The native ACP lacks a converted custom profile field")
        path = urljoin(panel,unescape(edit.group(1)))
        if ident == "plus_f54":
            if "Mehrfachauswahl" not in row.group(1) or not re.search(r'name="plus_multi_defaults\[\]" value="2" checked',owner.fetch(path + "&step=2")):
                raise RuntimeError("The native ACP lost the selected multi-choice default")
        elif 'id="plus_view_profile"' not in owner.fetch(path):
            raise RuntimeError("The member profile visibility setting is missing from the native field editor")


def visibility_after_toggle(base, enabled):
    try:
        page = Browser(base).fetch("memberlist.php?mode=viewprofile&u=87")
    except RuntimeError as error:
        raise RuntimeError(f"Profile request after community extension {'enable' if enabled else 'disable'} failed") from error
    if ("Lesen, Bild &amp; Ton" in page) != enabled or "Nur im Thema 42" in page or "Vertrauliche interne Notiz" in page:
        raise RuntimeError("Toggling the community extension changed custom profile visibility or lost selections")
