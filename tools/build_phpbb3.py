"""Assemble a fresh phpBB3 Plus web root from verified release archives."""
import argparse
from hashlib import sha256
import json
from pathlib import Path, PurePosixPath
import os
import re
import shutil
import tempfile
import urllib.request
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PACKAGES = (
    ("https://download.phpbb.com/pub/release/3.3/3.3.19/phpBB-3.3.19.zip",
     "1530e5cabf7ecc9ecfaed3fa13da79f9e8a514623beafababc8800b5ce966de9", "phpBB3"),
    ("https://downloads.phpbb.de/pakete/deutsch/3.3/3.3.19/phpBB_lang_de-3.3.19.zip",
     "3c95828a1d6ad4c86248e0fedb6153f59c57d211adbded1c507b169db54beef0", ""),
)
STYLE_IMAGES = ("arrow.gif", "link_folder.gif", "cellpic1.gif", "cellpic3.gif", "cellpic_bkg.jpg", "cellpic_nav.gif",
                "phpbb2_logo.jpg", "phpbb2_logor.jpg", "icon_minigender_male.gif", "icon_minigender_female.gif")


def digest(path):
    with path.open("rb") as handle:
        checksum = sha256()
        for block in iter(lambda: handle.read(131072), b""):
            checksum.update(block)
        return checksum.hexdigest()


def archive(url, checksum, cache, offline):
    name = url.rsplit("/", 1)[1]
    saved = cache / name
    if saved.is_file():
        if digest(saved) != checksum:
            raise ValueError(f"Incorrect SHA-256 for cached {name}")
        return saved
    if offline:
        raise ValueError(f"Missing offline package: {name}")
    cache.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=cache) as download_dir:
        incoming = Path(download_dir) / name
        with urllib.request.urlopen(url, timeout=45) as response, incoming.open("xb") as out:
            shutil.copyfileobj(response, out)
        if digest(incoming) != checksum:
            raise ValueError(f"Incorrect upstream SHA-256 for {name}")
        os.replace(incoming, saved)
    return saved


def unpack(package, prefix, root):
    directories = {root}
    with zipfile.ZipFile(package) as source:
        for item in source.infolist():
            path = PurePosixPath(item.filename)
            if path.is_absolute() or ".." in path.parts or "\\" in item.filename or ":" in item.filename:
                raise ValueError(f"Unexpected release path: {item.filename}")
            if prefix:
                path = path.relative_to(prefix)
            target = root / path
            directory = target if item.is_dir() else target.parent
            if directory not in directories:
                directory.mkdir(parents=True, exist_ok=True)
                directories.add(directory)
            if item.is_dir():
                continue
            try:
                out = target.open("xb")
            except FileExistsError:
                # Language packs may repeat identical directory index files from the core.
                if target.read_bytes() != source.read(item):
                    raise ValueError(f"Upstream packages disagree on {path}")
                continue
            with out, source.open(item) as inp:
                shutil.copyfileobj(inp, out)
            if item.external_attr >> 16 & 0o111:
                target.chmod(0o755)


def bundle_flags(web):
    source = ROOT / "phpBB2"
    folder = web / "images/plusflags"
    folder.mkdir(exist_ok=True)
    catalog = []
    for line in (source / "install/schemas/mysql_basic.sql").read_text(encoding="utf-8").splitlines():
        if not line.startswith("INSERT INTO phpbb_flags "):
            continue
        row = re.fullmatch(r"INSERT INTO phpbb_flags \(flag_name, flag_image\) VALUES \('([^']+)','([a-zA-Z0-9_-]+\.gif)'\);", line)
        if not row:
            raise ValueError("Unrecognised flag entry in the original Plus distribution")
        title, name = row.groups()
        data = (source / "images/flags" / name).read_bytes()
        if data[:6] not in (b"GIF87a", b"GIF89a"):
            raise ValueError(f"Invalid bundled flag image {name}")
        image = sha256(data).hexdigest() + ".gif"
        (folder / image).write_bytes(data)
        catalog.append({"label": title, "source_file": name, "image": image})
    if not catalog:
        raise ValueError("The Plus distribution contains no flag definitions")
    dest = web / "ext/phpbb2plus/community/data"
    dest.mkdir(exist_ok=True)
    (dest / "flags.json").write_text(json.dumps(catalog, ensure_ascii=True), encoding="ascii")


def bundle_post_icons(web):
    source = ROOT / "phpBB2"
    text = (source / "includes/icon_defaults.php").read_text(encoding="utf-8")
    icons = []
    destination = web / "images/icons/plus"
    destination.mkdir()
    for item in re.finditer(r"'ind'\s*=>\s*(\d+),\s*'img'\s*=>\s*'([^']*)',\s*'alt'\s*=>\s*'(\w+)',\s*'auth'\s*=>\s*(\d+)", text):
        index, image, label, level = item.groups()
        if not int(index):
            continue
        if not re.fullmatch(r"images/icon/icon\d+\.gif", image):
            raise ValueError("Unexpected image in the bundled post icon catalogue")
        name = Path(image).name
        shutil.copyfile(source / image, destination / name)
        icons.append(dict(id=int(index), image="plus/" + name, label=label, level=int(level)))
    defaults = {key: int(value) for key, value in re.findall(
        r"'(POST_[A-Z_]+)'\s*=>\s*array\(\s*'lang_key'\s*=>\s*'\w+',\s*'icon'\s*=>\s*(\d+)", text)}
    if len({icon["id"] for icon in icons}) != 22 or len(defaults) != 8:
        raise ValueError("Incomplete Plus post icon catalogue")
    titles = {}
    for locale, old in (("en", "english"), ("de", "german")):
        lang = (source / "language" / ("lang_" + old) / "lang_extend_post_icons.php").read_text(encoding="utf-8")
        titles[locale] = dict(re.findall(r"^\$lang\['(icon_\w+)'\]\s*=\s*'([^']*)';", lang, re.M))
        if any(icon["label"] not in titles[locale] for icon in icons):
            raise ValueError("Missing post icon translation for " + locale)
    manifest = web / "ext/phpbb2plus/community/data/post_icons.json"
    manifest.write_text(json.dumps(dict(icons=icons, defaults=defaults, titles=titles), ensure_ascii=False), encoding="utf-8")


def assemble(output, cache, offline=False):
    output = output.resolve()
    overlay = ROOT / "phpBB3"
    if output.exists() or overlay in output.parents or ROOT / "phpBB2" in output.parents:
        raise ValueError("Choose a new output directory outside both source trees")
    output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=output.parent) as staging:
        web = Path(staging) / "web"
        web.mkdir()
        for url, checksum, prefix in PACKAGES:
            unpack(archive(url, checksum, cache, offline), prefix, web)
        for source in sorted(overlay.rglob("*")):
            if source.is_symlink():
                raise ValueError(f"Unexpected overlay link: {source}")
            if source.is_dir():
                continue
            target = web / source.relative_to(overlay)
            if target.exists():
                raise ValueError(f"Plus file would replace upstream: {source.relative_to(overlay)}")
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
        for name, module in (("install/convertors/plus_jr_registrations.php", "functions_jr_admin_auth.php"),
                             ("ext/phpbb2plus/community/legacy/address_bans.php", "functions_ban.php")):
            helper = web / name
            if helper.exists():
                raise ValueError(f"The Plus helper would replace an existing converter file: {name}")
            helper.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / "phpBB2/includes" / module, helper)
        # Preserve the original local sign artwork, licensed font and standalone PNG bookmarks.
        for name in ("text2schild.php", "includes/functions_schild.php", "assets/fonts/DejaVuSans.ttf", "assets/fonts/LICENSE-DejaVu.txt"):
            target = web / name
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open("xb") as out:
                out.write((ROOT / "phpBB2" / name).read_bytes())
        faces = Path("smilie_creator/images/smilies/schild")
        shutil.copytree(ROOT / "phpBB2" / faces, web / faces)
        images = web / "styles" / "fisubsilversh" / "theme" / "images"
        bundle_flags(web)
        bundle_post_icons(web)
        runtime = ROOT / "phpBB2/assets/ruffle"
        player = web / "assets/ruffle"
        player.mkdir()
        for component in ("ruffle.js", "core.ruffle.*.js", "*.wasm", "LICENSE_*", "package.json", "UPSTREAM_README.md"):
            files = sorted(runtime.glob(component))
            if not files:
                raise ValueError(f"Incomplete bundled Ruffle runtime: {component}")
            for file in files:
                shutil.copyfile(file, player / file.name)
        images.mkdir(parents=True, exist_ok=True)
        old_images = ROOT / "phpBB2/templates/fisubsilversh/images"
        (web / "images/pluskb").mkdir()
        shutil.copyfile(old_images / "lang_english/kb.gif", web / "images/pluskb/header.gif")
        news_images = web / "images/plusnews"
        (web / "images/plusportal").mkdir(parents=True, exist_ok=True)
        news_images.mkdir()
        for name in ("topicnews.gif", "announcements.gif"):
            shutil.copyfile(old_images / "news" / name, news_images / name)
        style_images = [old_images / name for name in STYLE_IMAGES] + sorted(old_images.glob("folder*.gif"))
        for source in style_images:
            with (images / source.name).open("xb") as out:
                out.write(source.read_bytes())
        # phpBB inherits templates. Several native templates address theme files
        # directly under the active style, including the localized online marker.
        base_theme = web / "styles/prosilver/theme"
        theme = images.parent
        for language, legacy_language in (("de", "german"), ("en", "english")):
            shutil.copytree(base_theme / language, theme / language)
            for action in ("search", "stats", "toplist", "upload", "viewall", "download", "rate", "email"):
                name = f"icon_pa_{action}.gif"
                shutil.copyfile(old_images / f"lang_{legacy_language}" / name, theme / language / name)
        shutil.copyfile(base_theme / "images/no_avatar.gif", images / "no_avatar.gif")
        for name in ("bidi.css", "print.css", "plupload.css", "tweaks.css"):
            (theme / name).write_text(f'@import url("../../prosilver/theme/{name}");\n', encoding="utf-8")
        web.rename(output)
    print(f"phpBB 3.3.19 Plus assembled in {output}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, default=ROOT / "build/phpBB3")
    parser.add_argument("--cache", type=Path, default=ROOT / "vendor/phpbb")
    parser.add_argument("--offline", action="store_true")
    args = parser.parse_args()
    try:
        assemble(args.output, args.cache, args.offline)
    except (OSError, ValueError, zipfile.BadZipFile) as error:
        parser.exit(1, f"{error}\n")
