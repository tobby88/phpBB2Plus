"""Follow paFileDB category permissions, licence forms and protected downloads."""
import re
import time
from html import unescape
from urllib.parse import urljoin
from http_board import Browser, FormFields, Page
from gallery import ORIGINAL

PAYLOAD = "Fixture-Datei mit Grüßen 🎨\n".encode()
MIRROR = b"Separate mirror fixture\n"


def source_media(legacy):
    folder = legacy / "pafiledb/uploads"
    folder.mkdir(parents=True, exist_ok=True)
    for name, data in (("library-50.txt", PAYLOAD), ("mirror-50.txt", MIRROR), ("club-51.txt", b"Club fixture"), ("draft-52.txt", b"Draft fixture")):
        (folder / name).write_bytes(data)
    previews = legacy / "pafiledb/images/screenshots"
    previews.mkdir(parents=True, exist_ok=True)
    (previews / "vorschau.png").write_bytes(ORIGINAL)


def browse(base, founder, member, pending):
    guest = Browser(base)
    root = "app.php/downloads"
    if "/downloads" not in guest.fetch("app.php/portal"):
        raise RuntimeError("The portal does not link to the download library")
    for actor, permitted in ((guest, False), (pending, False), (member, True), (founder, True)):
        index = actor.fetch(root + "/all")
        search = actor.fetch(root + "?q=Fixture")
        for title in ("Clubbibliothek", "Privatfreigabe"):
            if (title in index) != permitted or (title in search) != permitted:
                raise RuntimeError("Download listings or search ignored inherited or personal permissions")
        if "Ungeprüfter Entwurf" in index or "Ungeprüfter Entwurf" in search:
            raise RuntimeError("An unapproved download appeared in the public listing")
        if not permitted:
            for suffix in ("/category/23", "/file/51", "/file/51/get/0", "/file/51/get/9", "/file/54", "/file/52"):
                actor.fetch(root + suffix, expected_status=404)
    if member.fetch(root + "/file/51/get/0", raw=True) != b"Club fixture" or "Ungeprüfter Entwurf" not in member.fetch(root + "/file/52"):
        raise RuntimeError("Approved group access or access to the owner's draft was lost")
    detail = guest.fetch(root + "/file/50")
    publisher = re.search(r"<dt>Eingereicht von</dt><dd>(.*?)</dd>", detail, re.S)
    if (not publisher or "HistoricMember</a>" not in publisher[1] or
            any(markup not in detail for markup in ("<dt>Autor</dt><dd>Fiktive Autorin</dd>", "<dt>Hinzugefügt am</dt>",
                                                     f"<dt>Größe</dt><dd>{len(PAYLOAD)} Bytes</dd>",
                                                     "<dt>Zuletzt heruntergeladen</dt><dd>Niemals</dd>", "9 / 10 (2 Stimmen)"))):
        raise RuntimeError("The converted file lost its submitter, creator, size, dates or original rating")
    external = member.fetch(root + "/file/54")
    if ("<dt>File size</dt><dd>1 KiB</dd>" not in external or
            not all(re.search(r"<dt>" + label + r"</dt><dd>[^<]*2024[^<]*</dd>", external)
                    for label in ("Last update", "Last downloaded"))):
        raise RuntimeError("The stored size or timestamps of an external download were lost")
    sources = guest.fetch(root + "/file/50/download")
    if "Downloadquellen" not in sources or "Originaldatei" not in sources or "Lokale Kopie &amp;amp; 📡" not in sources:
        raise RuntimeError("The source chooser did not distinguish the original and its literal mirror label")
    alternative = member.fetch(root + "/file/53/download")
    if "Mirror 10" not in alternative or "The original file is unavailable." not in alternative or "/file/53/get/10" not in alternative:
        raise RuntimeError("A file with only an unnamed remote mirror had no usable download choice")
    if "Blau, Rot" not in guest.fetch("dload.php?action=file%3Ffile_id%3D50") or "Beispiellizenz" not in guest.fetch("dload.php?action=download&file_id=50&mirror_id=8"):
        raise RuntimeError("Historical file and mirror links did not reach the converted item")
    if any(text not in detail for text in ("Zeichen &amp; Farben", "Blau, Rot", "Testkommentar", "HistoricMember", "Lokale Kopie")) or not re.search(r"<strong[^>]*>Beispiel</strong>", detail):
        raise RuntimeError("Download details lost a custom field, comment, mirror, or native BBCode")
    if guest.fetch(root + "/file/50/preview", raw=True) != ORIGINAL:
        raise RuntimeError("The protected download screenshot differs from the source")
    guest.fetch(root + "/file/50/get/9", expected_status=404)
    guest.fetch(root + "/file/53/get/0", expected_status=404)
    missing = guest.fetch(root + "/file/53")
    if any(text not in missing for text in ("Originaldatei", "<dt>Größe</dt><dd>Unbekannt</dd>",
                                             "<dt>Letzte Änderung</dt><dd>Niemals</dd>", ">Ohne Bewertung</dd>")):
        raise RuntimeError("Missing files should retain their metadata and explain their status")
    for mirror, expected in ((0, PAYLOAD), (8, MIRROR)):
        path = root + f"/file/50/get/{mirror}"
        license_page = member.fetch(path)
        if "Beispiellizenz" not in license_page or "fiktiven Test" not in license_page:
            raise RuntimeError("Missing download terms")
        action, fields = Page(license_page).forms["dl-license"]
        fields["accept"] = "1"
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        if member.fetch(urljoin(urljoin(base, path), action), fields, raw=True) != expected:
            raise RuntimeError("A licensed download or mirror returned different bytes")
    if "<dt>Zuletzt heruntergeladen</dt><dd>Niemals</dd>" in guest.fetch(root + "/file/50"):
        raise RuntimeError("A successful protected download did not update the file details")
    feedback(base, pending, founder, guest)


def feedback(base, author, moderator, guest):
    page = "app.php/downloads/file/50"
    url = urljoin(base, page)
    initial = author.fetch(page)
    if 'src="https://example.invalid/forbidden-preview.png"' in initial or "[No image please]" not in initial:
        raise RuntimeError("An imported comment ignored the paFileDB media settings")
    forms = Page(initial).forms
    action, comment = forms["dl-comment"]
    vote_url, vote = forms["dl-rate"]
    comment.update(subject="Fiktiver Folgekommentar", message="Ein [b]Farbkreis[/b] 🎨")
    vote["points"] = "6"
    time.sleep(max(0, int(comment["creation_time"]) + 1.05 - time.time()))
    for _ in range(2):
        posted = author.fetch(urljoin(url, action), comment)
        author.fetch(urljoin(url, vote_url), vote)
    if posted.count("Fiktiver Folgekommentar") != 1 or not re.search(r"<strong[^>]*>Farbkreis</strong>", posted):
        raise RuntimeError("A repeated comment or its native BBCode did not round-trip")
    link = re.search(r'href="([^"]+/comment/([0-9]+)/delete[^"]*)"', posted)
    if not link:
        raise RuntimeError("The author cannot manage their download comment")
    delete = urljoin(url, link.group(1))
    guest.fetch(delete, expected_status=404)
    vote_url, fresh = Page(author.fetch(page)).forms["dl-rate"]
    action, remove = Page(moderator.fetch(delete)).forms["dl-remove"]
    time.sleep(max(0, max(int(fresh["creation_time"]), int(remove["creation_time"])) + 1.05 - time.time()))
    author.fetch(urljoin(url, vote_url), dict(fresh, points="9"), expected_status=422)
    after = moderator.fetch(urljoin(delete, action), remove)
    if "Fiktiver Folgekommentar" in after or "Testkommentar" not in after:
        raise RuntimeError("The moderator did not remove the selected comment")


def submissions(base, founder, moderator, author, verify):
    verify("begin")
    guest = Browser(base)
    root = "app.php/downloads"
    category = root + "/category/21"
    club = root + "/category/23"
    upload = category + "/submit"
    queue = category + "/review"

    def post(actor, url, page, name, changes=None, files=None, status=200, raw=False):
        target, fields = Page(page).forms[name]
        time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
        return actor.fetch(urljoin(urljoin(base, url), target), dict(fields, **(changes or {})),
                           files=files, expected_status=status, raw=raw)

    def toolbar(path, viewer):
        return re.search(r'<nav id="dl-tools".*?</nav>', viewer.fetch(path), re.S)[0]

    destinations = author.fetch(root + "/submit")
    if "Öffentliche Dateien" not in destinations or "/23/submit" in destinations:
        raise RuntimeError("An upload destination ignored a private parent")
    if ("/category/21/submit" not in toolbar(root + "/file/50", author) or
            "/downloads/submit" not in toolbar(root + "/file/50", guest) or
            "/submit" in toolbar(category, guest) or "/submit" in toolbar(upload, author)):
        raise RuntimeError("The upload toolbar offered the wrong form for the current category or visitor")
    if not re.search(r'/en/icon_pa_search\.gif"[^>]*alt="Advanced download search"', toolbar(category, moderator)):
        raise RuntimeError("The English download toolbar did not serve its localized artwork and label")
    guest.fetch(upload, expected_status=404)
    author.fetch(club + "/submit", expected_status=404)
    moderator.fetch(club + "/review", expected_status=404)
    author.fetch(queue, expected_status=404)
    page = author.fetch(upload)
    if "Farbauswahl" not in page or 'value="Rot"' not in page:
        raise RuntimeError("The upload form lost the migrated custom field options")
    title = "Klassenplan 📘 &amp; Entwurf"
    shown = "Klassenplan 📘 &amp;amp; Entwurf"
    fields = {"title": title, "description": "Plan mit Grüßen", "license": "31", "version": "2", "field[81][]": "Rot"}
    assets = [("original", "Plan & Grüße.txt", "text/plain", PAYLOAD), ("screenshot", "Plan.png", "image/png", ORIGINAL)]
    invalid = post(author, upload, page, "dl-submit", dict(fields, title=""), assets, 422)
    if 'id="dl-submit"' not in invalid or "Plan mit Grüßen" not in invalid:
        raise RuntimeError("An invalid download submission discarded its form details")
    post(author, upload, page, "dl-submit", fields, [("original", "Plan.txt", "text/plain", b"x" * 262145)], 422)
    post(author, upload, page, "dl-submit", fields, [("original", "Plan.pdf", "application/pdf", PAYLOAD)], 422)
    verify("offline")
    unavailable = post(author, upload, page, "dl-submit", fields, assets, 503)
    verify("online")
    if 'id="dl-submit"' not in unavailable or "Plan mit Grüßen" not in unavailable:
        raise RuntimeError("A temporary storage failure lost the submission details")
    for _ in range(2):
        submitted = post(author, upload, page, "dl-submit", fields, assets)
        if (shown not in submitted or "Rot</dd>" not in submitted or "Freigabe" not in submitted):
            raise RuntimeError("The owner's pending download lost its fields or approval status")
    pending = founder.fetch(queue)
    match = re.findall(r'href="([^"]*/downloads/file/(\d+)[^"]*)">' + re.escape(shown) + '</a>', pending)
    if len(match) != 1 or shown in guest.fetch(category):
        raise RuntimeError("Retrying a submission produced a duplicate or exposed the draft")
    item = urljoin(base, unescape(match[0][0])).split("?", 1)[0]
    verify("record-assets-" + match[0][1])
    guest.fetch(item, expected_status=404)
    guest.fetch(item + "/preview", expected_status=404)
    moderator.fetch(item + "/review/approve", expected_status=404)
    approve = item + "/review/approve"
    reject = item + "/review/reject"
    old = founder.fetch(reject)
    post(founder, approve, founder.fetch(approve), "dl-review")
    if shown not in guest.fetch(category) or guest.fetch(item + "/preview", raw=True) != ORIGINAL:
        raise RuntimeError("Approval did not publish the original screenshot and file entry")
    source = item + "/get/0"
    if post(author, source, author.fetch(source), "dl-license", {"accept": "1"}, raw=True) != PAYLOAD:
        raise RuntimeError("A licensed upload returned different bytes")
    withdraw = item + "/review/withdraw"
    post(founder, withdraw, founder.fetch(withdraw), "dl-review")
    guest.fetch(item, expected_status=404)
    post(founder, reject, old, "dl-review", status=409)
    post(founder, reject, founder.fetch(reject), "dl-review")
    founder.fetch(item, expected_status=404)
    post(author, upload, page, "dl-submit", fields, assets, 422)
    verify("cleanup")

    new = club + "/submit"
    club_entry = post(moderator, new, moderator.fetch(new), "dl-submit",
                      {"title": "Clubverweis", "description": "Quelle im Archiv", "url": "https://example.invalid/club-source"})
    if "Clubverweis" not in club_entry or "Clubverweis" not in moderator.fetch(club):
        raise RuntimeError("A category moderator's own submission lost immediate publication")
    verify("moderators")
    if "Clubverweis" not in moderator.fetch(club + "/review?status=published"):
        raise RuntimeError("The configured category validator cannot review club downloads")
    moderator.fetch(queue, expected_status=404)
    inbox = root + "/category/25/submit"
    guest_submission = post(guest, inbox, guest.fetch(inbox), "dl-submit",
                            {"title": "Gastverweis", "description": "Post für das Archiv", "url": "https://example.invalid/guest"})
    if "eingereicht" not in guest_submission or "Gastverweis" in guest.fetch(root + "/category/25"):
        raise RuntimeError("Explicit guest uploads lost their confirmation or exposed an unapproved submission")
    verify("automatic")
    public = post(author, upload, author.fetch(upload), "dl-submit",
                  {"title": "Öffentlicher Verweis", "description": "Ohne manuelle Freigabe", "url": "https://example.invalid/archive"})
    if "Öffentlicher Verweis" not in public or "Öffentlicher Verweis" not in guest.fetch(category):
        raise RuntimeError("The automatic publication setting did not apply to a member's submission")

    edit_downloads(founder, moderator, author, guest, post, verify)


def edit_downloads(founder, moderator, author, guest, post, verify):
    verify("editing")
    item = "app.php/downloads/file/8021"
    edit = item + "/edit"
    delete = item + "/delete"
    approve = item + "/review/approve"
    fresh = author.fetch(edit)
    if ("Farben grüßen.txt" not in fresh or re.search(r'value="23"[^>]*>Clubdateien', fresh)
            or not all(f'value="{colour}" selected' in fresh for colour in ("Blau", "Rot"))):
        raise RuntimeError("The converted original and custom fields are missing from the edit form")
    guest.fetch(edit, expected_status=404)
    moderator.fetch(edit, expected_status=404)
    moderator.fetch(delete, expected_status=404)
    def form_values(page):
        values = FormFields(page, "dl-edit").values
        for key in ("field[81][]", "original", "screenshot", "form_token", "creation_time", "revision"):
            values.pop(key, None)
        # Two distinct scalar controls also work with the multipart fixture encoder.
        values.update({"field[81][0]": "Blau", "field[81][1]": "Rot"})
        return values

    original = form_values(fresh)
    if original["description"] != "Mehrzeilige Beschreibung" or original["license"] != "31" or original["category"] != "21":
        raise RuntimeError("Editing did not open the converted licence, description and category")
    old_delete = author.fetch(delete)
    old_approval = founder.fetch(approve)
    rejected = post(author, edit, fresh, "dl-edit", dict(original, category="23"), status=422)
    if "Mehrzeilige Beschreibung" not in rejected:
        raise RuntimeError("An unavailable destination discarded the edit draft")
    verify("offline")
    replacement = [("original", "Ersatz.txt", "text/plain", b"Revised download\n"), ("screenshot", "Neu.png", "image/png", ORIGINAL)]
    post(author, edit, fresh, "dl-edit", original, replacement, 503)
    verify("online")
    download = item + "/get/0"
    if post(author, download, author.fetch(download), "dl-license", {"accept": "1"}, raw=True) != PAYLOAD:
        raise RuntimeError("A failed replacement lost the previous file")
    details = dict(original, title="Fahrplan neu", description="Geänderte Beschreibung")
    saved = post(author, edit, fresh, "dl-edit", details, replacement)
    if "Fahrplan neu" not in saved or "Freigabe" not in saved or "Blau, Rot" not in saved:
        raise RuntimeError("Replacing a pending original changed its approval or imported metadata")
    if post(author, download, author.fetch(download), "dl-license", {"accept": "1"}, raw=True) != b"Revised download\n":
        raise RuntimeError("The replacement bytes could not be downloaded")
    guest.fetch(item, expected_status=404)
    post(author, edit, fresh, "dl-edit", original, replacement, 409)
    post(author, delete, old_delete, "dl-delete", status=409)
    post(founder, approve, old_approval, "dl-review", status=409)
    stale = author.fetch(edit)
    post(founder, approve, founder.fetch(approve), "dl-review")
    post(author, edit, stale, "dl-edit", details, status=409)
    if guest.fetch(item + "/preview", raw=True) != ORIGINAL:
        raise RuntimeError("Approval lost the replacement screenshot")
    moderator.fetch(edit, expected_status=404)
    moderator.fetch(delete, expected_status=404)

    def edit_page(actor, changes):
        page = actor.fetch(edit)
        return post(actor, edit, page, "dl-edit", dict(form_values(page), **changes))

    edit_page(author, {"category": "25", "url": "https://example.invalid/revised", "remove_preview": "1"})
    author.fetch(delete, expected_status=404)
    post(author, delete, old_delete, "dl-delete", status=404)
    guest.fetch(item + "/preview", expected_status=404)
    verify("moved")
    edit_page(founder, {"category": "23"})
    author.fetch(edit, expected_status=404)
    guest.fetch(item, expected_status=404)
    removal = moderator.fetch(delete)
    if FormFields(moderator.fetch(edit), "dl-edit").values["title"] != "Fahrplan neu" or "Fahrplan neu" not in removal:
        raise RuntimeError("The destination category moderator cannot maintain the moved download")
    moderator.fetch(item + "/review/withdraw", expected_status=404)
    post(moderator, delete, removal, "dl-delete")
    owned = "app.php/downloads/file/8022/delete"
    post(author, owned, author.fetch(owned), "dl-delete")
    verify("edited_cleanup")
