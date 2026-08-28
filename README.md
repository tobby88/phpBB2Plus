# phpBB2 Plus

This repository is a historical archive of **phpBB2 Plus 1.53a**, a pre-modded
phpBB2 distribution that was originally based on phpBB 2.0.21. Its official
phpBB baseline and database version identity are 2.0.23. The preserved code is
patched beyond that release with ten post-release changes from the phpBB
2.0.23.x branch, applicable changes described by IntegraMOD as unofficial
2.0.24/2.0.25 patch levels, CrackerTracker Professional 5.0.6, and later local
compatibility fixes. These patch-level names do not represent official phpBB
releases or a wholesale replacement of the 2.0.23-based product, so the
database version deliberately remains `.0.23`.

The repository exists to preserve the software and its history. It does not
represent an active continuation of phpBB2 Plus, and no ongoing development,
maintenance, or support is implied.

phpBB2 Plus combines phpBB2 with a large collection of extensions and a shared
administration interface. Major bundled features include:

- portal and forum-index layouts;
- category hierarchy, announcements, calendars, recent topics, and statistics;
- attachments, a photo album, a download database, and a knowledge base;
- a shoutbox, custom profile fields, user groups, and additional moderation
  tools;
- CrackerTracker security and logging features;
- seven bundled styles, including FI Subsilver Shadow and six responsive
  styles preserved from IntegraMOD;
- English and German language directories.

This list is intentionally not a version inventory. The source tree and
[CHANGELOG.md](CHANGELOG.md) are the authoritative records of the integrated
code and the changes preserved after the 1.53a baseline.

## Project status

This is legacy software. The original phpBB2 and phpBB2 Plus projects are no
longer supported, and this archive does not provide maintenance or security
support. The included compatibility fixes reduce some runtime failures but do
not make the complete application equivalent to a current forum platform or
safe by default for an untrusted public deployment.

Use backups, test changes in an isolated environment, and review all migration
scripts before running them against an existing forum.

## Repository layout

- `phpBB2/` contains the deployable forum application.
- `phpBB2/README.md` summarizes the application tree and its supported runtime
  scope for GitHub visitors browsing that directory.
- `update/` contains clearly named legacy upgrade paths plus the consolidated
  post-1.53a database updater and UTF-8/search maintenance scripts. They are
  not needed during normal operation and must not remain in a public web root.
- `set-permissions.sh` applies the writable Unix permissions required by the
  forum.
- `folder+file-permissions.txt` documents the same writable paths and the
  shared-hosting fallback modes.
- `CHANGELOG.md` summarizes the preserved changes after 1.53a and includes the
  original phpBB2 Plus changelog.

## Fresh installation

The original installation flow is retained for archival and maintenance use:

1. Copy the contents of `phpBB2/` to the intended web root.
2. Create an empty database and open `install/install.php` in a browser.
3. Complete the installer with the database and administrator details.
4. Remove or rename the `install/` directory immediately after installation.
5. Apply the required writable permissions as described below.

The installer is legacy code. Perform a fresh installation only in a test
environment until the exact PHP and database combination has been verified.

The fresh-install schema already contains the restored Arcade, Nuffload and DB
Maintenance database structures as well as the IntegraMOD-derived responsive
style, social-profile, cookie-consent and optional StopForumSpam fields. No
separate SQL imports are required for a fresh installation.

The standalone DB Maintenance Emergency Recovery Console at `admin/erc.php`
is disabled by default because it can make extensive database changes. To use
it, temporarily add `define('DBMTNC_ENABLE_ERC', true);` to `config.php`, open
the console only for the required recovery operation, and remove the setting
immediately afterwards.

## Upgrading an existing forum

Before replacing files or running anything from `update/`:

1. Back up the complete database and the complete existing web root.
2. Preserve user-generated data, especially album uploads and thumbnails,
   attachments, avatars, smilies, ranks, screenshots, and download uploads.
3. Confirm which source version each legacy migration script expects. Do not
   run every legacy path indiscriminately.
4. Test the full upgrade and login/posting/upload workflows on a copy first.
5. Remove migration and installation scripts from the public web root when the
   upgrade is complete.

The old upgrade paths now state both their source and target in their names.
Use only the path matching the installed database, for example
`update_plus_152_to_153a.php`, `update_plus_153_to_153a.php`, or
`update_phpbb_20xx_to_plus_153a.php`. The separately named phpBB,
Attachment MOD and CrackerTracker updaters retain their historical scope.

After the database has reached the original 1.53a baseline, preview every
post-release schema addition from the repository root:

```text
php update/update_from_153a.php
```

Apply it only to a tested copy after verifying current backups:

```text
php update/update_from_153a.php --apply --backup-confirmed
```

The updater is idempotent and preserves existing current configuration values.
As required by the original CrackerTracker 4.x-to-5.x instructions, it removes
the incompatible 4.x tables and user columns after preparing the 5.x schema.
The old CrackerTracker settings and logs cannot be migrated and are discarded;
the mandatory backup confirmation therefore also covers this cleanup.

## Integrated and excluded MOD packages

Admin Userlist 2.1 (including Color Groups compatibility), Log Actions MOD
1.1.6 with Enhanced Log Actions, and Registration IP 1.1.2 are integrated in
the application and in both fresh-install and post-1.53a database paths. Their
historical source-package copies are therefore not duplicated in the
repository.

Registration IP stores an IP address as account metadata. Operators must
document and retain that data according to the privacy rules that apply to
their deployment.

Digests, Registration Spam, and Rules & Policies are intentionally not part of
the application. The duplicate IM Portal package is also excluded: phpBB2 Plus
already contains the authoritative Smartor ezPortal implementation. Responsive
portal templates in the style directories extend that existing portal and are
not an installation of IM Portal.

## Encoding and database support

Distributed text sources, templates, English/German language files and mail
templates are UTF-8. Fresh MySQL/MariaDB tables and the MySQLi connection use
the matching `utf8mb4` character set.

For an existing installation, put the forum into maintenance mode and create
verified file and database backups. Test the complete procedure on a database
clone first. From the repository root, inspect the conversion plan with:

```text
php update/migrate_database_to_utf8mb4.php
```

An isolated database on the same server can be selected with
`--database=clone_name`. Apply only after reviewing the selected database and
the complete dry-run output:

```text
php update/migrate_database_to_utf8mb4.php --apply --backup-confirmed
php update/rebuild_search_index.php --apply --backup-confirmed
```

The migration may shorten indexed configuration-name columns after first
checking that no value would be truncated. It clears derived search tables
before changing collations because formerly distinct words may collide under
a Unicode collation. `ALTER TABLE` and `TRUNCATE TABLE` auto-commit; restore
the verified backup if a conversion stops part-way through. Finally verify
login, posting, private messages, search, administration and album uploads.
Older databases that already contain UTF-8 bytes in columns labelled as
Latin-1 need individual inspection—an unchecked conversion can create
mojibake.

MySQLi is the supported modern database driver. Existing `config.php` files
which still name `mysql` or `mysql4` automatically use MySQLi, so they do not
call the removed PHP `mysql_*` extension on PHP 7 or 8. Fresh installations
offer only MySQL/MariaDB through MySQLi; obsolete and unsupported alternative
database-driver sources are no longer distributed.

## Writable permissions

Git cannot preserve arbitrary directory permissions. On Unix-like hosting,
preview the required changes from the repository root with:

```sh
./set-permissions.sh --dry-run /path/to/phpbb/webroot
```

Then apply them with:

```sh
./set-permissions.sh /path/to/phpbb/webroot
```

The defaults are `0775` for writable directories and `0664` for writable
files. See `folder+file-permissions.txt` before using the historical
world-writable fallback modes.

## Credits and licensing

phpBB2 is copyright the phpBB Group and is distributed under the GNU General
Public License version 2; see [COPYING](COPYING).

phpBB2 Plus incorporates many third-party MODs and assets. Their original
copyright notices, author credits, and license statements remain in the source
files. Some bundled components may carry terms different from the phpBB2 base;
the presence of the GPL text must not be interpreted as relicensing every
third-party file in this historical distribution.
