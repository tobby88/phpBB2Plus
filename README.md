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

ACP private-message repair keeps a durable cleanup inventory before deleting
message parents or attachment links. After an interrupted run, reopen the same
maintenance operation: it rechecks current permissions and source state before
finishing the pending text, counter and attachment cleanup. Restored messages,
changed attachment registrations and other references are protected; changed-source cases are
reported for review. Completed writes are not rolled back. The journal contains
IDs, state/ownership metadata and registered filenames, not message text or
passwords. It does not sweep unrelated orphan files or unfinished uploads, and
does not reconstruct inventories for interruptions that predate this change.
This journal is specific to ACP PM repair; ordinary mailboxes use the separate
owner-scoped journal described below.
On existing databases, back up first and run `update/update_from_153a.php` (dry
run, then `--apply --backup-confirmed`) to add the two PM repair journal tables
before using maintenance. Fresh installations include them automatically.

Ordinary PM deletion, savebox eviction and automatic mailbox-capacity cleanup
also record their cleanup inventories before removing messages. Reopening the
affected mailbox as its authenticated, active owner retries interrupted cleanup
of already removed messages (at most 100 pending jobs per visit). An old intent
never deletes a surviving or restored message: another deletion requires a
fresh authorized action. Sending and reading have separate quota capabilities
because they can legitimately trim another participant's mailbox. Explicit
deletion and saving require POST/session validation. Delete-all pagination does
not expand to newly arriving higher message IDs.
Delivery and sent-copy quota eviction now run only after the new header, text
and attachment-publication steps succeed. The new copy is excluded from eviction
even when it carries an older original date. Delivery counters are recounted,
not incremented again after quota cleanup. Failed publication therefore does
not first discard an older message merely to make space.

The post-1.53a updater adds `pm_delete_jobs` and `pm_delete_items` without
rewriting existing messages. Run it before opening mailboxes with this version.
These tables store only IDs, state/ownership metadata and registered filenames,
not PM content. Shared references, changed attachment registrations and unrelated
uploads are preserved. Resolve persistent storage errors before retrying; changed
registrations and journals belonging to removed accounts can need administrator
review. This is cleanup recovery, not a transaction/rollback guarantee for the
entire send/read/copy workflow, and it cannot reconstruct pre-journal failures.

PM read transitions retain a recovery token on the source and a unique token on
the sent copy. Interrupted text/attachment copying resumes without creating a
second sent copy; unfinished copies are not displayed or downloadable. The
post-1.53a updater adds the corresponding source/copy fields and index without
rewriting existing message bodies. It also adds the publication fields,
`pm_write_receipts` table and attachment reservation fields used by the durable
write helpers. Apply these additive changes before replacing the PM code.
Unlike the cleanup inventories above, an unfinished publication payload contains
the prepared message text and attachment metadata until publication completes.
Protect these fields and backups as private-message content.

SEND/EDIT forms carry a random request identity and an edit revision. Repeated
accepted requests resume their stored payload before upload parsing, encoding or
flood checks, without publishing a second message. The sender's mailbox offers
session-protected POST actions for unfinished writes. Ordinary message deletion
retains the content-free replay receipt; removal of its owning account cleans it
up. Editing a message does not resend its creation notification.

The updater also adds `pm_write_receipts.notify_state`. Existing receipts default
to no notification. New-message email attempts are claimed once before calling
the mailer, with current recipient preferences checked and the database lock
released before SMTP. A lost acknowledgement or mail-server failure does not
automatically trigger another email. This avoids duplicate automatic attempts,
but is not a guaranteed-delivery mail queue; the saved PM remains in the forum.

Attachment-only deletion checks the current mailbox owner and session before
changing links. Compose attachment edits use the shared owning connection, with
fresh author/undelivered-message checks at database mutations. Replies and quotes
do not inherit attachment-edit permissions from their source. New temporary PM
uploads use server-generated, uploader-bound random filenames; stored message
attachments keep their existing names. Reload forms opened before this change
and re-add any not-yet-saved uploads. These checks do not reconstruct previously
lost files or undo writes already completed before an interruption.

Replacing a stored PM attachment or hiding its thumbnail creates separate
metadata and switches only the edited message's link. Other copies, shared file
registrations and their thumbnails remain intact. Changed attachment comments
also use a request-bound metadata copy and resume with the same reserved ID.
Failed pre-switch writes leave the original attached; a lost switch response can
mean the replacement already succeeded, so reload the message before retrying.
Interrupted cleanup can leave an unreferenced description for ACP orphan cleanup;
it must not be confused with permission to remove shared bytes.

An accepted publication also protects its new upload filenames before attachment
description rows exist. Older compose forms, another send request and orphan-file
cleanup cannot remove or adopt those uploads while that intent is pending. The
original request can still resume. Unknown or malformed pending intents preserve
the uploader's temporary files for review instead of treating them as abandoned;
the cleanup age threshold alone is not sufficient to release them.
Temporary PM uploads are also excluded from the ordinary post editor and its
publication/deletion helpers, so they cannot be exposed through a public post.

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

Personal post-count synchronization also uses this writer coordination. Counts
are calculated at write time from posts in existing count-enabled forums, with
zero for accounts without counted posts; guest/reserved IDs remain untouched.
Only current authorized ACP requests can run it. Reports use verified current
counts and names, and flag removed or changed targets. Partial failures can leave
earlier corrections applied; no automatic recount runs during deployment.

Manual post/forum and user-post recounts preserve the current board-disable
setting, including an administrator's change made while a recount is running.
Old signed synchronization links still authenticate their original state token,
but that state no longer authorizes reopening the forum. Each counter write
requires a currently logged-in ACP session belonging to the current authorized
administrator. Deleted, logged-out or deprivileged sessions cannot continue a
recount. Post/forum recounts refresh the navigation cache after releasing their
writer, including after partial failures. No extra schema migration is needed.

Moderator-status synchronization also preserves board availability and requires
the current ACP session before expiring target sessions or updating derived
USER/MOD flags. A delegated administrator whose own derived role needs repair
keeps only the active, freshly verified ACP session; other sessions still expire.
Its separate maintenance grant must remain valid throughout, and subsequent
requests reload the current user record. Administrator and other special roles
are not rewritten. Interrupted changes may be retried, but revoked sessions are
never recreated. This change requires no schema migration.

Search-index maintenance cleans unused non-common words and invalid matches on
the coordinated writer connection. Each batch contains at most 100 candidate
word or post IDs; deletion rechecks current references, common-word flags and
ACP authorization and the currently logged-in ACP session. Deleted, reassigned,
logged-out or deprivileged sessions cannot continue cleanup. Newly used words
and restored valid matches are retained. The existing board-disable setting is
left untouched, including independent changes made while cleanup is running.
This does not delete source posts or rebuild the entire index. Earlier batches
can remain applied after a failure, so back up before maintenance and retry only
after resolving the error. Deployment does not perform cleanup or a migration.

ACP table checking, repair and optimization likewise leave board availability
unchanged. They hold the coordinated writer connection, rechecking the current
administrator and ACP session immediately before and after each table command.
A revoked session stops subsequent commands, but cannot cancel or roll back a
command already submitted to the database. Query and statistics failures release
the writer before the error report; resolve the error and explicitly retry.
Database table locks can still delay concurrent requests while a command runs.
Unsupported or unconfirmed operations remain visible as incomplete diagnostics.
If damaged login/permission tables prevent normal ACP authorization, use the
separately authenticated emergency console rather than bypassing ACP checks.
No additional migration or automatic table repair is performed by deployment.

Configuration recovery adds missing defaults only; existing and concurrently
restored settings win. ACP writes require the current authorized session and
shared writer. Existing board availability is untouched; if that setting itself
is missing, recovery restores it disabled so an administrator can review it.
ACP and emergency recovery use the current HTTPS/path validators and list only
restored keys, not values. A lost or unknown version remains explicitly flagged,
not replaced with an invented completed migration. After backup and dry-run
review, `update/update_from_153a.php` finalizes missing/empty/2.0.0/2.0.21/2.0.22
version markers as 2.0.23 only after its schema work and engine check succeed.
Custom version markers and other settings are retained. Interrupted additions
may remain; resolve the cause and retry. Deployment itself performs no recovery.

Missing-author repair preserves stored guest names and valid user references,
including inactive accounts. Each write rechecks the original missing reference,
current users and current ACP authority. Topic authors come from the current
first post, with an anonymous fallback; post text and other metadata are untouched.
Interrupted repairs can be retried without re-anonymizing a repaired assignment.
This action needs no additional schema migration.

Orphan-text recovery in structural maintenance preserves original text, subjects,
BBCode IDs and existing attachment links. It creates missing post records only
while the selected original still exists unchanged, in a locked administrator-only
recovery area. Reserved category/forum/topic identities let a retry reuse partially
created containers and finish their counters. Back up first and run
`update/update_from_153a.php` before using this action on an existing installation:
it adds nullable unique `maintenance_token` columns to categories, forums and topics.
Neither migration nor deployment runs content recovery automatically. Resolve a
reported failure before retrying; partial MyISAM writes are not rolled back.
Structural topology repair also reuses these identities. Topics with a missing
forum keep their original IDs; posts with a missing/redirect topic are recovered
in separate locked topics according to their original topic IDs. A repeated run
finishes dependent forum links, attachment indicators and counters after an
interruption. New recovery IDs avoid dangling numeric references and respect
auto-increment high-water marks, so old subscriptions or ACLs are not adopted by
new content. The account must be able to read its own database metadata. Invalid
reference types or exhausted ID ranges require manual review, not ID reuse.
On MySQL, the dedicated recovery connection disables
[cached table metadata](https://dev.mysql.com/doc/mysql-infoschema-excerpt/8.0/en/information-schema-tables-table.html)
when that session option exists, before consulting the auto-increment value.
Do not repurpose the reserved recovery area while recovering data.

The complete post-table check now coordinates every repair/cleanup phase with
the shared writer lock and current ACP session/authorization. Deletions recheck
current parent references, and empty reserved recovery topics survive retries.
Prune rules are coalesced only when all rules for that forum are identical;
different policies are retained and reported by forum ID for manual selection.
Automatic pruning refuses ambiguous schedules. Valid subscriptions and permission
references restored during diagnosis are not deleted. The check finishes its
counter synchronization inline and never toggles the board-disable setting,
including on failure or when the administrator had already disabled the forum.
This is not an all-or-nothing transaction: resolve reported errors and repeat the
check after an interrupted run. These cleanup changes need no additional schema
migration beyond the recovery identities above and never run during deployment.

The auto-increment maintenance action repairs a missing attribute on an ordinary
integer primary key; it does not reset healthy counters or replace column types.
Explicit defaults, special attributes and ambiguous keys are left for review.
The ACP action keeps the existing board availability setting unchanged and uses
the shared dedicated writer connection. It checks the current administrator and
session before and after metadata reads and ALTER commands, including already
healthy tables. Errors release the writer before the error page is rendered.
Revocation stops further work, but cannot cancel DDL already submitted. Session
SQL mode is restored where the connection remains usable; failed connections
are closed, not returned for reuse. Deployment never runs this repair, and this
controller hardening needs no schema migration.
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
