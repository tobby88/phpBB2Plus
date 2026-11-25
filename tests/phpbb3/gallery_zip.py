"""Submit ordinary gallery ZIPs to the native PHP upload, approval and quota flows."""
from io import BytesIO
import struct
from zipfile import ZipFile, ZIP_DEFLATED

from gallery import ORIGINAL, png, submit
from gallery_media import picture, settings_url, upload
from http_board import FormFields, submit_form


def archive(entries):
    result = BytesIO()
    with ZipFile(result, "w", ZIP_DEFLATED) as package:
        for name, data in entries:
            package.writestr(name, data)
    return ("Fotos.ZIP", "application/zip", result.getvalue())


def browse(admin, owner, guest, root):
    settings = settings_url(admin)
    form_id = "album-media-settings"
    values = FormFields(admin.fetch(settings), form_id).values
    keys = ("zip_uploads", "multiple_uploads", "max_uploads", "gd_version", "resize_pic", "max_file_size",
            "max_width", "max_height", "resize_width", "resize_height")
    initial = {key: values[key] for key in keys}

    def configure(**changes):
        submit_form(admin, settings, admin.fetch(settings), form_id, changes)

    def remove(actor, pictures):
        urls = [item + "/manage/delete" for item in pictures]
        forms = [actor.fetch(url) for url in urls]
        for url, form in zip(urls, forms):
            submit_form(actor, url, form, "album-manage", {})

    public = "app.php/album/category/1"
    private = "app.php/album/category/" + root
    public_form, private_form = public + "/upload", private + "/upload"
    first, second = "Früh am See", "Auf dem Heimweg"
    small = png(11, 7, b"\x13\x36\x61")
    entries = [("Bilder/", b""), ("Bilder/" + first + ".png", ORIGINAL), (second + ".png", small)]
    package = [archive(entries)]
    configure(zip_uploads="0", multiple_uploads="0", max_uploads="2", gd_version="2", resize_pic="0", max_file_size="512")
    if "application/zip" in owner.fetch(private_form) or "deaktiviert" not in upload(owner, private_form, "", package, status=422)[0]:
        raise RuntimeError("The configured ZIP switch changed only the picker or only the upload handler")
    configure(zip_uploads="1")
    if "application/zip" not in owner.fetch(private_form):
        raise RuntimeError("The archive picker is missing with the zip extension and converted settings")
    guest.fetch(public_form, expected_status=404)
    # An incidental text file and an overfull photo archive must leave the first image unpublished.
    invalid = None
    for tail in (("Liesmich.txt", b"Beschreibung"), ("Dritter Blick.png", ORIGINAL)):
        _, invalid = upload(owner, private_form, "", [archive(entries + [tail])], status=422, retry=invalid)
    landscape = png(800, 600, b"\x27\x6c\x1b")
    failure, _ = upload(owner, private_form, "", [archive([("Landschaft.png", landscape)])], status=422, retry=invalid)
    if "entpackte Dateigröße" not in failure:
        raise RuntimeError("The configured uncompressed archive budget was not applied")
    if first in owner.fetch(private):
        raise RuntimeError("Part of an invalid archive was published")

    # A single multipart file may carry several pictures, each subject to approval and membership quotas.
    result, retry = upload(owner, public_form, "", package)
    if "Freigabe" not in result:
        raise RuntimeError("Archived pictures did not follow the destination approval rule")
    upload(owner, public_form, "", package, retry=retry)
    stored = [picture(admin, public, title) for title in (first, second)]
    for title, route, data in zip((first, second), stored, (ORIGINAL, small)):
        if admin.fetch(public).count(title) != 1 or admin.fetch(route + "/full", raw=True) != data:
            raise RuntimeError("An accepted archive changed its original, member name, or repeat receipt")
        guest.fetch(route + "/small", expected_status=404)
    if "Albumkontingent" not in upload(owner, public_form, "", package, status=422)[0]:
        raise RuntimeError("A ZIP was charged as a single photo against the member quota")
    submit(admin, stored[0] + "/manage/approve", "album-manage", {})
    if first not in guest.fetch(public):
        raise RuntimeError("A single picture in an archived batch cannot be approved")
    remove(admin, stored)

    configure(gd_version="0", resize_pic="1", max_width="32", max_height="32", resize_width="20", resize_height="18")
    crops = [("crop-A.png", png(8, 6, b"\x10\x40\x20")), ("crop-B.png", png(9, 7, b"\x15\x20\xaa"))]
    upload(owner, private_form, "", package, [archive(crops[:1])], 422)
    title = "ZIP-Fotos"
    _, retry = upload(owner, private_form, title, package, [archive(crops)])
    upload(owner, private_form, title, package, [archive(crops)], retry=retry)
    upload(owner, private_form, title, package, [archive(list(reversed(crops)))], 422, retry)
    numbered = []
    for index, expected_size in enumerate(((20, 12), (11, 7))):
        label = title + " - %03d" % (index + 1)
        route = picture(owner, private, label)
        original = admin.fetch(route + "/full", raw=True)
        if (owner.fetch(private).count(label) != 1 or admin.fetch(route + "/small", raw=True) != crops[index][1] or
                struct.unpack(">II", original[16:24]) != expected_size or (index == 1 and original != small)):
            raise RuntimeError("A paired ZIP changed the order, crop, number, output size, or untouched original")
        numbered.append(route)
    remove(owner, numbered)

    configure(gd_version="2", multiple_uploads="1", max_uploads="3")
    upload(owner, private_form, "", [archive([entries[2]]), ("Einzelbild.png", "image/png", small)])
    mixed = [picture(owner, private, name) for name in (second, "Einzelbild")]
    for route in mixed:
        if admin.fetch(route + "/full", raw=True) != small:
            raise RuntimeError("An ordinary file mixed with zipped pictures lost its name or original")
    remove(owner, mixed)
    configure(**initial)
