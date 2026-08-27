# phpBB2 Plus

This repository preserves and maintains **phpBB2 Plus 1.53a**, a pre-modded
phpBB2 distribution that was originally based on phpBB 2.0.21. The current
`main` branch includes the official phpBB 2.0.22 and 2.0.23 changes,
CrackerTracker Professional 5.0.6, and additional compatibility fixes from the
maintained phpBB 2.0.23.x branch.

phpBB2 Plus combines phpBB2 with a large collection of extensions and a shared
administration interface. Major bundled features include:

- portal and forum-index layouts;
- category hierarchy, announcements, calendars, recent topics, and statistics;
- attachments, a photo album, a download database, and a knowledge base;
- a shoutbox, custom profile fields, user groups, and additional moderation
  tools;
- CrackerTracker security and logging features;
- the FI Subsilver Shadow template and English, German, and Spanish language
  directories.

This list is intentionally not a version inventory. The source tree and
[CHANGELOG.md](CHANGELOG.md) are the authoritative records of the integrated
code and later maintenance work.

## Project status

This is legacy software. The original phpBB2 and phpBB2 Plus projects are no
longer supported, and this repository must not be assumed to provide the
security guarantees of a current forum platform. Modern-PHP compatibility work
reduces runtime failures but does not by itself make the complete application
safe for an untrusted public deployment.

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
- `CHANGELOG.md` contains the current maintenance summary followed by the
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
