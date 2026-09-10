<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_mailbox_journal.php';

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
	// Neither an unfinished write nor its unpublished sent-copy staging row
	// is a downloadable message, including via a previously known attachment ID.
	$where = 'privmsgs_id = ' . $message_ids[0] . ' AND privmsgs_write_payload IS NULL AND privmsgs_type IN ('
		. PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_SENT_MAIL . ','
		. PRIVMSGS_SAVED_IN_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')';
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
	if ($recipient <= 0) { return; }
	phpbb_pm_cleanup_query($database, 'UPDATE ' . USERS_TABLE . ' SET
		user_new_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_write_payload IS NULL AND privmsgs_type = ' . PRIVMSGS_NEW_MAIL . '),
		user_unread_privmsg = (SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_to_userid = ' . $recipient . ' AND privmsgs_write_payload IS NULL AND privmsgs_type = ' . PRIVMSGS_UNREAD_MAIL . ')
		WHERE user_id = ' . $recipient);
}

function phpbb_pm_require_complete_selection($database, $where)
{
	if (phpbb_acl_rows($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE (' . $where . ') AND privmsgs_write_payload IS NOT NULL LIMIT 1'))
	{
		phpbb_acl_error('PM_write_pending');
	}
}

// Attachment-only deletion keeps the message itself. Its authority must remain
// bound to every selected current mailbox parent, not to stale link user IDs.
class PhpbbPmAttachmentDatabase extends PhpbbMailboxDatabase
{
	var $message_ids;
	function __construct($connection, $owner, $folder, $ids)
	{
		$this->message_ids = attach_delete_id_array($ids);
		if (!$this->message_ids) { phpbb_acl_error('PM_journal_changed'); }
		parent::__construct($connection, $owner, $folder, 'owner');
	}
	function authority()
	{
		$guard = parent::authority();
		$guard .= ' AND (SELECT COUNT(*) FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE
			. ' WHERE privmsgs_id IN (' . implode(',', $this->message_ids) . ') AND (' . phpbb_pm_mailbox_condition($this->owner, $this->folder)
			. ') AND privmsgs_write_payload IS NULL) attachment_parents) = ' . count($this->message_ids);
		$check = new PhpbbAclDatabase($this->connection, 'PM_cleanup_failed');
		if (!phpbb_acl_rows($check, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Not_Authorised'); }
		return $guard;
	}
}

function phpbb_pm_delete_attachments($ids, $user_id, $folder)
{
	global $db;
	phpbb_mailbox_post_request();
	$ids = attach_delete_id_array($ids);
	if (!$ids || !phpbb_pm_mailbox_condition($user_id, $folder)) { phpbb_acl_error('PM_journal_changed'); }
	$lock = attach_require_mutation_lock($db);
	try
	{
		$owner = new PhpbbMailboxDatabase($lock->connection, $user_id, $folder, 'owner');
		phpbb_pm_require_complete_selection($owner, 'privmsgs_id IN (' . implode(',', $ids) . ') AND (' . phpbb_pm_mailbox_condition($user_id, $folder) . ')');
		$database = new PhpbbPmAttachmentDatabase($lock->connection, $user_id, $folder, $ids);
		return attach_delete_selected($database, $ids, array(), PAGE_PRIVMSGS, 0, false, true);
	}
	finally { $lock->release(); }
}

// Run before the legacy compose parser even on GET: loading an edit form
// otherwise exposes another message's attachment metadata before the later
// message controller checks ownership. Reply/quote sources are NOT editable
// attachment parents; those forms start a new attachment list.
function phpbb_pm_compose_attachment_source($database, $mode, $message_id)
{
	global $userdata;
	if (!in_array($mode, array('post','reply','quote','edit'), true)
		|| empty($userdata['session_logged_in'])) { phpbb_acl_error('Not_Authorised'); }
	$actor = isset($userdata['user_id']) ? attach_delete_id_array(array($userdata['user_id'])) : false;
	if (!$actor || !phpbb_acl_rows($database, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $actor[0]
		. ' AND user_active <> 0 AND user_allow_pm <> 0')) { phpbb_acl_error('Not_Authorised'); }
	if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') { phpbb_mailbox_post_request(); }
	if ($mode !== 'edit') { return 0; }
	$ids = attach_delete_id_array(array($message_id));
	if (!$ids) { phpbb_acl_error('Not_Authorised'); }
	$rows = phpbb_acl_rows($database, 'SELECT privmsgs_write_payload FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $ids[0]
		. ' AND (' . phpbb_pm_mailbox_condition($actor[0], 'outbox') . ')');
	if (!$rows) { phpbb_acl_error('Not_Authorised'); }
	if ($rows[0]['privmsgs_write_payload'] !== null) { phpbb_acl_error('PM_write_pending'); }
	return $ids[0];
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
		$database = new PhpbbMailboxDatabase($lock->connection, $user_id, 'savebox', 'owner');
		phpbb_mailbox_recover($database);
		$where = '(' . $where . ') AND privmsgs_id IN (' . implode(',', $ids) . ')';
		phpbb_pm_require_complete_selection($database, $where);
		$where .= ' AND privmsgs_write_payload IS NULL';
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
				$excess -= phpbb_mailbox_delete_selected($database, '(' . $save_where . ') AND privmsgs_id = ' . (int) $entry['privmsgs_id']);
			}
		}
		return $moved;
	}
	catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
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
	$where = '((privmsgs_from_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ',' . PRIVMSGS_PENDING_SENT_MAIL . ')) OR (privmsgs_to_userid = ' . $user_id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ',' . PRIVMSGS_PENDING_SENT_MAIL . '))) AND ' . $missing;
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
	global $db;
	$ids = attach_delete_id_array($ids);
	if (!$ids) { return 0; }
	require_once dirname(__FILE__) . '/functions_maintenance_pm.php';
	$spec = phpbb_pm_repair_spec($mode, $now);
	if (!$spec) { return 0; }
	if (!defined('IN_ADMIN') || !IN_ADMIN) { phpbb_acl_error('Not_Authorised'); }
	$lock = new attach_mutation_lock($db);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$database = new PhpbbPmRepairDatabase($lock->connection, 'Maintenance_pm_repair_failed');
		return phpbb_pm_repair_selected($database, $ids, $spec);
	}
	finally { $lock->release(); }
}

// Only never-published sent-copy staging can be discarded automatically.
// A surviving matching read intent protects it, including the interval before
// the copy ID is bound. Correlate the generation and original participants.
function phpbb_pm_abandoned_copy_condition()
{
	$table = PRIVMSGS_TABLE;
	return 'privmsgs_type = ' . PRIVMSGS_PENDING_SENT_MAIL . " AND privmsgs_copy_token IS NOT NULL AND HEX(privmsgs_copy_token) <> ''"
		. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_read_token,privmsgs_read_copy_id,privmsgs_type,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date FROM '
		. $table . ') pending_source WHERE HEX(pending_source.privmsgs_read_token) = HEX(' . $table . '.privmsgs_copy_token)'
		. ' AND pending_source.privmsgs_type IN (' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')'
		. ' AND pending_source.privmsgs_read_copy_id IN (0,' . $table . '.privmsgs_id)'
		. ' AND pending_source.privmsgs_from_userid = ' . $table . '.privmsgs_from_userid'
		. ' AND pending_source.privmsgs_to_userid = ' . $table . '.privmsgs_to_userid'
		. ' AND pending_source.privmsgs_date = ' . $table . '.privmsgs_date)';
}

function phpbb_pm_repair_spec($mode, $now = null)
{
	$table = PRIVMSGS_TABLE; $key = 'privmsgs_id'; $update = '';
	$cutoff = max(0, ($now === null ? time() : (int)$now) - 300);
	switch ($mode)
	{
		case 'missing_text':
			// Sending creates parent and text separately. A recently created
			// parent must not be interpreted as a broken message.
			$where = 'privmsgs_date <= ' . $cutoff . ' AND privmsgs_type <> ' . PRIVMSGS_PENDING_SENT_MAIL . ' AND privmsgs_write_payload IS NULL AND NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' pmt WHERE pmt.privmsgs_text_id = ' . PRIVMSGS_TABLE . '.privmsgs_id)';
			break;
		case 'abandoned_copy':
			$where = phpbb_pm_abandoned_copy_condition();
			break;
		case 'orphan_text':
			$table = PRIVMSGS_TEXT_TABLE; $key = 'privmsgs_text_id';
			$where = 'NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' pm WHERE pm.privmsgs_id = ' . PRIVMSGS_TEXT_TABLE . '.privmsgs_text_id)';
			break;
		case 'invalid_sender':
		case 'invalid_recipient':
			$update = $mode === 'invalid_sender' ? 'privmsgs_from_userid' : 'privmsgs_to_userid';
			$where = $update . ' <> ' . DELETED . ' AND NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . PRIVMSGS_TABLE . '.' . $update . ')';
			break;
		case 'deleted_users':
			$where = '((privmsgs_from_userid = ' . DELETED . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ',' . PRIVMSGS_PENDING_SENT_MAIL . ')) OR (privmsgs_to_userid = ' . DELETED . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ',' . PRIVMSGS_PENDING_SENT_MAIL . ')))';
			break;
		default: return false;
	}
	return array('table'=>$table, 'key'=>$key, 'update'=>$update, 'where'=>$where, 'delete_parent'=>in_array($mode, array('missing_text','deleted_users','abandoned_copy'), true), 'mode'=>$mode, 'cutoff'=>$cutoff);
}

// Internal worker: the caller holds the mutation lock and supplies the
// current-authority connection. Diagnostic IDs alone never authorize a write.
function phpbb_pm_repair_selected($database, $ids, $spec)
{
	$where = '(' . $spec['where'] . ') AND ' . $spec['key'] . ' IN (' . implode(',', $ids) . ')';
	if ($spec['delete_parent'])
	{
		require_once dirname(__FILE__) . '/functions_pm_repair_journal.php';
		return dbmtnc_pm_delete_planned($database, $ids, $spec);
	}
	$sql = $spec['update'] !== '' ? 'UPDATE ' . $spec['table'] . ' SET ' . $spec['update'] . ' = ' . DELETED : 'DELETE FROM ' . $spec['table'];
	phpbb_pm_cleanup_query($database, $sql . ' WHERE ' . $where);
	return (int)$database->sql_affectedrows();
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
		$database = new PhpbbMailboxDatabase($lock->connection, $user_id, $folder, 'owner');
		phpbb_mailbox_recover($database);
		if ($all !== true) { $where = '(' . $where . ') AND privmsgs_id IN (' . implode(',', $ids) . ')'; }
		phpbb_pm_require_complete_selection($database, $where);
		$where = '(' . $where . ') AND privmsgs_write_payload IS NULL';
		return phpbb_mailbox_delete_selected($database, $where);
	}
	catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	finally { $lock->release(); }
}

function phpbb_pm_trim_oldest($user_id, $folder, $limit, $policy = 'owner', $source_id = 0, $published_id = 0)
{
	global $db;
	$where = phpbb_pm_mailbox_condition($user_id, $folder);
	$limit = (int) $limit;
	if ($where === false || $limit <= 0) { return 0; }
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = new PhpbbMailboxDatabase($lock->connection, $user_id, $folder, $policy, $source_id);
		return phpbb_pm_trim_mailbox_locked($database, $limit, $published_id);
	}
	catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	finally { $lock->release(); }
}

function phpbb_pm_trim_mailbox_locked($database, $limit, $published_id = 0, $publication_guard = '1 = 1')
{
	$limit = (int)$limit;
	if ($limit <= 0) { return 0; }
	$ids = $published_id === 0 ? array() : attach_delete_id_array(array($published_id));
	if ($ids === false) { return 0; }
	$where = phpbb_pm_mailbox_condition($database->owner, $database->folder);
	phpbb_mailbox_recover($database);
	if ($ids)
	{
		// Capacity is reclaimed only after a complete copy is in this mailbox.
		// A moved/deleted/half-written new copy must not authorize eviction.
		$ready = phpbb_acl_rows($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE (' . $where . ') AND privmsgs_id = ' . $ids[0]
			. ' AND (' . $publication_guard . ') AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $ids[0] . ')');
		if (!$ready) { return 0; }
	}
	$rows = phpbb_acl_rows($database, 'SELECT COUNT(*) AS total FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where);
	if ((int)$rows[0]['total'] < $limit + ($ids ? 1 : 0)) { return 0; }
	if ($ids)
	{
		$published = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE (' . $where . ') AND privmsgs_id = ' . $ids[0]
			. ' AND (' . $publication_guard . ') AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $ids[0] . ')) quota_publication)';
		$full = '(SELECT COUNT(*) FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where . ') quota_mailbox) > ' . $limit;
		$where = '(' . $where . ') AND privmsgs_id <> ' . $ids[0] . ' AND ' . $published . ' AND ' . $full;
	}
	// An interrupted accepted edit/send retains its recovery payload. A
	// subsequent unrelated delivery must not evict that unfinished operation.
	$where .= ' AND privmsgs_write_payload IS NULL';
	$rows = phpbb_acl_rows($database, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $where . ' ORDER BY privmsgs_date, privmsgs_id LIMIT 1');
	return $rows ? phpbb_mailbox_delete_selected($database, '(' . $where . ') AND privmsgs_id = ' . (int)$rows[0]['privmsgs_id']) : 0;
}

// Called only after header, text and attachment publication returned success.
// Recount instead of adding one after a quota deletion already recounted mail.
function phpbb_pm_finalize_delivery($user_id, $published_id, $limit)
{
	global $db;
	$ids = attach_delete_id_array(array($published_id));
	if (!$ids) { return 0; }
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = new PhpbbMailboxDatabase($lock->connection, $user_id, 'inbox', 'send');
		$ready = phpbb_acl_rows($database, 'SELECT privmsgs_date FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $ids[0]
			. ' AND privmsgs_from_userid = ' . $database->actor . ' AND privmsgs_to_userid = ' . $database->owner
			. ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')'
			. ' AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $ids[0] . ')');
		if (!$ready) { return 0; }
		phpbb_pm_recount_recipient($database, $database->owner);
		$sent_at = max(0,(int)$ready[0]['privmsgs_date']);
		$database->sql_query('UPDATE ' . USERS_TABLE . ' SET user_last_privmsg = CASE WHEN user_last_privmsg IS NULL OR user_last_privmsg < ' . $sent_at
			. ' THEN ' . $sent_at . ' ELSE user_last_privmsg END WHERE user_id = ' . $database->owner);
		return phpbb_pm_trim_mailbox_locked($database, $limit, $ids[0]);
	}
	catch (PhpbbAclException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
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
