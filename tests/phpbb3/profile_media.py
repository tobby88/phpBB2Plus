"""Verify that converted profile images work through phpBB's native entry points."""
from html import unescape
import re

from gallery import png

UPLOAD = png(18, 16, b"\x20\x40\x80")
LOCAL = png(20, 14, b"\x80\x40\x20")
SMILE = png(10, 8, b"\x20\x60\x20")
RANKS = {"de": png(21, 6, b"\x80\x10\x10"), "en": png(21, 6, b"\x10\x10\x80")}


def source_media(legacy):
    files = {"images/avatars/historic.png": UPLOAD, "images/avatars/gallery/Grüne Gruppe/Blauer Stern.png": LOCAL,
             "images/flags/nordlicht.png": png(15, 10, b"\x20\x90\x60"), "images/flags/Abendrot.png": SMILE,
             "images/avatars/gallery/Anderes/Unused.png": SMILE, "images/smiles/zeichen.png": SMILE}
    for lang, name in (("en", "english"), ("de", "german")):
        files[f"templates/fisubsilversh/images/lang_{name}/ranks/abzeichen.png"] = RANKS[lang]
    for name, content in files.items():
        path = legacy / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(content)


def browse(english, german, data):
    upload = "download/file.php?avatar=" + data["uploaded"]
    gallery = "images/avatars/gallery/" + data["gallery"]
    for path, content in ((upload, UPLOAD), (gallery, LOCAL), ("images/smilies/" + data["smiley"], SMILE),
                          ("images/avatars/gallery/Plus-Anderes/Unused.png", SMILE)):
        if english.fetch(path, raw=True) != content:
            raise RuntimeError("Converted profile media differ from the source")
    for actor, locale in ((english, "en"), (german, "de")):
        profile = actor.fetch("memberlist.php?mode=viewprofile&u=2")
        badge = re.search(r'src="([^"]*images/ranks/plus-rank-77-[a-f0-9]+-' + locale + r'\.png)"', profile)
        if gallery not in profile or not badge or actor.fetch(unescape(badge.group(1)), raw=True) != RANKS[locale]:
            raise RuntimeError("The native profile is missing its gallery avatar or localized rank")
    if upload not in english.fetch("memberlist.php?mode=viewprofile&u=" + str(data["member"])):
        raise RuntimeError("The native uploaded-avatar URL does not follow its remapped owner")
    if data["smiley"] not in german.fetch("viewtopic.php?t=1"):
        raise RuntimeError("The new text formatter did not render the imported smiley")
