"""Follow both the native credits URL and an existing Plus bookmark."""
from html import unescape
from http_board import Browser


def browse(base, english):
    guest = Browser(base)
    first = guest.fetch("hacks_list.php")
    expected = ('Aktive Plus-Erweiterungen', 'Credits der ursprünglichen phpBB2-Installation',
                'phpBB2 Plus Arcade', 'phpBB2 Plus Member Colours', 'id="credit-19000"', '0000 Archive 00', '(0)',
                'mailto:credits@example.invalid', 'https://example.invalid/credits?a=1&amp;b=2')
    if (any(value not in first for value in expected)
            or 'Die Datei C:\\Archiv enthält "Grüße" 📗 und den Text &copy;.' not in unescape(first)):
        raise RuntimeError("The public credits lost an active extension, source attribution or original text")
    second = guest.fetch("app.php/credits?start=30")
    if ('id="credit-19030"' not in second or 'id="credit-19000"' in second or
            any(value in first + second for value in ("local-file.zip", "mailto:unrecorded", "private-fixture-manifest.hl", "0000 Hidden archival entry"))):
        raise RuntimeError("Credit pagination, historic visibility or old contact links changed")
    english_page = english.fetch("app.php/credits")
    if 'Credits from the original phpBB2 board' not in english_page or 'Active Plus extensions' not in english_page:
        raise RuntimeError("The credits do not follow the signed-in member's language")
    if 'app.php/credits' not in guest.fetch("index.php"):
        raise RuntimeError("The forum does not link to the credits")


def colours_disabled(base):
    page = Browser(base).fetch("app.php/credits")
    if 'phpBB2 Plus Arcade' not in page or 'phpBB2 Plus Member Colours' in page:
        raise RuntimeError("A disabled Plus extension was listed as active")
