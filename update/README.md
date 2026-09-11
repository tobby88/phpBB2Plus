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
php update/update_from_153a.php --apply --backup-confirmed --maintenance-confirmed
```

It installs the database additions represented by the source after the 1.53a
release: phpBB 2.0.23's version marker, CrackerTracker 5 tables and user
columns, Arcade Mod Plus 2.1.8, Nuffload 1.4.2, DB Maintenance 1.3.8, modern
social-profile fields, cookie consent and the disabled StopForumSpam option.
It also normalizes all theme and member-style records to FI Subsilver Shadow.
Existing unrelated configuration values are preserved.

Search-index rebuilding in the ACP now saves an atomic `dbmtnc_rebuild_job`
checkpoint in the configuration table. The updater adds an empty default only
when absent; it never overwrites an active job. The ACP also initializes this
value when starting on an older installation. Keep following the continuation
link through final cleanup, not just the last post. After interruption use
**Continue rebuilding search index**; source posts are not deleted. The original
board-disable setting is restored only after successful completion. A rebuild
resumed from the legacy cursor cannot infer whether the board was previously
enabled, so it preserves the existing disabled state. Old PHP 3/4 tuning values
are no longer used; batch size and checkpoint integrity are managed internally.

The updater also creates and initializes `user_id_sequence`, the durable
counter required by all current registration paths. Its initial floor includes
existing accounts and retained numeric ownership in bundled modules, not just
the highest surviving account. Repeated updates never lower the counter and
do not rewrite accounts or content. Run the update before reopening registration
with the new code; missing sequence metadata stops account creation safely.
Include this table in database backups and restores. Resetting it or restoring
only older account tables can defeat the historical non-reuse guarantee.

It also reconciles the public components-and-credits table with the verified
post-1.53a integrations, including Arcade Rewards API, social-profile fields
and the bundled Ruffle runtime, while retaining the
historical phpBB2 Plus credits. Existing credit IDs, author email addresses,
download links and file metadata are preserved when a known entry is updated.
Incompatible CrackerTracker 4.x tables and user columns are removed when
present, as required by the original 4.x-to-5.x upgrade instructions; their
settings and logs cannot be migrated.

The same definitions are part of the normal fresh-install schema, so a new
installation does not run this updater.

The existing `user_removals` and `user_removal_items` journal tables also cover
standalone account pruning. Its saved criteria and temporary notification data
use typed journal items; no additional columns or separate migration are needed.
Do not drop pending journal records to recover a failed cleanup. Use its resume
controls in the original ACP module instead.

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

These files describe installations older than 1.53a, but are **not executable in
this package**. They stop before bootstrap or database access, even if the former
legacy-updater opt-in is set. Their original SQL is retained as historical
reference, not silently rewritten to claim a modern migration. For an older
installation, prepare an isolated 1.53a copy using the matching historical release,
verify it, and then apply the current UTF-8 and post-1.53a migration path.

- `update_phpbb_to_2022.php` — legacy phpBB database upgrade to 2.0.22;
- `update_attachment_221_to_243.php` — Attachment MOD 2.2.1+ to 2.4.3;
- `update_plus_152_to_153a.php` — phpBB2 Plus 1.52 to 1.53a;
- `update_plus_153_to_153a.php` — phpBB2 Plus 1.53 prereleases/final to 1.53a;
- `update_phpbb_20xx_to_plus_153a.php` — old standalone phpBB 2.0.x to Plus
  1.53a;
- `migrate_album_personal_galleries.php` — legacy Album Category Hierarchy
  personal-gallery migration.

The duplicate `phpBB2/install/update_to_latest.php` entrypoint and the phpBB 1
conversion (`phpBB2/install/upgrade.php`, including installer POST requests) are
blocked too. Fresh installation remains available.
The `fissh/` directory retains the presentation assets of the historical scripts.

## Offline database restore

The ACP no longer executes uploaded SQL, including previously generated dumps.
This avoids reintroducing MyISAM/MEMORY, old character sets or arbitrary session
settings and prevents partial imports while the forum is accepting writes.
Backup downloads and CrackerTracker's separate configuration recovery remain.

1. Verify a complete current backup and first test the intended restore in an
   isolated database. Restore a coherent snapshot including the ID sequence,
   pending journals and their associated user files; do not mix table versions.
2. Block **all** web requests and scheduled/external writers. Board-disable alone
   is insufficient. Import the trusted dump using the hosting tools or database
   CLI; do not remove the maintenance gate after a partial or failed import.
3. For a legacy character set, preview and apply
   `migrate_database_to_utf8mb4.php` as described in the project README. It is a
   character-set migration, not a repair for previously corrupted/mojibake text.
4. Preview `update_from_153a.php`, then apply with `--apply --backup-confirmed
   --maintenance-confirmed`. Use `--storage-only` only when the restored schema
   already matches the current source. This also converts older InnoDB row
   formats to DYNAMIC; it does not change character encodings.
5. Verify the expected tables/columns, InnoDB/DYNAMIC, utf8mb4, content and file
   consistency. Rebuild the derived search index when required by a character-set
   migration. Reopen the forum only after successful completion.

No application can prevent a database administrator or external SQL import from
overriding its schema. These offline checks are required after those operations.
