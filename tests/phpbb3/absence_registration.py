"""Opt-in absence fields on phpBB's registration agreement and validated account form."""
from secrets import token_hex

from http_board import Browser, FormFields, Page, login, submit_form


def browse(base, verify):
    path = "ucp.php?mode=register"
    note = "Natur & <Berge> 🎨\n0"
    password = "Az!78" + token_hex(9)

    def accepted(actor):
        return submit_form(actor, path, actor.fetch(path), "agreement", {"agreed":"1"})

    def register(actor, page, name, kind):
        return submit_form(actor, path, page, "register", {"username":name, "new_password":password, "password_confirm":password,
                           "email":name.lower() + "@example.invalid", "plus_signup_away":"1", "plus_signup_kind":kind,
                           "plus_signup_note":note, "submit":"1"})

    verify("seed")
    try:
        public = Browser(base)
        if "plus_signup_away" in FormFields(accepted(public),"register").values:
            raise RuntimeError("The optional signup fields appeared while disabled")
        verify("on")
        current, another = Browser(base), Browser(base)
        first, stale = accepted(current), accepted(another)
        fields = FormFields(first,"register").values
        if fields.get("plus_signup_away") != "0" or fields.get("plus_signup_kind") != "1" or fields.get("plus_signup_note") != "":
            raise RuntimeError("The optional native signup did not start with the historical defaults")
        invalid = register(current, first, "AwaySignupTest", "20")
        if "register" not in Page(invalid).forms or FormFields(invalid,"register").values.get("plus_signup_note") != note:
            raise RuntimeError("An invalid signup lost the personal input or bypassed native form validation")
        verify("invalid")
        registered = register(current, invalid, "AwaySignupTest", "3")
        if "register" in Page(registered).forms or note.splitlines()[0] in registered:
            raise RuntimeError("The native registration failed or echoed the private absence note after completion")
        owner = login(base, "AwaySignupTest", password)
        own = "app.php/absence"
        account = owner.fetch(own)
        own_values = FormFields(account,"plus-absence").values
        if own_values.get("note") != note:
            raise RuntimeError("The newly registered member could not read their own original absence")
        updated = submit_form(owner, own, account, "plus-absence", {"action":"save"})
        if FormFields(updated,"plus-absence").values.get("revision") == own_values["revision"]:
            raise RuntimeError("The newly registered member could not save the shared native profile form")
        verify("off")
        register(another, stale, "AwaySignupClosed", "3")
        verify("outcome")
    finally:
        verify("restore")
