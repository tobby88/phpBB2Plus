<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_pm_mailbox_condition($user_id, $folder)
{
	$user_id = (int) $user_id;
	if ($user_id <= 0) { return false; }
	switch ($folder)
	{
		case 'inbox': return 'privmsgs_to_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')';
		case 'outbox': return 'privmsgs_from_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')';
		case 'sentbox': return 'privmsgs_from_userid = ' . $user_id . ' AND privmsgs_type = ' . PRIVMSGS_SENT_MAIL;
		case 'savebox': return '((privmsgs_from_userid = ' . $user_id . ' AND privmsgs_type = ' . PRIVMSGS_SAVED_OUT_MAIL . ') OR (privmsgs_to_userid = ' . $user_id . ' AND privmsgs_type = ' . PRIVMSGS_SAVED_IN_MAIL . '))';
	}
	return false;
}

function phpbb_pm_cleanup_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['PM_cleanup_failed']); }
	return $result;
}

// Internal helper: callers authorize explicit deletion or the existing mailbox
// capacity policy. Never accept a caller-supplied SQL predicate.
function phpbb_pm_delete_messages($ids, $user_id, $folder, $all = false)
{
	global $db;
	$ids = attach_delete_id_array($ids);
	$where = phpbb_pm_mailbox_condition($user_id, $folder);
	if ($ids === false || $where === false || (!$ids && $all !== true)) { return 0; }
	$lock = attach_require_mutation_lock($db);
	try
	{
		if ($all !== true) { $where = '(' . $where . ') AND privmsgs_id IN (' . implode(',', $ids) . ')'; }
		return phpbb_pm_delete_selected($lock->connection, $where);
	}
	finally { $lock->release(); }
}

function phpbb_pm_trim_oldest($user_id, $folder, $limit)
{
	global $db;
	$where = phpbb_pm_mailbox_condition($user_id, $folder);
	$limit = (int) $limit;
	if ($where === false || $limit <= 0) { return 0; }
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = $lock->connection;
		$result = phpbb_pm_cleanup_query($database, 'SELECT COUNT(*) AS total FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where);
		$row = $database->sql_fetchrow($result); $database->sql_freeresult($result);
		if ((int) $row['total'] < $limit) { return 0; }
		// Deterministic tie-breaker; do not remove every message with the same date.
		$result = phpbb_pm_cleanup_query($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where . ' ORDER BY privmsgs_date, privmsgs_id LIMIT 1');
		$row = $database->sql_fetchrow($result); $database->sql_freeresult($result);
		return $row ? phpbb_pm_delete_selected($database, '(' . $where . ') AND privmsgs_id = ' . (int) $row['privmsgs_id']) : 0;
	}
	finally { $lock->release(); }
}

// Internal: all SQL below uses the attachment mutation lock's owning session.
function phpbb_pm_delete_selected($database, $where)
{
	$result = phpbb_pm_cleanup_query($database, 'SELECT privmsgs_id, privmsgs_to_userid FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where);
	$rows = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
	$deleted = array(); $recipients = array();
	foreach ($rows as $row)
	{
		$id = (int) $row['privmsgs_id'];
		// A message may have moved since selection. Only remove its text/files
		// if this exact owner/mailbox-qualified parent deletion actually happened.
		phpbb_pm_cleanup_query($database, 'DELETE FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id . ' AND (' . $where . ')');
		if ((int) $database->sql_affectedrows() !== 1) { continue; }
		$deleted[] = $id; $recipients[(int) $row['privmsgs_to_userid']] = (int) $row['privmsgs_to_userid'];
		phpbb_pm_cleanup_query($database, 'DELETE FROM ' . PRIVMSGS_TEXT_TABLE . ' WHERE privmsgs_text_id = ' . $id);
	}
	foreach ($recipients as $recipient)
	{
		// Recount rather than subtract stale counters or create negative values.
		phpbb_pm_cleanup_query($database, 'UPDATE ' . USERS_TABLE . ' SET
			user_new_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_type = ' . PRIVMSGS_NEW_MAIL . '),
			user_unread_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_type = ' . PRIVMSGS_UNREAD_MAIL . ')
			WHERE user_id = ' . $recipient);
	}
	if ($deleted) { attach_delete_selected($database, $deleted, array(), PAGE_PRIVMSGS, 0, false, true); }
	return count($deleted);
}
