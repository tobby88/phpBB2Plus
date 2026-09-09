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
- the classic FI Subsilver Shadow style used by phpBB2 Plus;
- English and German language directories.

The public `hacks_list.php` page is seeded with the historical phpBB2 Plus
component credits and the later integrations whose identity can be verified
from the preserved source. The source tree and [CHANGELOG.md](CHANGELOG.md)
remain the authoritative records of code-level maintenance changes, which are
not presented as separate MODs. The self-hosted Ruffle runtime used by the
preserved Arcade is documented in
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Project status

This is legacy software. The original phpBB2 and phpBB2 Plus projects are no
longer supported, and this archive does not provide maintenance or security
support. The included compatibility fixes reduce some runtime failures but do
not make the complete application equivalent to a current forum platform or
safe by default for an untrusted public deployment.

Use backups, test changes in an isolated environment, and review all migration
scripts before running them against an existing forum.

Current welcome emails do not contain passwords. Account activation links remain
part of the corresponding mails. The ACP account-creation form requires an
explicit password and matching confirmation; no shared default password is
assigned. These changes do not alter existing account credentials or retract
passwords from emails already sent by older versions.

The ACP reference account supplies profile defaults only. New accounts receive
their own personal group, but do not inherit the reference account's shared
memberships or permissions (including pending membership requests). Assign
required shared groups separately through the authorized group-management
workflow. Existing accounts and memberships are not changed automatically.

New registrations and quick-added accounts are published only after their
personal group and membership exist. Registration IP, password-change time and
custom profile fields are included in the final account insert; ACP core and
custom profile changes are saved together. This prevents the tested database
failure paths from exposing usable, incomplete accounts, including on MyISAM.
Creation and the maintenance module's user/group repair use the same dedicated
writer connection and lock, so repair cannot remove in-flight personal groups.
It is not a transactional rollback of every ancillary operation: interrupted
creation can still leave an inactive ACP placeholder or unused personal-group
records for a permanently reserved ID. Existing records are not automatically
deleted or activated; no additional schema migration is needed for this change.

Database Maintenance recreates missing personal groups, but does not guess how
to merge multiple or shared personal groups. It reports the affected user IDs
and preserves their group IDs, memberships and permission references for review.
Empty groups are also reported without deletion: they can still own forum
permissions, quotas or plugin policies. Back up first and resolve ambiguous
ownership explicitly; a maintenance report does not mean every inconsistency
has been automatically repaired.

Moderator synchronization only repairs ordinary USER/MOD flags from approved
memberships with an existing group and forum. It shares the coordinated writer
lock and rechecks current roles, permissions and the acting administrator before
writes; administrator and special roles are excluded. Changed accounts lose
cached sessions. Concurrently changed candidates are skipped and reported.
Failures can leave earlier repairs or session expiry in place, especially on
MyISAM: this is not an all-or-nothing transaction. No migration or automatic
synchronization runs during deployment.

Post synchronization uses the same writer lock and computes topic/forum counters
inside guarded writes, rather than publishing an earlier snapshot. Moved-topic
links retain their historical post cutoff. Empty topics, invalid redirects and
concurrently changed targets are reported for review without deleting content.
Empty forums and forums containing only redirects get zero own-post counters.
Signed continuations retain their maintenance-state behavior, and current ACP
authorization is checked throughout. A failed run can leave earlier counter
repairs in place; back up first and retry after resolving the error. Deployment
does not run this repair, and no new schema migration is required.

The auto-increment maintenance action repairs a missing attribute on an ordinary
integer primary key; it does not reset healthy counters or replace column types.
Explicit defaults, special attributes and ambiguous keys are left for review.
Back up first: DDL may rebuild a table and is not transactionally rolled back.
The repair restates supported existing attributes as required by
[MySQL's MODIFY rules](https://dev.mysql.com/doc/refman/8.4/en/alter-table.html)
and protects existing zero IDs using
[MariaDB's documented NO_AUTO_VALUE_ON_ZERO behavior](https://mariadb.com/docs/server/reference/data-types/auto_increment).

The optional table-optimization action displays every server message, including
the final status after an InnoDB rebuild note (see
[MySQL's OPTIMIZE output](https://dev.mysql.com/doc/refman/8.4/en/optimize-table.html)).
Warnings, failures and missing success statuses are reported explicitly. Size
comparisons are server statistics, not proof of success or reclaimed disk space;
growth remains visible and a zero starting size has no percentage. This action
is not run by deployment or migration. Use a backed-up maintenance window: table
optimization can rebuild and lock tables.

CHECK and REPAIR use the same complete diagnostic handling in the ACP and
Emergency Recovery Console. A failed status is never presented as OK, and the
console only confirms success if every table has a final successful status
without warnings or errors. Unsupported engines remain explicitly unresolved;
there is no automatic engine conversion or alternative repair attempt. Follow
the server's [CHECK TABLE](https://dev.mysql.com/doc/refman/8.4/en/check-table.html)
and [REPAIR TABLE](https://dev.mysql.com/doc/refman/8.4/en/repair-table.html)
instructions and back up before repairs; the report is not a recovery guarantee.

SQL diagnostics in the main error page, repeated errors, database maintenance
and emergency recovery show only a numeric error code, a recognized statement
type and an escaped source basename/line. Driver messages and full SQL values
are deliberately omitted: they can repeat passwords, tokens or private content.
DEBUG remains off by default; main/ACP details additionally require an authenticated
administrator in the ACP. ERC remains behind its explicit recovery access gates.
Trusted localized help links remain available. No sensitive diagnostic copy is
written to a new log by this renderer.

ACP password creation and changes preserve special characters and whitespace
as entered, matching login. Existing password hashes are not rewritten. If an
older ACP version saved a transformed password, use the regular password-reset
or administrator workflow to set it again; no alternate decoded login is added.

New passwords are limited to **72 UTF-8 bytes**, not 72 characters, because the
portable bcrypt implementation ignores bytes beyond that limit. The forms show
this limit and reject longer submissions instead of truncating them. Accented
characters and emoji can occupy multiple bytes. Configured minimum-length and
complexity rules now also apply to both ACP forms; historical minimum settings
above 72 are bounded to 72 at runtime and in the ACP display. The installer uses
the fresh-install policy. No database migration or bulk password rewrite is
needed. This follows the [OWASP bcrypt input guidance](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html#input-limits-of-bcrypt).

Existing long passwords remain verifiable within the previous 128-byte login
bound so their owners can authenticate and change them; they are not silently
rehash-truncated. The existing policy check requests a replacement after login.
Old bcrypt hashes cannot reveal or restore previously ignored suffixes. Users
who previously chose a password longer than 72 bytes should set a new one;
retaining legacy verification does not repair that historical ambiguity.

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
Maintenance database structures as well as social-profile, cookie-consent and
optional StopForumSpam fields. No separate SQL imports are required for a
fresh installation.

### Style

FI Subsilver Shadow is the sole bundled and supported style. Fresh installs
activate it directly, and the consolidated post-1.53a updater moves the board
default and every stored member preference to it before removing obsolete
theme records. The experimental automatic mobile-style selection and its
footer switcher are no longer part of the application.

### Multiple host names

If the same forum must remain usable through both an apex domain and its
`www` alias, set the ACP cookie domain to their shared parent (for example
`.example.com`) and enable secure cookies on HTTPS. When changing an existing
host-scoped installation, change the cookie name once as well: this prevents
old host-only cookies from shadowing the new shared cookies and avoids asking
members to clear browser data manually. The request, Arcade and hotlink checks
accept only the configured host and its exact `www`/non-`www` counterpart;
unrelated subdomains remain untrusted.

The standalone DB Maintenance Emergency Recovery Console at `admin/erc.php`
is disabled by default because it can make extensive database changes. To use
it, temporarily add `define('DBMTNC_ENABLE_ERC', true);` and a random secret of
at least 32 characters as `define('DBMTNC_ERC_TOKEN', '...');` to `config.php`.
Open `admin/erc.php?token=...` over HTTPS only. The console immediately moves
the token into a secure, host-only session cookie and redirects to a clean URL.
Use it only for the required recovery operation, close the browser session,
then remove both settings immediately afterwards.

CrackerTracker's separate `ctracker/emergency.php` console cannot be enabled:
its original edit-to-unlock design had no authentication. Use the guarded DB
Maintenance console above for emergency recovery instead.

## Upgrading an existing forum

Current registration requires the durable `user_id_sequence` table supplied by
`update/update_from_153a.php` (and by the fresh-install schema). Complete that
database update before reopening registration with the current files. Keep the
counter in full database backups and restores; never reset it to the highest
surviving account ID. See [update/README.md](update/README.md) for details.

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

The browser-based `phpBB2/install/update_to_latest.php` legacy updater is
disabled on an installed forum. If that exact historical migration is needed,
temporarily add `define('PHPBB_ENABLE_LEGACY_UPDATER', true);` to `config.php`,
run it only against a backed-up test copy, then remove the constant and the
entire `install/` directory.

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
It also creates `user_removals` and `user_removal_items` (with your configured
table prefix). These tables are required before publishing the resumable
inactive-account, user-manager and standalone pruning workflows. Failed removals remain
visible in their original ACP module for explicit resumption; completed job
metadata is cleared. Existing or
restored accounts are not automatically deleted by a pending job. This recovery
mechanism retains the original pruning criteria across retries. The user manager
retains its all-participant-copy PM deletion policy; inactive-account removal
preserves other recipients' delivered/saved copies. The first administrator and
self-deletion remain protected; only full administrators may delete or resume
removal of a secondary administrator. No additional schema beyond these two
journal tables is needed for these recovery extensions. Pending pruning jobs
temporarily store the removed account's notification email and language; these
are cleared with the completed/discarded job. Optional deletion notifications
are handed off after durable cleanup and outside its storage lock. Delivery is
best-effort: interruptions can lose a notification, but resuming cleanup never
automatically retries an ambiguously delivered message. Pruning remains limited
to current full administrators and cannot target another administrator.
Run it before publishing the notification changes: the runtime needs the
additive `notify_claim` and `notify_claimed_at` columns in `topics_watch`.
These columns preserve existing subscriptions and coordinate concurrent mail
deliveries without holding a forum writer lock during mail transmission.
Optional reply-notification delivery failures are logged without failing the
stored post; unsent claims become eligible for a later reply. This is not a
background retry queue. Required account/password mail still reports failures.
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

## Preserved Flash games

The Arcade plays locally installed SWF games through the bundled Ruffle 0.5.0
self-hosting runtime; no CDN or browser plug-in is required. Game files and
historical scores are deployment data and are not included in this repository.
The existing phpBB Arcade score protocols remain in place: ordinary
`newscore.php` submissions, IBProArcade, vBulletin and pnFlashGames requests
continue through the original same-origin PHP endpoints. A small bridge also
exposes otherwise unknown FSCommand calls as a browser event for optional,
game-specific handling without guessing a score protocol.

Ruffle is an emulator rather than an exact Adobe Flash Player replacement.
Compatibility depends on each SWF and its ActionScript/API usage, and score
submission can only work when the game already implements one of the supported
Arcade protocols. Games tied to unavailable remote services, unsupported
ActionScript behavior, DRM or external assets may still fail and require an
individual port. Only install SWFs from trusted sources: Arcade games are
allowed same-origin networking and script access so that their legacy score
protocols can operate.

The included `.htaccess` registers the WebAssembly MIME type. Operators adding
a Content Security Policy must permit the locally served Ruffle scripts and
WebAssembly execution (normally `script-src 'self' 'wasm-unsafe-eval'`; older
browsers may require the broader `'unsafe-eval'`).

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

After upgrading from an older indexer, missing matches caused by partial title/
text edits or incorrect common-word counts can be recovered with the same search
index rebuild command above. Use a backed-up maintenance window without concurrent
posts or edits: rebuilding clears derived index data, not stored posts. Code
updates alone do not reconstruct old missing matches or reclassify old markers.

The main `config` table now uses InnoDB so CrackerTracker configuration restores
commit together or roll back on failure. The post-1.53a updater converts this
table without changing its columns or values; unrelated MyISAM tables remain
unchanged. Back up the database and run the updater before using restore on an
older installation. The runtime refuses an unsafe restore until this migration
has completed. Configuration is read directly from the database on each request
(one small query each for board and Plus settings), rather than using the old
unversioned file cache. Other caches are unaffected.

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
