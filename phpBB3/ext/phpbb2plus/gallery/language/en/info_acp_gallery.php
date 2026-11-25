<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_PERSONAL_ALBUMS' => 'Personal photo albums',
	'LOG_PLUS_PERSONAL_ALBUMS' => '<strong>Changed the personal photo album settings</strong>',
	'PLUS_ALBUM_CFG_EXPLAIN' => 'You can assign permission to create a personal album to individual members and groups. Selecting album administrators also restricts further uploads to personal albums to those administrators. Default visibility applies to newly created albums. For quotas, −1 means unlimited and 0 prevents further pictures or subalbums.',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY' => 'Who can create a personal album',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY_VIEW' => 'Default visibility for new personal albums',
	'PLUS_ALBUM_CFG_PERSONAL_GALLERY_LIMIT' => 'Maximum pictures per member across their albums',
	'PLUS_ALBUM_CFG_PERSONAL_ALLOW_GALLERY_MOD' => 'Members may moderate their album',
	'PLUS_ALBUM_CFG_PERSONAL_ALLOW_SUB_CATEGORIES' => 'Members may manage their subalbums',
	'PLUS_ALBUM_CFG_PERSONAL_SUB_CATEGORY_LIMIT' => 'Maximum subalbums per member',
	'PLUS_ALBUM_CFG_SHOW_PERSONAL_GALLERY_LINK' => 'Link to accessible albums from member profiles',
	'PLUS_ALBUM_CFG_GUESTS' => 'All visitors', 'PLUS_ALBUM_CFG_MEMBERS' => 'Registered members',
	'PLUS_ALBUM_CFG_SELECTED' => 'Members with the user permission',
	'PLUS_ALBUM_CFG_PRIVATE' => 'Private', 'PLUS_ALBUM_CFG_MODS' => 'Album moderators', 'PLUS_ALBUM_CFG_ADMINS' => 'Album administrators',
	'PLUS_ALBUM_CFG_YES' => 'Yes', 'PLUS_ALBUM_CFG_NO' => 'No', 'PLUS_ALBUM_CFG_IMPORTED' => 'Imported value (%s)',
	'PLUS_ALBUM_CONFIG_INVALID' => 'Check “%s”. Choose one of the listed policies or enter a whole number between −1 and 2147483647 for a quota.',
	'PLUS_ALBUM_CONFIG_CHANGED' => 'The album settings have changed. Review the current values before saving.',
));
