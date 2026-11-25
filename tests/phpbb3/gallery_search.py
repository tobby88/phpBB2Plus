"""Photo album searches through the public form, restricted albums and bounded results."""
from html import unescape
from http.client import HTTPConnection
import json
import re
from urllib.parse import parse_qs, urlencode, urljoin, urlsplit

from http_board import FormFields, Page


def browse(administrator, member, applicant, guest, check, *, bookmarks=True):
    route = "app.php/album/search"
    original = "album_search.php"

    def listing(page):
        block = re.search(r'<section\b[^>]* id="album-search-results"[^>]*>(.*?)</section>', page, re.S)
        if not block:
            raise RuntimeError("The gallery search did not render a result section")
        ids = [int(pic) for pic in re.findall(r'href="[^"]*/album/picture/(\d+)(?:\?[^"]*)?"', block[1])]
        return ids, block[1]

    def find(actor, mode, term, path=route):
        return actor.fetch(path + "?" + urlencode({"mode": mode, "search": term}))

    setup = json.loads(check("seed"))
    try:
        for actor, address in [(guest, "app.php/album"), (guest, "app.php/album/category/1"),
                               (member, "app.php/album/legacy/member/1"), (guest, route)]:
            html = actor.fetch(address)
            action = Page(html).forms["album-search"][0]
            if not urlsplit(action).path.endswith("/album/search") or FormFields(html, "album-search").values.get("mode") != "user":
                raise RuntimeError("A native gallery or historic personal album lost the original search fields")
        expected = [20006, 20005, 20000]
        for actor in (guest, applicant):
            ids, content = listing(find(actor, "name", "KÜSTENLICHT"))
            if ids != expected or "Küstenlicht &amp; Nebel" not in content or "href=" not in content:
                raise RuntimeError(f"The unaffiliated album title search returned {ids}")
        ids, content = listing(find(member, "name", "küstenlicht"))
        if ids != [20007, 20006, 20005, 20004, 20002, 20001, 20000] or f"/album/category/{setup['personal']}" not in content:
            raise RuntimeError("Search lost a visible personal album or an allowed nested category")
        if 20003 in listing(find(administrator, "name", "Küstenlicht"))[0]:
            raise RuntimeError("The public search included a pending photo for its approver")
        if listing(guest.fetch(route + "?mode=user", {"mode": "DESC", "search": "'Welle'"}))[0] != [20000]:
            raise RuntimeError("The description search lost its POST precedence, punctuation or mode")
        if listing(find(guest, "name", "K%licht am _ai"))[0] != [20006]:
            raise RuntimeError("An old album wildcard pattern no longer finds the intended title")
        if listing(find(guest, "user", "frühere fotografin"))[0] or "Frühere Fotografin" in listing(find(guest, "name", "Küstenlicht am Ufer"))[1]:
            raise RuntimeError("A removed photo author was identifiable in the guest search")
        if listing(find(member, "user", "frühere fotografin"))[0] != [20005] or listing(find(guest, "user", "RIA & ada"))[0] != [20006]:
            raise RuntimeError("The member-only former byline or searchable actual guest changed")
        if 20000 not in listing(find(guest, "user", "modernmember"))[0] or listing(find(member, "user", "Früherer Uploadname"))[0]:
            raise RuntimeError("Searching an active photographer used an outdated upload byline")
        guest.fetch(route + "?" + urlencode({"mode": "category", "search": "Welle"}), expected_status=422)
        guest.fetch(route + "?" + urlencode({"mode": "name", "search": "ä" * 51}), expected_status=422)
        if listing(find(guest, "desc", "Unterwasserkamera ohne Treffer"))[0]:
            raise RuntimeError("A search with no matching pictures returned a link")
        limit = setup["limit"]
        for actor, newest in [(guest, 20611), (member, 21211)]:
            page = find(actor, "name", "Bilderbogen")
            found, _ = listing(page)
            if found != list(range(newest, newest - limit, -1)):
                raise RuntimeError("Hidden albums or pictures with the same date displaced visible matches before the 500-result ceiling")
            last = (499 // limit) * limit
            paths = [unescape(url) for url in re.findall(r'href="([^"]+)"', page)]
            links = [p for p in paths if urlsplit(p).path.endswith("/album/search") and parse_qs(urlsplit(p).query).get("start") == [str(last)]]
            if not links or parse_qs(urlsplit(links[0]).query).get("search") != ["Bilderbogen"] or listing(actor.fetch(links[0]))[0] != list(range(newest - last, newest - 500, -1)):
                raise RuntimeError("The final result page exceeded the legacy ceiling or lost the search term")
        post = guest.fetch(route, {"mode": "name", "search": "Bilderbogen"})
        action, hidden = Page(post).forms["album-search-pagination"]
        if (any("Bilderbogen" in unescape(url) for url in re.findall(r'(?:href|action)="([^"]+)"', post)) or
                listing(guest.fetch(action, dict(hidden, start=str(limit))))[0] != list(range(20611-limit, 20611-2*limit, -1))):
            raise RuntimeError("Paging through a POSTed search exposed its term in a URL or lost its page")
        if bookmarks:
            if listing(find(guest, "name", "Küstenlicht", original))[0] != expected:
                raise RuntimeError("The original album search did not keep its GET parameters")
            # urllib deliberately does not replay POST on 307. Follow the same-origin Location as a browser does.
            source = urljoin(guest.base, original + "?mode=user")
            parts = urlsplit(source)
            body = {"mode": "desc", "search": "'Welle'"}
            connection = HTTPConnection(parts.hostname, parts.port, timeout=10)
            try:
                connection.request("POST", parts.path + "?" + parts.query, urlencode(body), {"Content-Type": "application/x-www-form-urlencoded"})
                response = connection.getresponse()
                raw = response.getheader("Location")
                if response.status != 307 or not raw:
                    raise RuntimeError("The legacy album search did not preserve the POST method")
                destination = urljoin(source, raw)
            finally:
                connection.close()
            query = parse_qs(urlsplit(destination).query)
            if (urlsplit(destination).netloc != parts.netloc or not urlsplit(destination).path.endswith("/app.php/album/search") or
                    query.get("mode") != ["user"] or "search" in query or listing(guest.fetch(destination, body))[0] != [20000]):
                raise RuntimeError("The legacy search redirect disclosed its body, lost the GET mode or replaced the POST fields")
    finally:
        check("cleanup")
