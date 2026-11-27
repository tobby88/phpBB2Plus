"""Check personal and fallback avatars through the actual viewer, board and gallery controls."""
import base64
from html import unescape
import json
from pathlib import Path
import re
from urllib.parse import urljoin

from delegation import authenticate
from http_board import Browser, FormFields, Page, admin_url, submit_form


CUSTOM = base64.b64decode("R0lGODlhAQABAIAAAAUIAv///ywAAAAAAQABAAACAUwAOw==")


def source_media(legacy):
    (legacy / "images/default_avatar.gif").write_bytes(CUSTOM)


def browse(base, board, target, php, owner=None):
    fixture = Path(__file__).with_suffix(".php")
    data = json.loads(php(fixture, target, "prepare", cwd=board, capture=True))
    try:
        if owner is None:
            owner, entry = authenticate(base, "ModernMember")
        else:
            entry = admin_url(owner)
        guest = Browser(base)
        acp = entry + "&i=acp_board&mode=avatar"
        topic = "viewtopic.php?t=28701"
        marker = "plus-default-avatar"
        def set_option(key, value):
            page = owner.fetch(acp)
            if "config["+key+"]" not in FormFields(page, "acp_board").values:
                raise RuntimeError("The native avatar ACP has no option " + key)
            submit_form(owner, acp, page, "acp_board", {"submit": 1, "config["+key+"]": str(value)})
        def rows(actor):
            page = actor.fetch(topic)
            return {int(k): v for k, v in re.findall(r'<div id="p(\d+)" class="post has-profile(.*?)(?=<div id="p\d+" class="post has-profile|<!-- EVENT viewtopic_body_topic_actions_before|$)',page,re.S)}
        def check(actor, enabled):
            posts = rows(actor)
            shown = {pid for pid, html in posts.items() if marker in html}
            if shown != ({28701,28702} if enabled else set()):
                raise RuntimeError("Unexpected fallback avatars in rendered posts: " + str(shown))
            return posts
        if "Standardavatar in Themen anzeigen" not in owner.fetch(acp):
            raise RuntimeError("The migrated avatar switch is absent from the native ACP")
        check(guest, False)
        set_option("plus_topic_default_avatar", 1)
        for actor in (owner, guest):
            posts = check(actor, True)
            if "Blauer%20Stern.png" not in posts[28703] or "example.invalid/avatar.jpg" not in posts[28706] or "file.php?avatar=" not in posts[28708]:
                raise RuntimeError("The fallback replaced an actual local, remote or uploaded avatar")
            image = re.search(r'<img class="avatar plus-default-avatar" src="([^"]+)"',posts[28702])
            if not image or not unescape(image[1]).endswith('/' + data["image"]) or actor.fetch(urljoin(base,unescape(image[1])),raw=True) != CUSTOM:
                raise RuntimeError("The topic uses a different bitmap from the operator's original")
        stock = (Path(__file__).resolve().parents[2] / "phpBB2/images/default_avatar.gif").read_bytes()
        folder = board / "images/plusavatars"
        if (folder/"stock.gif").read_bytes() != stock:
            raise RuntimeError("The Plus package lost the original default artwork")
        override = folder / "default_avatar.gif"
        with override.open("xb") as local:
            local.write(stock)
        try:
            image = re.search(r'<img class="avatar plus-default-avatar" src="([^"]+)"',rows(guest)[28701])
            if not image or not image[1].endswith("/default_avatar.gif") or guest.fetch(urljoin(base,unescape(image[1])),raw=True) != stock:
                raise RuntimeError("The documented local replacement was ignored")
        finally:
            override.unlink()
        if marker in owner.fetch("memberlist.php?mode=viewprofile&u="+str(data["profile"])):
            raise RuntimeError("The topic default was assigned to the user's profile")
        ucp = "ucp.php?i=ucp_prefs&mode=view"
        submit_form(owner,ucp,owner.fetch(ucp),"ucp",{"submit":1,"avatars":"0"})
        check(owner,False)
        check(guest,True)
        submit_form(owner,ucp,owner.fetch(ucp),"ucp",{"submit":1,"avatars":"1"})
        check(owner,True)
        for key in ("allow_avatar_local","allow_avatar"):
            set_option(key,0)
            check(owner,False)
            set_option(key,1)
        check(owner,True)
        personal = entry + "&i=acp_users&mode=avatar&u=" + str(data["withdrawn"])
        personal_page = owner.fetch(personal)
        if 'value="avatar_driver_local"' not in personal_page:
            raise RuntimeError("The native personal-avatar selector lost its local gallery driver")
        submit_form(owner, personal, personal_page, "avatar_settings", {"update": 1, "avatar_driver": "avatar_driver_local",
                    "avatar_local_cat": "Plus-Anderes", "avatar_local_file": "Unused.png"})
        if "Plus-Anderes/Unused.png" not in rows(owner)[28704]:
            raise RuntimeError("A subsequently selected personal avatar did not take precedence")
        remove = submit_form(owner, personal, owner.fetch(personal), "avatar_settings", {"update": 1,"avatar_delete": 1})
        action, fields = Page(remove).forms["confirm"]
        fields["confirm"] = unescape(re.search(r'name="confirm" value="([^"]+)"',remove)[1])
        owner.fetch(urljoin(personal,action),fields)
        if marker not in rows(owner)[28704]:
            raise RuntimeError("Explicit removal kept the earlier source-only suppression")
    finally:
        php(fixture,target,"clean",cwd=board)
