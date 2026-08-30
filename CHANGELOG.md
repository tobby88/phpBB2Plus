# phpBB2 Plus changelog

## Preserved changes after phpBB2 Plus 1.53a

The preserved history continues from the `phpBB2-Plus-1.53a` tag. The Git
history remains the authoritative record; this section summarizes notable
changes consolidated after that baseline without implying active maintenance.

### Security and runtime hardening

- Repaired personal-album category resolution when an otherwise valid category
  is absent from the filtered hierarchy cache. The fallback loads only the
  requested owner's exact category and applies its real permissions; missing
  permission fields now default to deny rather than generating PHP 8 warnings
  or accidentally granting moderator actions.
- Centralized the Knowledge Base session-page constant so the online-user page
  can resolve KB sessions on PHP 8 instead of failing on an undefined constant.
- Stopped legacy Download, Banner and Statistics AdminCP modules from
  redefining the phpBB bootstrap constant while the module menu is discovered.
  The ACP integration audit now rejects this warning-prone pattern.
- Repaired Knowledge Base article and statistics lists with rated articles:
  rendering no longer overwrites a database row before reading its rating, and
  ratings now use the actual article fields. Admin article IDs are normalized
  before they reach SQL. Guest authors no longer depend on an undefined
  variable, and article-notification private messages no longer persist
  privileged action URLs containing an old session ID. Approve, unapprove and
  confirmed-delete operations now require POST plus the active AdminCP token.
- Added POST/session-token checks to paFileDB category and custom-field writes
  and to country-flag saves/deletes. Flag image names are confined to plain
  local GIF, PNG or JPEG filenames and SQL values use the database driver's
  escaping boundary. The same scalar/type and SQL-boundary checks now protect
  paFileDB category and custom-field internals. Category ordering and
  synchronization now also use tokenized POST forms instead of write-capable
  GET links, with strict category and movement validation.
- Kept localized Arcade highscore positions available when extension language
  files are loaded from inside phpBB's user-preference initializer. Position
  formatting now also handles incomplete language packs and invalid ranks
  without PHP warnings.
- Hardened Knowledge Base category and type administration with scalar input
  normalization, SQL-boundary escaping and POST/session tokens for every
  write. Category ordering is no longer a GET mutation, invalid parent cycles
  are rejected, deleting a category keeps children reachable and bulk article
  deletion removes dependent search and vote rows. Single-article deletion
  now removes its vote rows in both AdminCP and moderator workflows as well.
- Made CrackerTracker configuration snapshots atomic: a failed refresh no
  longer empties the last usable snapshot first. Restore refuses empty
  snapshots, preserves settings added after the snapshot and invalidates the
  configuration cache after success. The AdminCP no longer directs operators
  to the deliberately disabled public emergency console, and CI verifies the
  staging/rename sequence and escaping.
- Replaced CrackerTracker's account-wide failed-login CAPTCHA flag with a
  15-minute limiter scoped to the server-verified IP and submitted username.
  Distributed guessing is still bounded by the broader IP limiter, but an
  attacker can no longer impose an unlock step on the victim's account.
  Existing legacy flags are cleared after a valid login, unknown and existing
  names follow the same limiter path, and the AdminCP now describes the actual
  account-safe behavior.
- Hardened the CrackerTracker source scanner so a magic source-code comment
  can no longer declare an arbitrary file safe. Traversal stays inside the
  forum root, skips symlinks and volatile/cache data, escapes stored paths and
  indexes files linearly instead of querying the maximum ID for every file.
  Reports are built and analyzed in a staging table, then atomically replace
  the last complete report only after success; unreadable files no longer
  cause PHP 8 type errors. Filesystem behavior is covered in CI.
- Extended CrackerTracker's central rate limiter from a manually maintained
  plugin filename list to every POST entry point, while retaining tighter
  buckets for logins, registrations, uploads, password resets and email forms.
  Browser-confirmed cross-site writes and unsafe TRACE/TRACK/CONNECT methods
  are rejected centrally, while headerless legacy clients remain compatible.
  Security logs now redact passwords, session IDs, confirmation values and
  tokens from request and referrer query strings.
- Replaced CrackerTracker's file-size/line-count fingerprint with SHA-256
  content checksums, so equal-size file tampering is detected. Baselines are
  now built in a separate table and atomically swapped only after a complete
  scan; symlinks outside the forum tree are skipped, database paths are safely
  escaped, and legacy weak baselines are clearly marked for an explicit
  administrator rebuild. Fresh and upgraded databases support 64-character
  hashes, covered by an idempotent migration and CI regression test.
- Corrected CrackerTracker log rotation so configured caps are exact and every
  rotated entry is preserved in the cumulative counter once. Repeated logger
  construction no longer redefines a constant, numeric counter reads are
  bounded, and the behavior is covered by a filesystem-level CI test.
- Replaced the CrackerTracker maintenance page's checks for removed PHP
  features and discontinued update servers with local diagnostics for the
  structural request engine, blocklist matcher, atomic rate-limit table,
  supported PHP runtime, HTTPS and adaptive password hashing. Log permissions
  are described as read/write requirements rather than recommending CHMOD 777.
- Removed the legacy unauthenticated account hard-lock and repeated warning
  email path, which let an attacker deny service to a known user with only a
  few bad passwords. Failed attempts remain counted and logged, while the
  account-specific CAPTCHA and central per-IP limiter continue to slow guesses
  without making the victim's account unusable. The now-unused automatic
  lockout threshold was removed from the AdminCP form.
- Moved CrackerTracker search throttling from shared mutable fields on the
  anonymous user record to the atomic rate-limit store. Guests are isolated by
  verified IP, signed-in members by user ID, fixed windows no longer grow after
  repeated retries, and the wait response now uses HTTP 429 without fragile
  inline JavaScript.
- Hardened CrackerTracker's client blocklist matching against malformed and
  excessive wildcard patterns, bounded inspected headers and added native
  IPv4/IPv6 CIDR support without trusting proxy-supplied client addresses.
- Replaced CrackerTracker's unscoped 2006-era request word blacklist with
  bounded, repeatedly decoded and context-aware structural checks for SQL
  injection, traversal, executable stream wrappers and browser-script payloads.
  Normal prose, apostrophes, filenames and technical discussions in free-text
  fields no longer trigger the central firewall merely because they contain a
  blacklisted word.
- Added central, configurable per-IP rate limits for login attempts,
  registrations, uploads and write actions. The limiter uses only the verified
  connection address, returns a standards-compliant `429` response, cleans up
  expired counters opportunistically and fails open if its table is not yet
  available during an interrupted update.
- Added a CI audit for AdminCP module registration, English/German language
  keys and fallback templates. Invalid configured fallback-style names now
  safely fall back to the bundled `subSilver` AdminCP templates.
- Hardened CrackerTracker's user and spam functions against malformed PHP 8
  request values, invalid IP history and duplicate bans. Content heuristics now
  reject the submitted content without destructively erasing account profile
  data, and password expiry/complexity handling is PHP 8-safe.
- Made CrackerTracker confirmation codes single-use under concurrent requests
  and replaced its unbounded session-list cleanup query with a database join.
  CrackerTracker IP history now validates addresses and stores IPv6 without
  truncation; the idempotent updater widens existing columns accordingly.
- Made CrackerTracker's first-administrator protection follow the actual
  lowest current administrator account instead of assuming user ID 2, and
  routed login-name lookups through the active database driver's escaping.
- Hardened CrackerTracker configuration and blocklist writes, sanitized its
  text log records, serialized concurrent log/counter updates and made a local
  logging failure non-fatal without changing the request-blocking decision.
- Bound CrackerTracker settings, global messages, footer selection, blocklist,
  log deletion and miserable-user changes to session-authenticated POST
  requests; bounded submitted values and safely rendered stored log data.
- Bound CrackerTracker maintenance, checksum/file scans and configuration
  recovery to authenticated POST actions. Configuration restore now updates
  the existing table in place instead of dropping and recreating it.
- Bounded CrackerTracker request depth, field count, key size and scalar size;
  fixed complete inspection of mixed nested arrays, returned explicit 403
  responses for blocked requests and exempted validated free-text searches
  from obsolete single-word heuristics.
- Added a CI guard that rejects new public PHP entry points which bypass the
  central `common.php` security bootstrap without an explicit reviewed marker.
- Normalized hardened ACP form values before SQL-boundary escaping so literal
  apostrophes are stored without legacy magic-quotes backslashes.
- Added adaptive password hashing with transparent migration after a successful
  legacy-MD5 login. Fresh installs and all password creation/reset paths use
  `password_hash()`; existing installations opt in only after the guarded
  updater widens both password columns, preventing accidental hash truncation.
- Replaced direct deserialization of browser cookies, search data, Arcade data,
  and extension caches with a PHP 5.6-to-8.x compatibility helper that blocks
  object instantiation.
- Added `HttpOnly`, `SameSite=Lax`, and automatic HTTPS-only handling to forum
  cookies, plus conservative response headers against MIME sniffing, external
  framing, and unnecessary browser-device access.
- Removed avoidable dynamic code execution from email templates, BBCode
  templates, statistics-module conditions, attachment administration, and ZIP
  callbacks. Fixed the mismatched `right` BBCode template marker exposed by
  strict parsing and corrected repeated email-variable assignment.
- Removed PAFiledb's shell-based Windows deletion fallback and shell-escaped
  every ImageMagick thumbnail command argument.
- Removed stray patch-prefix characters that made the fresh-install Arcade
  schema and seed SQL invalid, and extended the updater self-test to reject a
  recurrence.
- Blocked server-side script execution in attachment, avatar, album and
  PAFiledb upload directories, protected the remaining writable game,
  screenshot and download-cache directories according to the files they must
  serve, and stopped making uploaded or generated image files world-writable.
- Disabled the legacy "upload avatar from URL" feature, whose unbounded socket
  client could reach arbitrary hosts, while retaining safe external-avatar
  links over HTTP or HTTPS and ordinary local uploads.
- Removed discontinued eXtreme Styles update/download services and remote or
  filesystem style imports; styles can still be imported through an
  administrator upload without forwarding forum sessions to third parties.
- Removed automatic outbound requests for remote PAFileDB sizes and obsolete
  CrackerTracker version data, and bounded the remaining StopForumSpam lookup.
- Stopped server-side image probing for linked avatars, escaped linked-avatar
  output in every forum module and use client-side size limits instead.
- Removed the Arcade's broken custom DNS parser, which could suppress private
  message notifications and incorrectly ban recipients when an MX lookup
  failed; notifications now use the forum's normal mail transport.
- Converted the legacy bulk-user deletion endpoint to an explicit, session-
  bound POST confirmation, excluded administrator accounts from pruning and
  isolated per-user deletion lists to prevent unintended follow-on deletes.
- Added session-bound confirmation to album image/comment deletion and
  replaced raw JavaScript banner redirects with validated HTTP(S) redirects.
- Bound Hot-or-Not album ratings to POST and the active session, rechecked
  view/rate/approval permissions in the write path, prevented disallowed
  duplicate votes atomically, and fixed its stale category and filename
  variables.
- Added session tokens to album picture edits, comment edits, new comments and
  ordinary ratings; enforced comment and rating permissions independently,
  used the existing auto-increment comment key instead of `MAX()+1`, and
  restored the intended moderator edit access.
- Bound PAFileDB ratings, comments, uploads, edits, deletions and moderator
  approvals to the active session; changed destructive links into confirmed
  POST actions, rechecked permissions against each target record and prevented
  duplicate rating races. Comment owners can now delete only their own
  comments instead of inheriting the uploaded file owner's identity.
- Hid unapproved PAFileDB downloads from unauthorized direct requests, made
  download counters concurrency-safe, recorded downloader history per file,
  tightened referrer host matching and normalized the legacy email form's
  scalar, address, session and header handling.
- Bound Knowledge Base ratings, article creation/edits and moderator actions
  to session-verified POST requests; rechecked article ownership on the write
  path, hid unapproved rating targets and replaced timestamp-based new-article
  lookup with the inserted database ID.
- Added the previously missing Shoutbox form tokens, required an explicit
  submit action before inserting messages, escaped stored fields through the
  active database driver and converted censor/delete links into authorized,
  session-bound POST confirmations.
- Bound the CrackerTracker login-history IP-warning preference to the active
  session and normalized its stored boolean value.
- Secured Arcade searches against SQL injection, bound favourite changes to
  session-authenticated POST requests and rejected missing game records.
- Hardened legacy Arcade score submissions with bounded numeric scores,
  escaped database writes and logs, delayed one-time session consumption and
  PHP 8-safe handling of malformed log data.
- Secured pnFlashGames save-state and score callbacks with bounded scores,
  escaped session/game data, scalar-safe logs and the shared Arcade session
  lookup instead of loosely scoped user-only queries.
- Corrected Arcade score pruning after deletes and limited the cached all-time
  player-name refresh to the actual highscore holder instead of every player.
- Restricted Arcade cache keys to local safe filenames and stopped stripping
  legitimate backslashes from serialized cache values while reading them.
- Made password changes PHP 8-safe for malformed inputs, preserved intentional
  whitespace, invalidated other sessions and persistent-login keys, supported
  longer passwords and removed obsolete autoplay browser markup.
- Hardened the public Links module against search SQL injection and stored
  HTML/script injection, validated all outbound and logo URLs, and bound link
  submissions to the active user session.
- Hardened the public recent-topics page against SQL injection and PHP 8 type
  errors, bounded its date and pagination inputs, and normalized configured
  forum filters without weakening per-forum read permissions.
- Made Portal polls, news articles, archives, and news-category listings honor
  both forum visibility and read permissions, removed SQL disclosure from news
  failures, and deleted unused legacy fetch/title implementations.
- Added file-count and expanded-size limits before extracting Nuffload ZIP
  uploads to reduce archive-bomb exposure. Each upload now uses an isolated,
  unpredictable extraction directory, with bounded cleanup of stale sessions.
- Bound Arcade score sessions to the current logged-in player (or the matching
  guest cookie), validated session hashes, rejected array input on scalar
  fields and escaped Arcade request-log values.
- Replaced the remaining executable regular expressions, corrected obsolete
  PHP argument order in database backup generation and replaced removed
  `utf8_decode()` use while preserving existing custom-profile column names.
- Fixed the modern-superglobal compatibility layer so recursively quoted
  request values are synchronized back to the legacy `$HTTP_*_VARS` arrays
  used by phpBB2. This closes a gap where old SQL-building code could receive
  an unquoted copy; the separately initialized `$_REQUEST` array is covered as
  well.
- Replaced predictable session-adjacent, activation, CAPTCHA, upload and Arcade
  identifiers, including legacy anti-robot keys and physical attachment-name
  suffixes, with operating-system randomness while preserving their legacy
  database formats and a PHP 5.6 OpenSSL fallback. New forum sessions use the
  full 128 bits represented by their existing 32-character hexadecimal field,
  and malformed incoming session identifiers are ignored.
- Stopped constructing public and upload-return URLs from attacker-controlled
  `Host`/`PHP_SELF` request values, escaped the Tell-a-Friend form values and
  stripped line breaks from mail address headers.
- Reduced generated cache, template, upload and thumbnail permissions from
  world-writable modes to owner/group-writable files and directories.
- Removed an unused duplicate template interpreter and CAPTCHA renderer,
  replaced the misplaced full forum script under `images/` with a directory
  guard, and reduced the unreachable phpBB1 upgrader to its explanatory stub.
- Disabled the browser-based legacy database updater on installed forums
  unless an administrator explicitly enables it in `config.php` for a
  controlled migration window.
- Required a separate, long recovery token in addition to the opt-in constant
  before the standalone Emergency Recovery Console can run, and removed its
  request-derived form actions.
- Fixed PAFileDB upload failures being reported as success, enforced limits
  against the actual temporary-file size for every uploader, required genuine
  HTTP uploads, blocked executable types independently of administrator
  settings, confined replacement and deletion paths, generated safe screenshot
  names and verified screenshot image contents.
- Restricted PAFileDB remote downloads and screenshots to validated HTTP(S)
  URLs at write and read time, protected redirects against header injection,
  and HTML-escaped stored file/category metadata throughout public, moderator
  and administration views.
- Added a compatibility-safe Origin/Fetch-Metadata check for state-changing
  requests and a minimal CSP covering form targets, framing, base URLs and
  legacy objects, supplementing SameSite cookies without excluding old clients
  that send neither header.
- Repaired the standalone shield-smiley renderer on PHP 8, validated its
  colors and image selection, bounded missing parameters and adapted the GD
  polygon calls for current signatures.
- Replaced CrackerTracker's unauthenticated edit-to-unlock emergency console
  with a permanent disabled stub; the token-protected DB Maintenance recovery
  path remains available.
- Repaired Arcade score persistence and its legacy pnFlashGames bridge: score
  sessions are now bound to their stored game and validated hash, first scores
  can be inserted, unavailable games are rejected correctly, monthly dates no
  longer use PHP 8-fatal bare constants, and saved-game responses are encoded.
- Hardened attachment, PAFileDB and topic-export downloads against header
  injection, path disclosure and active inline content; removed size-based
  direct-file fallback and an unused, broken email attachment prototype.
- Added cross-site navigation rejection to the SID-protected administration
  panel and removed the standalone shield renderer's duplicate PHP 4 request
  bootstrap.
- Bound Nuffload upload hand-offs and progress cleanup to the initiating forum
  session, restricted its temp cleanup to known generated files, removed the
  CGI's caller-controlled redirect and symbolic request variables, restored
  SQL quoting after the Perl hand-off, and require genuine PHP uploads.
- Validated and context-escaped legacy profile websites, locations, messenger
  handles, custom fields, ranks and country flags throughout topic, profile,
  member, group, album, shoutbox, knowledge-base and download views. Country
  flag input is now restricted to a safe filename before it reaches SQL or a
  generated image path.
- Restricted Arcade statistics and reward database fields to valid columns,
  normalized reward amounts and user IDs before SQL use, repaired global
  reward settings, score clearing and game import, and removed unused broken
  helpers. Imports are POST-only and confined to local forum directories;
  ordinary game play and score submission no longer trigger reverse-DNS calls.
- Hardened the generic per-user MOD settings page against array-shaped input,
  unsafe dynamic SQL identifiers and unescaped form values, and repaired its
  empty submenu handling on PHP 8.
- Replaced the remaining PHP-7-incompatible executable regular expressions in
  posts, AJAX edits, print views, calendars, staff signatures, knowledge-base
  articles, acronyms and PAFileDB template compilation with non-executing
  replacements that preserve HTML tags.
- Made all state-changing AJAX actions POST-only, strengthened their session
  comparison, rejected array-shaped scalar input, escaped inline-edit SQL and
  fixed anonymous poll identity plus duplicate-vote recording order. The
  bundled JavaScript clients now use the matching request methods.
- Bound account-maintenance, style-cache, style-uninstall, PAFileDB-license
  and Arcade administration actions to the active session; constrained style
  file operations to validated template paths and repaired several malformed
  legacy update/delete queries.
- Repaired public Arcade tournaments so only enrolled players can launch a
  configured game in an active tournament, signed launch URLs to the current
  session, normalized legacy game data on PHP 8 and protected tournament
  create, join and finish actions.
- Revalidated Arcade comment ownership on every edit and delete, bound comment
  and rating writes to the active session, made new comment IDs database-safe,
  prevented duplicate ratings under concurrent requests and removed the
  external administrator IP lookup from comment pages.
- Confined Arcade image discovery to local relative assets and removed its
  unexpected public-page file copy. Monthly highscore views now select exact
  stored months, validate legacy offsets, respect game availability and access
  requirements, and escape stored game/player presentation data.
- Load Arcade translations only after session preferences are initialized, so
  guest and member pages consistently use the forum-selected language instead
  of an earlier stale fallback.
- Hardened album moderation and category administration against forged
  actions and injected ID lists, validated move targets and signed legacy
  one-click moderation/order links. Personal-gallery management now verifies
  ownership before initialization, album deletion is confined to generated
  basenames, stored moderation labels are escaped, and obsolete external
  Whois links no longer disclose administrator lookups to a third party.
- Restored album-comment permalinks, mini-post icons and rank images instead
  of emitting an empty image request and an unusable link in picture views.
- Repaired medium-size album previews by removing the unusable historical
  Windows ImageMagick branch and consistently validating and processing images
  with the available GD runtime.
- Hardened local avatar uploads against spoofed extensions and request sizes,
  confined replacement and deletion paths, and made the administration panel
  use the same validation and gallery handling as the public profile editor.
- Removed stale Shoutbox profile state and fixed anonymous online-list entries
  so guest rows no longer attempt to build member-profile links or emit PHP 8
  warnings.
- Converted legacy PAFileDB, acronym, banner, news-category, Arcade-reset and
  statistics administration writes from immediate GET actions to explicit,
  session-bound POST requests. Confirmations are retained for destructive
  operations, and failed PAFileDB mirror replacements no longer discard the
  previously stored file.
- Bound word-censor, disallowed-name, rank, post-icon, user and smiley
  administration writes to the active admin session. Smiley-pack imports are
  size-bounded, validated completely before replacing data and confined to
  package and image files already present in the configured smiley directory.
- Hardened moderation-log deletion and pruning against malformed identifiers
  and moved Registration IP hostname resolution and persistence from a GET
  link to an explicit session-bound POST action.
- Added shared scalar-input, output-escaping and session-field helpers for the
  administration panel, then applied them to Portal, Nuffload, Links and
  CAPTCHA configuration writes.
- Applied the same protections to phpBB2 Plus and News settings, and confined
  News filesystem configuration to local relative paths while accepting only
  HTTP(S) absolute base URLs.
- Removed the RSS endpoint's undefined and semantically incorrect third
  session argument on current PHP versions.
- Bound Arcade-wide value changes and personal-album group permissions to the
  active admin session. Personal-gallery permission lists now contain only
  positive, unique numeric group identifiers, and the Arcade forms use valid
  table/form markup.
- Applied the same numeric permission normalization to individual Album
  categories, separated category selection from the authenticated write, and
  repaired the misspelled hidden-field placeholder that had left Arcade cache
  settings without a usable session token.
- Hardened custom-profile-field administration against injected identifiers,
  array-shaped form values and unescaped database content; column names used
  by schema changes are derived and validated server-side. Also repaired the
  statistics ACP bootstrap that previously stopped at its own include guard.

### Repository and update cleanup

- Reconciled the later `phpbb2premods/phpBB2Plus` snapshot against the
  preserved 1.53a baseline. Retained its valid statistics bootstrap cleanup;
  the remaining PHP compatibility fixes were already present or superseded,
  while generated installation data and blanket schema-engine changes were
  intentionally not imported.
- Removed the development-only Docker setup and generated upstream audit
  documents; their complete provenance remains available in Git history.
- Removed the now-empty `mods/` staging area after integrating the selected
  packages and discarding the packages that are intentionally not shipped.
- Removed one-time PowerShell integration helpers and moved the two useful PHP
  maintenance tools into `update/` with explicit names.
- Renamed every legacy updater so its source and target are visible instead of
  using ambiguous `to_latest` names.
- Consolidated all database additions after 1.53a into the guarded,
  idempotent `update/update_from_153a.php` updater and incorporated the same
  Arcade, Nuffload and DB Maintenance definitions into the fresh-install
  schema.
- Replaced executable generated configuration and hierarchy caches with
  validated non-executable data. Existing post-icon settings migrate
  automatically to the protected `data/icons.dat` store; the permissions tool
  and update instructions cover its writable directory.
- Integrated the required CrackerTracker 4.x database cleanup into the
  consolidated updater and removed the obsolete standalone 4.1.7 updater and
  unsafe `mysql_*` uninstall script.
- Moved the UTF-8 migration procedure into the project README and removed the
  separate documentation directory.
- Removed the unused smoke-test tree, duplicated legacy phpBB documentation,
  optional contribution utilities, and their obsolete support links. The GPL
  text now lives at the repository root, while a concise Markdown README makes
  the deployable `phpBB2/` directory directly readable on GitHub.
- Reduced the database layer and fresh installer to MySQL/MariaDB through
  MySQLi. Existing `mysql` and `mysql4` configuration values remain compatible
  aliases, while removed-extension and unsupported alternative drivers are no
  longer distributed.
- Moved the actively integrated portal mini calendar from the historical
  `mods/` package path to `includes/mini_cal/`; its portal display and calendar
  search integration remain unchanged.

### phpBB 2 maintenance baseline and post-release patch level

- Applied the official phpBB 2.0.22 and 2.0.23 changes, bringing the bundled
  phpBB base to `release-2.0.23`.
- Applied ten later fixes from the phpBB 2.0.23.x maintenance branch.
- Ported the applicable changes which IntegraMOD described as unofficial
  2.0.24 and 2.0.25 patch levels. These were not official phpBB releases and
  were not imported as a replacement product; the audited database and runtime
  version identity therefore deliberately remain `.0.23`.

### CrackerTracker

- Updated CrackerTracker Professional from 4.1.7 to 5.0.4, including its
  administration, security, logging, language, database, and template changes.
- Updated CrackerTracker from 5.0.4 to 5.0.6.

### Arcade

- Restored the Arcade Mod Plus 2.1.8 framework, administration modules,
  templates, language files, and score protocols without bundled games or
  historical activity data.
- Added the Arcade definitions to the fresh-install schema and deployment
  permissions; the Arcade is disabled by default after installation.
- Restored the missing Arcade administration arrows and popup close button
  referenced by the integrated code.
- Restored the Rewards API used by the optional Cash and Allowance integrations.
- Replaced the obsolete Flash plug-in/SWFObject embedding path with a locally
  bundled Ruffle 0.5.0 self-hosting runtime and registered the WebAssembly MIME
  type for Apache deployments.
- Preserved the existing same-origin newscore, IBProArcade, vBulletin and
  pnFlashGames score transports. Unknown FSCommand calls are exposed as a
  browser event but are deliberately not guessed or converted into score
  submissions. Game-specific Ruffle compatibility still depends on the SWF
  and its ActionScript/API usage.

### Photo Album

- Restored Nuffload 1.4.2 multiple-file and ZIP upload support for the Photo
  Album, without uploaded images or production data.
- Added neutral installation defaults and a protected CGI temporary directory.
  The optional Perl CGI uploader is disabled by default and requires CGI
  support and the Perl CGI module on the server.

### Database maintenance

- Restored DB Maintenance Mod 1.3.8, including its administration interface,
  consistency checks, synchronization tools, search-index rebuilding, and
  optional Emergency Recovery Console.
- Added neutral MySQL/MariaDB configuration defaults and compatibility with
  `mysqli`, current database version strings, and PHP 5.6 through PHP 8.x.
- The standalone Emergency Recovery Console is disabled by default and must be
  explicitly enabled in `config.php` for a short maintenance window.

### Administration and audit modules

- Updated Admin Userlist from 1.1 to an adapted 2.1 implementation with safe
  sorting and filtering, bulk activation, banning and group assignment, and
  Color Groups name formatting. User deletion remains in the central user
  editor so phpBB2 Plus data is cleaned through one authoritative path.
- Integrated Log Actions MOD 1.1.6 and Enhanced Log Actions with IPv4/IPv6
  support, an administration viewer, guarded deletion and pruning, and logging
  for delete, move, lock, unlock, split, edit, sticky and announcement actions.
- Integrated Registration IP 1.1.2 with IPv6-capable storage, server-verified
  remote addresses, optional on-demand reverse DNS, and a German translation.
- Updated `hacks_list.php` into a maintained components-and-credits page,
  refreshed the historically verifiable component versions, added the Arcade
  Rewards API, IntegraMOD responsive-style and social-profile credits, and
  corrected the authors and versions of the later integrations. The public
  page escapes database-provided output and no longer mutates its database
  while rendering or imports the bundled example `.hl` placeholder.
- Confirmed that IntegraMOD's IM Portal was not installed: the existing
  Smartor ezPortal remains authoritative, with responsive templates retained
  solely as additional style coverage.
- Removed the unused Digests, Registration Spam, and Rules & Policies source
  packages.

### Modern PHP compatibility

- Added the `mysqli` database abstraction layer.
- Removed unused row and rowset caching that caused `Illegal offset type`
  warnings.
- Removed calls to the obsolete magic-quotes functions.
- Replaced removed `ereg*` and `split()` calls with supported alternatives.
- Replaced the deprecated `preg_replace()` `/e` modifier with
  `preg_replace_callback()`.
- Prevented duplicate `IN_PHPBB` constant warnings.
- Fixed the fatal duplicate by-reference `$email` parameter in the avatar
  gallery code.
- Synchronized the installer with the maintained phpBB 2.0.23.x branch.

### IntegraMOD phpBB2x preservation merge

- Merged the complete 201-commit IntegraMOD phpBB2x history after mapping its
  `phpBB/` product directory to `phpBB2/`, retaining upstream authorship and
  merge ancestry.
- Ported the applicable fixes from IntegraMOD's self-described phpBB 2.0.25
  state while preserving the official phpBB 2.0.23 baseline identity.
- Imported all six responsive styles and their redistributable assets; added
  complete subSilver fallback coverage for phpBB2 Plus modules.
- Retained only English and German from the upstream language collection.
- Added modern social-profile fields throughout registration, profiles,
  messages, member/group views and all seven styles while preserving legacy
  contact fields.
- Integrated cookie consent and optional StopForumSpam registration checks;
  external spam checking is disabled by default.
- Normalized distributed text sources, language files, mail templates and
  charset declarations to UTF-8 and aligned fresh MySQL/MariaDB schemas and
  MySQL-family connections with that encoding.
- Restored PHP-4-style constructor behavior through PHP 8-compatible wrappers,
  including the database, template, attachment, statistics and module classes.
- Preserved optional MOD packages only where upstream never installed them;
  unsupported database experiments imported with those sources are not exposed
  as active database paths.
- Excluded BootstrapMade HeroBiz demo media and proprietary form files while
  retaining redistributable style code and recording third-party licenses.

### Production compatibility preservation

- Reconciled all generally applicable changes from a 56-commit production
  compatibility branch while excluding its private deployment automation,
  host redirects, operational data, and acceptance records.
- Restored posting, photo-album upload and image responses, the browser upload
  progress display, portal and topic routes, Shoutbox rendering, guest-language
  selection, topic export, news archives, and legacy administration modules on
  modern PHP runtimes.
- Replaced obsolete phpBB2 version polling in the administration dashboard and
  restored portable database-size reporting.
- Added guarded utf8mb4 migration and optional search-index rebuild tools for
  existing installations, plus protected local PHP error logging.
- Preserved the complete source ancestry and individual dispositions in Git
  history while removing generated audit ledgers from the release tree.

### Repository and deployment maintenance

- Added a pinned GitHub Actions syntax-check matrix for PHP 5.6, 7.4 and 8.5.
- Added a reproducible Unix file-permissions script and documented the required
  writable paths.
- Added a project README.
- Removed obsolete code-change instructions and plugin update packages after
  their changes had been integrated into the Git history.
- Reduced the repository to the maintained `main` branch, phpBB2-related tags,
  and this project's own release tags.

## Historical changelog through phpBB2 Plus 1.53a

The following is the original changelog shipped with phpBB2 Plus 1.53a.

```text
Changes from phpBB2 Plus 1.53 Final -> 1.53a
----------------------------------------------

- CrackerTracker v4.1.7 updatet
- Ajax-feature-hack to v1.0.4 updatet with CT integration 
- 2nd mistake in profil_add_body.tpl fixed (onnicon)
- memberlist_body.tpl html-comment re-designed
- cache changes in common.php + functions_color_groups.php
- file+folder-writepermissions Checker added
- typos in english/lang_admin_captcha.php fixed (Reliable)
- admin_mass_mail.php -workaround (cYbercOsmOnauT)
- Attachment-Mod v2.4.3 updatet
- header-topiccalendar-cache included
- kb-articlesort @ latest fixed
- security-fixes in kb, hacklist + pafiledb
- added phpBB 2.0.21 Code Changes (with fix)
- custom-profile-fileds "require" fixed


##################################################################
08/Apr/06 Changes from phpBB2 Plus 1.53 Beta 9 -> Final
----------------------------------------------

- admin_profile_fields.php small fixes
- mistake in profil_add_body.tpl fixed (onnicon)
- Ajax options for edit+preview
- pafiledb php5.0.5 extionsfix (landy_110)
- pafiledb other configcache writing
- Amigalink Adv.Captcha added
- added phpBB 2.0.20 Code Changes


##################################################################
22/Feb/06 Changes from phpBB2 Plus 1.53 Beta 8 -> Beta 9
----------------------------------------------

- admin_forums.php some changes
- pafiledDB-Admin "global_" fix (cback)
- Cracker Tracker Professional v4.1.1 updatet
- posting-bug on PHP5.0.5-3 (Litidian)
- KB select_one fixed
- new ColorGroups cache added
- shoutbox max change
- search all bluecards fixed
- Custom Profile Fields added
- Ajax-Mod added
- portal.php user_lastvisit change to user_lastlogon
- add option of MySQL Fulltextsearch (fanrpg)


##################################################################
10/Jan/06 Changes from phpBB2 Plus 1.53 Beta 7 -> Beta 8
----------------------------------------------

- added phpBB 2.0.19 Code Changes
- last-topic-url fixed 
- signatur-profile fix (liefland) 
- album smilie fixed
- shoutbox-highlight (alcaeus)
- [web]-bbcode replaced (cback)
- jr-admin sorting fixed
- cracktracker updatet v4 (changed to "Plus-Edition" ;) )
- portal-lastvisitbox fixed
- protectuser account little changes
- Attachment MOD 2.4.1
- Run stats (Ptirhiik - backport of CH2.1.x)
- Boardconfig-Cache
- bbcodebox @ KB
- extreme Styles Mod 2.3.1 updated
- visalconfirm of phpBB as option added
- little fix admin_statistics.php (asterix)
- recent.php & pafiledb toplist MySQL5
- on shorturl for Categories+Forums now "speaking urls" avalible ( /forum1,forums-name.html)


##################################################################
22/Jul/05 Changes from phpBB2 Plus 1.53 Beta 6 -> Beta 7
----------------------------------------------

- added phpBB 2.0.16 Code Changes
- added phpBB 2.0.17 Code Changes
- Attachment Mod updated to 2.3.14
- Small Includes optimizations
- fixed Typo in German Admin Language File
- some Search.php optimizations


##################################################################
15/May/05 Changes from phpBB2 Plus 1.53 Beta 5 -> Beta 6
----------------------------------------------

- fixed bug in topic_view_users.php pointing to wrong personal Album Link
- Updated extreme Styles Mod to 2.2.1
- fixed bug in Last Visit Mod and Color Groups Mod
- fixed bug in Recent Pics Portal Box (SQL-Error when adding more than 1 Cat-ID in Portal Config)
- fixed another Bug in Recent Pics Portal Box (Enable/Disable display of Private Pictures in Recent Pics Box works now)
- Disable Last Visit Function in Plus Config now also works if phpBB2 Default Index Layout is selected
- Permissions for upcoming events in Minical improved
- added Advanced Shoutbox Configuration (titus)
- added Configuration for Top-Posters Block into Admin Panel Portal Config
- added Switches to all Portalblocks (enable/disable) (Titus)
- fixed Bug in Knowledgebase Mod EMail Function. Notification of new KB-Docs by EMail works now.
- fixed Bug in Signature Editor...BBCode Buttons working again now
- fixed Bug in News System displaying Error Msg when no News exists (Fresh installed Board with deleted Demo Topic)
- added CBack Cracker Tracker XTreme Edition Code in common.php
- added phpBB 2.0.14 Changes
- added phpBB 2.0.15 Changes
- added Access-Key "s" to Quick Reply Box, now Quick Reply Messages can be sent with Alt+s directly
- fixed Recent Topics Links in Portal when ShortURL is enabled. Now Links point to latest Post instead of always first post in a Topic
- Attachment Mod updated to 2.3.13
- Added Color Groups Caching to increase Forum Speed
- removed phpBB and Plus Version Number from Printtopic view
- increased height size of Smilie Creator Popup (no more scrolling down to sent Smilie)
- added phpBB 2.0.15 Security Fix (http://www.phpbb.com/phpBB/viewtopic.php?t=290149)
- fixed SQL-Error while viewing Articles
- Deleting a User now removes Entry of Session Table also
- Fixed several Bugs in Album Mod


##################################################################
11/Mar/05 Changes from phpBB2 Plus 1.53 Beta 4 -> Beta 5
----------------------------------------------

- fixed bug in admin_album_config_extended.php (Oxpus)
- fixed bug in functions.php displaying pagination string also if just one page exists (Oxpus)
- removed Portal Welcome Box from News Articles View (now only visible in Main Portal View (Titus)
- added Knowledge Base Mod 0.76 + MX-Addon 1.03e
- added Disable Registrations Mod
- fixed bug in posting_smilies.tpl 
- Signature Editor completely recoded (Oxpus)
- Birthday Mod updated to Version 1.5.7
- fixed bug in album_search function (Oxpus)
- fixed bug in quick_reply.php (Oxpus)
- fixed several Bugs in Pafiledb
- fixed bug in viewforum displaying moderators: none also if moderators exist
- fixed wrong class name in index_box.tpl (White Line in Forum Index with Firefox)
- added lots of missing language variables for ACP Modul Descriptions
- added Page Generation Time Mod (On/Off in Plus Config)
- Portal Poll Table can be disabled in Portal Config if Forum-ID is empty or set to 0 (Titus)
- Last Visit Mod changes to speed up Portal and Index. Now only Registered Users will be displayed in History.
- fixed Shorturl display of Recent Topics Box in Portal if enabled
- fixed lots of Bugs in Knowledgebase Mod (Oxpus)
- added phpBB 2.0.12 Changes
- fixed Anonymous Links in Topics View List, Anonymous User is no more clickable and produces "User does not exist" error.
- Added Template-dependant Rank Images Mod 0.12 (Attention: Rank Images must be copied to template/fisubsilversh/images/lang_english/ranks/. Same for other languages and Templates ! In Rank Administration Rank Image must be set WITHOUT PATH !! Just rank.gif !)
- PHPInfo moved to Admin Module
- fixed gazillions of typos in different english language files (Fah_ww, Reliable)
- fixed Bug in Last Visit Mod caching generating Parse Error with ' in Usernames
- added phpBB 2.0.13 Changes
- fixed Bug in Junior Admin Mod 
- added missing permission control to recent files box in portal (now uses pafiledb authentication) (*speedy*)
- fixed bugs in Color Groups Mod
- fixed Bug in Advanced Links Mod generating Java Error when ' was used in Link Name
- fixed Bug pulling Calendar Data into Private Message Window



##################################################################
09/Feb/05 Changes from phpBB2 Plus 1.53 Beta 3 -> Beta 4
----------------------------------------------

- Album Categories Hierarchie Mod updated to 1.30
- Smartors Photo Album updated to 2.0.53
- Added Links for Portal Index and Portal Preview in Admin Panel Navigation (Reliable)
- replaced some hardcoded language in kontakt.tpl and portal_body.tpl with Language Variables (plasma)
- fixed recent topics date format in portal box, now uses Users Profile Date Format instead of hardcoded one
- replaced hardcoded language in album_showpage.tpl with Language Variable
- added DHTML Slide Menu for ACP Mod 1.0.0 by markus_petrux to keep better overview in Admin Panel ;)
- fixed Bug in News Authentication allowing Moderators to post News also in Forums set to Admin only (Oxpus)
- replaced hardcoded language in shoutbox_body.tpl and posting_body.tpl and added Lang Variables (plasma)


##################################################################
07/Feb/05 Changes from phpBB2 Plus 1.53 Beta 2 -> Beta 3
----------------------------------------------

- fixed missing Variable in Sessions.php, Session IDs cut off for Anonymous Users and Bots works now (Titus)
- removed hardcoded descriptions from admin_user_list_body.tpl and admin_album_clown_SP.php and added Language Variables (Plasma)
- fixed Bug in admin_banner.php (Oxpus)
- fixed cosmetical issue (too much &nbsp;) in viewforum_body.tpl (Reliable)
- added Option in Plus-Config to enable/disable visual confirmation (Reliable)
- Recent Topics Mod updated to current version 1.22
- fixed Date-Format Bug in news.php (Oxpus)


##################################################################
05/Feb/05 Changes from phpBB2 Plus 1.53 Beta 1 -> Beta 2

- fixed missing censored Icon in Shoutbox (Titus)
- fixed missing Shoutbox Prune Option in ACP (Titus)
- added Portal Link to shoutbox_max.php under Recent Topics (Titus)
- fixed Bug in Minical with long Forumnames stretching Portal Box in Firefox (Titus)
- added missing Toptemplates Banner (Reliable)
- fixed missing Link to request new Password in index_body_plus.tpl (Titus)
- added Link to viewonline Page in Live Statistics Box (Titus)
- fixed missing language variable for "Select Layout" in Admin Panel - Plus-Config (zemadz)
- Updated Staff Mod to Version 2.2.3
- fixed session Bug that was caused from last visit caching mod (Oxpus)
- added Images to Photo Album for next/previous Pic Scrolling instead of not visible Arrows (woza)
- fixed MSN Icon in Profile View and Viewtopic (Oxpus)
- fixed wrong Dateformat in news.php, now Dateformat of Userprofile is used in News (Oxpus)
- fixed Pafiledb-Bug in Categories Delete Function (Oxpus)
- fixed Bug in Color Groups Function of Last Visit Mod in Portal and Forum Index not displaying Users in correct colors (Oxpus)



##################################################################
01/Feb/05 Changes from phpBB2 Plus 1.52 -> 1.53 Beta1

- PHP5.x Codechanges
- Added Birthday Mod Caching and Last Visit Mod Caching to Speed Up Portal and Forums Index (remember to CHMOD 777 to /cache Folder!)
- Extreme Styles Mod Updated to Version 2.1.0 Final
- Attachment Mod Updated to Version 2.3.11
- Fixed a Bug in Admin Group Permissions not setting Forum Moderator Status
- Fixed Firefox Compatibility of QuickReply Box Quote Function (Oxpus)
- Fixed Firefox Compatibility of Portal Administration (Oxpus)
- Fixed Firefox Compatibility of Main Portal (hopefully)
- Fixed Firefox Compatibility of Links Mod
- Cosmetical Bug in Minical Fixed
- Removed fixed Smilie Size in posting_body.tpl and posting_smilies.tpl
- Latest Files Box in Portal limited to 23 Characters Filenames now (avoid stretching boxes)
- Fixed Firefox layout Bug in posting_body.tpl
- Applied Changes from phpBB 2.0.11 Code
- Fixed Google Visit Counter to use User Agent instead of IP-Address
- Fixed Bug in news_data.php
- Fixed Bug in Recent Downloads on Portal not displaying Licence File before Download
- Some cosmetic Bugs in portal_body.tpl fixed
- Removed <br> after Welcome Message in Welcome Box
- Added correct Images for Own Topics which are displayed now with a dot in the Folder Image(s)
- Added ShortURLs Mod from Larsneo (shows static .html Links for Forums and Topics / mod_rewrite required !)
- Added Fix message_die for multiple errors MOD
- Maximum Site Description characters now limited to 75 to avoid stretching Portal Box with long descriptions
- FI Divexpand mod replaced with more powerfull Select Expand BBcodes MOD from markus_petrux, now also works with PHP BBcode Mod
```
