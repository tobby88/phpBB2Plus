"""Check that rendered HTML controls can accept a complete Unicode field."""
from html.parser import HTMLParser


class UnicodeControls(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.controls = {}
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag in ("input", "textarea") and attrs.get("id"):
            self.controls[attrs["id"]] = attrs

    def accepts(self, ident, value):
        # HTML maxlength (unlike the application's limits) counts UTF-16 code units.
        maximum = self.controls[ident].get("maxlength")
        if maximum is not None and len(value.encode("utf-16-le")) // 2 > int(maximum):
            raise RuntimeError("The browser would truncate a valid Unicode value in " + ident)
