"""Browse migrated multi-page articles, moderation, search, topics and historical voting receipts."""
import os
import re
from http_board import Browser, Page, login
from gallery import submit


def browse(base, administrator, editor):
    guest = Browser(base)
    reader = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    index = "app.php/knowledge"
    article = index + "/article/"
    if "Wissensdatenbank" not in guest.fetch("app.php/portal") or "Wissensarchiv" not in guest.fetch(index + "/category/1"):
        raise RuntimeError("Missing Knowledge Base navigation or nested category")
    public = guest.fetch(index + "/category/8")
    if "Öffentliche Anleitung" not in public or "Ungeprüfte Anleitung" in public or "Überarbeitete Anleitung" in public:
        raise RuntimeError("Public article list did not honour the approval states")
    # HistoricMember belongs to the converted moderator group. DepartingFixture is a pending member.
    if "Ungeprüfte Anleitung" not in editor.fetch(index + "/category/8") or "Ungeprüfte Anleitung" in reader.fetch(index + "/category/8"):
        raise RuntimeError("Approved and pending memberships have incorrect Knowledge Base permissions")
    if "Überarbeitete Anleitung" not in reader.fetch(article + "203"):
        raise RuntimeError("The author cannot read an edit in the moderation queue")
    guest.fetch(article + "202", expected_status=404)
    if "Ungeprüfte Anleitung" in guest.fetch(index + "?q=Wissensprobe") or "Ungeprüfte Anleitung" not in editor.fetch(index + "?q=Wissensprobe"):
        raise RuntimeError("Knowledge Base search crossed the article approval boundary")
    first = guest.fetch(article + "201")
    if not re.search(r"<strong[^>]*>Grüße</strong>", first) or "Fortsetzung" not in first or "Zweite Wissensseite" in first or "8,25 von 10" not in first:
        raise RuntimeError("Unexpected first article page, table of contents, formatting or historical rating")
    if "Zweite Wissensseite" not in guest.fetch(article + "201?page=2&print=1") or "Diskussion im Forum" not in first:
        raise RuntimeError("Print view or native public forum discussion is missing")
    restricted = guest.fetch(article + "204")
    if "Restricted topic fixture" in restricted or "Restricted topic fixture" not in administrator.fetch(article + "204"):
        raise RuntimeError("A Knowledge Base link ignored native discussion permissions")
    if "EditorFixture" in restricted or "EditorFixture" not in editor.fetch(article + "204"):
        raise RuntimeError("The retired article author has the wrong byline")
    if "kb-rate" in Page(first).forms:
        raise RuntimeError("Guests were offered a member-only vote")
    if "already rated" not in submit(editor, article + "201", "kb-rate", {"rating": 9}, 422):
        raise RuntimeError("The Knowledge Base forgot an old voting receipt")
    new_rating = submit(reader, article + "201", "kb-rate", {"rating": 7})
    if "7,83 von 10 (3 Stimmen)" not in new_rating:
        raise RuntimeError("Historical and new Knowledge Base ratings do not add up")
