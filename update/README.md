# Database updates and maintenance

This directory is not part of normal forum operation. Back up the complete
database and web root, test on a clone, and remove the directory from the
public web root after use.

## Current preserved baseline

For an existing phpBB2 Plus 1.53a database, use the CLI-only consolidated
updater. It previews changes by default and requires an explicit backup
confirmation before applying them:

```text
php update/update_from_153a.php
php update/update_from_153a.php --apply --backup-confirmed
```

It installs the database additions represented by the source after the 1.53a
release: phpBB 2.0.23's version marker, CrackerTracker 5 tables and user
columns, Arcade Mod Plus 2.1.8, Nuffload 1.4.2, DB Maintenance 1.3.8, responsive
style metadata, modern social-profile fields, cookie consent and the disabled
StopForumSpam option. Existing configuration values are preserved.

The same definitions are part of the normal fresh-install schema, so a new
installation does not run this updater.

## UTF-8 and search maintenance

- `migrate_database_to_utf8mb4.php` previews or applies the guarded database
  conversion described in the project README.
- `rebuild_search_index.php` rebuilds the derived phpBB search tables after a
  conversion.

Both are CLI-only and require `--apply --backup-confirmed` before writing.

## Historical upgrade paths

These files are preserved for installations older than 1.53a. Run only the
path matching the actual source version:

- `update_phpbb_to_2022.php` — legacy phpBB database upgrade to 2.0.22;
- `update_attachment_221_to_243.php` — Attachment MOD 2.2.1+ to 2.4.3;
- `update_crackertracker_4x_to_417.php` — CrackerTracker 4.x to 4.1.7;
- `update_plus_152_to_153a.php` — phpBB2 Plus 1.52 to 1.53a;
- `update_plus_153_to_153a.php` — phpBB2 Plus 1.53 prereleases/final to 1.53a;
- `update_phpbb_20xx_to_plus_153a.php` — old standalone phpBB 2.0.x to Plus
  1.53a;
- `migrate_album_personal_galleries.php` — legacy Album Category Hierarchy
  personal-gallery migration.

The `fissh/` directory contains presentation assets required by these browser
based legacy scripts.

`uninstall_crackertracker_4x_legacy.php` is a destructive historical reference
using the removed `mysql_*` API. It is not compatible with current PHP and must
not be executed unchanged.
