"""The original voluntary profile choice through native forms, profiles and author cards."""
from html import unescape
import json
from pathlib import Path
import re
import secrets

from http_board import FormFields, Page, admin_url, submit_form


def browse(admin, member, guest, check):
    field = json.loads(check("prepare"))
    name = "pf_" + field["ident"]
    edit = "ucp.php?i=ucp_profile&mode=profile_info"
    acp = admin_url(admin) + "&i=users&mode=profile&u=2"
    register = "ucp.php?mode=register"
    graphics = Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images"

    def value(actor, user_id, label, expected):
        text = actor.fetch(f"memberlist.php?mode=viewprofile&u={user_id}")
        plain = unescape(text)
        if not re.search(r"<dt>" + label + r"\s*:</dt>\s*<dd>" + re.escape(expected) + "</dd>", plain):
            raise RuntimeError(f"Native profile {user_id} did not show {label} / {expected}")

    def card(actor, post_id, suffix=None, label=None, alternative=None):
        html = actor.fetch(f"viewtopic.php?p={post_id}")
        section = re.search(r'<dl\b[^>]*id="profile' + str(post_id) + r'"[^>]*>(.*?)</dl>', html, re.S)
        if not section:
            raise RuntimeError("Missing native author card")
        text = unescape(section[1])
        src = re.search(r'<img\b[^>]*src="([^"]*icon_minigender_(?:male|female)\.gif)"[^>]*>', text)
        if suffix:
            image = "icon_minigender_" + suffix + ".gif"
            if (not src or not src[1].endswith("/" + image) or f'alt="{label}"' not in src[0]
                    or actor.fetch(src[1], raw=True) != (graphics / image).read_bytes()):
                raise RuntimeError("The original author image or its accessible text changed")
        elif src:
            raise RuntimeError("An absent, private or customised choice acquired an author image")
        elif alternative is None and "profile-" + field["ident"] + '"' in text:
            raise RuntimeError("An unset or hidden author choice remained visible")
        elif alternative is not None and alternative not in text:
            raise RuntimeError("The author card lost the administrator's native text")

    def save(actor, url, form_name, choice):
        form = actor.fetch(url)
        if name not in FormFields(form, form_name).values:
            raise RuntimeError("The native profile editor omitted its dropdown")
        submit = "update" if form_name == "user_profile" else "submit"
        result = submit_form(actor, url, form, form_name, {
            name: str(choice), "pf_plus_f54[0]": "1", "pf_plus_f54[1]": "3", submit: "1",
        })
        errors = re.findall(r'<(?:div|p|span)[^>]*class="(?:error|errorbox)"[^>]*>(.*?)</(?:div|p|span)>', result, re.S)
        if errors:
            raise RuntimeError(f"Native {form_name} edit: " + " ".join(re.sub("<[^>]+>", " ", errors[0]).split())[:340])

    try:
        value(guest, 2, "Geschlecht", "Männlich")
        value(member, field["historical"], "Gender", "Female")
        value(guest, 87, "Geschlecht", "Keine Angabe")
        card(guest, 1, "male", "Geschlecht: Männlich")
        card(member, 1, "male", "Gender: Male")
        card(guest, 32)
        check("hide-author")
        card(guest, 1)
        value(guest, 2, "Geschlecht", "Männlich")
        check("show-author")
        pm = member.fetch("ucp.php?i=pm&mode=view&p=111")
        index = guest.fetch("memberlist.php")
        if (re.search(r"<th\b[^>]*>\s*Geschlecht\s*</th>", index) or name in index or
                "icon_minigender_" in pm or "profile-" + field["ident"] + '"' in pm):
            raise RuntimeError("The profile choice appeared outside the original public locations")
        signup = submit_form(guest, register, guest.fetch(register), "agreement", {"agreed":"1"})
        controls = FormFields(signup, "register")
        if controls.values.get(name) != "1":
            raise RuntimeError("Native registration did not default to an unspecified choice")
        password = "Az!78" + secrets.token_hex(9)
        signup_result = submit_form(guest, register, signup, "register", {name:"3", "username":"GenderSignupFixture", "new_password":password,
            "password_confirm":password, "email":"gender-signup@example.invalid", "pf_plus_f54[0]":"1", "pf_plus_f54[1]":"3", "submit":"1"})
        if "register" in Page(signup_result).forms:
            raise RuntimeError("The ordinary registration did not accept the optional dropdown")
        check("registered")
        save(admin, edit, "ucp", 3)
        value(guest, 2, "Geschlecht", "Weiblich")
        card(guest, 1, "female", "Geschlecht: Weiblich")
        save(admin, acp, "user_profile", 1)
        value(guest, 2, "Geschlecht", "Keine Angabe")
        card(guest, 1)
        save(admin, acp, "user_profile", 2)
        check("relabel")
        value(member, 2, "Gender", "Custom choice")
        card(member, 1, alternative="Custom choice")
        card(guest, 1, alternative="Männlich")
    finally:
        check("restore")
