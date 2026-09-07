<?php
if (!defined('IN_PHPBB')) { exit; }

// Internal post-delete cleanup. Callers must authorize the operation and verify
// a successful user DELETE first. This does not authorize account deletion.
// Keep credentials first so a later ancillary-table failure cannot leave them.
function phpbb_cleanup_removed_user_references($database, $user_id)
{
	global $lang;
	phpbb_require_removed_user($database, $user_id);
	$targets = array(
		SESSIONS_KEYS_TABLE => 'user_id',
		SESSIONS_TABLE => 'session_user_id',
		JR_ADMIN_TABLE => 'user_id',
		TOPICS_WATCH_TABLE => 'user_id',
		BOOKMARK_TABLE => 'user_id',
		BANLIST_TABLE => 'ban_userid'
	);
	foreach ($targets as $table => $column)
	{
		// Recheck absence in every write, not just the initial diagnostic read.
		$sql = 'DELETE FROM ' . $table . ' WHERE ' . $column . ' = ' . $user_id
			. ' AND NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . $user_id . ')';
		if (!$database->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['User_reference_cleanup_failed']);
		}
	}
	phpbb_require_removed_user($database, $user_id);
}

function phpbb_require_removed_user($database, $user_id)
{
	global $lang;
	if (!is_int($user_id) || $user_id <= 0)
	{
		message_die(GENERAL_ERROR, $lang['User_reference_cleanup_failed']);
	}
	$result = $database->sql_query('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $user_id);
	if (!$result) { message_die(GENERAL_ERROR, $lang['User_reference_cleanup_failed']); }
	$exists = $database->sql_fetchrow($result);
	$database->sql_freeresult($result);
	if ($exists) { message_die(GENERAL_ERROR, $lang['User_reference_cleanup_failed']); }
}
