"""Follow the forum's historical public link formats against the converted fixture."""
from http.client import HTTPConnection
from urllib.parse import parse_qs, urljoin, urlsplit

from http_board import Browser


def link(base, path, expected_path, query, fragment="", method="GET"):
    origin = urlsplit(base)
    if origin.hostname != "127.0.0.1":
        raise ValueError("The URL fixture requires loopback")
    conn = HTTPConnection(origin.hostname, origin.port, timeout=30)
    try:
        conn.request(method, "/" + path)
        response = conn.getresponse()
        location = urlsplit(urljoin(urljoin(base, path), response.getheader("Location", "")))
        if response.status != 302 or location.netloc != origin.netloc or location.path != "/" + expected_path:
            raise RuntimeError(f"Wrong redirect for {path} ({response.status}, {location.path})")
        values = parse_qs(location.query)
        values.pop("sid", None)
        if values != {k: [str(v)] for k, v in query.items()} or location.fragment != fragment:
            raise RuntimeError(f"Wrong sort, pagination, identity or fragment for {path}")
    finally:
        conn.close()


def browse(base, owner, historic_id):
    cases = (
        ("forums.html", "index.php", {}, ""),
        ("forum1,public-board.html", "viewforum.php", {"f": 1}, ""),
        ("app.php/viewforum1-7-20.html", "viewforum.php", {"f": 1, "st": 7, "start": 20}, ""),
        ("viewforum1--,public-board.html", "viewforum.php", {"f": 1, "st": 0, "start": 0}, ""),
        ("app.php/ptopic1.html", "viewtopic.php", {"t": 1, "view": "previous"}, ""),
        ("ntopic1.html", "viewtopic.php", {"t": 1, "view": "next"}, ""),
        ("ftopic1-15.html", "viewtopic.php", {"t": 1, "start": 15}, ""),
        ("ftopic1-7-desc-20.html", "viewtopic.php", {"t": 1, "st": 7, "sd": "d", "start": 20}, ""),
        ("fpost32.html", "viewtopic.php", {"p": 32}, "p32"),
        ("profile.php?mode=signature", "ucp.php", {"i": "ucp_profile", "mode": "signature"}, ""),
        ("profile.php?mode=activate&act_key=fixture", "ucp.php", {"mode": "resend_act"}, ""),
        ("app.php/legacy/member/1/viewprofile", "memberlist.php", {"mode": "viewprofile", "u": historic_id}, ""),
    )
    for path, script, query, fragment in cases:
        link(base, path, script, query, fragment)
    link(base, "ftopic1.html", "viewtopic.php", {"t": 1}, method="HEAD")
    guest = Browser(base)
    topic = guest.fetch("ftopic1.html")
    if 'id="32"' not in topic or 'id="p32"' not in topic or "Grüße" not in topic:
        raise RuntimeError("Old topic bookmark has lost its native post or numeric fragment")
    for path in ("forumc11,nested-category.html", "index.php?c=11"):
        if "Nested category" not in owner.fetch(path):
            raise RuntimeError("Old category link opened the wrong category")
    if "HistoricMember" not in guest.fetch("profile.php?mode=viewprofile&u=1"):
        raise RuntimeError("Old profile for source user one opened the wrong native member")
    # A retired source ID can now belong to a different native account.
    guest.fetch("profile.php?mode=viewprofile&u=" + str(historic_id), expected_status=404)
    if "Private fixture text" in guest.fetch("fpost40.html") or "Private fixture text" not in owner.fetch("fpost40.html"):
        raise RuntimeError("Old post link changed the forum's read permissions")
    guest.fetch("profile.php?mode=register", {}, expected_status=405)
