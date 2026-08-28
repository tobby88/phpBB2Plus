# phpBB2 Plus

This repository is a historical archive of **phpBB2 Plus 1.53a**, a pre-modded
phpBB2 distribution that was originally based on phpBB 2.0.21. It preserves a
later consolidated state that includes the official phpBB 2.0.22 and 2.0.23
changes, CrackerTracker Professional 5.0.6, and additional compatibility fixes
from the phpBB 2.0.23.x branch.

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
- `update/` contains historical database migration tools for specific old
  installations. These scripts can make destructive changes and are not needed
  during normal operation.
- `set-permissions.sh` applies the writable Unix permissions required by the
  forum.
- `folder+file-permissions.txt` documents the same writable paths and the
  shared-hosting fallback modes.
- `CHANGELOG.md` summarizes the preserved changes after 1.53a and includes the
  original phpBB2 Plus changelog.
- `docs/upstream/` records the audited IntegraMOD history merge and the
  semantic port from a production compatibility branch.

## Fresh installation

The original installation flow is retained for archival and maintenance use:

1. Copy the contents of `phpBB2/` to the intended web root.
2. Create an empty database and open `install/install.php` in a browser.
3. Complete the installer with the database and administrator details.
4. Remove or rename the `install/` directory immediately after installation.
5. Apply the required writable permissions as described below.

The installer is legacy code. Perform a fresh installation only in a test
environment until the exact PHP and database combination has been verified.

Modules restored after the original package baseline have separate database
installation scripts under `update/`. Run only the scripts for the restored
modules that are actually present in the deployed source tree:

- `install_arcade_218.sql` for Arcade Mod Plus;
- `install_nuffload_142.sql` for the Nuffload album uploader;
- `install_db_maintenance_138.sql` for DB Maintenance Mod.

The IntegraMOD-derived additions have one-time scripts for existing databases:

- `install_integramod_styles.sql` adds responsive-style metadata columns;
- `install_integramod_social_profiles.sql` adds the modern social-profile
  fields while retaining the legacy contact fields;
- `install_integramod_privacy_antispam.sql` adds cookie-consent and optional
  StopForumSpam configuration.

These additions are already present in the fresh-install schema. Do not run
their one-time scripts on a fresh installation or more than once.

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
3. Confirm which source version each migration script expects. Do not run all
   scripts indiscriminately.
4. Test the full upgrade and login/posting/upload workflows on a copy first.
5. Remove migration and installation scripts from the public web root when the
   upgrade is complete.

`update/db_uninstall_4x.php` is a destructive legacy CrackerTracker removal
reference. It uses the removed `mysql_*` API and must not be deployed or
executed unchanged.

## Encoding and database support

Distributed text sources, templates, English/German language files and mail
templates are UTF-8. Fresh MySQL/MariaDB tables and the MySQLi connection use
the matching character set. For an existing installation,
`tools/migrate-database-utf8mb4.php` provides a guarded and explicitly
confirmed migration, with `tools/rebuild-search-index.php` available when the
derived search tables must be rebuilt. See `docs/UTF8_MIGRATION.md` first. The
safe path depends on the real column encodings and on whether older data
already contains UTF-8 bytes in mislabelled columns; an unchecked conversion
can create mojibake.

MySQLi is the supported modern database driver. Existing `config.php` files
which still name `mysql` or `mysql4` automatically use MySQLi when available,
so they do not call the removed PHP `mysql_*` extension on PHP 7 or 8. The
experimental PDO source imported from IntegraMOD is preserved for provenance
but was never offered by the upstream installer and is not advertised as a
supported database path here.

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
Public License version 2; see [phpBB2/docs/COPYING](phpBB2/docs/COPYING).

phpBB2 Plus incorporates many third-party MODs and assets. Their original
copyright notices, author credits, and license statements remain in the source
files. Some bundled components may carry terms different from the phpBB2 base;
the presence of the GPL text must not be interpreted as relicensing every
third-party file in this historical distribution.
