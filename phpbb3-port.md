# phpBB3 conversion coverage

The source for this conversion is the current UTF-8 Plus distribution in `phpBB2/`. The installer in the assembled web root offers **phpBB2 Plus (UTF-8)** as a separate converter. Always use a new destination database and an offline copy of both the source database and its media. Keep that copy intact until counts, permissions, representative files and application behaviour have been checked.

| Source behaviour and data | phpBB3 destination | Verification and remaining work |
| --- | --- | --- |
| Users, logins and language preferences | Native users, groups and password manager | Browser fixture covers MD5 and bcrypt, special characters, an incorrect password, native rehash and the anonymous-ID collision. |
| Posts, BBCode, author and edit history | Native posts and text formatter, member-only retained bylines | Fixture covers Unicode, native bold, a zero-only post, soft deletion, editor ID and separate guest/member bylines. Quoted attributions, moderator edit text and the Plus placeholder still need dedicated coverage. |
| Forums, categories, topics and moderation | Native forum tree, ACLs, topics and MCP | Fixture checks a category nested in a forum, a link forum inside that category and a restricted subforum visible to the administrator. Private groups, personal grants, approval state and shadows need additional coverage. |
| PMs, polls, subscriptions and bookmarks | Native PM boxes, voting, watches and bookmarks | Duplicate and orphan bookmarks, topic and user IDs have fixtures. Exercise PM folders, attachment references, individual votes and watches separately. |
| Files, avatars, ranks and smilies | Native attachments, avatar manager, ranks and smilies | Upstream handles basic phpBB2 assets. Shared attachments, PM ownership, quotas and missing files need reconciliation against the source. |
| Profile fields, contact services, member colours, ranks, flags and delegated administration | Native profile fields, groups and permission roles where equivalent | Preserve member-only fields, values and visibility. Review special grants individually. |
| FI Subsilver Shadow, German and English | Inherited phpBB3 style using the original generic Plus graphics | Check the portal, board and topic pages and both language stylesheets. Additional visual comparison is pending. |
| Portal, news, navigation, banners, links, calendar, birthdays and statistics | Community extension using native topics, permissions and profile data | The root portal lists recent readable topics. Remaining portal blocks, ordering and their settings need conversion. |
| Shoutbox and moderation | Community extension | Preserve history, censored messages, posting, paging and moderation. |
| Smartor Album, special processing and Nuffload | Gallery extension using phpBB storage and permissions | Categories, personal albums, originals, previews, comments, votes, approvals and uploads require end-to-end fixtures. |
| paFileDB download area | Downloads extension | Categories, files, licences, mirrors, per-group access, custom fields, votes, comments and download metadata. |
| Knowledge Base | Knowledge Base extension | Article and category hierarchy, types, approval, ratings and permitted search. Rebuild derived search data. |
| Arcade, tournaments, Ruffle and rewards | Arcade extension | Game metadata and files supplied by the board, scores, favourites, saves, scoring rules, tournaments and compatible submissions. Game content stays outside the distribution. |
| CrackerTracker, captcha, user warnings and action logs | Native flood control, login limiting, bans, captcha, warning and log APIs where equivalent | Map durable policies and historical logs. Review remaining extension-only functions and differences in behaviour. |
| Search, cleanup and database maintenance | Native search backends, ACP maintenance and migrations | Rebuild search for the converted board, verify parity and avoid replaying sessions, tokens or in-flight maintenance jobs. |
| Plus credits, configuration and legacy URLs | Community extension and phpBB config | Preserve functioning entry points, credit the integrated modules and identify obsolete settings individually. |
