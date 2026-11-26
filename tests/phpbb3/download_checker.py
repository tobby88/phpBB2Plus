"""Read the converted media report through the full administrator and the single-purpose delegate."""
import hashlib
from delegation import authenticate
from download_file_actions import english_file_panel
from http_board import Page, submit_form
from knowledgebase import acp_panel


def administration(owner, verify):
    def name(label):
        return "download_" + hashlib.sha256(("checker-" + label).encode()).hexdigest()

    verify("seed")
    try:
        german = acp_panel(owner, "Download-Dateiprüfung")
        german_page = owner.fetch(german)
        if "Lokale Dateien prüfen" not in german_page or "Zu prüfende Dateien" in german_page:
            raise RuntimeError("Opening the German file checker did not show its initial form")
        actor, url = english_file_panel(owner.base, "DownloadCheckDelegate", "Download file check")
        page = actor.fetch(url)
        if 'id="dl-checker"' not in page or "Unreferenced download files" in page:
            raise RuntimeError("The report ran before its form was submitted")
        report = submit_form(actor, url, page, "dl-checker", {})
        for expected in ("#11081 Pending original", "#11082 Size difference", "#11084 Location to review",
                         "#11080 Shared original", "#11080 #11092 Missing mirror", "#53 Kein Original",
                         "The stored file size differs", name("orphan"), "137"):
            if expected not in report:
                raise RuntimeError("Missing download file checker result: " + expected)
        for excluded in ("Remote fixture title", "Shared copy", "Valid local mirror", "example.invalid",
                         name("shared"), name("mirror"), name("attachment"), name("queue"), name("directory"), name("size")):
            if excluded in report:
                raise RuntimeError("The checker presented remote, referenced, pending or non-file material: " + excluded)
        de = submit_form(owner, german, german_page, "dl-checker", {})
        if "Vorschaubild" not in de or "Die Dateigröße weicht vom Katalogeintrag ab." not in de:
            raise RuntimeError("The German report did not explain its actual findings")
        verify("unchanged")
        junior, entry = authenticate(owner.base, "DownloadCheckerJunior")
        mapped = entry + "&i=-phpbb2plus-downloads-acp-checker_module&mode=report"
        if name("orphan") not in submit_form(junior, mapped, junior.fetch(mapped), "dl-checker", {}):
            raise RuntimeError("The original file-checker-only delegation lost its native report")
        junior.fetch(entry + "&i=-phpbb2plus-downloads-acp-settings_module&mode=manage", expected_status=503)
        verify("revoke")
        denied = submit_form(actor, url, report, "dl-checker", {}, status=403)
        if "dl-checker" in Page(denied).forms or name("orphan") in denied:
            raise RuntimeError("A revoked checker could still inspect local files")
    finally:
        verify("cleanup")
