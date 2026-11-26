"""Browse migrated multi-page articles, moderation, search, topics and historical voting receipts."""
import re
import time
from html import escape, unescape
from urllib.parse import urlencode, urljoin
from http_board import Browser, FormFields, Page, admin_url, submit_form
from gallery import submit


def rankings(guest, editor):
    ranked = "app.php/knowledge/statistics/"
    rated = guest.fetch("kb.php?mode=stats&stats=toprated")
    title, other = "Öffentliche Anleitung", "Geschütztes Diskussionsthema"
    banner = re.search(r'<img [^>]*src="([^"]*images/pluskb/header.gif)"', rated)
    if not banner or guest.fetch(urljoin(guest.base + ranked + "toprated", unescape(banner[1])), raw=True)[:3] != b"GIF":
        raise RuntimeError("The original Knowledge Base header image was unavailable on the rankings page")
    if (rated.index(title) >= rated.index(other) or "EditorFixture" in rated or "8,25 von 10 (2 Stimmen)" not in rated
            or "Anleitung (1)" not in rated or "Sterne 🌠 &amp;amp; Zeichen (0)" not in rated):
        raise RuntimeError("Ranked articles lost historical votes, public type counts or retired-name protection")
    popular = editor.fetch(ranked + "mostpopular")
    if popular.index(title) >= popular.index(other) or "Überarbeitete Anleitung" in popular or "EditorFixture" not in popular:
        raise RuntimeError("The public most-read ranking included drafts or lost its order or member bylines")
    if title not in guest.fetch("kb.php?mode=stats&stats=latest&start=5") or "Brief der Klasse" not in guest.fetch(ranked + "latest"):
        raise RuntimeError("The legacy statistics bookmark lost the sort or page offset")


def reply_panel(page):
    start = page.index('id="kb-discussion"')
    return page[start:page.index("</section>", start)]


def inline_replies(guest, member):
    article = "app.php/knowledge/article/"
    public = reply_panel(guest.fetch(article + "201"))
    private = reply_panel(member.fetch(article + "201"))
    removed = re.search(r'id="kb-reply-31"(.*?)</article>', public, re.S)
    if (not removed or "gelöscht" not in removed[1] or "AliasFixture" in removed[1] or "viewtopic.php" in removed[1]
            or "Former member fixture" not in public or "RetiredFixGuest" not in public or "RetiredFixture" in public
            or "EditorFixture" in public or "RetiredFixture" not in private or "EditorFixture" not in private or 'id="kb-reply-1"' in public):
        raise RuntimeError("Inline replies lost a guest or historical byline, exposed a deleted post, or repeated the first post")
    if 'id="kb-discussion"' in guest.fetch(article + "204") or "A former member in a restricted forum" not in reply_panel(member.fetch(article + "204")):
        raise RuntimeError("Article replies did not follow their native forum's read permissions")


def reply_preferences(base, member, change):
    guest = Browser(base)
    article = "app.php/knowledge/article/201"
    change("kb-reply-limits")
    try:
        public = reply_panel(guest.fetch(article))
        own = reply_panel(member.fetch(article))
        if ('id="kb-reply-31"' in public or "Account removal fixture" not in public or "Ignorierliste" not in own
                or "Account removal fixture" in own or "Antwort im Forum lesen" not in own or "AliasFixture" in own):
            raise RuntimeError("An unpublished removal or an ignored reply was exposed in the discussion preview")
    finally:
        change("kb-reply-restore")
    if "Account removal fixture" not in reply_panel(member.fetch(article)):
        raise RuntimeError("Removing a personal ignore setting did not restore the visible reply")


def listings(guest, editor, category, public, moderated):
    def cells(page, path):
        rows = re.findall(r"<tr\b[^>]*>(.*?)</tr>", page, re.S)
        for row in rows:
            if re.search(r'href="[^"]*/knowledge/' + path + r'(?:\?[^"]*)?"', row):
                return re.findall(r"<td\b[^>]*>(.*?)</td>", row, re.S)
        raise RuntimeError("Missing Knowledge Base listing row for " + path)

    # Counts span nested folders. An editor can read the draft in folder 10 but it is unpublished.
    for page in (category, editor.fetch("app.php/knowledge/category/1")):
        for folder, count in ((8, "3"), (10, "3"), (12, "0")):
            if cells(page, f"category/{folder}")[1:] != [count]:
                raise RuntimeError(f"Incorrect published article count for folder {folder}")
    title, kind, byline, posted, views = cells(public, "article/201")
    if ("Beschreibung" not in title or kind != "Anleitung" or "HistoricMember" not in byline
            or "1970" not in posted or int(views) < 17 or "memberlist.php" not in cells(moderated, "article/201")[2]):
        raise RuntimeError("The migrated article lost its summary, type, author, date or view count")
    if cells(public, "article/210")[-1] != "0":
        raise RuntimeError("A published article with no views lost its count")
    search = guest.fetch("app.php/knowledge?" + urlencode({"q": "Geschütztes Diskussionsthema"}))
    if (cells(public, "article/204")[2] != "Ehemaliger Nutzer" or cells(search, "article/204")[2] != "Ehemaliger Nutzer"
            or "EditorFixture" in public or "EditorFixture" not in cells(moderated, "article/204")[2]):
        raise RuntimeError("The directory or search results exposed or incorrectly hid a former member's name")


def browse(base, administrator, editor, reader):
    guest = Browser(base)
    index = "app.php/knowledge"
    article = index + "/article/"
    category = guest.fetch(index + "/category/1")
    if "Wissensdatenbank" not in guest.fetch("app.php/portal") or "Wissensarchiv" not in category:
        raise RuntimeError("Missing Knowledge Base navigation or nested category")
    public = guest.fetch(index + "/category/8")
    if "Öffentliche Anleitung" not in public or "Ungeprüfte Anleitung" in public or "Überarbeitete Anleitung" in public:
        raise RuntimeError("Public article list did not honour the approval states")
    # HistoricMember belongs to the converted moderator group. DepartingFixture is a pending member.
    moderated = editor.fetch(index + "/category/8")
    if "Ungeprüfte Anleitung" not in moderated or "Ungeprüfte Anleitung" in reader.fetch(index + "/category/8"):
        raise RuntimeError("Approved and pending memberships have incorrect Knowledge Base permissions")
    listings(guest, editor, category, public, moderated)
    if "Überarbeitete Anleitung" not in reader.fetch(article + "203"):
        raise RuntimeError("The author cannot read an edit in the moderation queue")
    guest.fetch(article + "202", expected_status=404)
    if "Ungeprüfte Anleitung" in guest.fetch(index + "?q=Wissensprobe") or "Ungeprüfte Anleitung" not in editor.fetch(index + "?q=Wissensprobe"):
        raise RuntimeError("Knowledge Base search crossed the article approval boundary")
    rankings(guest, editor)
    inline_replies(guest, administrator)
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
    ranked_after = guest.fetch(index + "/statistics/toprated")
    if (ranked_after.index("Geschütztes Diskussionsthema") >= ranked_after.index("Öffentliche Anleitung")
            or "7,83 von 10 (3 Stimmen)" not in ranked_after):
        raise RuntimeError("A new vote did not reorder the combined historical and current rankings")
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


def acp_action(panel, page, suffix):
    matches = [unescape(u) for u in re.findall(r'<a[^>]* href="([^"]+)"', page) if unescape(u).endswith(suffix)]
    if len(matches) != 1:
        raise RuntimeError("Expected one Knowledge Base operation for " + suffix)
    return urljoin(panel, matches[0])


def type_administration(owner):
    panel = acp_panel(owner, "Artikeltypen verwalten")
    guest = Browser(owner.base)
    article = "app.php/knowledge/article/201"
    editor = article + "/edit"
    category = guest.fetch("app.php/knowledge/category/8")
    if "Pflanzen 🌿" not in category or "Gärten &amp;amp; Parks 🌼" not in category:
        raise RuntimeError("A converted category lost a four-byte character or literal entity")

    listing = owner.fetch(panel)
    if "Sterne 🌠 &amp;amp; Zeichen" not in listing:
        raise RuntimeError("The article types lost their converted label")
    create = acp_action(panel, listing, "&action=create")
    original = owner.fetch(create)
    title = "Ä" * 250 + " 📘 &"
    listing = submit_form(owner, create, original, "kb-type", {"title": title})
    found = re.search(r'<a href="([^"]+type_id=(\d+)&amp;action=edit)">' + re.escape(escape(title)) + '</a>', listing)
    if not found:
        raise RuntimeError("An article type near the 255-character limit was truncated")
    edit, created = urljoin(panel, unescape(found[1])), found[2]
    if "inzwischen geändert" not in submit_form(owner, create, original, "kb-type", {"title": title}):
        raise RuntimeError("Retrying the same create form added a duplicate article type")
    name = "Märchen 🐉 &amp; „Bücher“"
    rename = owner.fetch(edit)
    if FormFields(rename, "kb-type").values["title"] != title:
        raise RuntimeError("An article type was escaped twice in its edit form")
    listing = submit_form(owner, edit, rename, "kb-type", {"title": name})
    remove = acp_action(panel, listing, "&type_id=2&action=delete")
    previous = owner.fetch(editor)
    review = owner.fetch(remove)
    if "anderen Artikeltyp" not in submit_form(owner, remove, review, "kb-type", {"destination": ""}):
        raise RuntimeError("An empty destination silently detached articles from their type")
    listing = submit_form(owner, remove, review, "kb-type", {"destination": created})
    if escape(name) not in guest.fetch(article) or "Wissensprobe" not in guest.fetch(article):
        raise RuntimeError("Reassigning a type lost the article or its new label")
    guest.fetch("app.php/knowledge/article/202", expected_status=404)
    if "inzwischen geändert" not in submit_form(owner, editor, previous, "kb-editor", {}, 422):
        raise RuntimeError("An old article editor could overwrite its new type")
    remove = acp_action(panel, listing, "&type_id=" + created + "&action=delete")
    submit_form(owner, remove, owner.fetch(remove), "kb-type", {"destination": "0"})
    edit_article = owner.fetch(editor)
    if (FormFields(edit_article, "kb-editor").values["type"] != "0" or escape(name) in guest.fetch(article)
            or "Wissensprobe" not in submit_form(owner, editor, edit_article, "kb-editor", {})):
        raise RuntimeError("The article or its unassigned type was lost on editing")


def category_administration(owner):
    panel = acp_panel(owner, "Kategorien verwalten")
    guest = Browser(owner.base)
    directory = "app.php/knowledge/category/"
    article = "app.php/knowledge/article/"

    def operation(listing, cat, name):
        return acp_action(panel, listing, "&cat_id=" + str(cat) + "&action=" + name)

    def save(url, values):
        return submit_form(owner, url, owner.fetch(url), "kb-category", values)

    listing = owner.fetch(panel)
    remove_parent = operation(listing, 10, "delete")
    stale = owner.fetch(remove_parent)
    edit_parent = operation(listing, 10, "edit")
    page = owner.fetch(edit_parent)
    if any('value="' + n + '"' in page.split('id="kb-parent"', 1)[1].split("</select>", 1)[0] for n in ("10", "11", "14")):
        raise RuntimeError("An ancestor could be moved beneath itself or a descendant")
    refused = submit_form(owner, edit_parent, page, "kb-category", {"parent": "11"})
    if "Wähle eine übergeordnete Kategorie" not in refused:
        raise RuntimeError("The category manager accepted a cycle")
    create = acp_action(panel, listing, "&action=create")
    name = "Ablage 📚 &amp; Bestand"
    listing = save(create, {"parent": "1", "title": name, "description": "Historische Übersicht"})
    found = re.search(r'<a href="[^"]+cat_id=(\d+)&amp;action=edit">' + re.escape(escape(name)) + '</a>', listing)
    if not found:
        raise RuntimeError("The new category lost its label")
    created = found[1]
    conflict = submit_form(owner, remove_parent, stale, "kb-category", {"destination": "0"})
    if "inzwischen geändert" not in conflict or "Lesemappenbrief" not in guest.fetch(directory + "10"):
        raise RuntimeError("An out-of-date confirmation removed the category")
    for direction, name_first in (("up", True), ("down", False)):
        listing = save(operation(listing, created, direction), {})
        front = guest.fetch(directory + "1")
        if (front.index(escape(name)) < front.index("Archivregister")) != name_first:
            raise RuntimeError("The public directory did not preserve the new sibling order")
    edit = operation(listing, created, "edit")
    listing = save(edit, {"parent": "8", "description": "Überarbeitet"})
    if escape(name) in guest.fetch(directory + "1") or "Überarbeitet" not in guest.fetch(directory + "8"):
        raise RuntimeError("Moving a category did not update the public hierarchy")
    refused = save(remove_parent, {"destination": ""})
    if "andere Kategorie" not in refused:
        raise RuntimeError("An empty category destination was interpreted as deletion")
    listing = save(remove_parent, {"destination": "11"})
    guest.fetch(directory + "10", expected_status=404)
    moved = guest.fetch(directory + "11")
    if not all(text in moved for text in ("Lesemappenbrief", "Ein Fundstück", "Briefe der Klasse")) or "Interner Entwurf" in moved:
        raise RuntimeError("Moving contents into the promoted child lost its own articles, children or approval boundary")
    if "Fundstücke" not in guest.fetch(directory + "1"):
        raise RuntimeError("The former subcategory did not move up to its parent's level")
    save(operation(listing, 11, "delete"), {"destination": "0"})
    for deleted in ("211", "212", "213"):
        owner.fetch(article + deleted, expected_status=404)
    if ("Archivbrief" not in guest.fetch(article + "214") or "Briefe der Klasse" not in guest.fetch(directory + "1")
            or "Welche Jahreszeit?" not in guest.fetch("viewtopic.php?t=1")):
        raise RuntimeError("Deleting direct category contents also removed a descendant article or an independent discussion")


def settings_administration(owner, contributor):
    panel = acp_panel(owner, "Einstellungen der Wissensdatenbank")
    page = owner.fetch(panel)
    guest = Browser(owner.base)
    article = "app.php/knowledge/article/201"
    new = "app.php/knowledge/new"
    submission, discussion, notices, ratings = ["kb-settings-" + s for s in ("submission", "discussion", "notices", "ratings")]
    if not {submission, discussion, notices, ratings}.issubset(Page(page).forms):
        raise RuntimeError("The native ACP lost a Knowledge Base settings section")
    prefs = FormFields(page, submission).values
    if FormFields(page, discussion).values["forum_id"] != "1" or FormFields(page, notices).values["notify"] != "1":
        raise RuntimeError("The settings panel did not load the converted discussion and review preferences")
    intro = "Hinweise 📗 &amp; Druck"
    message = "Bitte A & B prüfen."
    result = submit_form(owner, panel, page, submission, {"show_pretext": "1", "pt_header": intro, "pt_body": message})
    if "wurden gespeichert" not in result or escape(intro) not in contributor.fetch(new) or escape(message) not in contributor.fetch(new):
        raise RuntimeError("Submission instructions failed to reach the article form intact")
    stale = submit_form(owner, panel, page, submission, {"allow_new": None})
    if "inzwischen geändert" not in stale or FormFields(stale, submission).values["pt_header"] != intro:
        raise RuntimeError("A stale settings page replaced the newer instructions")
    result = submit_form(owner, panel, stale, submission, {"allow_new": None})
    contributor.fetch(new, expected_status=404)
    owner.fetch(new)
    result = submit_form(owner, panel, result, submission, {"allow_new": prefs["allow_new"], "show_pretext": prefs.get("show_pretext"),
                                                        "pt_header": prefs["pt_header"], "pt_body": prefs["pt_body"]})
    if "kb-editor" not in Page(contributor.fetch(new)).forms:
        raise RuntimeError("Reopening submissions did not restore the member's form")
    def save_discussion(page, fields):
        expected = FormFields(page, discussion).values
        expected.update(fields)
        saved = submit_form(owner, panel, page, discussion, fields)
        if 'class="successbox"' not in saved:
            reason = re.search(r'class="errorbox"><p>([^<]+)</p>', saved)
            raise RuntimeError("Saving Knowledge Base discussion settings failed: " + (reason[1] if reason else "no confirmation"))
        actual = FormFields(saved, discussion).values
        for key in ("comments", "comments_show", "comments_pagination", "forum_id"):
            if actual.get(key) != expected.get(key):
                raise RuntimeError(f"The ACP returned {key}={actual.get(key)!r} after saving {expected.get(key)!r}")
        return saved

    result = save_discussion(result, {"comments": None})
    if "Diskussion im Forum" in guest.fetch(article) or "Welche Jahreszeit?" not in guest.fetch("viewtopic.php?t=1"):
        raise RuntimeError("Disabling article discussions removed a native topic or left its article link active")
    result = save_discussion(result, {"comments": "1"})
    options = FormFields(result, discussion).values
    result = save_discussion(result, {"comments_show": None, "comments_pagination": "1"})
    hidden = guest.fetch(article)
    if 'id="kb-discussion"' in hidden:
        raise RuntimeError("The article still displayed inline replies after the setting was saved")
    if not re.search(r'<p>Diskussion im Forum\s*&middot;\s*<a href="[^"]*viewtopic\.php\?t=1(?:&amp;[^"]*)?">', hidden):
        title = re.search(r"<title>([^<]{1,160})</title>", hidden)
        details = (f"page={title[1] if title else 'unknown'}, article={'Öffentliche Anleitung' in hidden}, "
                   f"caption={'Diskussion im Forum' in hidden}, translated={'Forum discussion' in hidden}")
        raise RuntimeError(f"The article lost its native discussion link when inline replies were hidden ({details})")
    result = save_discussion(result, {"comments_show": "1"})
    first = reply_panel(guest.fetch(article + "?page=2"))
    second = guest.fetch("kb.php?mode=article&k=201&page_num=2&start=1&print=true")
    if first.count('id="kb-reply-') != 1 or 'id="kb-reply-31"' not in first or 'id="kb-reply-32"' not in reply_panel(second) or "Zweite Wissensseite" not in second:
        raise RuntimeError("The article and reply page offsets did not survive the printed legacy bookmark")
    result = save_discussion(result, {"comments_show": options.get("comments_show"), "comments_pagination": options["comments_pagination"]})
    result = submit_form(owner, panel, result, ratings, {"allow_rating": None})
    if "kb-rate" in Page(contributor.fetch(article)).forms or "Stimmen)" in guest.fetch(article):
        raise RuntimeError("Disabled article ratings were still visible")
    guest.fetch("app.php/knowledge/statistics/toprated", expected_status=404)
    result = submit_form(owner, panel, result, ratings, {"allow_rating": "1"})
    display = "kb-settings-display"
    original_display = FormFields(result, display).values
    result = submit_form(owner, panel, result, display, {"stats_list": None, "header_banner": None, "art_pagination": "1"})
    latest = "app.php/knowledge/statistics/latest"
    first, second = guest.fetch(latest), guest.fetch(latest + "?start=1")
    if ("Veröffentlichte Artikel nach Typ" in first or "images/pluskb/header.gif" in first or len(re.findall('class="topictitle"', first)) != 1
            or re.findall(r'class="topictitle" href="([^"]+)', first) == re.findall(r'class="topictitle" href="([^"]+)', second)):
        raise RuntimeError("The article count or page-size preference had no public effect")
    refused = submit_form(owner, panel, result, display, {"art_pagination": "1001"})
    if "errorbox" not in refused or FormFields(owner.fetch(panel), display).values["art_pagination"] != "1":
        raise RuntimeError("The list settings accepted an out-of-range page size")
    defaults = {"stats_list": original_display.get("stats_list"), "header_banner": original_display.get("header_banner"), "art_pagination": original_display["art_pagination"],
                "news_sort": original_display["news_sort"], "news_sort_par": original_display["news_sort_par"]}
    directory = "app.php/knowledge/category/8"
    title, other = "Öffentliche Anleitung", "Geschütztes Diskussionsthema"
    result = submit_form(owner, panel, result, display, dict(defaults, news_sort="Id", news_sort_par="DESC"))
    default, override = guest.fetch(directory), guest.fetch(directory + "?order=alphabetical")
    if default.index(other) >= default.index(title) or 'value="alphabetical" selected' not in override:
        raise RuntimeError("The category lost the configured direction or the reader's sort preference")
    result = submit_form(owner, panel, result, display, {"news_sort": "Latest"})
    by_discussion = guest.fetch(directory)
    if by_discussion.index(title) >= by_discussion.index(other):
        raise RuntimeError("Restricted discussion activity affected the public article order")
    result = submit_form(owner, panel, result, display, defaults)
    saved = FormFields(result, notices).values
    refused = submit_form(owner, panel, result, notices, {"notify": "2", "admin_id": "999999"})
    if "Benutzer-ID" not in refused or FormFields(owner.fetch(panel), notices).values["notify"] != saved["notify"]:
        raise RuntimeError("An invalid recipient partially changed the review notification method")
