# phpBB2 Plus changelog

## Preserved changes after phpBB2 Plus 1.53a

The preserved history continues from the `phpBB2-Plus-1.53a` tag. The Git
history remains the authoritative record; this section summarizes notable
changes consolidated after that baseline without implying active maintenance.

### Security and runtime hardening

- Hardened the Top Smilies statistics module against malformed stored smile
  codes and asset names, escaped its HTML output, constrained its selected
  style path and removed negative-offset warnings for empty/single-item data.
  Its per-post counting mode now lets the database return a count instead of
  transferring every matching post body to PHP.
- Rebuilt the public Album search around a fixed mode-to-column allowlist and
  database-driver escaping. Queries are bounded, inaccessible personal or
  restricted categories are filtered through the normal Album permissions,
  orphaned rows are hidden, result text is escaped, and the previously
  hard-coded English/invalid form output now follows the selected language.
- Escaped stored quote names and Google-search labels again at BBCode render
  time, so malformed legacy rows cannot turn decoded entities into markup.
  The Smilie Creator's generated image URL now validates its color and shadow
  parameters, encodes its UTF-8 query safely and no longer reads an undefined
  option when a post uses the default settings.
- Changed the distributed runtime from always-on debug mode to a secure
  production default. SQL text, database diagnostics and source locations are
  now visible only to an authenticated administrator who deliberately enables
  local debugging; query profiling is disabled with debug mode, recursive
  error handling no longer bypasses the restriction, and session creation no
  longer scans and deletes obsolete `last*.dat` cache files on every request.
- Stopped CrackerTracker's default footer from reading several complete log
  files on every page view. Its cumulative counter now uses a bounded cache
  invalidated on each security event, log-size checks stream lines instead of
  loading whole files, and the log directory is denied over HTTP even to local
  proxy requests.
- Replaced password-reset e-mails containing temporary plaintext passwords
  with expiring, one-use links on which users choose their own password. The
  reset is consumed atomically, enforces the configured CrackerTracker expiry,
  revokes existing sessions and auto-login keys, and keeps the public request
  response account-enumeration resistant. Already-issued legacy activation
  links retain their historical compatibility path.
- Replaced legacy `dss_rand()`/MD5 generation for visual-confirmation IDs,
  CAPTCHA text, Arcade play capabilities and private upload filenames with
  128-bit cryptographic identifiers and unbiased random strings. Existing
  database and URL field lengths remain unchanged. Auto-login rotations and
  BBCode identifiers now also use the shared operating-system CSPRNG directly;
  BBCode creation no longer reseeds PHP's process-wide legacy PRNG.
- Removed legacy `phpinfo()` scraping from public GD detection and replaced
  the AdminCP's full configuration/request dump with a concise escaped runtime
  summary. Useful version, limit and extension diagnostics remain available
  without exposing environment, request or cookie contents.
- Prevented raw attachment, Album, avatar and paFileDB storage URLs from
  rendering active HTML, SVG, JavaScript, CSS, Flash or PDF documents inside
  the forum origin. Authorized PHP download paths remain available, while
  directly served bitmap assets now explicitly disable MIME sniffing.
- Denied direct web delivery of database configuration, PHP include sources,
  raw templates, style configuration, SQL, logs, caches and common backup
  suffixes using Apache 2.2/2.4-compatible root rules, and disabled directory
  listings. Runtime includes and browser assets remain unaffected.
- Added a shared decoded-image pixel and dimension budget before GD opens
  avatars, attachments, Album uploads, Nuffload resizes or generated Album
  thumbnails. Small compressed raster files can no longer force unbounded
  memory allocation, and corrupt legacy Album images fall back to the standard
  placeholder instead of emitting warnings or calling an invalid decoder.
- Centralized validation of To, Cc, Bcc, From and Reply-To mailboxes for both
  PHP `mail()` and SMTP delivery. Invalid or control-character-bearing values
  are discarded before header construction, duplicate carbon-copy recipients
  are collapsed before sending, non-scalar template substitutions are
  rejected, and Message-IDs use the shared cryptographic random source.
- Rebuilt the Arcade category-moderator screen around its working score
  controls. It no longer sends player IP addresses to an obsolete third-party
  lookup site, advertises unimplemented score/game editing actions, or trusts
  stored game metadata as HTML and asset paths; score queries are confined to
  the moderator's authorized category and the German/English interface no
  longer presents the original alpha placeholder layout.
- Replaced four hand-built HTTP gzip responses and the database-backup
  compressor with `gzencode()`. The old code advertised gzip while embedding
  a zlib stream, which made standards-compliant clients reject affected
  responses; compressed responses now also vary explicitly by
  `Accept-Encoding`. Compression negotiation is now independent of the user
  agent, and speaking-URL rewriting can no longer consume and emit the output
  buffer before its advertised gzip encoding is applied. Header and footer
  also share compression state when phpBB renders them from `message_die()`,
  so disabled/error flows no longer advertise gzip around plain HTML.
- Normalized every configured account and board language through one strict
  installed-pack allowlist before extensions can construct language-file
  paths. Empty legacy account values now fall back deterministically, and a
  repaired preference updates only that account instead of interpolating the
  corrupt old value into a broad SQL update.
- Confined paFileDB's separate legacy template engine to installed style
  directories and relative `.tpl` files. Its executable template directives
  remain disabled, cache keys can no longer create selected paths, cache
  directories are not created world-writable, and compiled templates are
  published with a complete atomic write instead of being included while a
  concurrent request may still be writing them.
- Bounded SMTP response reads, validated every envelope address, and escaped
  remote server diagnostics before HTML rendering. SMTP DATA is now correctly
  dot-stuffed as one payload, preventing a user-authored line containing only
  a period from ending the message early and turning following text into SMTP
  protocol commands.
- Deferred StopForumSpam lookups until a registration has passed every local
  check, disabled redirects from its fixed HTTPS API endpoint, bounded and
  validated query values and responses, and made third-party outages fail
  open by default instead of disabling registration. Administrators who
  prefer strict availability coupling can explicitly select fail-closed mode;
  the new option is included in fresh schemas and idempotent upgrades.
- Removed CrackerTracker's obsolete factory blocklist of 32 spoofable
  User-Agent strings, which included legitimate archival and scripting
  clients. Fresh installations now start with an empty administrator-managed
  blocklist; the updater and ACP cleanup preserve every custom IP, CIDR,
  hostname and User-Agent rule. Blocklist IDs are allocated atomically by the
  database, and invalid legacy selector values no longer trigger PHP warnings.
- Added canonical runtime defaults for every active CrackerTracker setting.
  A missing row in an incomplete legacy upgrade no longer emits warnings or
  silently disables protection, and saving the ACP form repairs absent rows
  through a whitelisted upsert. Fresh installs no longer start with a
  decades-old scan timestamp or the sample global message “Hello world!”.
- Gave CrackerTracker login-history events a stable auto-incrementing identity
  and a matching per-user time index. Retention and display order are now
  deterministic even when several logins share one timestamp, so the ACP
  history limit remains an actual bound instead of allowing same-second rows
  to accumulate indefinitely; existing installations are migrated in place.
- Escaped reverse-DNS results before rendering the Shoutbox moderator IP
  view. A hostname supplied by external DNS can no longer become trusted HTML
  merely because a moderator requested the lookup.
- Removed CrackerTracker's two obsolete per-user search counters. Search
  protection already uses the shared atomic rate-limit table for guests and
  members, so fresh schemas no longer create parallel state and the updater
  drops the unused legacy columns.
- Added a shared, spoof-resistant HTTPS detector for secure cookies, absolute
  board URLs and response policy. Direct TLS responses now advertise a
  one-year HSTS policy without claiming unrelated subdomains or preload
  eligibility; untrusted forwarding headers cannot enable secure-request
  state.
- Moved CrackerTracker's internal-mail cooldown from a user-table deadline to
  the shared per-account rate-limit store. Merely opening a mail form or
  submitting invalid data no longer blocks it, and the cooldown starts only
  after the primary message was sent successfully. Profile mail and
  tell-a-friend continue to share the same bounded window, retain phpBB's
  short flood check, and report the precise remaining wait; the updater drops
  the obsolete `ct_last_mail` column.
- Replaced CrackerTracker's destructive posting-burst response with a bounded
  per-account rate limit. Rapid legitimate posting is no longer treated as
  proof of abuse and can never automatically ban or deactivate an account;
  excess attempts receive HTTP 429 and a precise retry delay. The ACP now
  exposes a clear on/off switch, old ban/deactivate mode values normalize to
  enabled, and the updater removes the obsolete user counters and unused spam
  log-size setting.
- Removed the remaining dead code and database columns for CrackerTracker's
  former account-wide CAPTCHA lock. The login path had already stopped
  setting or honoring that flag because any unauthenticated visitor could
  impose it on another account. Failed attempts are now handled solely by the
  bounded IP and IP/account-pair limiters; the obsolete public unlock route,
  template and class methods are gone, and upgraded databases drop their two
  unused per-user columns.
- Replaced CrackerTracker's board-global registration locks with a rolling
  cooldown keyed only by the web server's verified client IP. The old design
  allowed one completed signup to block every visitor temporarily and could
  leave a shared IP blocked until a different address registered. Only a
  fully completed signup now starts the cooldown; invalid form submissions do
  not. The obsolete IP Watcher setting and its global database state are
  removed by the idempotent updater, while the separate central hourly
  request limit continues to contain automated registration bursts.
- Separated CrackerTracker's password-age timestamp from its password-reset
  request cooldown. The original shared field could block reset requests for
  days and then mark a newly reset password as expired after only minutes.
  All public and administrator password-write paths now maintain the proper
  change timestamp, and the idempotent updater repairs legacy rows once. The
  public reset route now also uses the database driver's escaping and bounded
  cooldown values instead of its ineffective legacy quote replacement. Reset
  responses no longer disclose whether an account exists, is inactive or is
  currently throttled. Newly issued activation keys retain their full 128-bit
  entropy regardless of the board URL length, and temporary passwords use all
  64 random bits while previously issued shorter keys remain accepted.
- Modernized public contact profiles. Retired ICQ, AIM, MSN, Yahoo Messenger
  and Skype links plus the non-contact Pinterest field are no longer offered
  or rendered, while their historic database values remain preserved. Signal
  usernames/share links and Threema IDs now have first-class schema and update
  support; every retained service has format-specific guidance and accepts
  only links to its own trusted hosts. Missing IntegraMOD icon files were
  replaced with accessible text links, and empty services no longer leave
  blank rows in profile views.
- Reorganized the AdminCP navigation into a stable task-oriented order with
  core board administration first and integrated feature areas afterwards.
  The historically separate eXtreme Styles, attachment-extension, custom
  profile-field, PHP-information and Plus-configuration menus are now
  presented inside their corresponding Styles, Attachments, Users and General
  sections; original module hashes remain intact for existing Junior Admin
  permissions.
- Consolidated the legacy Arcade configuration and game-management entries
  into one AdminCP category. Building the navigation no longer queries Arcade
  configuration tables, avoiding menu-time database warnings and keeping all
  Spielhalle controls together.
- Clarified that the Banner MOD's positions 16 through 19 populate the portal's
  left-hand link box, including rotation when a position contains several
  active entries. German and English AdminCP labels now make those portal
  links discoverable, and malformed URL validation in the separate Links MOD
  no longer emits a regular-expression warning.
- Made apex-domain and `www` access coexist without weakening host checks.
  Origin, CrackerTracker, Arcade and media/download Referer validation now
  recognize only those exact counterparts, while the old Album substring
  comparison was replaced with parsed host matching. Deployment guidance now
  documents shared parent-domain cookies and the one-time cookie-name change
  needed to retire conflicting browser state without manual cache clearing.
- Initialized poll form state on every posting path and retained Calendar,
  announcement, news and post-icon fields in edit-submit lookups. Editing a
  poll-less post no longer reads absent rows or an obsolete `edit_vote` flag
  under PHP 8.
- Replaced the AJAX editor's obsolete Latin-1 `escape()` transport with
  single-pass UTF-8 form encoding, reject invalid UTF-8 before storage, keep
  malformed response bytes from blanking the AJAX result, and carry unsaved
  quick-edit text into the full editor.
- Removed the six incomplete alternative style packages and the experimental
  automatic mobile-style selection. FI Subsilver Shadow is now the only
  bundled style; fresh installs seed only it, while the idempotent updater
  migrates the board default and all member preferences before deleting stale
  theme records. Legacy template fallbacks now consistently target the
  remaining style.
- Synchronized legacy Album, Arcade, shoutbox and personal-group display-name
  snapshots whenever an account is renamed. Public and ACP renames now use one
  path, identify personal groups by membership instead of a colliding name,
  clear affected caches, and the post-1.53a updater reconciles existing rows
  wherever a stable user ID is available.
- Added the missing stable user ID to monthly Arcade highscores. Fresh score
  writes retain it, score and point views prefer the authoritative account
  name, and the updater backfills unambiguous historical owners before
  normalizing their old name snapshots. A conservative exact game-and-score
  fallback also recovers owners on installations whose all-time name snapshot
  had already been normalized; ambiguous matches remain untouched.
- Localized the Arcade point ranking in German and English and replaced its
  inaccurate hardcoded three-month notice with the actual calculation period
  (current and previous calendar month). The shared eXtreme Styles fallback
  templates now expose only translated labels and explanatory text.
- Removed the empty Arcade ACP "Moderators" page and its unused templates. The
  working moderator-mode and moderator-action switches remain available on
  the existing Arcade switches page.
- Hardened and localized Arcade bulk settings. Values must now be explicit
  non-negative integers within the destination column's supported range, and
  success, failure, buttons and formerly hardcoded field labels use the active
  ACP language instead of emitting ad-hoc English HTML.
- Localized Arcade game-import choices and private-message settings, removed
  obsolete external support instructions and repaired malformed table markup
  in both ACP fallback templates.
- Centralized custom profile-field parsing for registration and administrator
  user management. Dynamic columns now have a strict identifier boundary,
  values obey their configured type, choices and length, and database writes
  use the driver escape API; ACP field labels, choices, descriptions and saved
  values are escaped before form rendering. Public profile, member-list and
  topic rendering also normalizes both legacy raw text and entity-encoded
  values to one safe HTML representation.
- Replaced the remaining Magic-Quotes-era SQL substitutions in public and ACP
  account creation and profile updates with the active database driver's
  escaping API. Apostrophes in legitimate profile data therefore work on
  modern PHP without weakening the query boundary.
- Normalized legacy entity-encoded and raw profile values before redisplaying
  public or administrator account forms, including the avatar-gallery hidden
  state. Stored text can no longer escape an input attribute or textarea when
  an old account is edited, and password fields are no longer reflected after
  a validation error.
- Initialized profile zodiac output for accounts without a birthday or without
  a matching localized image. Profile views now render an empty optional field
  instead of emitting PHP 8 undefined-variable or missing-image warnings.
- Brought the separate ACP quick-add user assistant under the same runtime
  boundaries: only installed languages and styles are accepted, timezone and
  date-format input is bounded, and rejected form values are safely reflected.
- Extended the central CrackerTracker request boundary to reject unsupported
  HTTP methods and structurally abusive cookie or upload metadata. Normal GET,
  POST, HEAD and legacy upload/cookie shapes remain accepted; uploaded file
  contents and ordinary free text are not subjected to brittle word matching.
- Hardened the shared Junior Admin/ACP discovery layer with initialized module
  state, safe directory failures and validated language fallbacks. Numeric user
  lookups and administrator notes now have explicit SQL/HTML boundaries, while
  an unused legacy configuration helper with a raw-SQL API was removed.
- Made ColorGroups ignore pending memberships and choose the highest-priority
  confirmed group without relying on MySQL's nondeterministic non-aggregate
  `GROUP BY` behaviour. Empty records are PHP-8-safe, renamed users invalidate
  cached display names immediately, and other stale color data is bounded to
  five minutes instead of a full day.
- Escaped administrator-supplied Arcade game, category and tournament metadata
  across score, category, statistics and jump-list views. Stored game names can
  no longer alter score-list SQL, sort order is explicitly allowlisted, and
  external link categories are revalidated when followed.
- Made player-statistics views read-only by removing the surprising automatic
  private message sent merely by opening another member's scores. The Arcade
  welcome now receives an escaped username from PHP instead of parsing a
  translated login label through inline JavaScript.
- Removed the now-unreferenced automatic-statistics-PM throttle and its table
  from fresh-install schemas. Existing installations may retain the harmless
  legacy table; update code deliberately performs no destructive table drop.
  Existing category and game image references are constrained to local assets
  and escaped before HTML output.
- Extended opener isolation to PHP-generated automatic BBCode links, Album
  index thumbnails and external forms, and taught the regression audit to cover
  escaped PHP fragments and form targets in addition to literal anchors.
- Bounded the shared Arcade request/log boundary, rejected non-finite numeric
  protocol values, supplied stable empty-score defaults, made media dimension
  probing fall back to configured sizes and corrected the escaped search term
  used by result counts.
- Completed the image map of partial bundled styles at runtime from the
  validated preservation style. Calendar, birthday, profile, rank, Album and
  CrackerTracker icons therefore remain available on responsive styles without
  PHP 8 undefined-key warnings. paFileDB search caching also uses an explicit
  PHP 8-compatible field map and its optional comment label has a translation
  fallback.
- Routed paFileDB recommendation mail through the configured board sender with
  a validated Reply-To address, bounded its user-controlled fields and placed
  the endpoint under CrackerTracker's tighter hourly mail/account throttle.
- Escaped stored paFileDB descriptions and URLs when redisplaying edit forms,
  made stale download/category references fail before permission lookups, and
  normalized and bounded moderator batch and sorting inputs.
- Made the post-1.53a updater's dry run reflect existing defaults, bundled
  styles and Arcade name snapshots before queuing work, while retaining
  existing administrator settings and cleaning seed-statement output.
- Isolated all links which open a new browser window, including generated
  Album and attachment fragments, from the originating page to prevent legacy
  reverse-tabnabbing through linked external content.
- Reworked the legacy AJAX controller around one typed request boundary and an
  explicit operation allowlist. Inline edits now enforce forum edit rights,
  private poll/topic/forum data cannot leak through alternate AJAX views,
  mutations and previews require body-bound session tokens, member suggestions
  are escaped and bounded, and previously undefined poll/PM-preview variables
  no longer cause PHP 8 failures.
- Completed the forum/category editor in all seven bundled styles. Every ACP
  style now exposes icons, link-forum controls, post counting and hierarchy
  fields; submitted text uses typed scalar boundaries and driver escaping,
  stored values are HTML-escaped, and unsafe resource URL schemes are rejected.
- Removed the final PHP-8-incompatible `each()` and legacy MySQL escape calls
  from historical update paths. The advanced CAPTCHA now remains usable with
  GD but no TrueType fonts, and custom or damaged time-zone offsets render a
  stable GMT label instead of triggering missing-language-key warnings.
- Restored coherent responsive-style handling. Installed public Bootstrap
  styles are selected automatically for mobile clients, footer controls let
  visitors retain automatic, mobile or desktop display without changing their
  account style, and private/missing styles are excluded. Fresh installations
  and the idempotent updater now provide the `theme_public` column expected by
  eXtreme Styles; the long-broken positional default-theme seed was replaced
  by an explicit, schema-stable column list.
- Removed 91 unnecessary cross-style asset dependencies from public and ACP
  templates. Styles now use their own available icons, spacer images, editor
  scripts and stylesheets; only assets genuinely absent from the current style
  retain the established shared fallback. This also fixes the broken historic
  `fisubsilver` path typo.
- Centralized typed decoding for all remaining serialized legacy state. Session
  and cache payloads now always return arrays, topic/forum tracking cookies are
  restricted to 150 numeric identifiers and sane timestamps, and paFileDB
  option lists accept scalar values only. Damaged Arcade tournament rows are
  skipped field by field rather than reaching PHP 8 `count()`, offset or
  arithmetic errors.
- Repaired legacy helper pages around the Smilie creator, Quick Reply, credits
  and recent topics. Smilie assets are enumerated by their real file names,
  missing GD/assets fail deliberately instead of producing PHP 8 fatals,
  generated images disable sniffing and caching, Quick Reply safely embeds the
  quoted post, and malformed tracking cookies or paging configuration no longer
  cause warnings or division-by-zero failures.
- Repaired and hardened attachment and paFileDB delivery. Attachment thumbnails
  retain their protected thumbnail subdirectory, post attachments require view,
  read and download permission together, stale post references are ignored, and
  binary responses disable MIME sniffing and private caching. paFileDB routing
  no longer accepts cookie-merged actions, and its mirror chooser now terminates
  after rendering instead of falling through into an undefined download. Rule
  labels are escaped at their final HTML boundary.
- Consolidated the user-facing mail boundary. Email template names and language
  directories are centrally allowlisted, board mail now uses the configured
  board sender with the member address only as Reply-To for better DMARC
  compatibility, subjects/messages are bounded, absence text is escaped and
  tell-a-friend cannot smuggle extra recipients through a display name or link
  to an unexpected port. Contact-field filtering also remains safe for invalid
  byte sequences instead of relying on successful UTF-8 regular expressions.
- Hardened the complete account lifecycle across sessions, activation, profile
  updates and password changes. Invalid serialized cookies and nested session
  identifiers can no longer reach PHP 8 string/hash operations, new sessions
  use direct operating-system randomness, activation keys use constant-time
  comparison, and account-changing names as well as passwords/emails require
  the current password. Submitted languages, styles, time zones and date
  formats are restricted to usable installed values; profile links use the
  validated board origin and the long-broken time-zone fallback now assigns
  rather than merely compares the default value.
- Clarified the Arcade's current and all-time scoreboard links. Games whose
  complete score lists are identical now show one concise `Bestenliste` link;
  differing lists are labelled as current and all-time lists, without the
  unexplained legacy separator.
- Made Arcade winner names follow the authoritative user account instead of a
  stale all-time-score snapshot, while retaining a safe fallback for deleted
  accounts and applying profile-visibility rules consistently. New scores and
  the idempotent upgrade path also reconcile the historical snapshot column.
- Hardened Calendar dates, paging and typed forum/category filters, fixing a
  scheduler bug that discarded the filter prefix before validation. Calendar
  option labels are escaped and the birthday popup now rejects anonymous or
  invalid direct requests instead of producing runtime warnings.
- Hardened member, staff, topic-viewer and online directories against array
  request values, unbounded offsets and stale session references. The topic
  viewer now enforces the source forum's view/read permissions; the staff
  profile no longer loses signatures when no censor words exist, divides by
  zero in empty boards, or renders its page tail twice.
- Completed scalar session-token and POST-method checks on remaining account,
  Album, Arcade and administration mutations, preventing malformed array
  requests from reaching `hash_equals()` on PHP 8.x.
- Normalized and bounded topic, forum, news archive and hierarchy-navigation
  parameters at the central browsing entry points. Invalid day-range filters,
  nested parameters and stale navigation targets now degrade safely instead of
  causing PHP 8.x warnings/500 responses or oversized database offsets.
- Hardened print, plain-text export and RSS alternatives: print view now checks
  both forum visibility and read access, export parameters and filenames stay
  on strict scalar/header boundaries, and the RSS feed emits its correct media
  type with XML-safe metadata and CDATA termination handling.
- Hardened secondary Arcade pages for ratings, comments and tournaments.
  Malformed tokens/offsets and nested tournament fields are rejected, join
  batches are deduplicated and bounded, and comment edit/delete confirmation
  pages now terminate cleanly instead of rendering a second page afterwards.
- Normalized Shoutbox and Knowledge Base listing offsets and identifiers,
  repaired the compact Shoutbox pagination URL, escaped its displayed account
  names, and supplied safe Knowledge Base sort/pagination defaults for damaged
  or incomplete legacy configuration.
- Completed a further PHP 8.x request-boundary pass over administration lists,
  statistics, mass mail, permissions, acronyms, ranks and word filters so nested
  request values cannot produce conversion warnings or unexpected identifiers.
- Corrected the banner redirect's PHP 8 regular expression, retained strict
  HTTP(S)-only destinations and moved click IP/identity/duration values onto
  explicit database and integer boundaries.
- Hardened paFileDB search requests, author matching and cached-result reuse.
  Search IDs, cached file IDs, sort fields, paging and dictionaries are now
  validated and bounded; serialized state and session/author values use the
  active database driver. Comment matches are correctly combined with file
  matches, cache records carry their module identity and expiry time, and the
  result query no longer multiplies rating votes by comments.
- Rebuilt the Knowledge Base search boundary for current PHP and database
  drivers. Scalar and bounded requests, installed-language dictionaries,
  escaped terms/session/cache data, validated cached article IDs and approved-
  article filtering replace the legacy raw SQL paths. Multibyte search now
  uses the real article-table constant, result counts work for more than one
  match, and cached-result pagination retains its search mode.
- Hardened forum search input, SQL and cached-result boundaries. Request
  values, modes, paging and result sizes are bounded; author, full-text,
  word-index and multibyte searches use database-driver escaping; dictionary
  paths use installed languages; cached IDs and options are validated before
  reuse; and highlight terms are quoted before becoming regular expressions.
  Removing bookmarks from search results is now POST-only, session-token
  protected and restricted to unique positive topic IDs.
- Hardened personal Album category administration with allowlisted modes,
  installed-language paths, normalized permission values, owner-bound move and
  edit targets, bounded category text and database-driver escaping. Root
  personal-category names can no longer be changed by bypassing the readonly
  browser field, empty category sets receive a safe ordering baseline, and
  Hot-or-Not rating IPs now use the active database driver.
- Hardened the legacy Arcade score protocols, including GET-capable Flash
  callbacks, with a shared strict same-origin check that remains compatible
  with older clients lacking browser metadata. IBPro logging no longer calls
  MySQLi behind the active database driver, its score challenge uses the
  compatibility-safe random source and validates challenge/score bounds.
  Arcade-generated private messages now use driver escaping, bounded inbox
  configuration, real recipient names and allowlisted installed languages.
- Hardened Album uploads from staging through persistence. The storage path is
  now bound to the owning Nuffload session, actual server-side file sizes and
  image signatures determine acceptance and extensions instead of client MIME
  claims, limits are clamped, manual thumbnails are verified before storage,
  generated thumbnails handle allocation/write failures cleanly, and all
  metadata uses database-driver escaping. Files are removed again when their
  database insert fails, preventing untracked uploads from accumulating.
- Hardened compact and full-page Shoutbox writes with bounded message,
  flood-control, pruning and pagination values and driver-escaped normalized
  message/IP data. Full-page moderation now accepts only scalar positive shout
  IDs, handles missing records before reading their fields, and keeps reverse-
  DNS lookups restricted to validated encoded IPs. The IP detail view no longer
  reads an undefined post-row variable, and a zero posts-per-page setting can
  no longer break Shoutbox pagination or SQL limits.
- Hardened public link submissions and their administrator notifications.
  Link, URL, logo and IP values now use database-driver escaping; URL fields
  reject embedded credentials and overlong values instead of silently storing
  truncated destinations. Only active administrators are notified, language
  directory names are allowlisted, notification PM content is escaped and the
  submitting member is recorded as its sender. A failed link insert can no
  longer be overwritten by a misleading success response.
- Hardened the warning/report-card endpoint with an explicit POST-only,
  timing-safe session check, exact action selection and scalar target IDs.
  Missing posts and users now fail before record fields are read, direct user
  mode can no longer be confused with a post report, report notification
  intervals are clamped away from division by zero, moderator recipients are
  active confirmed members without duplicates, and stored language names are
  allowlisted before use in mail-template paths. Temporary account blocks now
  use the installed `block_time` setting instead of the nonexistent legacy
  `RY_block_time` key.
- Hardened posting, editing, deletion and non-AJAX poll voting with a shared
  POST-only, timing-safe session guard. Text, subjects, guest names, topic
  descriptions and poll content are now escaped by the active database driver
  at their storage boundary instead of by partial apostrophe replacement.
  Poll option normalization now updates the actual option list, portal and
  AJAX-rendered ballots carry the session ID, repeated votes no longer increase
  the result after a competing voter row was inserted, and negative
  announcement durations are corrected by assignment rather than a no-op
  comparison.
- Hardened private-message send, edit, save and delete operations with a shared
  POST-only, timing-safe session guard and database-driver escaping. Editing is
  now limited to messages that have not been delivered, including the related
  text row. Opening an already-read message no longer reuses an undefined sent-
  copy ID or attempts to duplicate its attachments a second time.
- Consolidated public group membership and moderation writes behind one
  POST-only, timing-safe session guard. Joining now verifies the open group
  independently of its existing members, works for empty groups, avoids
  duplicate pending rows and duplicate moderator notifications, while manual
  member lookup uses database-driver escaping and missing records fail cleanly.
- Bound the legacy topic-watch and bookmark fallback links to their session,
  action and topic. The normal AJAX controls remain POST-only and now return
  equally protected fallback URLs, while forged GET links can no longer add or
  remove a user's subscriptions or bookmarks.
- Protected the legacy one-click topic moderation links with session-, action-
  and topic-bound HMAC capabilities. Lock/unlock and normal/sticky/announcement
  controls retain their existing non-JavaScript fallback, but a copied or
  cross-site GET URL can no longer change topic state; AJAX responses also
  preserve the protected fallback link.
- Hardened the public Album edit and delete endpoints with scalar positive
  identifiers, explicit POST-only session-token checks and database-driver
  escaping. Missing picture records are now rejected before their fields are
  read, authorized category moderators can manage pictures without also being
  global administrators, and comment edits remain bound to the picture that
  was verified during authorization. New comments and ratings use the same
  request and database boundaries; missing pictures fail before dereferencing,
  guest comments no longer read a nonexistent post field, and both members and
  guests are prevented from rating the same picture repeatedly (guests are
  identified by their stored session IP).
- Removed obsolete magic-quotes emulation from Nuffload and paFileDB instead
  of reprocessing request globals already normalized by `common.php`. The
  Attachment MOD no longer calls removed driver-specific `mysql_*`/PostgreSQL
  escaping functions or maintains a hand-written fallback; it delegates all
  SQL text escaping to the active database driver. The now-unused compatibility
  shims were deleted and CI prevents these removed APIs from returning.
- Replaced the remaining removed POSIX-regex APIs (`ereg*`, `split*`) with
  native PCRE/string operations across downloads, attachments, SMTP, page
  dates, Album archives/hierarchy, export filenames, Knowledge Base,
  paFileDB and MiniCal. The broad regex compatibility shims were removed, date
  parsing now has a safe fallback, multibyte searches split whitespace without
  empty terms and generated export filenames remain bounded safe basenames.
- Removed the final runtime and installer dependencies on PHP's removed
  `each()` API, including authorization, bookmarks, Album/paFileDB smilies,
  attachment input normalization, Knowledge Base and paFileDB search, private
  message counters, groups, registration, signatures, MiniCal and upgrade
  routines. The compatibility polyfill is no longer needed. Knowledge Base
  AND-search now intersects real article IDs instead of undefined post IDs,
  and both Knowledge Base indexing/search and paFileDB search escape terms at
  their SQL boundaries.
- Removed another set of PHP 8 failure paths from language, MOD-settings,
  database-maintenance, Album and avatar-gallery directory scans by checking
  directory handles before iteration. User and AdminCP avatar galleries no
  longer depend on the removed `each()` function, close nested handles, accept
  only real image files and handle empty galleries. Gallery category/file and
  custom-profile values are escaped and array-checked before being carried
  through hidden form fields.
- Hardened Knowledge Base configuration with the central session-bound
  AdminCP token and an explicit editable-setting list. Boolean, sort, paging
  and referenced user/forum/group values are normalized before driver-escaped
  updates; stored preface text and forum/group names are escaped in the form,
  and pagination can no longer be configured to zero.
- Reworked MOD/Credits administration around exact actions, validated record
  identifiers, bounded scalar fields, database-driver escaping and the central
  session-bound AdminCP token. Stored names, descriptions, e-mail addresses
  and HTTP(S) links are now rendered safely, new entries receive their real
  creation time, and invalid edit records no longer produce PHP warnings.
  Legacy `.hl` metadata scanning also tolerates unreadable directories/files
  on PHP 8 and escapes imported metadata at the database boundary.
- Hardened the extended MOD settings module with the central session-bound
  AdminCP POST token, scalar and allowlisted form values, database-driver
  escaping and UTF-8-safe output escaping. Invalid menu indices and missing or
  unreadable settings directories are now handled without PHP 8 warnings or
  type errors.
- Hardened Album thumbnail-cache cleanup with the central session-bound
  AdminCP POST token, a verified cache directory and end-anchored image
  extensions. It now ignores links and non-files, handles unreadable cache
  directories without PHP 8 type errors, and the cancel action actually leaves
  the confirmation page.
- Hardened Arcade tournament administration with allowlisted modes, scalar and
  bounded settings, verified tournament/game records and the central
  session-bound AdminCP POST token. Creation now enforces the configured number
  of concurrent tournaments, game assignment enforces the per-tournament
  maximum without duplicating entries, and the configured SQL sort direction
  is reduced to `ASC` or `DESC`. Tournament, category and game text is escaped
  consistently in administration output.
- Hardened Arcade category hierarchy management. Category actions and IDs use
  strict modes and validated records; ordering swaps only an adjacent category
  on the same hierarchy level. Deleting or demoting a parent promotes its
  children instead of orphaning them, link categories accept only HTTP(S)
  destinations, icons remain relative local assets, public-group restrictions
  are validated and are now actually persisted for newly created categories.
  Deletion also refreshes the global game totals, and malformed category table
  markup was corrected in both shipped styles.
- Repaired and consolidated Arcade cache, error-log and score maintenance. The
  cache form now submits to its real action; log and score mutations use the
  central session-bound AdminCP token; pagination, modes, identifiers and score
  values are normalized. Score edits are bound to the displayed player and
  refresh highscore state, while the empty, unregistered Arcade ban stub was
  removed as dead code.
- Hardened Arcade game editing, importing, ordering and maintenance. Every
  write and confirmation now carries and verifies the central AdminCP session
  token; IDs, pagination, import paths, categories and form values are bounded
  before use. Reordering verifies its source record, imports remain inside the
  forum tree, duplicate game names are rejected, and renaming or deleting a
  game now updates or removes dependent scores, favourites, comments, ratings,
  sessions, tournament assignments and cached last-game references instead of
  leaving inconsistent records behind.
- Consolidated Album settings on the tabbed AdminCP configuration and removed
  the redundant legacy settings module and templates. Album configuration
  writes now require the central session-bound POST token, accept only scalar
  values exposed by the submitted tab, use database-driver escaping and render
  stored values through UTF-8-safe HTML escaping. Invalid tab and table
  selections can no longer steer configuration updates.
- Hardened Arcade configuration with allowlisted pages, scalar and type-aware
  setting normalization, safe relative asset directories, driver-escaped
  database writes and escaped AdminCP text. Configuration changes now require
  the central session-bound POST token, automatic reward-field corrections no
  longer mutate state during a page view, and the misspelled template marker
  that previously discarded hidden fields on the main page is repaired.
- Hardened Album category administration. Creation, editing and deletion now
  use allowlisted POST modes and the central AdminCP session token; IDs,
  parents and permission levels are validated; text uses driver escaping and
  HTML output escaping; and the removed PHP 8 `each()` iterator is gone.
  Moving a category below one of its descendants is rejected, deletion cannot
  move pictures into that subtree, and child categories are reliably moved to
  the deleted category's parent instead of retaining an orphaned parent ID.
- Hardened paFileDB category permissions and configuration. Both AdminCP forms
  now require the central session-bound POST token; category IDs and access
  levels are checked against real categories and strict allowlists, while
  configuration writes are limited to the settings actually exposed by the
  module. Numeric, boolean and enum values are normalized, storage paths reject
  traversal and absolute/URL targets, displayed values are escaped, and the
  central paFileDB configuration writer now uses database-driver escaping.
- Reworked paFileDB user, group and global permission administration around
  validated user/public-group targets and fixed boolean permission maps. The
  PHP 8-removed `each()` paths and ambiguous merged request input are gone,
  writes require the AdminCP session token, irrelevant zero rows are removed,
  names are escaped in output, and moderator lookups are cached independently
  per group instead of leaking the first result to every subsequent group.
- Hardened paFileDB file and mirror administration. Modes, IDs, paging and
  sorting are normalized; writes use session-bound POST actions and database-
  driver escaping; category, file and mirror relationships are verified; and
  stored download and mirror text is escaped in AdminCP forms. Mirror updates
  can no longer cross download boundaries, while deletion now removes an
  associated uploaded mirror only after its database row is deleted and only
  through the storage-root-constrained unlink helper.
- Hardened paFileDB license administration and repaired its file checker.
  License actions, identifiers and form text are normalized, all writes use
  the central AdminCP POST/session check, missing edit targets are handled and
  stored names/text are escaped. The read-only checker now follows the actual
  configured upload and screenshot directories, rejects traversing paths,
  counts mirror files as references, escapes disk names at SQL/HTML boundaries
  and reports orphaned screenshots as well as downloads.
- Hardened attachment configuration, extension management and the attachment
  control panel with session-bound AdminCP POST checks. Attachment
  synchronization is no longer a state-changing GET request and now requires
  explicit confirmation. Shadow deletion and control-panel identifiers are
  normalized, extension-group permission forms carry the session token, and
  attachment, extension, forum and topic text is escaped in administration
  output.
- Hardened forum, group and user/group permission administration. All rights
  and group mutations now require the centralized AdminCP POST/session token;
  user, group, forum and permission values are normalized against real targets
  and allowed values. Crafted group deletion can no longer target personal
  one-user groups, group text uses database-driver escaping and HTML output
  escaping, and PHP 8-incompatible `each()` permission loops were replaced.
  Empty membership and missing permission records no longer generate invalid
  `IN ()` queries or undefined-array warnings.
- Hardened Junior Admin delegation and user lookup. Permission changes now use
  the centralized AdminCP POST/session check, accept only module hashes from
  the installed module list, normalize user, sorting, paging and color-group
  identifiers, and escape notes and searches at the database boundary. The
  user and color-group lists are escaped for HTML, malformed colors cannot
  inject style markup, and users without a prior Junior Admin record no longer
  trigger PHP 8 array warnings.
- Hardened general board configuration writes with session-bound POST checks,
  scalar-only values, database-driver escaping and the actual 255-character
  schema bound. Displayed configuration values and forum names are escaped
  consistently, server schemes are normalized, and the writable avatar path
  can no longer traverse outside the forum directory. CrackerTracker's
  misconfiguration checks and pre-change recovery snapshot remain integrated.
- Protected user bulk actions, inactive-account deletion and ban management
  with the centralized AdminCP POST/session check. Bulk activation, blocking
  and group assignment can no longer target administrator accounts. Ban input
  is scalar-normalized, IPv4 ranges are validated and capped before expansion,
  wildcard email bans cannot catch the protected first administrator, and
  selected unban IDs are normalized before SQL. This also removes an undefined
  warning-reset accumulator, empty `IN ()` queries and unescaped values in ban
  selection lists.
- Hardened custom-profile-field administration and account pruning. Profile
  field writes now carry their mode and final target in the session-bound POST
  form, dynamic column identifiers and option values are strictly bounded,
  missing edit records are handled without PHP 8 warnings, and the success
  page no longer references an undefined variable. Link-category session
  fields use the same centralized mechanism. Account-pruning day values are
  scalar-normalized and bounded before SQL or HTML use, displayed usernames
  are escaped, and the deletion worker normalizes request values and database
  identifiers at their boundaries.
- Modernized acronym, word-censor, rank and smiley administration. All writes
  now use session-bound POST requests, confirmed deletes take their target
  only from the confirmation form, request modes and scalar identifiers are
  normalized, and database text uses the driver escaping boundary. Missing
  edit records no longer trigger PHP 8 array warnings. Acronym data is stored
  as text and escaped when rendered, while legacy entity-encoded entries stay
  readable and can no longer inject markup through acronym tooltips.
- Repaired banner administration on PHP 8 by replacing removed `each()` and
  `ereg()` calls. Saves now take scalar values only from POST, scheduling and
  modes are allowlisted/bounded, SQL strings use the driver escape boundary,
  and deletion uses the confirmed POST target. Normal image, text and legacy
  Flash banner output is HTML-escaped; the explicitly selected custom-code
  banner type remains raw by design. Redirects reject credentials, control
  characters and backslashes in addition to non-HTTP(S) destinations.
- Hardened news-category administration with allowlisted modes, strict
  positive identifiers and centralized session-token fields. Confirmed
  deletion takes its target only from POST, category labels are length-bounded
  and escaped, and selected icons must still resolve to an image discovered in
  the configured style directory.
- Protected link creation, editing and deletion with session-bound POST forms.
  Mutation modes and actions are allowlisted, write values no longer fall back
  to query-string input, category references are verified, SQL values use the
  database escaping boundary, and per-row edit/delete URLs no longer splice a
  raw session ID into template data.
- Hardened the built-in database backup and restore utility. Backup options
  can no longer be replayed through GET URLs, both data export and SQL restore
  require a session-bound POST, table names must resolve to real database
  tables, and the normal backup set is limited to the configured forum prefix.
  Restore now uses PHP's current upload API, verifies a genuine upload and
  accepts only the utility's `.sql` and `.sql.gz` formats.
  MySQL/MariaDB schema exports now use `SHOW CREATE TABLE`, preserving modern
  utf8mb4, engine, index and default definitions and avoiding the PHP-8-removed
  `each()` path; exported identifiers and values are safely quoted.
- Removed dormant raw-request debug output from the ColorGroups AdminCP module.
  Its user-color cache is now invalidated only after an actual administrative
  write instead of on every page view, while all write controls continue to
  require a session-bound POST token.
- Hardened the DB Maintenance AdminCP module: function and numeric inputs now
  use strict allowlists/scalar parsing, database-changing actions always show a
  confirmation and require a session-bound POST token, and the multi-request
  search-index workflow uses signed, session-bound continuation URLs. Direct
  GET execution of ordinary maintenance operations is rejected.
- Protected forum/category creation, editing, deletion, ordering and forum
  synchronization with AdminCP POST/session tokens. Ordering and resync links
  are now in-form POST buttons, movement values are allowlisted, and category
  and forum identifiers are scalar-normalized before use.
- Retired phpBB2's duplicate built-in style manager and its unused AdminCP
  templates. Old direct bookmarks now lead to the integrated eXtreme Styles
  manager, whose replacement menu is always registered; this removes the dead
  executable `theme_info.cfg` importer and legacy GET write paths entirely.
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
