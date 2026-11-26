"""Read, visit and review websites from the converted directory without contacting their destinations."""
from http.client import HTTPConnection
from html import unescape
import os
import re
import shutil
from urllib.parse import urljoin, urlsplit
from http_board import Browser, Page, admin_url, submit_form
from legacy_links import link
from download_mail import mailbox


def source_media(source, legacy):
    target = legacy / "images/links"
    target.mkdir(parents=True, exist_ok=True)
    for name in ("phpBB_88a.gif", "phpbb-tw_logo88a.gif", "phpbb2_de_logo.gif", "forumstyles_button.gif",
                 "oxpus_banner.gif", "web_logo88a.gif"):
        shutil.copyfile(source / "images/links" / name, target / name)


def browse(base, admin, member, verify):
    with mailbox() as sink:
        exercise(base, admin, member, verify, sink)


def exercise(base, admin, member, verify, sink):
    home = "app.php/links"
    submit = home + "/submit"
    guest = Browser(base)
    def ids(page):
        return [int(n) for n in re.findall(r'data-weblink="(\d+)"', page)]

    def redirect_only(path, method):
        origin = urlsplit(base)
        if origin.hostname != "127.0.0.1":
            raise ValueError("Only disposable loopback boards support this test")
        conn = HTTPConnection(origin.hostname, origin.port, timeout=10)
        try:
            conn.request(method, "/" + path)
            reply = conn.getresponse()
            if reply.status != 302 or reply.getheader("Location") != "https://directory.example.invalid/old":
                raise RuntimeError("The directory did not redirect to the stored approved destination")
            reply.read()
        finally:
            conn.close()

    verify("import:" + str(sink.server_address[1]))
    try:
        index = guest.fetch(home)
        if "/links" not in guest.fetch("app.php/portal") or "Linkverzeichnis" not in index:
            raise RuntimeError("The guest portal does not expose the German directory")
        link(base, "links.php?t=sub_pages&cat=711", home, {"t":"sub_pages","cat":"711"})
        link(base, "links.php?action=go&link_id=4111", home + "/visit/4111", {})
        link(base, "link_register.php", submit, {})
        link(base, "links.php", home, {}, method="POST", form={"t":"search","search_keywords":"Café"}, status=307)
        if "login" not in Page(guest.fetch(submit)).forms or ids(guest.fetch(home, {"t":"search","search_keywords":"Café"})) != [4111]:
            raise RuntimeError("The guest login or historical read-only search form did not survive")
        if index.index("Leer") >= index.index("Verzeichnis &amp; Café") or "Verzeichnis &amp; Café</a> (3)" not in index:
            raise RuntimeError("Imported category order or approved counts diverged")
        cat = home + "?t=sub_pages&cat=711&mode=link_title&order=ASC"
        if ids(guest.fetch(cat)) != [4113,4112] or ids(guest.fetch(cat + "&start=2")) != [4111]:
            raise RuntimeError("Category sorting or configured page size changed")
        star = guest.fetch(home + "?t=search&search_keywords=100%25_")
        if ids(star) != [4112] or "Ehemaliges Mitglied" not in star or "Stern ⭐" not in unescape(star):
            raise RuntimeError("Literal search, emoji or former submitter did not survive")
        if ids(guest.fetch(home + "?t=pop"))[:1] != [4112] or 4114 in ids(guest.fetch(home + "?t=new")):
            raise RuntimeError("Popular and recent lists included a pending website or ignored visit counts")
        image = guest.fetch(home + "/logo/4111", raw=True)
        if not image.startswith(b"GIF"):
            raise RuntimeError("An original local directory logo was not restored")
        guest.fetch(home + "/logo/4114", expected_status=404)
        guest.fetch(home + "/visit/4114", expected_status=404)
        for method in ("HEAD", "GET", "GET"):
            redirect_only(home + "/visit/4111", method)
        verify("count")
        form = member.fetch(submit)
        data = {"title":"Local suggested site","description":r"Öffentliche Beschreibung C:\nachlesbar","destination":"https://directory.example.invalid/submitted",
                "logo":"","cat_id":"711","submit":"1"}
        missing = submit_form(member, submit, form, "wl-submit", data)
        if "requires a logo address" not in missing:
            raise RuntimeError("The imported rule for a required logo did not apply")
        data["logo"] = "https://directory.example.invalid/new.png"
        preview = submit_form(member, submit, missing, "wl-submit", dict(data, submit=None, preview="1"))
        decoded = unescape(preview)
        if 'src="https://directory.example.invalid/new.png"' not in decoded or "Local suggested site" not in decoded:
            raise RuntimeError("Missing preview image or draft title: " + str(("https://directory.example.invalid/new.png" in decoded,
                                                                              "Local suggested site" in decoded, "wl-submit" in decoded)))
        saved = submit_form(member, submit, preview, "wl-submit", data)
        if "submitted for review" not in saved or "Local suggested site" in guest.fetch(home + "?t=new"):
            raise RuntimeError("An ordinary submission bypassed moderation")
        if "one minute" not in submit_form(member, submit, form, "wl-submit", data):
            raise RuntimeError("The wait interval between submissions was lost")
        verify("submitted")
        notices = [message for message in sink.mail if message["Subject"] in ("Website wartet auf Freigabe", "Website awaiting review")]
        if (not any("modern@example.invalid" in str(message["To"]) and "Local suggested site" in message.get_content() for message in notices)
                or any("historic@example.invalid" in str(message["To"]) for message in notices)):
            raise RuntimeError("Local SMTP did not receive the review notice for the correct audience")
        acp = admin_url(admin)
        sign_in = admin.fetch(acp)
        if "login" in Page(sign_in).forms:
            token = Page(sign_in).forms["login"][1]["credential"]
            submit_form(admin, acp, sign_in, "login", {"username":"ModernMember","login":"1",
                "password_" + token:os.environ["PLUS_TEST_MODERN_PASSWORD"]})
            acp = admin_url(admin)
        menu = admin.fetch(acp + "&i=acp_board&mode=settings")
        match = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Linkverzeichnis verwalten</span>', menu)
        if not match:
            raise RuntimeError("Website directory review is missing from the native board ACP")
        target = urljoin(acp, unescape(match[1]))
        queue = admin.fetch(target)
        name = "wl-review-947750"
        if name not in Page(queue).forms or "Alter Entwurf" not in queue:
            raise RuntimeError("The native ACP review queue did not include imported and new pending entries")
        submit_form(admin, target, queue, name, {"decision":"approve"})
        if "inzwischen geändert" not in submit_form(admin, target, queue, name, {"decision":"decline"}):
            raise RuntimeError("An older review form changed a previously approved entry")
        if 947750 not in ids(guest.fetch(home + "?t=new")) or ids(guest.fetch(home + "?t=search&search_keywords=%5Cnachlesbar")) != [947750]:
            raise RuntimeError("An approved website was missing from the public directory")
        live = target + "&tab=1"
        submit_form(admin, live, admin.fetch(live), name, {"decision":"decline"})
        if 947750 in ids(guest.fetch(home + "?t=new")) or name not in Page(admin.fetch(target + "&tab=2")).forms:
            raise RuntimeError("A declined website was exposed or discarded")
        verify("lock")
        member.fetch(submit, expected_status=403)
        if "/links/submit" in guest.fetch(home):
            raise RuntimeError("Locked submissions still appeared in the directory")
        data.update(title="Local published site", destination="https://directory.example.invalid/published")
        if "veröffentlicht" not in submit_form(admin, submit, admin.fetch(submit), "wl-submit", data):
            raise RuntimeError("The separately privileged publisher could not publish while suggestions were closed")
    finally:
        verify("cleanup")
