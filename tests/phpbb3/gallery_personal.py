"""Exercise personal gallery ownership, native group admission and category operations."""
from html import escape, unescape
import re
import time
from urllib.parse import urljoin, urlsplit

from gallery import ORIGINAL, submit
import gallery_media
from http_board import Browser, FormFields, Page, admin_url, submit_form


def browse(base, administrator, member, owner, check):
    guest = Browser(base)
    directory = "app.php/album/member/87"
    start = directory + "/0/start"
    form_id = "album-personal"
    check("restricted")
    bookmark = "app.php/album/legacy/member/"
    if "Persönliche Fotoprobe" not in member.fetch(bookmark + "1") or "Historisches Gastfoto" not in administrator.fetch(bookmark + "-1"):
        raise RuntimeError("A personal bookmark lost its remapped owner or the historical guest collection")
    member.fetch(bookmark + "-1", expected_status=404)
    guest.fetch(bookmark + "1", expected_status=404)
    for source in ("0", "-1"):
        if "login" not in Page(guest.fetch(bookmark + source)).forms:
            raise RuntimeError("A personal bookmark for the current member did not offer native sign-in")
    owner.fetch(start, expected_status=404)
    guest.fetch(directory, expected_status=404)
    if "/album/member/87" in guest.fetch("memberlist.php?mode=viewprofile&u=87"):
        raise RuntimeError("The profile advertised a private or missing album to visitors")
    check("approved")
    if "/album/member/87" not in owner.fetch("app.php/album"):
        raise RuntimeError("An approved album creator cannot find their own directory")
    initial = owner.fetch(start)
    for _ in range(2):
        listing = submit_form(owner, start, initial, form_id, {})
    roots = re.findall(r'href="([^"]*/album/member/87/(\d+)/edit[^"]*)"', listing)
    if len(roots) != 1:
        raise RuntimeError("Repeated creation did not leave exactly one personal root")
    root = roots[0][1]
    folder = lambda cat: "app.php/album/category/" + cat
    path = lambda cat, action: directory + "/" + cat + "/" + action
    guest.fetch(directory, expected_status=404)
    member.fetch(folder(root), expected_status=404)
    member.fetch(path(root, "edit"), expected_status=404)
    owner.fetch(path(root, "delete"), expected_status=404)
    if form_id not in Page(administrator.fetch(path(root, "delete"))).forms:
        raise RuntimeError("The album administrator cannot manage a member's root")

    def save(cat, mode, changes, status=200):
        url = path(cat, mode)
        return submit_form(owner, url, owner.fetch(url), form_id, changes, status)

    def cat_id(page, title):
        match = re.search(r'href="[^"]*/album/category/(\d+)(?:\?[^"]*)?">' + re.escape(escape(title)) + r'</a>', page)
        if not match:
            raise RuntimeError("A personal category is missing from its directory: " + title)
        return match[1]

    a_title, b_title = "Bilderbuch 📷 &amp; Grüße", "Unterwegs"
    values = dict(title=a_title, description="Neu &amp; früher 🌷", view="-1", upload="3", rate="0", comment="0")
    first = owner.fetch(path(root, "add"))
    listing = submit_form(owner, path(root, "add"), first, form_id, values)
    a = cat_id(listing, a_title)
    if "inzwischen geändert" not in submit_form(owner, path(root, "add"), first, form_id, values, 409):
        raise RuntimeError("An old form could duplicate a personal category")
    listing = save(root, "add", dict(values, title=b_title))
    b = cat_id(listing, b_title)
    if path(root, "add").removeprefix("app.php") in listing:
        raise RuntimeError("An owner at their subalbum limit still sees a create link")
    owner.fetch(path(root, "add"), expected_status=422)
    guest.fetch(folder(a), expected_status=404)
    save(root, "edit", {"view": "-1"})
    if "DepartingFixture" not in guest.fetch("app.php/album") or a_title not in unescape(owner.fetch(bookmark + "0")):
        raise RuntimeError("An album directory or a current owner's bookmark lost its member")
    if "Neu &amp;amp; früher 🌷" not in guest.fetch(folder(root)):
        raise RuntimeError("The public category changed the description's literal entity or four-byte character")
    if "/album/member/87" not in guest.fetch("memberlist.php?mode=viewprofile&u=87"):
        raise RuntimeError("The public profile has no visible link to its public album")
    if any("/edit" in unescape(url) for url in re.findall(r'href="([^"]*/album/member/87/[^"]+)"', guest.fetch(directory))):
        raise RuntimeError("A visitor was offered personal category controls")
    for direction, first_in_list in (("up", True), ("down", False)):
        listing = save(b, direction, {})
        if (listing.index(">" + b_title + "</a>") < listing.index(">" + escape(a_title) + "</a>")) != first_in_list:
            raise RuntimeError("Personal sibling reordering lost the displayed order")
    save(b, "edit", {"parent": a})
    edit = owner.fetch(path(a, "edit"))
    if FormFields(edit, form_id).values["title"] != a_title:
        raise RuntimeError("The personal editor changed literal entities or a four-byte character")
    submit_form(owner, path(a, "edit"), edit, form_id, {"parent": b}, 422)
    check("disable")
    owner.fetch(path(a, "edit"), expected_status=404)
    owner.fetch(path(a, "edit"), FormFields(edit, form_id).values, expected_status=404)
    check("enable")

    def upload(cat, name):
        route = folder(cat) + "/upload"
        action, data = Page(owner.fetch(route)).forms["album-upload"]
        data.update(title=name, description="Original")
        time.sleep(max(0, int(data["creation_time"]) + 1.05 - time.time()))
        owner.fetch(urljoin(urljoin(base, route), action), data, files=[("photos[]", "probe.png", "image/png", ORIGINAL)])
        match = re.search(r'<a href="([^"]*/album/picture/\d+[^"]*)"><img [^>]*><strong>' + re.escape(name) + '</strong>', owner.fetch(folder(cat)))
        if not match:
            raise RuntimeError("The personal upload was not listed")
        return urlsplit(unescape(match[1])).path

    pic = upload(a, "Album transfer")
    submit(owner, pic, "album-discuss", {"body": "Kommentar zum Bild"})
    submit(member, pic, "album-discuss", {"points": 8})
    before = member.fetch(pic + "/full", raw=True)
    save(a, "delete", {"destination": ""}, 422)
    listing = save(a, "delete", {"destination": root})
    if (a_title in unescape(listing) or cat_id(listing, b_title) != b or "Album transfer" not in guest.fetch(folder(root))
            or member.fetch(pic + "/full", raw=True) != before or "Kommentar zum Bild" not in member.fetch(pic)
            or FormFields(owner.fetch(path(b, "edit")), form_id).values["parent"] != root):
        raise RuntimeError("Removing the personal parent lost its child, photo, media or comments")
    old = owner.fetch(path(b, "delete"))
    removed = upload(b, "Temporary album picture")
    submit_form(owner, path(b, "delete"), old, form_id, {"destination": "remove"}, 409)
    save(b, "delete", {"destination": "remove"})
    guest.fetch(removed, expected_status=404)
    owner.fetch(folder(b), expected_status=404)
    check("complete")
    check("restore")
    configuration(administrator, owner, guest, root)
    check("inactive")
    guest.fetch(directory, expected_status=404)
    if "/album/member/87" in guest.fetch(folder(root)) or "(DepartingFixture)" in guest.fetch("app.php/album"):
        raise RuntimeError("An inactive owner still has an advertised member directory")
    check("active")
    gallery_media.manual(administrator, owner, guest, root, check)
    gallery_media.resize(administrator, owner, root, check)


def configuration(administrator, owner, guest, root):
    entry = admin_url(administrator)
    menu = administrator.fetch(entry + "&i=acp_board&mode=settings")
    link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Persönliche Fotoalben</span>', menu)
    if not link:
        raise RuntimeError("The administrator cannot reach the album rules from board configuration")
    url = urljoin(entry, unescape(link[1]))
    form = "album-personal-settings"
    first = administrator.fetch(url)
    original = {key: value for key, value in FormFields(first, form).values.items()
                if key.startswith("personal_") or key == "show_personal_gallery_link"}
    if original.get("personal_gallery_view") != "3" or len(original) != 7:
        raise RuntimeError("Converted personal album controls are missing or have changed defaults")
    changes = {"personal_gallery": "1", "personal_gallery_view": "0", "show_personal_gallery_link": "0", "personal_sub_category_limit": "0"}
    result = submit_form(administrator, url, first, form, dict(changes, personal_gallery_limit="2147483648"))
    if ("Prüfe den Wert für" not in result or FormFields(result, form).values["personal_gallery"] != "1" or
            FormFields(administrator.fetch(url), form).values["personal_gallery"] != original["personal_gallery"]):
        raise RuntimeError("An invalid album quota partially changed another policy")
    submit_form(administrator, url, first, form, changes)
    if "inzwischen geändert" not in submit_form(administrator, url, first, form, original):
        raise RuntimeError("An old administration form changed current album rules")
    profile = "memberlist.php?mode=viewprofile&u=87"
    category = "app.php/album/category/" + root
    if "/album/member/87" in guest.fetch(profile) or "Album transfer" not in guest.fetch(category):
        raise RuntimeError("The profile link switch changed visibility of an existing gallery")
    owner.fetch(category + "/upload", expected_status=404)
    saved = submit_form(administrator, url, administrator.fetch(url), form, original)
    if (FormFields(saved, form).values["personal_gallery"] != original["personal_gallery"] or
            "/album/member/87" not in guest.fetch(profile) or "album-upload" not in Page(owner.fetch(category + "/upload")).forms):
        raise RuntimeError("Restoring the album settings did not restore profile links and owner uploads")
