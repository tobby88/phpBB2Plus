"""Open native style previews through the ACP and alternate their shared templates."""
from html import unescape
import re
from urllib.parse import parse_qs, urljoin, urlsplit

from http_board import admin_url


def browse(admin):
    acp = admin_url(admin) + "&i=acp_styles&mode=style"
    previews = {}
    for row in re.findall(r"<tr\b[^>]*>(.*?)</tr>", admin.fetch(acp), re.S | re.I):
        for label, style in (("FI Subsilver Shadow", "fisubsilversh"), ("prosilver", "prosilver")):
            if not re.search(r">" + re.escape(label) + r"</(?:strong|span)>", row, re.I):
                continue
            for href in re.findall(r'href="([^"]+)"', row):
                href = urljoin(acp, unescape(href))
                parsed = urlsplit(href)
                style_id = parse_qs(parsed.query).get("style", [""])[0]
                if parsed.path.endswith("/index.php") and style_id.isdecimal():
                    previews[style] = href, style_id
    if set(previews) != {"fisubsilversh", "prosilver"}:
        raise RuntimeError("Both installed styles must have a native ACP preview")
    for style in ("fisubsilversh", "prosilver", "fisubsilversh"):
        href, style_id = previews[style]
        home = admin.fetch(href)
        topic = admin.fetch("viewtopic.php?t=1&style=" + style_id)
        plus = style == "fisubsilversh"
        for page in (home, topic):
            if ("styles/" + style + "/theme/stylesheet.css" not in page or
                    ('class="plus-brand-portal"' in page) != plus):
                raise RuntimeError("The shared header did not follow the selected style: " + style)
        if 'class="topic-title"' not in topic or topic.count('class="plus-topic-neighbours"') != (2 if plus else 0):
            raise RuntimeError("The shared topic template lost or duplicated its style-specific navigation: " + style)
