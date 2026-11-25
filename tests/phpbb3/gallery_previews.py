"""Recent pictures of a selected category, separately from its sortable listing."""
from html import unescape
import re
from urllib.parse import urljoin

from gallery_navigation import AlbumPage
from http_board import FormFields, admin_url, submit_form


def browse(administrator, member, applicant, guest, check):
    def page(actor, category):
        return actor.fetch(f"app.php/album/category/{category}?sort_method=pic_title&sort_order=ASC")

    def grids(actor, category, recent=None, pictures=None):
        html = page(actor, category)
        album = AlbumPage(html)
        expected = (["recent"] if recent is not None else []) + (["category"] if pictures is not None else [])
        if album.grids != expected or list(album.recent) != (recent or []) or list(album.pictures) != (pictures or []):
            raise RuntimeError(f"Wrong recent/category grids in album {category}: {album.grids}, {list(album.recent)}, {list(album.pictures)}")
        if not expected and "plus-album-empty" not in html:
            raise RuntimeError("An empty category did not tell its reader there are no visible pictures")

    check("seed")
    try:
        entry = admin_url(administrator)
        menu = administrator.fetch(entry + "&i=acp_board&mode=settings")
        link = re.search(r'<a href="([^"]+)"[^>]*>\s*<span>Anzeige im Fotoalbum</span>', menu)
        if not link:
            raise RuntimeError("The category preview options are missing from the ACP")
        url = urljoin(entry, unescape(link[1]))
        options = ("show_recent_in_subcats", "show_recent_instead_of_nopics", "personal_show_recent_in_subcats", "personal_show_recent_instead_of_nopics")
        form = "album-display-settings"

        def configure(values):
            submit_form(administrator, url, administrator.fetch(url), form, dict(zip(options, values)))
            saved = FormFields(administrator.fetch(url), form).values
            if [saved.get(key) for key in options] != list(values):
                raise RuntimeError("An independently saved preview setting did not persist in the ACP")

        for viewer in [guest, applicant]:
            grids(viewer, 650, [9601, 9600], [9600])
        grids(member, 650, [9603, 9602, 9601, 9600], [9600])
        grids(administrator, 650, [9604, 9603, 9602, 9601, 9600], [9600, 9604])
        grids(guest, 651, [9601], [9601])
        grids(guest, 654, [9601, 9600])
        grids(member, 655, [9605])
        grids(member, 656, [9605], [9605])
        grids(member, 657)
        guest.fetch("app.php/album/category/655", expected_status=404)
        configure("0110")
        grids(guest, 650, pictures=[9600])
        grids(guest, 654, [9601, 9600])
        grids(member, 655)
        grids(member, 656, [9605], [9605])
        configure("1001")
        grids(guest, 650, [9601, 9600], [9600])
        grids(guest, 654)
        grids(member, 655, [9605])
        grids(member, 656, pictures=[9605])
    finally:
        check("cleanup")
