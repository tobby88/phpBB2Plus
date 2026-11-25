"""Save converted paFileDB settings with a settings-only administrator and observe their actual effects."""
from html import unescape
import re
import time
from urllib.parse import urljoin
from download_file_actions import english_file_panel
from gallery import ORIGINAL
from http_board import Browser, FormFields, Page, submit_form
from knowledgebase import acp_panel


def administration(owner, member, verify):
    verify("installed")
    verify("seed")
    moderator, url = english_file_panel(owner.base, "DownloadSettingsDelegate", "Download settings")
    founder_url = acp_panel(owner, "Download-Einstellungen")
    if "Globale Zugriffsstufen" not in owner.fetch(founder_url):
        raise RuntimeError("The German settings module did not load")
    guest = Browser(owner.base)
    root = "app.php/downloads"
    original = moderator.fetch(url)
    fields = FormFields(original, "dl-settings").values
    if (fields["v[validator]"] != "historic_mode" or fields["v[settings_newdays]"] != "2000000" or
            "old/downloads/" not in original or any("v[" + k + "]" in fields for k in ("upload_dir", "screenshots_dir", "tpl_php", "allow_html"))):
        raise RuntimeError("An imported exception, folder or obsolete setting was lost or rendered as an active storage control")

    def save(page, values):
        return submit_form(moderator, url, page, "dl-settings", {"v[" + key + "]": value for key, value in values.items()})

    invalid = save(original, {"settings_dbname": "Uncommitted title", "settings_file_page": "101"})
    if "outside the allowed range" not in invalid:
        raise RuntimeError("An unusable page size did not reject the entire form")
    if 'class="successbox"' not in save(original, {}):
        raise RuntimeError("A settings form with unchanged historical values did not save")
    verify("unchanged")
    saved = save(original, {"settings_dbname": "Katalog &amp; Archiv 📁", "settings_file_page": "2", "sort_method": "file_rating",
                           "sort_order": "DESC", "settings_newdays": "1", "max_file_size": "32",
                           "forbidden_extensions": ".ABC, xyz, abc", "max_comment_chars": "20", "allow_comment_images": "0",
                           "no_comment_image_message": "Archivbild & Hinweis 📷", "auth_search": "1",
                           "hotlink_prevent": "1", "hotlink_allowed": "https://downloads.example.invalid, club.example.invalid",
                           "auth_toplist": "0", "settings_topnumber": "1"})
    if 'class="successbox"' not in saved:
        raise RuntimeError("The valid settings form did not save")
    verify("saved")
    if "changed after this form was opened" not in save(original, {"settings_file_page": "3"}):
        raise RuntimeError("A second administrator's older form overwrote the settings")
    if "Katalog &amp; Archiv 📁" not in unescape(guest.fetch(root)):
        raise RuntimeError("The public catalogue did not display the saved title literally")
    guest.fetch(root + "?q=Fixture", expected_status=404)
    member.fetch(root + "?q=Fixture")
    listed = guest.fetch(root + "/category/10630")
    if (not re.search(r'Pinned fixture</a>.*?Alpha recent</a>\s*<strong>Neu</strong>', listed, re.S) or "Zulu oldest</a>" in listed):
        raise RuntimeError("The converted catalogue ignored rating, pins, the new marker or the page size")
    if "Zulu oldest</a>" not in guest.fetch(root + "/category/10630?start=2"):
        raise RuntimeError("The changed page size made the older file unreachable")
    ranked = guest.fetch(root + "/toplist?mode=downloads")
    if len(re.findall(r'<td><a href="[^"]*/downloads/category/\d+[^"]*">', ranked)) != 1:
        raise RuntimeError("The ACP top list length did not limit the default public ranking")
    if "Archivbild & Hinweis 📷" not in unescape(guest.fetch(root + "/file/50")):
        raise RuntimeError("Stored comment image replacement text was not applied")
    referred = Browser(owner.base)
    referred.opener.addheaders = [("Referer", "https://gallery.club.example.invalid/")]
    preview = root + "/file/10641/preview"
    if referred.fetch(preview, raw=True) != ORIGINAL:
        raise RuntimeError("The saved referrer list blocked a permitted preview")
    referred.opener.addheaders = [("Referer", "https://other.example.invalid/")]
    referred.fetch(preview, expected_status=403)

    upload = root + "/category/21/submit"
    page = member.fetch(upload)
    target, params = Page(page).forms["dl-submit"]
    params = dict(params, title="Settings size fixture", description="Disposable fixture")
    time.sleep(max(0, int(params["creation_time"]) + 1.05 - time.time()))
    for title, content, error in (("archive.abc", b"short", "extension is unavailable"),
                                   ("archive.txt", b"x" * 33, "exceeds the permitted size")):
        denied = member.fetch(urljoin(member.base + upload, target), params,
                              files=[("original", title, "application/octet-stream", content)], expected_status=422)
        if error not in denied:
            raise RuntimeError("The saved file size or extension policy did not reach the public upload workflow")
    current = moderator.fetch(url)
    if "valid hostnames" not in save(current, {"hotlink_allowed": "https://", "settings_disable": "1"}):
        raise RuntimeError("Invalid referrers allowed an unrelated setting to be applied")
    active = save(current, {"settings_disable": "1"})
    if 'class="successbox"' not in active:
        raise RuntimeError("The settings-only delegate could not close the download library")
    guest.fetch(root, expected_status=404)
    moderator.fetch(root, expected_status=404)
    owner.fetch(root)
    verify("revoke")
    denied = submit_form(moderator, url, active, "dl-settings", {"v[settings_disable]": "0"}, status=503)
    if "dl-settings" in Page(denied).forms:
        raise RuntimeError("A revoked settings permission retained its save form")
    verify("restore")
    guest.fetch(root)
