"""Native profile and fiSubSilver author artwork for private birthday details."""
from html import unescape
from pathlib import Path
import re

from http_board import Browser


def browse(base, german, english, check):
    check("prepare")
    guest = Browser(base)
    graphics = Path(__file__).resolve().parents[2] / "phpBB2/templates/fisubsilversh/images"
    icon = r'icon_(?:zodiac|chinese)_[a-z]+\.gif'

    def profile(actor, date=None, signs=(), age=False):
        html = unescape(actor.fetch("memberlist.php?mode=viewprofile&u=2"))
        if "ModernMember" not in html:
            raise RuntimeError("Native member profile unavailable")
        field = re.search(r'<dt>(?:Geburtstag|Birthday)\s*:</dt>\s*<dd>([^<]*)</dd>', html)
        has_age = bool(re.search(r'<dt>(?:Alter|Age)\s*:</dt>\s*<dd>\d+</dd>', html))
        shown = field[1] if field else None
        if shown != date or has_age != age:
            label_loaded = "<dt>BIRTHDAY:" not in html
            raise RuntimeError(f"Incorrect birthday/age on native profile: expected {(date, age)}, got {(shown, has_age)}, localized label={label_loaded}")
        if re.findall(icon, html) != [name for name, label in signs]:
            raise RuntimeError("A native profile showed the wrong birthday images")
        for name, label in signs:
            if not re.search(r'<dd>\s*' + re.escape(label) + r'\s*<img[^>]*' + re.escape(name) + r'[^>]*alt=""', html):
                raise RuntimeError(f"Missing profile sign label {label}")

    def card(actor, expected=(), post_id=1):
        html = unescape(actor.fetch(f"viewtopic.php?p={post_id}"))
        section = re.search(r'<dl\b[^>]*id="profile' + str(post_id) + r'"[^>]*>(.*?)</dl>', html, re.S)
        if not section:
            raise RuntimeError("Missing normal native author card")
        text = section[1]
        if re.findall(icon, text) != [name for name, label in expected]:
            raise RuntimeError("Incorrect author signs or visibility")
        for name, label in expected:
            if not re.search(r'<img[^>]*' + re.escape(name) + r'[^>]*alt="' + re.escape(label) + r'"', text):
                raise RuntimeError("Missing accessible author sign name")
        if re.search(r'<(?:dt|dd)>\s*(?:Birthday|Geburtstag)\s*:', text):
            raise RuntimeError("A birth date became author-card text")
        return text

    try:
        profile(guest)
        card(guest)
        card(english, post_id=32)
        profile(german, "30.01.1938", (("icon_zodiac_aquarius.gif", "Wassermann"), ("icon_chinese_buffalo.gif", "Büffel")), True)
        profile(english, "30 Jan 1938", (("icon_zodiac_aquarius.gif", "Aquarius"), ("icon_chinese_buffalo.gif", "Buffalo")), True)
        rendered = card(german, (("icon_zodiac_aquarius.gif", "Sternzeichen: Wassermann"), ("icon_chinese_buffalo.gif", "Chinesisches Sternzeichen: Büffel")))
        href = re.search(r'src="([^"]+icon_chinese_buffalo\.gif)"', rendered)[1]
        if german.fetch(href, raw=True) != (graphics / "icon_chinese_buffalo.gif").read_bytes():
            raise RuntimeError("The author card did not serve the original Plus image")
        check("cat")
        card(english, (("icon_zodiac_aquarius.gif", "Zodiac: Aquarius"), ("icon_chinese_cat.gif", "Chinese zodiac: Cat")))
        check("partial")
        profile(german, "29.02.", (("icon_zodiac_pisces.gif", "Fische"),))
        profile(english, "29 Feb", (("icon_zodiac_pisces.gif", "Pisces"),))
        card(english, (("icon_zodiac_pisces.gif", "Zodiac: Pisces"),))
        check("invalid")
        profile(german, "Keiner angegeben")
        card(english)
        check("unset")
        profile(english, "None specified")
        check("cat")
        check("off")
        profile(german)
        card(english)
    finally:
        check("restore")
