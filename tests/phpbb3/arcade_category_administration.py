"""Use the converted Arcade category administrator's real phpBB ACP forms."""
from html import unescape
import json

from arcade_administration import panel_for
from delegation import authenticate
from http_board import Browser, FormFields, Page, submit_form


def exercise(base, founder, historical, fixture):
    junior, entry = authenticate(base, "ArcadeCategoryJunior")
    panel = entry + "&i=-phpbb2plus-arcade-acp-categories_module&mode=manage"
    founder_panel = panel_for(founder, "Spielhallen-Kategorien")
    edit_form = "arc-category-edit"
    confirm_form = "arc-category-confirm"
    public = "app.php/arcade/category/"

    def url(action, category=0):
        return panel + "&action=" + action + ("&category=" + str(category) if category else "")

    def state():
        data = json.loads(fixture("state"))
        cats = {int(row["cat_id"]): row for row in data["cats"]}
        games = {int(game): int(category) for game, category in data["games"].items()}
        return cats, games

    def check(ok, message):
        if not ok:
            raise RuntimeError(message)

    fixture("seed")
    try:
        listing = junior.fetch(panel)
        check("The category hierarchy needs repair" in listing and "Manage games and scores" not in listing,
              "A single category delegation lost its repair action or offered the score editor")
        check("Kategorien neu abgleichen" in founder.fetch(founder_panel), "The German Arcade module is missing")
        check(FormFields(junior.fetch(url("edit", 10)), edit_form).values["description"] == "Offene Spiele 🎮",
              "The converter damaged a four-byte character in an original category description")
        for module, mode in (("scores", "manage"), ("settings", "settings")):
            denied = junior.fetch(entry + f"&i=-phpbb2plus-arcade-acp-{module}_module&mode={mode}", expected_status=503)
            check("Module not accessible" in denied, "Category management also gave access to " + module)
        initial, _ = state()
        guest = Browser(base)
        picture = junior.fetch(url("picture", 10), raw=True)
        check(picture.startswith(b"\x89PNG") and picture == guest.fetch(public + "10/icon", raw=True),
              "The authorized ACP preview differs from the converted category icon")
        junior.fetch(url("picture", 908001), expected_status=404)

        confirm = junior.fetch(url("resync"))
        check(confirm_form in Page(confirm).forms and initial[908006]["cat_type"] == "s",
              "Category repair did not wait for confirmation")
        submit_form(junior, panel, confirm, confirm_form, {})
        check(state()[0][908006]["cat_type"] == "s", "An unconfirmed repair changed the catalogue")
        submit_form(junior, panel, confirm, confirm_form, {"confirm": 1})
        cats, games = state()
        check(cats[908006]["cat_type"] == "p" and int(cats[908006]["cat_parent"]) == 0 and games[908015] == -1,
              "The source topology repair lost an orphaned category or game")
        check((int(cats[908001]["total_games"]), int(cats[908001]["total_played"]), int(cats[908002]["total_games"]),
               int(cats[908002]["total_played"])) == (1, 20, 1, 11) and cats[908005]["total_played"] == "18446744073709551615",
              "Recounting changed direct game counts, offline/nested play counts, or an existing link's visits")
        player = int(cats[908002]["last_user_id"])
        check(player > 2 and int(cats[-1]["last_time"]) == 2000000200 and int(cats[-1]["last_player"]) == 1,
              "The resynchronised global last play lost its original actor or time")
        check(cats[908006]["last_game"] is None and int(cats[908005]["last_time"]) == 2100000100,
              "An ambiguous game supplied a play or an ordinary repair replaced existing link metadata")
        fixture("missing-global")

        for move, cat_id, expected_order in (("up", 908004, [908004,908001,908002,908003]),
                                             ("down", 908002, [908004,908001,908003,908002])):
            listing = junior.fetch(panel)
            submit_form(junior, panel, listing, f"arc-category-{move}-{cat_id}", {})
            cats, _ = state()
            check(sorted(expected_order, key=lambda key: int(cats[key]["cat_order"])) == expected_order,
                  "Reordering broke the sibling order or separated a parent from its children")

        new = junior.fetch(url("create"))
        entered_name = "Fixture ACP Münze & Spiel 🎮 ".ljust(100, "&")
        values = {"name": entered_name, "description": "Größe & Vielfalt", "icon": "images/arcade/category.png"}
        invalid = submit_form(junior, url("create"), new, edit_form, {**values, "icon": "games/puzzle/private.png"}, 422)
        check("shared Arcade image" in invalid, "The category editor accepted a private game's artwork")
        result = submit_form(junior, url("create"), new, edit_form, values)
        check(entered_name in unescape(result), "The created Unicode category was not in the catalogue")
        cats, _ = state()
        created = next(key for key, row in cats.items() if unescape(row["cat_name"] or "") == entered_name)
        check(junior.fetch(url("picture", created), raw=True) == picture,
              "Selecting the same shared image lost the existing protected file")
        edit = junior.fetch(url("edit", created))
        check(FormFields(edit, edit_form).values["description"] == values["description"], "The form damaged saved Unicode text")
        wrong = submit_form(junior, panel, edit, edit_form, {"type": "s", "parent": created}, 422)
        check("another main category" in wrong, "The editor accepted a category as its own parent")
        submit_form(junior, panel, edit, edit_form, {"icon": "images/arcade/new-shared.png", "special": "7"})
        check("has changed" in submit_form(junior, panel, edit, edit_form, {"description": "Second edit"}, 409),
              "Reusing a stale category form silently overwrote the newer change")
        junior.fetch(url("picture", created), expected_status=404)
        fixture("stale-picture")

        edit = junior.fetch(url("edit", 908002))
        submit_form(junior, panel, edit, edit_form,
                    {"parent": 908004, "moderator": "HistoricMember", "special": "3", "group": "905300"})
        cats, games = state()
        child = cats[908002]
        check((int(child["cat_parent"]), int(child["moderator_id"]), int(child["special_play"]),
               int(child["native_group_id"]), int(child["group_required"])) == (908004, player, 3, 905300, -1),
              "The moved category lost its moderator, threshold or native group scope")
        check(int(cats[908001]["total_played"]) == 9 and cats[908001]["last_game"] == "cat-sibling" and
              int(cats[908004]["total_played"]) == 13 and cats[908004]["last_game"] == "cat-child",
              "Moving a child did not recalculate the two parents' history")
        for visitor in (guest, junior, historical):
            visitor.fetch(public + "908002", expected_status=404)
        check("Fixture historical scope" in historical.fetch(public + "908007"), "Original personal group access changed")
        junior.fetch(public + "908007", expected_status=404)
        fixture("active")
        check("Fixture child" in junior.fetch(public + "908002"), "An approved native group member cannot open the restricted category")
        junior.fetch(public + "908007", expected_status=404)

        edit = junior.fetch(url("edit", 908004))
        submit_form(junior, panel, edit, edit_form, {"type": "l", "description": "https://example.invalid/archive?a=1&b=2"})
        cats, games = state()
        check(cats[908002]["cat_type"] == "p" and int(cats[908002]["cat_parent"]) == 0 and games[908014] == -1 and
              int(cats[908004]["total_played"]) == 0 and cats[908004]["last_time"] is None,
              "Converting to a link discarded a child, retained game starts as visits or left a stale last player")
        old_link = junior.fetch(url("edit", 908005))
        submit_form(junior, panel, old_link, edit_form, {"name": "Fixture renamed link"})
        check(state()[0][908005]["total_played"] == "18446744073709551615", "Editing the link reset existing visits")
        confirm = junior.fetch(url("delete", 908002))
        check("Access previously inherited" in confirm and state()[1][908011] == 908002, "Deletion omitted the scope change or ran early")
        submit_form(junior, panel, confirm, confirm_form, {"confirm": 1})
        cats, games = state()
        check(908002 not in cats and [games[908011], games[908012], games[908013]] == [-1,-1,908003] and
              cats[-1]["last_game"] == "cat-child" and int(cats[-1]["last_user_id"]) == player and int(cats[-1]["last_time"]) == 2000000200,
              "Removing a category lost games or the latest attributable global play")
        fixture("preserved")
        edit = junior.fetch(url("edit", created))
        fixture("never")
        try:
            denied = submit_form(junior, panel, edit, edit_form, {"special": "11"}, 403)
            check("not authorised to administer" in denied and int(state()[0][created]["special_play"]) == 7,
                  "Explicitly revoking the native module permission left an already-open form writable")
        finally:
            fixture("restore")
    finally:
        fixture("cleanup")
