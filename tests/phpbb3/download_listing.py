"""Browse the converted directory and repeat the original folder and all-files sorts."""
import re
from html import unescape
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit
from urllib.request import Request
from http_board import Browser, FormFields, Page


def browse(base, member, ordinary, verify):
    root = "app.php/downloads"
    entire = root + "/all"
    shelf = 10931
    folder = f"{root}/category/{shelf}"
    guest = Browser(base)
    verify("seed")

    def fixture_ids(page):
        return list(map(int, re.findall(r'<a href="[^"]*/downloads/file/(1094\d)(?:\?[^"]*)?">', page)))

    def directory_row(page):
        match = re.search(r'<tr\b[^>]*><td><a class="forumtitle"[^>]*>Listing directory</a>(.*?)</tr>', page, re.S)
        if not match:
            raise RuntimeError("The viewer did not find the permitted directory")
        return match[1]

    def category_menu(page):
        menu = re.search(r'<nav id="dl-category-menu".*?</nav>', page, re.S)
        if not menu:
            raise RuntimeError("The download category menu is missing")
        return menu[0]

    for actor, count, child, last in ((guest, 6, False, "Bravo"), (ordinary, 6, False, "Bravo"),
                                      (member, 7, True, "Inherited listing file")):
        home = actor.fetch(root)
        row = directory_row(home)
        menu = category_menu(home)
        expected = [10930, 10932, 10933, 10931] if child else [10930, 10931]
        if list(map(int, re.findall(r'/downloads/category/(1093\d)\b', menu))) != expected:
            raise RuntimeError("The category menu lost its hierarchy, order or inherited visibility")
        search = actor.fetch(root + "/search")
        picker = re.search(r'<select id="dl-cat".*?</select>', search, re.S)
        if not picker or list(map(int, re.findall(r'<option value="(1093\d)"', picker[0]))) != expected:
            raise RuntimeError("The advanced search placed a child before its parent or offered a private directory")
        if (f"<td>{count}</td>" not in row or f">{last}</a>" not in row or "Public listing shelf" not in row or
                ("Shared listing shelf" in row) != child or "dl-sort-form" in home or "/downloads/all" not in home or
                "Waiting listing file" in home or "Folder-only file" in home):
            raise RuntimeError("Directory totals, last files or subfolders crossed a category or inherited grant")
    directory = member.fetch(root + "/category/10930")
    if (directory.index('>Shared listing shelf</a>') > directory.index('>Public listing shelf</a>') or
            "Inherited listing shelf" not in directory or "dl-sort-form" in directory):
        raise RuntimeError("The folder did not retain its configured order or nested links")
    guest.fetch(root + "/category/10933", expected_status=404)
    for file_id in (10941, 10942):
        detail = guest.fetch(f"{root}/file/{file_id}")
        if ("<dt>Eingereicht von</dt><dd>Gast</dd>" not in detail or
                (file_id == 10941 and "<dt>Größe</dt><dd>0 Bytes</dd>" not in detail)):
            raise RuntimeError("A guest or deleted submitter was attributed to a profile, or a known empty file lost its size")

    initial = guest.fetch(folder)
    for page in (initial, guest.fetch(root + "/file/10941")):
        menu = category_menu(page)
        if (not re.search(r'<a href="[^"]*/category/10931[^"]*" aria-current="location">Public listing shelf</a>', menu) or
                "Shared listing shelf" in menu or "Inherited listing shelf" in menu):
            raise RuntimeError("The category or file menu missed the current public folder or disclosed a private branch")
    for path in ("/search", "/stats", "/toplist", "/file/50/get/0"):
        if "Public listing shelf" not in category_menu(guest.fetch(root + path)):
            raise RuntimeError("The directory menu could not be reached from a download tool or licence")
    defaults = FormFields(initial, "dl-sort-form").values
    if (defaults.get("sort_method") != "file_dls" or defaults.get("sort_order") != "ASC" or
            fixture_ids(initial) != [10942, 10941, 10944, 10945] or "/downloads/file/10944/preview" not in initial):
        raise RuntimeError("Configured sorting, pins, language, preview or the normal page size was lost")
    if fixture_ids(guest.fetch(folder + "?q=Listing&start=2")) != [10944, 10945]:
        raise RuntimeError("The existing quick search repeated pinned matches on its second page")
    for method, direction, pins, normal in (
            ("file_name", "ASC", [10942, 10941], [10943, 10944]), ("file_time", "DESC", [10942, 10941], [10944, 10945]),
            ("rating", "DESC", [10942, 10941], [10944, 10946]), ("file_update_time", "DESC", [10942, 10941], [10945, 10943]),
            ("file_dls", "DESC", [10941, 10942], [10943, 10946])):
        params = {"sort_method": method, "sort_order": direction}
        page = guest.fetch(folder + "?" + urlencode(params))
        if fixture_ids(page) != pins + normal:
            raise RuntimeError(f"Ordering by {method} {direction} changed pins, ties or the first pair of files")
    page = guest.fetch(folder + "?sort_method=file_name&sort_order=ASC")
    links = [unescape(url) for url in re.findall(r'href="([^"]*start=2[^"]*)"', page)]
    if not links:
        raise RuntimeError("No next-page URL was rendered for the two remaining ordinary files")
    next_page = guest.fetch(links[0])
    if (fixture_ids(next_page) != [10942, 10941, 10945, 10946] or 'id="dl-page"' not in next_page or
            not all(term in links[0] for term in ("sort_method=file_name", "sort_order=ASC")) or
            fixture_ids(guest.fetch(folder + "?sort_method=file_name&sort_order=ASC&start=999")) != fixture_ids(next_page)):
        raise RuntimeError("Pagination did not preserve both sorts, repeat both pins or clamp to the last page")
    if fixture_ids(guest.fetch(folder + "?sort_method=old&sort_order=unknown")) != fixture_ids(initial):
        raise RuntimeError("Unrecognized historical preferences did not fall back to the saved defaults")
    legacy = f"dload.php?action=category&cat_id={shelf}"
    if fixture_ids(guest.fetch(legacy, {"sort_method": "file_name", "sort_order": "ASC", "start": 2})) != fixture_ids(next_page):
        raise RuntimeError("The original category sort form opened a different page of files")
    for path in (folder, entire):
        request = Request(urljoin(base, path), data=urlencode({"sort_method": "file_rating", "sort_order": "ASC"}).encode())
        with guest.opener.open(request, timeout=30) as response:
            query = parse_qs(urlsplit(response.geturl()).query)
            if query.get("sort_method") != ["file_rating"] or query.get("sort_order") != ["ASC"] or "no-store" not in response.headers["Cache-Control"]:
                raise RuntimeError("A historical sort POST did not produce a private repeatable listing URL")
    if ("Alle Dateien" not in guest.fetch(entire) or "All files" not in member.fetch(entire) or
            "/downloads/all" not in guest.fetch(root + "/file/50") or "/downloads/all" not in guest.fetch(root + "/search")):
        raise RuntimeError("All files was not reachable in both languages from the public directory tools")
    verify("private")
    for actor in (guest, ordinary):
        if "/downloads/all" in actor.fetch(root):
            raise RuntimeError("A viewer without the global grant received the all-files link")
        if fixture_ids(actor.fetch(folder))[:2] != [10942, 10941] or fixture_ids(actor.fetch(root + "/category/10930?q=Listing")) != [10942, 10941, 10944, 10945, 10946, 10943]:
            raise RuntimeError("Disabling global access changed the independently readable folder or its quick search")
    if "login" not in Page(guest.fetch(entire + "?sort_method=file_name&start=2")).forms:
        raise RuntimeError("A guest was not offered login for the private all-files view")
    ordinary.fetch(entire, expected_status=403)
    ordinary.fetch("dload.php?action=viewall", expected_status=403)
    permitted = member.fetch(entire)
    if ("Clubbibliothek</a>" not in permitted or "Privatfreigabe</a>" not in permitted or "Inherited listing file" not in permitted or
            "Waiting listing file" in permitted or "Folder-only file" in permitted):
        raise RuntimeError("The global grant overrode approval, folder type, group or personal file visibility")
    verify("personal")
    limited = ordinary.fetch(entire)
    if ("/downloads/all" not in ordinary.fetch(root) or "Alpha</a>" not in limited or
            any(title in limited for title in ("Inherited listing file", "Clubbibliothek", "Privatfreigabe", "Waiting listing file"))):
        raise RuntimeError("A personal all-files grant did not respect the user's separate category permissions")
    verify("disabled")
    for actor in (guest, member):
        actor.fetch(entire, expected_status=404)
        if "/downloads/all" in actor.fetch(root) or not fixture_ids(actor.fetch(folder)):
            raise RuntimeError("The all-files switch affected ordinary category access")
    verify("restore")
