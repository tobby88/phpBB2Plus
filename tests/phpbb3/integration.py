"""Install the built distribution in a fresh, disposable MySQL/MariaDB database."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from itertools import islice
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
import arcade_administration
import arcade_directory
import arcade_categories
import arcade_category_administration
import arcade_moderation
import arcade_standings
import banners
import weblinks
import weblink_logos
import weblinks_admin
import board_statistics
import statistics_administration
import attachments
import attachment_quotas
import forum_permissions
import forum_recovery
import news
import portal
import recent_topics
import custom_profiles
import colours
import post_icons
import removed_posts
import delete_policy
import confirmed_deletions
import removed_shadow_links
import age_pruning
import print_topics
import announcement_duration
import locked_polls
import poll_administration
import legacy_links
import legacy_login
import acronym_dictionary
import explicit_acronym
import account_pruning
import inline_editor
import quick_reply
import moderation_bookmarks
import mail_links
import admin_contact
import topic_readers
import arcade_diagnostics
import arcade_game_editor
import arcade_game_rename
import arcade_provisioning
import arcade_batch_import
import recommend
import attachment_rules
import attachment_upload_types
import member_attachments
import birthday_greeting
import absence
import absence_contact
import absence_registration
import registration_network
import mailbox_counters
import smilie_signs
import downloads
import download_categories
import download_checker
import download_comment_drafts
import download_moderation
import download_fields
import download_file_actions
import download_file_editor
import download_permissions
import download_reports
import download_search
import download_listing
import download_mail
import download_settings
import download_licenses
import download_mirrors
import delegation
import delegation_administration
import country_flag_administration
import historical_actions
import credits
import warning_cards
import account_logins
import password_renewal
import member_notice
import profile_media
import rank_overview
import topic_calendar
import portal_calendar
import gallery
import gallery_navigation
import gallery_activity
import gallery_moderation
import gallery_previews
import gallery_hot
import gallery_directory
import gallery_category_index
import gallery_search
import gallery_personal
import gallery_cache
import gallery_media_policy
import gallery_rating_policy
import gallery_progress
import gallery_hotlink
import gallery_management_bookmarks
import knowledgebase
from ruffle_browser import playback
from http_board import Browser, author_results, convert, former_names, login, native_content, public_pages, profiles, server, source_files
from shoutbox import exercise as exercise_shoutbox


def php(*args, cwd=None, capture=False):
    script = Path(args[0])
    fixture_dir = ROOT / "tests/phpbb3"
    fixture = script.parent == fixture_dir
    command = [os.environ.get("PLUS_TEST_PHP", "php"), "-d", "disable_functions=mail"]
    if fixture:
        # phpBB can render a fatal error page and exit with status zero. PHP only
        # runs auto_append_file when the fixture reaches a normal end or return.
        marker_file = (fixture_dir / "fixture_completed.php").as_posix().replace('"', '\\"')
        command.extend(["-d", f'auto_append_file="{marker_file}"'])
    result = subprocess.run([*command, *map(str, args)], cwd=cwd, text=True, encoding="utf-8",
                            errors="replace", stdout=subprocess.PIPE if fixture or capture else None,
                            stderr=subprocess.PIPE if fixture else subprocess.STDOUT if capture else None)
    if fixture:
        marker = "phpBB Plus fixture completed"
        lines = result.stderr.splitlines()
        for line in lines:
            if line != marker:
                print(line, file=sys.stderr)
        result.check_returncode()
        if marker not in lines:
            raise RuntimeError(f"{script.name} stopped before completing its checks")
        if not capture and result.stdout:
            print(result.stdout, end="", flush=True)
    else:
        result.check_returncode()
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


def verify_poll_recovery(web):
    command = ("bin/phpbbcli.php", "phpbb2plus:recovery:polls")
    held = [json.loads(line) for line in php(*command, cwd=web, capture=True).splitlines()]
    if len(held) != 7 or any(set(row) != {"source_id", "topic_id", "reason"} for row in held):
        raise RuntimeError("The poll recovery overview must show only IDs and reasons")
    records = [json.loads(line) for line in php(*command, "14401", cwd=web, capture=True).splitlines()]
    header, *options = records
    if header["question"] != "Doppelte Antwort 📷" or header["receipts"] != 1 or header["reason"] != "duplicate-option-id":
        raise RuntimeError("The original question or participation count is missing from poll recovery")
    if [(option["vote_option_text"], int(option["vote_result"])) for option in options] != [("Erste <Gruppe> &amp; 📷", 7), ("Zweite", 19)]:
        raise RuntimeError("Poll recovery did not preserve both distinct answers with the same source option number")


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
    for extension in ("community", "gallery", "knowledgebase", "arcade", "downloads", "calendar", "news", "colours", "banners", "weblinks"):
        if "Successfully enabled extension phpbb2plus/" + extension not in report:
            raise RuntimeError(f"The installer did not activate {extension}: " + report[-3500:])
    php("bin/phpbbcli.php", "extension:disable", "phpbb/viglink", "--no-interaction", cwd=web)


def verify_upstream(web, cache):
    count = 0
    # Independent reads of recently extracted files can wait on filesystem filters.
    # Bound outstanding reads and keep the shared ZIP reader on this thread.
    with ThreadPoolExecutor(max_workers=8) as readers:
        for url, _, prefix in PACKAGES:
            with zipfile.ZipFile(cache / url.rsplit("/", 1)[1]) as source:
                files = (info for info in source.infolist() if not info.is_dir())
                while batch := list(islice(files, 32)):
                    paths = [web / Path(info.filename).relative_to(prefix or ".") for info in batch]
                    for info, content in zip(batch, readers.map(Path.read_bytes, paths)):
                        if source.read(info) != content:
                            raise ValueError(f"Modified upstream file: {info.filename}")
                        count += 1
    return count


def download_checks(base, admin, historical, applicant, fixture, target, web, gallery_run=None):
    downloads.browse(base, admin, historical, applicant)
    php(fixture, "downloaded", target)
    # Permission edits use their own category tree. Complete them before the
    # category manager takes a snapshot of destinations and grants.
    with ThreadPoolExecutor(max_workers=1) as worker:
        permissions = worker.submit(download_permissions.administration,
                                    *(actor.separate_client() for actor in (admin, historical, applicant)),
                                    lambda phase: php(ROOT / "tests/phpbb3/download_permissions.php", target, phase, cwd=web))
        def verify_licence(phase):
            php(ROOT / "tests/phpbb3/download_licenses.php", target, phase, cwd=web)
        download_licenses.agreements(historical, verify_licence)
        download_licenses.administration(admin, historical, verify_licence)
        def verify_fields(phase):
            php(ROOT / "tests/phpbb3/download_fields.php", target, phase, cwd=web)
        download_fields.runtime(admin, historical, verify_fields)
        download_fields.administration(admin, historical, verify_fields)
        download_mirrors.administration(admin, historical, lambda phase: php(
            ROOT / "tests/phpbb3/download_mirrors.php", target, phase, cwd=web))
        download_file_actions.administration(admin, historical, lambda phase: php(
            ROOT / "tests/phpbb3/download_file_actions.php", target, phase, cwd=web))
        download_file_editor.administration(admin, historical, lambda phase: php(
            ROOT / "tests/phpbb3/download_file_editor.php", target, phase, cwd=web))
        # The storage-failure case temporarily switches the board's upload directory.
        if gallery_run is not None:
            gallery_run.result()
        downloads.submissions(base, admin, historical, applicant,
                              lambda phase: php(ROOT / "tests/phpbb3/download_submissions.php", target, phase, cwd=web))
        permissions.result()
        download_checker.administration(admin, lambda phase: php(
            ROOT / "tests/phpbb3/download_checker.php", target, phase, cwd=web))
        download_moderation.browse(base, admin, historical, applicant, lambda phase: php(
            ROOT / "tests/phpbb3/download_moderation.php", target, phase, cwd=web))
        download_comment_drafts.browse(base, admin, historical, lambda phase: php(
            ROOT / "tests/phpbb3/download_comment_drafts.php", target, phase, cwd=web))
        download_reports.browse(base, historical, applicant, lambda phase: php(
            ROOT / "tests/phpbb3/download_reports.php", target, phase, cwd=web))
        download_search.browse(base, historical, applicant, lambda phase: php(
            ROOT / "tests/phpbb3/download_search.php", target, phase, cwd=web))
        download_listing.browse(base, historical, applicant, lambda phase: php(
            ROOT / "tests/phpbb3/download_listing.php", target, phase, cwd=web))
        # The settings test temporarily closes the download area and restores its global policy.
        download_settings.administration(admin, historical, lambda phase: php(
            ROOT / "tests/phpbb3/download_settings.php", target, phase, cwd=web))
        download_mail.browse(base, historical, applicant, lambda phase: php(
            ROOT / "tests/phpbb3/download_mail.php", target, phase, cwd=web))


def download_category_checks(admin, target, web):
    def verify(phase):
        php(ROOT / "tests/phpbb3/download_categories.php", target, phase, cwd=web)
    verify("roles")
    download_categories.administration(admin, verify)


def media_administration(base, member, ordinary, pending, target, web):
    # Private album admission changes membership. Run it after download
    # submissions and keep cache invalidation after all gallery writes.
    guest = Browser(base)
    with ThreadPoolExecutor(max_workers=1) as worker:
        categories = worker.submit(download_category_checks, member.separate_client(), target, web)
        gallery_management_bookmarks.browse(member, ordinary, pending, guest, lambda phase: php(
            ROOT / "tests/phpbb3/gallery_management_bookmarks.php", target, phase, cwd=web, capture=True))
        gallery_personal.browse(base, member, ordinary, pending,
                                lambda phase: php(ROOT / "tests/phpbb3/gallery_personal.php", target, phase, cwd=web))
        gallery_media_policy.browse(member, ordinary, pending, guest, lambda phase: php(
            ROOT / "tests/phpbb3/gallery_media_policy.php", target, phase, cwd=web, capture=True))
        gallery_rating_policy.browse(member, ordinary, pending, guest, lambda phase: php(
            ROOT / "tests/phpbb3/gallery_rating_policy.php", target, phase, cwd=web, capture=True))
        gallery_cache.browse(member, pending, guest, lambda phase: php(
            ROOT / "tests/phpbb3/gallery_cache.php", target, phase, cwd=web, capture=True),
            lambda phase: php(ROOT / "tests/phpbb3/gallery_personal.php", target, phase, cwd=web))
        categories.result()


def arcade_management(base, admin, moderator, member, target, web):
    tests = ROOT / "tests/phpbb3"
    arcade_diagnostics.browse(base, admin, lambda phase: php(tests / "arcade_diagnostics.php", target, phase, cwd=web, capture=True))
    arcade_moderation.exercise(base, admin, moderator, member, lambda phase: php(tests / "arcade_moderation.php", target, phase, cwd=web))
    arcade_category_administration.exercise(base, admin, moderator, lambda phase: php(
        tests / "arcade_category_administration.php", target, phase, cwd=web, capture=True))
    arcade_game_editor.browse(base, lambda phase: php(tests / "arcade_game_editor.php", target, phase, cwd=web, capture=True), moderator, member)
    arcade_game_rename.browse(base, web, target, php)
    arcade_batch_import.browse(base, web, target, php)
    arcade_provisioning.browse(base, web, target, php)


def account_management(base, admin, moderator, member, target, web):
    def fixture(name):
        return lambda phase: php(ROOT / "tests/phpbb3" / (name + ".php"), target, phase, cwd=web, capture=True)

    member_attachments.browse(base, admin, moderator)
    attachment_rules.browse(admin, moderator, member, Browser(base), fixture("attachment_rules"))
    attachment_upload_types.browse(admin, moderator, member, fixture("attachment_upload_types"))
    birthday_greeting.browse(base, moderator, admin, fixture("birthday_greeting"))
    absence.browse(base, moderator, admin, fixture("absence"))
    absence_contact.browse(base, moderator, admin, fixture("absence_contact"))
    mailbox_counters.browse(base, admin, fixture("mailbox_counters"))
    absence_registration.browse(base, fixture("absence_registration"))
    php(ROOT / "tests/phpbb3/acronym_dictionary.php", target, "converted", cwd=web)
    acronym_dictionary.browse(base, admin, moderator)
    inline_editor.browse(base, admin, moderator, lambda phase: php(
        ROOT / "tests/phpbb3/inline_editor.php", target, phase, cwd=web, capture=True), web)
    account_pruning.browse(base, web, target, php)
    registration_network.browse(base, web, target, php)


def extension_upgrades(target, web, suite="all"):
    # The CI groups each run on both PHP versions. Exercise each migration
    # with its own workflows' data, including all groups on a single-board full run.
    started = monotonic()
    tests = ROOT / "tests/phpbb3"
    migrations, fixtures, preserved = [], [], []
    if suite in ("forum", "all"):
        migrations.extend(("community/migrations/v137_account_logins", "community/migrations/v150_country_flag_administration",
                           "community/migrations/v151_topic_readers"))
        fixtures.append(tests / "login_lifecycle.php")
        fixtures.append(tests / "topic_readers.php")
        preserved.append(tests / "country_flag_administration.php")
        migrations.append("community/migrations/v161_acronym_dictionary")
        preserved.append(tests / "acronym_dictionary.php")
    if suite in ("media", "downloads", "all"):
        migrations.extend(("downloads/migrations/v111_reports", "gallery/migrations/v114_rating_settings"))
        fixtures.extend(tests / name for name in ("download_reports.php", "download_search.php", "download_mail.php", "download_checker.php"))
        preserved.append(tests / "gallery_rating_policy.php")
    if suite in ("arcade", "management", "all"):
        # The diagnostics and game migrations depend on v113 and roll back with it.
        migrations.append("arcade/migrations/v113_category_administration")
        preserved.extend((tests / "arcade_category_administration.php", tests / "arcade_diagnostics.php"))
    for migration in migrations:
        php("bin/phpbbcli.php", "db:revert", "/phpbb2plus/" + migration, "--no-interaction", cwd=web)
    for fixture in fixtures:
        php(fixture, target, "older", cwd=web)
    snapshots = {fixture: php(fixture, target, "older", cwd=web, capture=True) for fixture in preserved}
    php("bin/phpbbcli.php", "db:migrate", "--no-interaction", cwd=web)
    for fixture in fixtures:
        php(fixture, target, "upgraded", cwd=web)
    for fixture, original in snapshots.items():
        if php(fixture, target, "upgraded", cwd=web, capture=True) != original:
            raise RuntimeError("The upgrade changed stored data checked by " + fixture.name)
    print(f"Extension upgrades and data checks ({suite}): {monotonic()-started:.1f}s", flush=True)


def module_administration(base, member, ordinary, pending, fixture, target, web, include_downloads):
    started = monotonic()

    def gallery_checks(admin, historical, applicant):
        gallery.browse(base, admin, historical, applicant)
        php(ROOT / "tests/phpbb3/gallery_cleanup.php", target, cwd=web)

    # Independent clients can wait for real form tokens concurrently.
    with ThreadPoolExecutor(max_workers=2) as workers:
        people = (member, ordinary, pending)
        gallery_run = workers.submit(gallery_checks, *(actor.separate_client() for actor in people))
        if include_downloads:
            download_run = workers.submit(download_checks, base, *(actor.separate_client() for actor in people),
                                          fixture, target, web, gallery_run)
        knowledgebase.administration(member)
        knowledgebase.type_administration(member)
        knowledgebase.category_administration(member)
        php(fixture, "kb-category-cleanup", target)
        knowledgebase.settings_administration(member, pending)
        gallery_run.result()
        if include_downloads:
            download_run.result()
    if include_downloads:
        media_administration(base, member, ordinary, pending, target, web)
    print(f"Module administration and gallery workflows: {monotonic()-started:.1f}s", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cache", type=Path, required=True)
    parser.add_argument("--offline", action="store_true")
    parser.add_argument("--suite", choices=("all", "forum", "media", "downloads", "arcade", "management"), default="all",
                        help="Run the full converted board or one of the independent CI suites")
    args = parser.parse_args()
    media_only = args.suite in ("media", "downloads")
    forum_suite = args.suite in ("forum", "all")
    account_suite = args.suite in ("management", "all")
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
            if forum_suite:
                # This also exercises conversion after the stock formatter has been cached.
                php(ROOT / "tests/phpbb3/explicit_acronym.php", target, "fresh", cwd=web)
            if account_suite:
                php(ROOT / "tests/phpbb3/attachment_upload_types.php", target, "native", cwd=web, capture=True)
            php("bin/phpbbcli.php", "--version", cwd=web)
            fixture = ROOT / "tests/phpbb3/legacy_database.php"
            php(fixture, "installed-flags", target)
            os.environ["PLUS_TEST_MODERN_PASSWORD"] = secrets.token_hex(7) + '"&Ü\\'
            os.environ["PLUS_TEST_HISTORIC_PASSWORD"] = secrets.token_hex(7) + "'&ä\\"
            # The independent media installation covers boards that disabled pruning globally.
            php(fixture, "seed-pruning-off" if media_only else "seed", source)
            if media_only:
                php(ROOT / "tests/phpbb3/quick_reply.php", source, "source-off")
            statistics_fixture = ROOT / "tests/phpbb3/board_statistics.php"
            php(statistics_fixture, "seed", source)
            if forum_suite:
                php(ROOT / "tests/phpbb3/topic_readers.php", source, "source")
                php(ROOT / "tests/phpbb3/recommend.php", source, "source")
                php(ROOT / "tests/phpbb3/smilie_signs.php", source, "source")
                php(ROOT / "tests/phpbb3/explicit_acronym.php", source, "source")
            if forum_suite or account_suite:
                php(ROOT / "tests/phpbb3/acronym_dictionary.php", source, "source")
            if account_suite:
                php(ROOT / "tests/phpbb3/birthday_greeting.php", source, "source")
                php(ROOT / "tests/phpbb3/inline_editor.php", source, "source")
                php(ROOT / "tests/phpbb3/absence.php", source, "source")
            php(ROOT / "tests/phpbb3/weblinks.php", source, "source")
            php(ROOT / "tests/phpbb3/weblink_logos.php", source, "source")
            source_files(ROOT / "phpBB2", root / "legacy")
            board_statistics.source_modules(ROOT / "phpBB2", root / "legacy")
            delegation.source_modules(ROOT / "phpBB2", root / "legacy")
            attachments.source_media(root / "legacy")
            news.source_media(root / "legacy")
            gallery.source_media(root / "legacy")
            arcade.source_media(root / "legacy")
            downloads.source_media(root / "legacy")
            profile_media.source_media(root / "legacy")
            weblinks.source_media(ROOT / "phpBB2", root / "legacy")
            seeded = monotonic()
            print(f"Installation and source fixtures: {seeded-built:.1f}s", flush=True)
            with server(web, root) as base:
                convert(base, source)
                if forum_suite:
                    php(fixture, "original-topology", source)
                (web / "install").rename(root / "finished-installer")
                if account_suite:
                    php(ROOT / "tests/phpbb3/absence.php", target, "converted", cwd=web)
                    php(ROOT / "tests/phpbb3/birthday_greeting.php", target, "converted", cwd=web)
                if args.suite in ("arcade", "management"):
                    php(ROOT / "tests/phpbb3/delegation_acl.php", target, cwd=web)
                    admin, _ = delegation.authenticate(base, "ModernMember")
                    moderator = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
                    player = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
                    arcade_management(base, admin, moderator, player, target, web)
                    if account_suite:
                        account_management(base, admin, moderator, player, target, web)
                    extension_upgrades(target, web, args.suite)
                    print(f"Verified {count} upstream files and converted {args.suite} workflows in {monotonic()-start:.1f}s")
                    return
                php(fixture, "converted-pruning-off" if media_only else "converted", target)
                if media_only:
                    php(ROOT / "tests/phpbb3/quick_reply.php", target, "converted-off", cwd=web)
                if not media_only:
                    recovery = ("bin/phpbbcli.php", "phpbb2plus:recovery:posts")
                    ids = php(*recovery, cwd=web, capture=True).splitlines()
                    record = json.loads(php(*recovery, "143001", cwd=web, capture=True))
                    if ids != ["143001", "143002", "143003"] or record.get("post_subject") != "Unzugeordnet 📷":
                        raise RuntimeError("The recovery command did not list and retrieve the imported post text")
                    if [file["source_id"] for file in record["attachments"]] != [50] or record["attachments"][0]["attach_id"] == 50:
                        raise RuntimeError("The recovery command did not identify the detached copy of the shared file")
                    verify_poll_recovery(web)
                    php(ROOT / "tests/phpbb3/poll_lifecycle.php", target, cwd=web)
                php(ROOT / "tests/phpbb3/download_reports.php", target, "converted", cwd=web)
                php(ROOT / "tests/phpbb3/download_search.php", target, "converted", cwd=web)
                php(ROOT / "tests/phpbb3/download_mail.php", target, "converted", cwd=web)
                php(ROOT / "tests/phpbb3/download_checker.php", target, "converted", cwd=web)
                php(statistics_fixture, "converted", target)
                if not media_only:
                    php(ROOT / "tests/phpbb3/topic_readers.php", target, "converted", cwd=web)
                    php(ROOT / "tests/phpbb3/recommend.php", target, "converted", cwd=web)
                attachments.verify_storage(web, php(fixture, "attachments", target, capture=True), with_recovery=not media_only)
                converted = monotonic()
                print(f"Conversion and database verification: {converted-seeded:.1f}s", flush=True)
                login(base, "ModernMember", "incorrect", expect_success=False)
                php(fixture, "hashes-unchanged", target)
                if media_only:
                    member, _ = delegation.authenticate(base, "ModernMember")
                else:
                    member = login(base, "ModernMember", os.environ["PLUS_TEST_MODERN_PASSWORD"])
                    ambiguous = member.fetch("viewtopic.php?t=701")
                    if "Duration fixture 701" not in ambiguous or "Doppelte Antwort" in ambiguous or 'class="topic_poll"' in ambiguous:
                        raise RuntimeError("A held ballot must leave its announcement readable without publishing the question")
                ordinary = login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"], portal=True)
                if not media_only:
                    forum_recovery.browse(base, member)
                    topic_readers.browse(base, member, ordinary, lambda phase: php(
                        ROOT / "tests/phpbb3/topic_readers.php", target, phase, cwd=web))
                    locked_polls.exercise(base, ordinary, lambda phase: php(ROOT / "tests/phpbb3/locked_polls.php", target, phase, cwd=web))
                pending = login(base, "DepartingFixture", os.environ["PLUS_TEST_MODERN_PASSWORD"])
                member_notice.imported(base, ordinary, member, media_only)
                if forum_suite:
                    explicit_acronym.browse(member, ordinary, lambda phase: php(
                        ROOT / "tests/phpbb3/explicit_acronym.php", target, phase, cwd=web, capture=True))
                    smilie_signs.browse(member, ordinary, lambda phase: php(
                        ROOT / "tests/phpbb3/smilie_signs.php", target, phase, cwd=web, capture=True))
                    quick_reply.browse(member, ordinary, Browser(base), lambda phase: php(
                        ROOT / "tests/phpbb3/quick_reply.php", target, phase, cwd=web, capture=True))
                php(fixture, "signed-in", target)
                def login_fixture(phase):
                    php(ROOT / "tests/phpbb3/login_networks.php", target, phase, cwd=web)
                def file_roles(phase):
                    php(ROOT / "tests/phpbb3/delegation_files.php", target, phase, cwd=web)
                if media_only:
                    account_logins.browse(base, ordinary, member, login_fixture)
                    member_notice.manage(base, ordinary, member, lambda phase: php(
                        ROOT / "tests/phpbb3/member_notice_member.php", target, phase, cwd=web))
                    password_renewal.exercise(base, member, lambda phase: php(
                        ROOT / "tests/phpbb3/password_renewal.php", target, phase, cwd=web), lambda phase: php(fixture, phase, target))
                    delegation.module_roles(base, file_roles)
                    download_checks(base, member, ordinary, pending, fixture, target, web)
                    php(ROOT / "tests/phpbb3/guest_profiles.php", target, cwd=web)
                    media_administration(base, member, ordinary, pending, target, web)
                    php(fixture, "download-voter", target)
                    php(ROOT / "tests/phpbb3/retain_user.php", target, cwd=web)
                    php(fixture, "download-departed", target)
                    gallery.departed(base, ordinary)
                    extension_upgrades(target, web, args.suite)
                    print(f"Verified {count} upstream files, conversion, downloads and album management in {monotonic()-start:.1f}s")
                    return
                account_logins.disabled(base, ordinary)
                login_fixture("disabled")
                board_statistics.browse(base, member, ordinary, lambda phase: php(statistics_fixture, phase, target, cwd=web))
                print_topics.browse(base, member, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/print_topics.php", phase, target, cwd=web))
                forum_permissions.publishing(member, ordinary, pending)
                php(ROOT / "tests/phpbb3/forum_access.php", target, "membership", cwd=web)
                forum_permissions.reading(base, ordinary, pending)
                announcement_duration.exercise(member, lambda step: php(ROOT / "tests/phpbb3/announcement_duration.php", target, step, cwd=web))
                public_pages(base, member)
                legend = member.fetch("index.php")
                if ('#5A769B">Redaktion &amp; Gäste</span>' not in legend or 'Hidden palette' in legend
                        or 'plus-colour-legend">Invalid colour</span>' not in legend):
                    raise RuntimeError("The index lost a custom colour, double-escaped its name or revealed a hidden legend entry")
                php(ROOT / "tests/phpbb3/colour_membership.php", target, cwd=web)
                portal.browse(base, member)
                recent_topics.browse(base, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/recent_topics.php", target, phase, cwd=web))
                portal.media(base, member, ordinary, pending)
                banners.browse(base, member, ordinary)
                php(fixture, "banner-clicks", target)
                php(ROOT / "tests/phpbb3/attachment_quotas.php", target, "before", cwd=web)
                attachment_quotas.browse(ordinary)
                php(ROOT / "tests/phpbb3/attachment_quotas.php", target, "after", cwd=web)
                exercise_shoutbox(base, member, ordinary)
                php(ROOT / "tests/phpbb3/guest_profiles.php", target, cwd=web)
                profiles(base, ordinary, member)
                news.browse(base, member)
                custom_profiles.exercise(base, pending)
                media = json.loads(php(fixture, "profile-media", target, capture=True))
                profile_media.browse(ordinary, member, media)
                rank_overview.browse(base, member, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/rank_overview.php", target, phase, cwd=web))
                legacy_links.browse(base, member, media["member"])
                legacy_login.browse(web, base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"], member)
                moderation_bookmarks.browse(base, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/moderation_bookmarks.php", target, phase, cwd=web))
                legacy_links.groups(base, member, ordinary, pending, int(php(fixture, "group-bookmarks", target, capture=True)))
                credits.browse(base, ordinary)
                topic_calendar.browse(base, member, ordinary, pending)
                portal_calendar.exercise(base, member, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/portal_calendar.php", target, phase, cwd=web, capture=True))
                with ThreadPoolExecutor(max_workers=2) as worker:
                    credit_run = worker.submit(credits.administration, member.separate_client())
                    poll_run = worker.submit(poll_administration.browse, member.separate_client())
                    structure_run = worker.submit(forum_recovery.administration, member.separate_client())
                    news.administration(base, member)
                    credit_run.result()
                    poll_run.result()
                    structure_run.result()
                    # This test counts the exact number of index page views.
                    # Finish the other ACP logins before and start new ones afterwards.
                    banners.administration(base, member, web)
                    # The portal checks the five most recent sign-ins, including a hidden member.
                    portal.administration(base, member)
                    flag_run = worker.submit(country_flag_administration.exercise, base, member.separate_client(), lambda phase: php(
                        ROOT / "tests/phpbb3/country_flag_administration.php", target, phase, cwd=web, capture=True))
                    statistics_run = worker.submit(statistics_administration.exercise, base, lambda phase: php(
                        ROOT / "tests/phpbb3/statistics_administration.php", phase, target, cwd=web))
                    colours.administration(member, media["member"])
                    custom_profiles.administration(member)
                    statistics_run.result()
                    warning_cards.exercise(member, media["member"], os.environ["PLUS_TEST_MODERN_PASSWORD"], lambda step: php(fixture, step, target))
                    flag_run.result()
                def delegation_fixture(operation):
                    php(ROOT / "tests/phpbb3/delegation_management.php", target, operation, cwd=web)
                delegation_fixture("backfill")
                if args.suite == "all":
                    delegation.module_roles(base, file_roles)
                junior, junior_entry = delegation.browse(base)
                delegation_administration.manage(member, junior, junior_entry, delegation_fixture)
                for action in ("disable", "enable"):
                    php("bin/phpbbcli.php", "extension:" + action, "phpbb2plus/community", "--no-interaction", cwd=web)
                    php(ROOT / "tests/phpbb3/login_lifecycle.php", target, action, cwd=web)
                    if action == "disable":
                        delegation.suspended(junior, junior_entry)
                    else:
                        delegation.resumed(junior)
                        credits.colours_disabled(base)
                    php("bin/phpbbcli.php", "extension:" + action, "phpbb2plus/colours", "--no-interaction", cwd=web)
                    php("bin/phpbbcli.php", "cache:purge", "--no-interaction", cwd=web)
                    php(fixture, "colours-" + action, target)
                    php(ROOT / "tests/phpbb3/forum_access.php", target, action, cwd=web)
                    php(ROOT / "tests/phpbb3/profile_defaults.php", target, cwd=web)
                    custom_profiles.visibility_after_toggle(base, action == "enable")
                native_content(base, member, ordinary)
                admin_contact.browse(Browser(base), ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/admin_contact.php", target, phase, cwd=web, capture=True))
                recommend.browse(base, ordinary, lambda phase: php(ROOT / "tests/phpbb3/recommend.php", target, phase, cwd=web))
                if account_suite:
                    account_management(base, member, ordinary, pending, target, web)
                mail_links.browse(base, member, ordinary, media["member"], lambda phase: php(fixture, phase, target))
                weblinks.browse(base, member, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/weblinks.php", target, phase, cwd=web))
                weblink_logos.browse(base, member, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/weblink_logos.php", target, phase, cwd=web, capture=True), web)
                weblinks_admin.administration(base, lambda phase: php(
                    ROOT / "tests/phpbb3/weblinks_admin.php", target, phase, cwd=web))
                attachment_quotas.messages(member, ordinary, media["member"])
                php(ROOT / "tests/phpbb3/attachment_quotas.php", target, "messages", cwd=web)
                attachment_quotas.administration(member, lambda phase: php(ROOT / "tests/phpbb3/attachment_quotas.php", target, phase, cwd=web))
                attachments.downloads(ordinary)
                attachments.bookmarks(base, member, ordinary, pending, lambda phase: php(fixture, phase, target))
                arcade.awards(member, ordinary, pending)
                arcade_directory.browse(base, ordinary, lambda phase: php(
                    ROOT / "tests/phpbb3/arcade_directory.php", target, phase, cwd=web, capture=True))
                arcade_directory.social_bookmarks(base, ordinary, pending)
                arcade_categories.browse(base, member, ordinary, pending, lambda phase: php(
                    ROOT / "tests/phpbb3/arcade_categories.php", target, phase, cwd=web))
                if args.suite == "all":
                    arcade_management(base, member, ordinary, pending, target, web)
                arcade_standings.browse(base, member, ordinary, pending, lambda phase: php(
                    ROOT / "tests/phpbb3/arcade_standings.php", target, phase, cwd=web, capture=True))
                arcade_standings.artwork(base, ordinary, pending, lambda: php(
                    ROOT / "tests/phpbb3/arcade_standings.php", target, "recover-artwork", cwd=web),
                    lambda source=root / "legacy": php(web / "bin/phpbbcli.php", "phpbb2plus:arcade:import-artwork", source, cwd=web, capture=True))
                arcade.tournaments(base, member, ordinary, pending, lambda step: php(ROOT / "tests/phpbb3/tournaments.php", target, step, cwd=web))
                game_source = arcade.browse(base, member, ordinary, pending)
                php(fixture, "played", target)
                arcade.protocols(base, ordinary, game_source)
                arcade.wallets(base, ordinary, member)
                php(fixture, "scored", target)
                arcade.community(base, member, ordinary, pending)
                arcade.comments(base, member, ordinary, pending)
                arcade.tournament_play(base, member, ordinary, pending, lambda step: php(ROOT / "tests/phpbb3/tournaments.php", target, step, cwd=web))
                arcade_administration.exercise(member, ordinary, lambda: php(ROOT / "tests/phpbb3/tournaments.php", target, "proposal", cwd=web))
                creation = arcade_administration.control(member, ordinary, pending, lambda: php(ROOT / "tests/phpbb3/tournaments.php", target, "control", cwd=web))
                arcade_administration.closure(member, ordinary, pending, creation, lambda step: php(ROOT / "tests/phpbb3/tournament_closure.php", target, step, cwd=web))
                playback(web, base, root)
                php(ROOT / "tests/phpbb3/module_roles.php", target, cwd=web)
                php(ROOT / "tests/phpbb3/historical_actions_finish.php", target, cwd=web)
                historical_actions.browse(member)
                php(ROOT / "tests/phpbb3/delegation_acl.php", target, cwd=web)
                delegation.verify_report(php, web)
                # The gallery checks briefly change its display options. Keep them
                # away from other gallery requests, including the Chromium check.
                def gallery_views():
                    admin, reader, applicant = (actor.separate_client() for actor in (member, ordinary, pending))
                    guest = Browser(base)
                    gallery_progress.browse(admin, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_progress.php", target, phase, cwd=web, capture=True))
                    gallery_hotlink.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_hotlink.php", target, phase, cwd=web, capture=True))
                    gallery_navigation.browse(admin, reader, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_navigation.php", target, phase, cwd=web, capture=True))
                    gallery_previews.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_previews.php", target, phase, cwd=web, capture=True))
                    gallery_search.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_search.php", target, phase, cwd=web, capture=True))
                    gallery_hot.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_hot.php", target, phase, cwd=web, capture=True))
                    gallery_directory.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_directory.php", target, phase, cwd=web, capture=True))
                    gallery_category_index.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_category_index.php", target, phase, cwd=web, capture=True))
                    gallery_activity.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_activity.php", target, phase, cwd=web, capture=True))
                    gallery_moderation.browse(admin, reader, applicant, guest, lambda phase: php(
                        ROOT / "tests/phpbb3/gallery_moderation.php", target, phase, cwd=web, capture=True))

                with ThreadPoolExecutor(max_workers=1) as worker:
                    navigation = worker.submit(gallery_views)
                    knowledgebase.browse(base, member, ordinary, pending)
                    knowledgebase.reply_preferences(base, member, lambda phase: php(fixture, phase, target))
                    php(ROOT / "tests/phpbb3/knowledgebase_mail.php", target, cwd=web)
                    navigation.result()
                module_administration(base, member, ordinary, pending, fixture, target, web, args.suite == "all")
                former_names(base, ordinary)
                removed_posts.converted(base, ordinary, member)
                if args.suite == "all":
                    php(fixture, "download-voter", target)
                php(ROOT / "tests/phpbb3/retain_user.php", target, cwd=web)
                if args.suite == "all":
                    php(fixture, "download-departed", target)
                former_names(base, ordinary, removed=True, founder=member)
                if args.suite == "all":
                    gallery.departed(base, ordinary)
                post_icons.browse(member, ordinary, json.loads(php(fixture, "post-icons", target, capture=True)))
                php(ROOT / "tests/phpbb3/mysql_search.php", target, cwd=web)
                author_results(base, ordinary, removed=True, founder=member)
                login(base, "HistoricMember", os.environ["PLUS_TEST_HISTORIC_PASSWORD"])
                removal_fixture = ROOT / "tests/phpbb3/removed_posts.php"
                positions = json.loads(php(removal_fixture, target, cwd=web, capture=True))
                removed_posts.native(base, positions)
                php(removal_fixture, target, positions["posts"][4], cwd=web)
                removed_posts.restored(base, positions)
                delete_policy.exercise(ordinary, member, lambda phase: php(
                    ROOT / "tests/phpbb3/delete_policy.php", target, phase, cwd=web))
                confirmed_deletions.exercise(ordinary, member, lambda phase: php(
                    ROOT / "tests/phpbb3/confirmed_deletions.php", target, phase, cwd=web))
                removed_shadow_links.exercise(member, lambda phase: php(
                    ROOT / "tests/phpbb3/removed_shadow_links.php", target, phase, cwd=web))
                prune_admin, _ = delegation.authenticate(base, "ModernMember")
                age_pruning.browse(prune_admin, lambda phase: php(
                    ROOT / "tests/phpbb3/age_pruning.php", target, phase, cwd=web))
                extension_upgrades(target, web, args.suite)
                print(f"Application requests and native actions: {monotonic()-converted:.1f}s", flush=True)
    print(f"Verified {count} upstream files, installation, conversion and logins in {monotonic()-start:.1f}s")


if __name__ == "__main__":
    main()
