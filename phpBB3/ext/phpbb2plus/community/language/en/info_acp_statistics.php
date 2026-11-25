<?php
if (!defined('IN_PHPBB')) { exit; }
$lang = array_merge($lang ?? array(), array(
	'ACP_PLUS_STATS_MANAGE' => 'Manage statistics modules', 'ACP_PLUS_STATS_SETTINGS' => 'Configure statistics',
	'PLUS_STATS_NAME' => 'Report', 'PLUS_STATS_ADD' => 'Add report', 'PLUS_STATS_AUDIENCE' => 'Visible to',
	'PLUS_STATS_EVERYONE' => 'All visitors', 'PLUS_STATS_MEMBERS_ONLY' => 'Registered members', 'PLUS_STATS_ADMINS_ONLY' => 'Authorised administrators',
	'PLUS_STATS_UNMAPPED' => 'Unrecognised historical access level', 'PLUS_STATS_STATE_0' => 'Uninstalled',
	'PLUS_STATS_STATE_1' => 'Installed and inactive', 'PLUS_STATS_STATE_2' => 'Active', 'PLUS_STATS_POSITION' => 'Position on the statistics page',
	'PLUS_STATS_SOURCE' => 'Original settings', 'PLUS_STATS_ARCHIVED' => 'Historical registration', 'PLUS_STATS_NONE' => 'No reports are registered yet.',
	'PLUS_STATS_SOURCE_RECORD' => 'No. %1$d · %2$s · Position %3$d · Installed %4$d · Active %5$d · Access level %6$d · Historical refresh interval %7$d seconds',
	'PLUS_STATS_SOURCE_DIR' => 'Historical module directory', 'PLUS_STATS_LIMIT' => 'Maximum entries per ranking',
	'PLUS_STATS_STORAGE_TTL' => 'Seconds to cache database and avatar sizes (0 measures them on each visit)',
	'PLUS_STATS_CLEAR' => 'Measure storage sizes again on the next visit',
	'PLUS_STATS_CHANGED' => 'The statistics changed while this form was open. Please reopen it.',
	'ACL_A_PLUS_STATS_MODULES' => 'Can manage statistics modules, their order and their audiences',
	'ACL_A_PLUS_STATS_SETTINGS' => 'Can manage general board statistics settings',
	'LOG_PLUS_STATS_SETTINGS' => '<strong>Updated statistics settings</strong>', 'LOG_PLUS_STATS_MODULE' => '<strong>Updated statistics module</strong><br>» %s',
));
