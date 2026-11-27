"""Write through phpBB's posting form, registration, UCP and ACP on the synthetic board."""
import json
from secrets import token_hex

from http_board import Browser, FormFields, admin_url, login, submit_form


def browse(admin, member, verify, source_enabled=True):
    prefs = "ucp.php?i=ucp_prefs&mode=post"
    start = "posting.php?mode=post&f=1"
    name = "Posting bookmark fixture"
    signup = "ucp.php?mode=register"
    def fields(page, form="postform"):
        return FormFields(page, form).values
    def save(actor, url, page, **changes):
        return submit_form(actor, url, page, "postform", {"post": "Submit", **changes})
    verify("setup" if source_enabled else "setup-missing")
    try:
        guest = Browser(admin.base)
        if "plus_bookmark_choice" in fields(guest.fetch(start)):
            raise RuntimeError("A guest received the personal bookmark option")
        p = member.fetch(prefs)
        if fields(p, "ucp").get("plus_bookmark_default") != str(int(source_enabled)):
            raise RuntimeError("The UCP did not display the migrated default")
        if not source_enabled:
            submit_form(member, prefs, p, "ucp", {"submit":"1", "plus_bookmark_default":"1"})
        first = member.fetch(start)
        if "Bookmark this topic" not in first or fields(first).get("setbm") != "1":
            raise RuntimeError("An enabled member default was not selected in the composer")
        draft = submit_form(member, start, first, "postform", {"preview":"1", "subject":name, "message":"Native preview", "setbm":None})
        if "setbm" in fields(draft) or fields(draft).get("plus_bookmark_choice") != "1":
            raise RuntimeError("Preview reselected a deliberately cleared checkbox")
        invalid = save(member, start, draft, subject="", message="Complete text", setbm="1")
        if fields(invalid).get("setbm") != "1":
            raise RuntimeError("A validation error discarded the checked bookmark option")
        verify("unpublished")
        save(member, start, invalid, subject=name, message="A native topic with a personal bookmark")
        topic, post, uid = json.loads(verify("created"))
        verify("member")
        reply = f"posting.php?mode=reply&t={topic}"
        quote = f"posting.php?mode=quote&p={post}"
        edit = f"posting.php?mode=edit&p={post}"
        if "plus_bookmark_choice" in fields(member.fetch(edit)):
            raise RuntimeError("Editing an existing post offered to replace bookmarks")
        save(member, reply, member.fetch(reply), message="Reply without changing the existing bookmark", setbm=None)
        save(member, quote, member.fetch(quote), message="Adding the same personal bookmark again", setbm="1")
        verify("member")
        opened = admin.fetch(reply)
        if "Lesezeichen für dieses Thema setzen" not in opened or "setbm" in fields(opened):
            raise RuntimeError("The German composer did not keep its own account’s default")
        verify("off")
        save(admin, reply, opened, message="Reply after bookmarks were switched off", setbm="1")
        if "plus_bookmark_choice" in fields(admin.fetch(reply)) or "plus_bookmark_default" in fields(member.fetch(prefs), "ucp"):
            raise RuntimeError("Disabled native bookmarks still offered a posting or account option")
        verify("member")
        verify("on")
        save(admin, reply, admin.fetch(reply), message="A separate member's bookmark", setbm="1")
        verify("both")
        if name not in member.fetch("ucp.php?i=ucp_main&mode=bookmarks"):
            raise RuntimeError("The topic was not available in the native bookmark list")
        submit_form(member, prefs, member.fetch(prefs), "ucp", {"submit":"1", "plus_bookmark_default":"0"})
        acp = admin_url(admin) + f"&i=users&mode=prefs&u={uid}"
        current = admin.fetch(acp)
        if fields(current, "user_prefs").get("plus_bookmark_default") != "0":
            raise RuntimeError("The ACP did not see the member’s saved preference")
        submit_form(admin, acp, current, "user_prefs", {"update":"1", "plus_bookmark_default":"1"})
        if fields(member.fetch(reply)).get("setbm") != "1":
            raise RuntimeError("The ACP preference was not applied to the member’s next reply")
        accepted = submit_form(guest, signup, guest.fetch(signup), "agreement", {"agreed":"1"})
        if fields(accepted, "register").get("plus_bookmark_default") != "0":
            raise RuntimeError("The optional signup preference had an unexpected default")
        password = "Az!78" + token_hex(10)
        submit_form(guest, signup, accepted, "register", {"submit":"1", "username":"BookmarkSignTest",
                    "email":"posting-bookmark@example.invalid", "new_password":password, "password_confirm":password,
                    "plus_bookmark_default":"1"})
        verify("signup")
        new_member = login(admin.base, "BookmarkSignTest", password)
        if fields(new_member.fetch(reply)).get("setbm") != "1":
            raise RuntimeError("The new account did not inherit its own registration preference")
        verify("hide-forum")
        if "plus_bookmark_choice" in fields(new_member.fetch(reply)):
            raise RuntimeError("A non-listed forum still offered a personal bookmark")
    finally:
        verify("restore")
