"""Browse migrated multi-page articles, moderation, search, topics and historical voting receipts."""
import re
import time
from html import unescape
from urllib.parse import urlencode, urljoin
from http_board import Browser, Page, admin_url, submit_form
from gallery import submit


def browse(base, administrator, editor, reader):
    guest = Browser(base)
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
    legacy = guest.fetch("kb.php?mode=article&k=201&page_num=2&print=true")
    if "Zweite Wissensseite" not in legacy or "Wissensarchiv" not in guest.fetch("kb.php?mode=cat&cat=8"):
        raise RuntimeError("A bookmarked Knowledge Base page no longer opens its original content")
    guest.fetch("kb.php?mode=article&k=202", expected_status=404)
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
    publication(base, guest, reader, editor, administrator)


def publication(base, guest, author, reviewer, administrator):
    new = "app.php/knowledge/new"
    guest.fetch(new, expected_status=404)
    target, fields = Page(author.fetch(new)).forms["kb-editor"]
    target = urljoin(urljoin(base, new), target)
    fields.update(title="Neuer Wissensbeitrag", description="Veröffentlichungsprobe", category=8, type=2,
                  body="Übersicht[toc]\n[b]Öffentlicher Text[/b][page]Anhang[toc]\nNachtrag")
    time.sleep(max(0, int(fields["creation_time"]) + 1.05 - time.time()))
    preview = author.fetch(target, dict(fields, preview="1"))
    if not re.search(r"<strong[^>]*>Öffentlicher Text</strong>", preview):
        raise RuntimeError("Article preview lost native BBCode")
    search = "app.php/knowledge?" + urlencode({"q": "Veröffentlichungsprobe"})
    if "Neuer Wissensbeitrag" in reviewer.fetch(search):
        raise RuntimeError("Preview unexpectedly created an article")
    for _ in range(2):
        pending = author.fetch(target, fields)
        if "Wartet auf Freigabe" not in pending:
            raise RuntimeError("New article bypassed the configured approval step")
    listing = reviewer.fetch(search)
    paths = re.findall(r'href="([^"]*/knowledge/article/\d+[^"]*)"[^>]*>Neuer Wissensbeitrag</a>', listing)
    if len(paths) != 1:
        raise RuntimeError("A retried submission did not produce exactly one article")
    item = urljoin(base, paths[0]).split("?", 1)[0]
    guest.fetch(item, expected_status=404)
    inbox = administrator.fetch("ucp.php?i=pm&folder=inbox")
    notices = re.findall(r'href="([^"]+)"[^>]*>Ein Artikel der Wissensdatenbank wartet auf Freigabe</a>', inbox)
    if len(notices) != 1 or "Neuer Wissensbeitrag" not in administrator.fetch(unescape(notices[0])):
        raise RuntimeError("The review mailbox did not receive exactly one native notification after retry")
    approved = submit(reviewer, item + "/approve", "kb-editor", {})
    if "Withdraw approval" not in approved or "Öffentlicher Text" not in guest.fetch(item) or "Nachtrag" not in guest.fetch(item + "?page=2"):
        raise RuntimeError("Approved article pages did not become public")
    topic = re.search(r'href="([^"]*viewtopic\.php[^"]*)">\[KB\] Neuer Wissensbeitrag</a>', guest.fetch(item))
    if not topic:
        raise RuntimeError("Approval did not link the native forum discussion")
    topic = urljoin(item, unescape(topic.group(1)))
    if "Discuss this Knowledge Base article" not in guest.fetch(topic):
        raise RuntimeError("The public discussion does not contain the initial announcement")
    _, old = Page(author.fetch(item + "/edit")).forms["kb-editor"]
    fields.update(title="Bearbeiteter Wissensbeitrag", body="Änderung zur Prüfung")
    changes = {key: fields[key] for key in ("title", "description", "body", "category", "type")}
    if "Wartet auf Freigabe" not in submit(author, item + "/edit", "kb-editor", changes):
        raise RuntimeError("Article edits did not return to approval")
    guest.fetch(item, expected_status=404)
    stale = author.fetch(item + "/edit", dict(old, **changes), expected_status=422)
    if "inzwischen geändert" not in stale:
        raise RuntimeError("A stale editor could overwrite the newer version")
    approved = submit(reviewer, item + "/approve", "kb-editor", {})
    if "Withdraw approval" not in approved or "updated" not in guest.fetch(topic).lower():
        raise RuntimeError("A revised article did not publish its update to the existing native topic")
    submit(reviewer, item + "/unapprove", "kb-editor", {})
    guest.fetch(item, expected_status=404)
    if "Bearbeiteter Wissensbeitrag" not in reviewer.fetch(item + "/delete"):
        raise RuntimeError("Missing article deletion confirmation")
    submit(administrator, item + "/delete", "kb-editor", {})
    reviewer.fetch(item, expected_status=404)
    guest.fetch(topic, expected_status=404)
    if "Welche Jahreszeit?" not in guest.fetch("viewtopic.php?t=1"):
        raise RuntimeError("Article deletion did not remove just the managed discussion")


def acp_panel(owner, title):
    entry = admin_url(owner)
    tab = re.search(r'<a href="([^"]+)">Erweiterungen</a>', owner.fetch(entry))
    if not tab:
        raise RuntimeError("The native Extensions tab is missing from the ACP")
    page = owner.fetch(urljoin(entry, unescape(tab[1])))
    links = re.findall(r'<a href="([^"]+)"><span>' + re.escape(title) + r'</span>', page)
    if len(links) != 1:
        raise RuntimeError("Expected exactly one Knowledge Base ACP entry for " + title)
    return urljoin(entry, unescape(links[0]))


def administration(owner):
    panel = acp_panel(owner, "Artikel verwalten")
    guest = Browser(owner.base)
    article = "app.php/knowledge/article/203"
    title = "Überarbeitete Anleitung"
    listing = owner.fetch(panel)

    def link(page, fragment):
        urls = [unescape(url) for url in re.findall(r'<a href="([^"]+)"', page) if fragment in unescape(url)]
        if len(urls) != 1:
            raise RuntimeError("Expected one Knowledge Base ACP link for " + fragment)
        return urljoin(panel, urls[0])

    pending = link(listing, "&status=pending")
    published = link(listing, "&status=published")
    edited = link(listing, "&status=edited")
    queue = owner.fetch(edited)
    if (title not in queue or "Ungeprüfte Anleitung" in queue or "Öffentliche Anleitung" in queue
            or "Ungeprüfte Anleitung" not in owner.fetch(pending) or title in owner.fetch(published)):
        raise RuntimeError("The article desk lost the distinction between edits, new submissions and published articles")
    delete = link(queue, "article_id=203&action=delete")
    approve = link(queue, "article_id=203&action=approve")
    original = owner.fetch(delete)
    review = owner.fetch(approve)
    guest.fetch(article, expected_status=404)
    after = submit_form(owner, approve, review, "kb-review", {})
    if title in after or title not in guest.fetch(article):
        raise RuntimeError("Approving an edit failed to publish it or lost the ACP queue filter")
    conflict = submit_form(owner, delete, original, "kb-review", {})
    if "inzwischen geändert" not in conflict:
        raise RuntimeError("A stale ACP deletion confirmation was accepted after publication")
    withdraw = link(owner.fetch(published), "article_id=203&action=unapprove")
    submit_form(owner, withdraw, owner.fetch(withdraw), "kb-review", {})
    guest.fetch(article, expected_status=404)
    delete = link(owner.fetch(pending), "article_id=203&action=delete")
    after = submit_form(owner, delete, owner.fetch(delete), "kb-review", {})
    if title in after or "Ungeprüfte Anleitung" not in after:
        raise RuntimeError("Deleting an article changed the wrong entry or lost the selected queue")
    owner.fetch(article, expected_status=404)
