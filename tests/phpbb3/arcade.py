"""Verify the visible games and native rankings for different viewers."""
import os
from http_board import Browser, login


def browse(base, administrator, member):
    guest = Browser(base)
    pending = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
    root = "app.php/arcade"
    if "Spielhalle" not in guest.fetch("app.php/portal"):
        raise RuntimeError("Arcade is missing from the portal")
    catalogue = guest.fetch(root)
    privileged = member.fetch(root)
    if ("Öffentliches Denkspiel" not in catalogue or "Vertrauliche Spielprobe" in catalogue or "Abgeschaltetes Spiel" in privileged
            or "Vertrauliche Spielprobe" not in privileged or "Persönliche Spielprobe" not in privileged
            or "Abgeschaltetes Spiel" not in administrator.fetch(root)):
        raise RuntimeError("Game availability or Arcade category membership was lost")
    for path in ("/category/12", "/game/62", "/game/62/scores/current", "/game/64"):
        guest.fetch(root + path, expected_status=404)
        pending.fetch(root + path, expected_status=404)
    public = guest.fetch(root + "/game/60")
    if "Aktuelle Bestenliste" not in public or "Ewige Bestenliste" not in public or "Steine &amp; Farben" not in public:
        raise RuntimeError("Distinct scoreboards or decoded instructions missing")
    equal = guest.fetch(root + "/game/61")
    if "Bestenliste</a>" not in equal or "Aktuelle Bestenliste" in equal or "Ewige Bestenliste" in equal:
        raise RuntimeError("Equal scoreboards should share one link")
    current = guest.fetch(root + "/game/60/scores/current")
    if ("95,1234" not in current or "-12,3751" not in current or "HistoricMember" not in current or "AlterSpielname" in current
            or "EditorFixture" in current or "Ehemaliger Nutzer" not in current or current.index("95,1234") > current.index("-12,3751")):
        raise RuntimeError("Ranking order, precision, renamed player or public byline is wrong")
    if "EditorFixture" not in member.fetch(root + "/game/60/scores/current"):
        raise RuntimeError("Members cannot see the retained historical player")
    if "9999999999,9999" not in guest.fetch(root + "/game/60/scores/all") or "2008-05" not in guest.fetch(root + "/game/60/scores/month"):
        raise RuntimeError("Missing all-time or historical monthly score")
    timed = guest.fetch(root + "/game/61/scores/current")
    if timed.index("0,0001") > timed.index("9,75"):
        raise RuntimeError("Lower-is-better games use the wrong ranking")
