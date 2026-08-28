# phpBB2 Plus changelog

## Preserved changes after phpBB2 Plus 1.53a

The preserved history continues from the `phpBB2-Plus-1.53a` tag. The Git
history remains the authoritative record; this section summarizes notable
changes consolidated after that baseline without implying active maintenance.

### phpBB 2 maintenance baseline

- Applied the official phpBB 2.0.22 and 2.0.23 changes, bringing the bundled
  phpBB base to `release-2.0.23`.

### CrackerTracker

- Updated CrackerTracker Professional from 4.1.7 to 5.0.4, including its
  administration, security, logging, language, database, and template changes.
- Updated CrackerTracker from 5.0.4 to 5.0.6.
- Preserved the destructive legacy 4.x database-uninstall script under
  `update/db_uninstall_4x.php` as a reference. It uses the removed `mysql_*`
  API and must not be deployed or executed unchanged.

### Arcade

- Restored the Arcade Mod Plus 2.1.8 framework, administration modules,
  templates, language files, and score protocols without bundled games or
  historical activity data.
- Added an empty MySQL/MariaDB installation script and deployment permissions;
  the Arcade is disabled by default after installation.
- Restored the missing Arcade administration arrows and popup close button
  referenced by the integrated code.
- Restored the Rewards API used by the optional Cash and Allowance integrations.

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
- Preserved historical MOD packages as source where upstream never installed
  them; documented superseded modules and the experimental, unsupported PDO
  driver instead of presenting incomplete installations as active features.
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
- Recorded every source commit and disposition under
  `docs/upstream/production-compatibility/`.

### Repository and deployment maintenance

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
