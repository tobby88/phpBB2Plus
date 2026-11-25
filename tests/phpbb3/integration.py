"""Install the built distribution in a fresh, disposable MySQL/MariaDB database."""
import argparse
from contextlib import contextmanager
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import tempfile
from time import monotonic
import zipfile

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools"))
from build_phpbb3 import assemble, PACKAGES
import arcade
import attachments
import news
import portal
import custom_profiles
import colours
import post_icons
import legacy_links
import downloads
import profile_media
import topic_calendar
import gallery
import knowledgebase
from ruffle_browser import playback
from http_board import convert, former_names, login, native_content, public_pages, profiles, server, source_files
from shoutbox import exercise as exercise_shoutbox


def php(*args, cwd=None, capture=False):
    result = subprocess.run([os.environ.get("PLUS_TEST_PHP", "php"), "-d", "disable_functions=mail",
                             *map(str, args)], cwd=cwd, text=True, encoding="utf-8",
                            errors="replace", stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.STDOUT if capture else None, check=True)
    return result.stdout if capture else None


@contextmanager
def database():
    tool = ROOT / "tests/phpbb3/database.php"
    name = php(tool, "create", capture=True).strip()
    if not re.fullmatch(r"plus3_test_[a-f0-9]{24}", name):
        raise ValueError("Failed to create an isolated schema")
    try:
        yield name
    finally:
        php(tool, "drop", name)


def install(web, settings_dir, name):
    config = settings_dir / "install.yml"
    quote = json.dumps
    config.write_text(f"""installer:
    admin:
        name: PlusFounder
        password: {quote(secrets.token_hex(12))}
        email: founder@example.invalid
    board:
        lang: de
        name: Plus integration test
        description: Disposable test installation
    database:
        dbms: mysqli
        dbhost: 127.0.0.1
        dbport: {quote(os.environ.get("PLUS_TEST_DB_PORT", "3306"))}
        dbuser: {quote(os.environ.get("PLUS_TEST_DB_USER", "root"))}
        dbpasswd: {quote(os.environ.get("PLUS_TEST_DB_PASSWORD", ""))}
        dbname: {quote(name)}
        table_prefix: p3_
    email:
        enabled: false
    server:
        cookie_secure: false
        server_protocol: http://
        force_server_vars: false
        server_name: 127.0.0.1
        server_port: 80
        script_path: /
    extensions: [all]
""", encoding="utf-8")
    report = php("install/phpbbcli.php", "install", config, "--no-interaction", cwd=web, capture=True)
    if "The installer has finished successfully" not in report or "PHPBB_INSTALLED" not in (web / "config.php").read_text(encoding="utf-8"):
        raise RuntimeError("phpBB installer did not finish: " + report[-4000:])
    for extension in ("community", "gallery", "knowledgebase", "arcade", "downloads", "calendar", "news", "colours"):
        if "Successfully enabled extension phpbb2plus/" + extension not in report:
            raise RuntimeError(f"The installer did not activate {extension}: " + report[-3500:])
    php("bin/phpbbcli.php", "extension:disable", "phpbb/viglink", "--no-interaction", cwd=web)


def verify_upstream(web, cache):
    count = 0
    for url, _, prefix in PACKAGES:
        with zipfile.ZipFile(cache / url.rsplit("/", 1)[1]) as source:
            for info in source.infolist():
                if info.is_dir():
                    continue
                relative = Path(info.filename).relative_to(prefix or ".")
                if source.read(info) != (web / relative).read_bytes():
                    raise ValueError(f"Modified upstream file: {relative}")
                count += 1
    return count


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cache", type=Path, required=True)
    parser.add_argument("--offline", action="store_true")
    args = parser.parse_args()
    if os.environ.get("PLUS_TEST_DATABASE") != "1":
        parser.error("Set PLUS_TEST_DATABASE=1 to create a disposable loopback schema")
    start = monotonic()
    php(ROOT / "tests/phpbb3/calendar_periods.php")
    with tempfile.TemporaryDirectory(prefix="plus3-integration-") as directory:
        root = Path(directory)
        web = root / "board"
        assemble(web, args.cache.resolve(), args.offline)
        count = verify_upstream(web, args.cache.resolve())
        built = monotonic()
        print(f"Assembly and comparison of {count} upstream files: {built-start:.1f}s", flush=True)
        with database() as target, database() as source:
            install(web, root, target)
            php("bin/phpbbcli.php", "--version", cwd=web)
            fixture = ROOT / "tests/phpbb3/legacy_database.php"
            php(fixture, "installed-flags", target)
            os.environ["PLUS_TEST_MODERN_PASSWORD"] = secrets.token_hex(7) + '"&Ü\\'
            os.environ["PLUS_TEST_HISTORIC_PASSWORD"] = secrets.token_hex(7) + "'&ä\\"
            php(fixture, "seed", source)
            source_files(ROOT / "phpBB2", root / "legacy")
            attachments.source_media(root / "legacy")
            news.source_media(root / "legacy")
            gallery.source_media(root / "legacy")
            arcade.source_media(root / "legacy")
            downloads.source_media(root / "legacy")
            profile_media.source_media(root / "legacy")
            seeded = monotonic()
            print(f"Installation and source fixtures: {seeded-built:.1f}s", flush=True)
            with server(web, root) as base:
                convert(base, source)
                (web / "install").rename(root / "finished-installer")
                php(fixture, "converted", target)
                attachments.verify_storage(web, php(fixture, "attachments", target, capture=True))
                converted = monotonic()
                print(f"Conversion and database verification: {converted-seeded:.1f}s", flush=True)
                login(base, "ModernMember", "incorrect", expect_success=False)
                php(fixture, "hashes-unchanged", target)
                member = login(base, "ModernMember", os.environ["PLUS_TEST_MODERN_PASSWORD"])
                ordinary = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"], portal=True)
                pending = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
                php(fixture, "signed-in", target)
                public_pages(base, member)
                legend = member.fetch("index.php")
                if ('#5A769B">Redaktion &amp; Gäste</span>' not in legend or 'Hidden palette' in legend
                        or 'plus-colour-legend">Invalid colour</span>' not in legend):
                    raise RuntimeError("The index lost a custom colour, double-escaped its name or revealed a hidden legend entry")
                php(ROOT / "tests/phpbb3/colour_membership.php", target, cwd=web)
                portal.browse(base, member)
                exercise_shoutbox(base, member, ordinary)
                php(ROOT / "tests/phpbb3/guest_profiles.php", target, cwd=web)
                profiles(base, ordinary, member)
                news.browse(base, member)
                custom_profiles.exercise(base, pending)
                media = json.loads(php(fixture, "profile-media", target, capture=True))
                profile_media.browse(ordinary, member, media)
                legacy_links.browse(base, member, media["member"])
                topic_calendar.browse(base, member, ordinary, pending)
                news.administration(base, member)
                colours.administration(member, media["member"])
                portal.administration(base, member)
                custom_profiles.administration(member)
                for action in ("disable", "enable"):
                    php("bin/phpbbcli.php", "extension:" + action, "phpbb2plus/community", "--no-interaction", cwd=web)
                    php("bin/phpbbcli.php", "extension:" + action, "phpbb2plus/colours", "--no-interaction", cwd=web)
                    php("bin/phpbbcli.php", "cache:purge", "--no-interaction", cwd=web)
                    php(fixture, "colours-" + action, target)
                    php(ROOT / "tests/phpbb3/profile_defaults.php", target, cwd=web)
                    custom_profiles.visibility_after_toggle(base, action == "enable")
                native_content(base, member, ordinary)
                attachments.downloads(ordinary)
                game_source = arcade.browse(base, member, ordinary, pending)
                php(fixture, "played", target)
                arcade.protocols(base, ordinary, game_source)
                arcade.wallets(base, ordinary, member)
                php(fixture, "scored", target)
                downloads.browse(base, member, ordinary, pending)
                php(fixture, "downloaded", target)
                playback(web, base, root)
                knowledgebase.browse(base, member, ordinary, pending)
                php(ROOT / "tests/phpbb3/knowledgebase_mail.php", target, cwd=web)
                gallery.browse(base, member, ordinary, pending)
                php(ROOT / "tests/phpbb3/gallery_cleanup.php", target, cwd=web)
                former_names(base, ordinary)
                php(ROOT / "tests/phpbb3/retain_user.php", target, cwd=web)
                former_names(base, ordinary, removed=True)
                gallery.departed(base, ordinary)
                post_icons.browse(member, ordinary, json.loads(php(fixture, "post-icons", target, capture=True)))
                login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
                print(f"Application requests and native actions: {monotonic()-converted:.1f}s", flush=True)
    print(f"Verified {count} upstream files, installation, conversion and logins in {monotonic()-start:.1f}s")


if __name__ == "__main__":
    main()
