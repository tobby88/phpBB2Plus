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
StopForumSpam option. Existing current configuration values are preserved.
It also reconciles the public components-and-credits table with the verified
post-1.53a integrations, including Arcade Rewards API, the responsive styles,
social-profile fields and the bundled Ruffle runtime, while retaining the
historical phpBB2 Plus credits. Existing credit IDs, author email addresses,
download links and file metadata are preserved when a known entry is updated.
Incompatible CrackerTracker 4.x tables and user columns are removed when
present, as required by the original 4.x-to-5.x upgrade instructions; their
settings and logs cannot be migrated.

The same definitions are part of the normal fresh-install schema, so a new
installation does not run this updater.

The source update also replaces legacy executable configuration/cache files
with non-executable data. Run `set-permissions.sh` after deploying the files;
it makes the protected `phpBB2/data` directory writable. Existing post-icon
settings are imported automatically from `includes/def_icons.php` on first
use and subsequently stored in `data/icons.dat`. No database operation is
needed for that migration.

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
- `update_plus_152_to_153a.php` — phpBB2 Plus 1.52 to 1.53a;
- `update_plus_153_to_153a.php` — phpBB2 Plus 1.53 prereleases/final to 1.53a;
- `update_phpbb_20xx_to_plus_153a.php` — old standalone phpBB 2.0.x to Plus
  1.53a;
- `migrate_album_personal_galleries.php` — legacy Album Category Hierarchy
  personal-gallery migration.

The `fissh/` directory contains presentation assets required by these browser
based legacy scripts.
