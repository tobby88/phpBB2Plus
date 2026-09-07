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

// Attachment links are not authorization records: their participant IDs can
// outlive an account, mailbox copy or message. Check the current parent/copy.
function phpbb_pm_attachment_access($database, $message_id, $viewer, $allow_pm_attach)
{
	$message_ids = attach_delete_id_array(array($message_id));
	$viewer_ids = isset($viewer['user_id']) ? attach_delete_id_array(array($viewer['user_id'])) : false;
	if (!$message_ids || !$viewer_ids || empty($viewer['session_logged_in'])) { return false; }
	$is_admin = isset($viewer['user_level']) && $viewer['user_level'] == ADMIN;
	if (!$is_admin && !$allow_pm_attach) { return false; }
	$where = 'privmsgs_id = ' . $message_ids[0];
	if (!$is_admin)
	{
		$mailboxes = array();
		foreach (array('inbox', 'outbox', 'sentbox', 'savebox') as $folder)
		{
			$mailboxes[] = '(' . phpbb_pm_mailbox_condition($viewer_ids[0], $folder) . ')';
		}
		$where .= ' AND (' . implode(' OR ', $mailboxes) . ')';
	}
	$result = phpbb_pm_cleanup_query($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where);
	$row = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	return (bool) $row;
}

function phpbb_pm_cleanup_query($database, $sql)
{
	global $lang;
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, $lang['PM_cleanup_failed']); }
	return $result;
}

function phpbb_pm_recount_recipient($database, $recipient)
{
	$recipient = (int) $recipient;
	phpbb_pm_cleanup_query($database, 'UPDATE ' . USERS_TABLE . ' SET
		user_new_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_type = ' . PRIVMSGS_NEW_MAIL . '),
		user_unread_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_type = ' . PRIVMSGS_UNREAD_MAIL . ')
		WHERE user_id = ' . $recipient);
}

function phpbb_pm_save_messages($ids, $user_id, $folder, $limit)
{
	global $db, $lang;
	$ids = attach_delete_id_array($ids);
	$where = phpbb_pm_mailbox_condition($user_id, $folder);
	if (!$ids || $where === false || !in_array($folder, array('inbox', 'sentbox'), true)) { return 0; }
	$limit = (int) $limit;
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = $lock->connection;
		$where = '(' . $where . ') AND privmsgs_id IN (' . implode(',', $ids) . ')';
		$result = phpbb_pm_cleanup_query($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where);
		$selected = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
		if (!$selected) { return 0; }
		if ($limit > 0 && count($selected) > $limit) { message_die(GENERAL_MESSAGE, $lang['PM_save_limit_exceeded']); }
		$selected_ids = array();
		foreach ($selected as $entry) { $selected_ids[] = (int) $entry['privmsgs_id']; }
		$where .= ' AND privmsgs_id IN (' . implode(',', $selected_ids) . ')';
		$save_where = phpbb_pm_mailbox_condition($user_id, 'savebox');
		// Snapshot only pre-existing archive rows. Never evict a newly saved
		// message, even if its original date is older than the current archive.
		$result = phpbb_pm_cleanup_query($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $save_where . ' ORDER BY privmsgs_date, privmsgs_id');
		$old = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
		$type = $folder === 'inbox' ? PRIVMSGS_SAVED_IN_MAIL : PRIVMSGS_SAVED_OUT_MAIL;
		phpbb_pm_cleanup_query($database, 'UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_type = ' . $type . ' WHERE ' . $where);
		$moved = (int) $database->sql_affectedrows();
		if (!$moved) { return 0; }
		phpbb_pm_recount_recipient($database, $user_id);
		// Eviction follows successful movement, not an untrusted selection or
		// stale form. On SQL failure completed moves remain; no fake rollback.
		if ($limit > 0)
		{
			$result = phpbb_pm_cleanup_query($database, 'SELECT COUNT(*) AS total FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $save_where);
			$row = $database->sql_fetchrow($result); $database->sql_freeresult($result);
			$excess = max(0, (int) $row['total'] - $limit);
			foreach ($old as $entry)
			{
				if (!$excess) { break; }
				$excess -= phpbb_pm_delete_selected($database, '(' . $save_where . ') AND privmsgs_id = ' . (int) $entry['privmsgs_id']);
			}
		}
		return $moved;
	}
	finally { $lock->release(); }
}

function phpbb_pm_require_admin_module($module)
{
	global $userdata, $lang;
	$allowed = defined('IN_ADMIN') && IN_ADMIN && !empty($userdata['session_logged_in'])
		&& !empty($userdata['session_admin']) && isset($userdata['user_id']) && (int) $userdata['user_id'] > 0
		&& in_array($module, array('admin_users.php', 'admin_db_maintenance.php', 'admin_account.php'), true);
	if ($allowed)
	{
		$allowed = isset($userdata['user_level']) && $userdata['user_level'] == ADMIN;
		if (!$allowed && function_exists('jr_admin_check_file_hashes')) { $allowed = jr_admin_check_file_hashes($module); }
	}
	// A skipped cleanup is not a successful account removal/repair. Abort the
	// caller before its later writes if the required capability is unavailable.
	if (!$allowed) { message_die(GENERAL_ERROR, $lang['Not_Authorised']); }
}

// Authorized ACP account removal retains the existing from/to deletion
// policy, but also cleans shared attachment references and recipient counters.
function phpbb_pm_delete_user_messages($user_id)
{
	global $db, $userdata;
	$ids = attach_delete_id_array(array($user_id));
	if (!$ids) { return 0; }
	phpbb_pm_require_admin_module('admin_users.php');
	$lock = attach_require_mutation_lock($db);
	try { return phpbb_pm_delete_selected($lock->connection, '(privmsgs_from_userid = ' . $ids[0] . ' OR privmsgs_to_userid = ' . $ids[0] . ')'); }
	finally { $lock->release(); }
}

// Standalone ADMIN pruning calls this only after its POST/session validation
// and successful account DELETE. Preserve other users' delivered/saved copies.
function phpbb_pm_prune_user_messages($user_id)
{
	global $db, $userdata;
	$ids = attach_delete_id_array(array($user_id));
	if (!$ids || empty($userdata['session_logged_in']) || !isset($userdata['user_level']) || $userdata['user_level'] != ADMIN) { return 0; }
	return phpbb_pm_remove_deleted_user_messages($ids[0]);
}

function phpbb_pm_delete_inactive_user_messages($user_id)
{
	phpbb_pm_require_admin_module('admin_account.php');
	return phpbb_pm_remove_deleted_user_messages($user_id);
}

// Internal worker shared by authorized account-removal paths. Existing or
// restored user rows are protected in every write, including copy anonymization.
function phpbb_pm_remove_deleted_user_messages($user_id)
{
	global $db;
	$ids = attach_delete_id_array(array($user_id));
	if (!$ids) { return 0; }
	$user_id = $ids[0];
	$missing = 'NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . $user_id . ')';
	// Pending mail belongs to both outbox and inbox. Include UNREAD as well as
	// NEW, consistently with the existing deleted-user maintenance policy.
	$where = '((privmsgs_from_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ')) OR (privmsgs_to_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . '))) AND ' . $missing;
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = $lock->connection;
		$deleted = phpbb_pm_delete_selected($database, $where);
		foreach (array('privmsgs_from_userid', 'privmsgs_to_userid') as $field)
		{
			phpbb_pm_cleanup_query($database, 'UPDATE ' . PRIVMSGS_TABLE . ' SET ' . $field . ' = ' . DELETED . ' WHERE ' . $field . ' = ' . $user_id . ' AND ' . $missing);
		}
		return $deleted;
	}
	finally { $lock->release(); }
}

// Authorized ACP repair. IDs from a diagnostic snapshot are not sufficient:
// recheck the defect in the modifying statement on the guarded session.
function phpbb_pm_repair_messages($ids, $mode, $now = null)
{
	global $db, $userdata;
	$ids = attach_delete_id_array($ids);
	if (!$ids) { return 0; }
	phpbb_pm_require_admin_module('admin_db_maintenance.php');
	$table = PRIVMSGS_TABLE; $key = 'privmsgs_id'; $update = '';
	switch ($mode)
	{
		case 'missing_text':
			// Sending creates parent and text separately. A recently created
			// parent must not be interpreted as a broken message.
			$where = 'privmsgs_date <= ' . (($now === null ? time() : (int) $now) - 300) . ' AND NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' pmt WHERE pmt.privmsgs_text_id = ' . PRIVMSGS_TABLE . '.privmsgs_id)';
			break;
		case 'orphan_text':
			$table = PRIVMSGS_TEXT_TABLE; $key = 'privmsgs_text_id';
			$where = 'NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' pm WHERE pm.privmsgs_id = ' . PRIVMSGS_TEXT_TABLE . '.privmsgs_text_id)';
			break;
		case 'invalid_sender':
		case 'invalid_recipient':
			$update = $mode === 'invalid_sender' ? 'privmsgs_from_userid' : 'privmsgs_to_userid';
			$where = 'NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . PRIVMSGS_TABLE . '.' . $update . ')';
			break;
		case 'deleted_users':
			$where = '((privmsgs_from_userid = ' . DELETED . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ')) OR (privmsgs_to_userid = ' . DELETED . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')))';
			break;
		default: return 0;
	}
	$where = '(' . $where . ') AND ' . $key . ' IN (' . implode(',', $ids) . ')';
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = $lock->connection;
		if ($mode === 'missing_text' || $mode === 'deleted_users') { return phpbb_pm_delete_selected($database, $where); }
		$sql = $update !== '' ? 'UPDATE ' . $table . ' SET ' . $update . ' = ' . DELETED : 'DELETE FROM ' . $table;
		phpbb_pm_cleanup_query($database, $sql . ' WHERE ' . $where);
		return (int) $database->sql_affectedrows();
	}
	finally { $lock->release(); }
}

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
		phpbb_pm_recount_recipient($database, $recipient);
	}
	if ($deleted) { attach_delete_selected($database, $deleted, array(), PAGE_PRIVMSGS, 0, false, true); }
	return count($deleted);
}
