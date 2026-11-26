"""Exercise the game's historical Edit registration and protect its existing media and scores."""
from html import unescape
import os
import re
from urllib.parse import urljoin
from delegation import authenticate, authenticate_pair
from http_board import Browser, FormFields, login, submit_form


def browse(base, check, member=None, pending=None):
    (actor, acp), (categories, other) = authenticate_pair(base, "ArcadeScoresJunior", "ArcadeCategoryJunior")
    suffix = "&i=-phpbb2plus-arcade-acp-games_module&mode=manage"
    panel = acp + suffix
    edit = panel + "&game=61"
    guest = Browser(base)
    form = "arc-game-edit"

    def saved(changes, page=None):
        body = submit_form(actor, edit, page or actor.fetch(edit), form, changes)
        if "Game settings saved." not in body:
            raise RuntimeError("The game editor did not acknowledge a valid metadata update")
        return body

    check("prepare")
    try:
        founder, founder_acp = authenticate(base, "ModernMember")
        if "Spielkatalog der Spielhalle" not in founder.fetch(founder_acp + suffix):
            raise RuntimeError("The German game editor was missing from the founder's ACP")
        if "Arcade game catalogue" not in actor.fetch(acp) or "Module not accessible" not in categories.fetch(other + suffix, expected_status=503):
            raise RuntimeError("The migrated editor and category-only registrations were not isolated")
        if re.findall(r'data-arc-game="([0-9]+)"',actor.fetch(panel)) != ["64","63"]:
            raise RuntimeError("The catalogue lost the original newest-ID-first default")
        if re.findall(r'data-arc-game="([0-9]+)"',actor.fetch(panel + "&cat=12&sort_mode=date_added")) != ["62"]:
            raise RuntimeError("The category filter included games from another branch")
        for mode in ("allow_guest","date_added","alphabetical","cat_id","offline","game_played"):
            sorted_page = actor.fetch(panel + "&cat=10&order=ASC&sort_mode=" + mode)
            filters = FormFields(sorted_page,"arc-game-search").values
            if filters.get("sort_mode")!=mode or filters.get("cat")!="10" or filters.get("order")!="ASC":
                raise RuntimeError("The catalog page discarded its selected sort or category")
        index = actor.fetch(panel + "&order=ASC")
        if 'data-arc-game="60"' not in index or 'data-arc-game="63"' in index:
            raise RuntimeError("The game catalogue did not paginate at the configured size")
        only = actor.fetch(panel + "&search=closed")
        if 'data-arc-game="63"' not in only or 'data-arc-game="61"' in only:
            raise RuntimeError("Searching by technical game identifier missed the offline game")
        path = next(urljoin(panel,unescape(link)) for link in re.findall(r'href="([^"]+)"',index) if re.search(r"game=61(?:&|$)",link))
        original = actor.fetch(path)
        fields = FormFields(original,form).values
        protected = {"game_name", "game_id", "game_path", "image_path", "main_asset", "game_use_gl", "game_flash"}
        if fields.get("game_desc") != "Stoppuhr" or fields.get("game_control") != "0" or set(fields) & protected:
            raise RuntimeError("The ordinary form lost catalogue metadata or exposed raw media identity")
        limits = submit_form(actor,edit,original,form,{"game_charge":"4294967296"})
        if "Check the entered text" not in limits:
            raise RuntimeError("An invalid points value was silently truncated")
        title = "🎮" * 255
        body = saved({"game_desc":title,"instructions":"Mäuse 🎮\nText <Probe> &lt;Beispiel&gt;","reverse_list":"0","score_type":"6",
                      "win_width":"320","win_height":"180","game_control":"3","game_reward":"4294967295","game_bonus":"65535",
                      "at_game_bonus":"32767","highscore_limit":"9223372036854775807"}, original)
        if FormFields(body,form).values.get("game_desc") != title:
            raise RuntimeError("A full-length Unicode title did not round-trip through the native form")
        check("edited")
        stale = submit_form(actor,edit,original,form,{"game_desc":"Earlier tab"})
        if "changed after you opened" not in stale:
            raise RuntimeError("An outdated tab overwrote later game metadata")
        detail = guest.fetch("app.php/arcade/game/61")
        if title not in detail or "Mäuse 🎮" not in detail or ("Maus und Tastatur" not in detail and "mouse and keyboard" not in detail):
            raise RuntimeError("The public game detail lost the title, instructions or controls")
        saved({"cat_id":"12"})
        check("moved")
        guest.fetch("app.php/arcade/game/61",expected_status=404)
        body = saved({"cat_id":"10","game_avail":"0"})
        guest.fetch("app.php/arcade/game/61",expected_status=404)
        # A regular native group must work even without any original game grant for this user.
        groups = re.search(r'<select name="group_required"[^>]*>(.*?)</select>',body,re.S).group(1)
        group = re.search(r'<option value="([0-9]+)"[^>]*>Photo Club</option>',groups).group(1)
        body = saved({"group_required":group,"game_avail":"1"})
        guest.fetch("app.php/arcade/game/61",expected_status=404)
        member = member or login(base,"HistoricMember",os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
        pending = pending or login(base,"DepartingFixture",os.environ["PLUS_TEST_MODERN_PASSWORD"])
        if title not in member.fetch("app.php/arcade/game/61"):
            raise RuntimeError("The selected native group could not see its game")
        pending.fetch("app.php/arcade/game/61",expected_status=404)
        check("never")
        denied = submit_form(actor,edit,body,form,{"game_desc":"Revoked edit"},status=503)
        if "Module not accessible" not in denied:
            raise RuntimeError("An explicit Never failed to revoke only the game editor")
        check("verify")
    finally:
        check("cleanup")
