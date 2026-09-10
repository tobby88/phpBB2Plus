<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_privmsgs.php';
require_once dirname(__FILE__) . '/php_compat.php';

// The source row is the durable read intent. Its token is written in the same
// statement as READ, and the copy has a unique nullable token of its own.
// Neither MyISAM nor lost SQL acknowledgements require a simulated rollback.
class PhpbbPmReadDatabase extends PhpbbAclDatabase
{
	var $actor; var $affected = 0;
	function __construct($connection)
	{
		global $userdata;
		parent::__construct($connection, 'PM_cleanup_failed');
		$this->actor = isset($userdata['user_id']) ? (int)$userdata['user_id'] : 0;
		$this->authority();
	}
	function authority()
	{
		global $userdata;
		if ($this->actor <= 0 || empty($userdata['session_logged_in']) || !isset($userdata['user_id'])
			|| (int)$userdata['user_id'] !== $this->actor) { phpbb_acl_error('Not_Authorised'); }
		$guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_active FROM ' . USERS_TABLE
			. ' WHERE user_id = ' . $this->actor . ') pm_reader WHERE user_active <> 0)';
		$rows = parent::sql_query('SELECT 1 AS allowed WHERE ' . $guard);
		$allowed = $this->connection->sql_fetchrow($rows); $this->connection->sql_freeresult($rows);
		if (!$allowed) { phpbb_acl_error('Not_Authorised'); }
		return $guard;
	}
	function sql_query($sql, $transaction = false)
	{
		$guard = $this->authority();
		if (preg_match('/^\s*(UPDATE|DELETE FROM)\b/i', $sql))
		{
			if (!preg_match('/\bWHERE\b/i', $sql)) { phpbb_acl_error('PM_cleanup_failed'); }
			$sql .= ' AND (' . $guard . ')';
		}
		elseif (!preg_match('/^\s*SELECT\b/i', $sql)) { phpbb_acl_error('PM_cleanup_failed'); }
		$result = parent::sql_query($sql, $transaction);
		$this->affected = (int)$this->connection->sql_affectedrows();
		$this->authority(); return $result;
	}
	function sql_affectedrows() { return $this->affected; }
	function insert_select($table, $columns, $select, $from, $where)
	{
		$guard = $this->authority();
		parent::sql_query('INSERT INTO ' . $table . ' (' . $columns . ') SELECT ' . $select . ' FROM ' . $from
			. ' WHERE (' . $where . ') AND (' . $guard . ')');
		$this->authority();
	}
}

function phpbb_pm_read_source($db, $id, $folder)
{
	$condition = $folder === 'inbox' ? 'p.privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ')'
		: 'p.privmsgs_type = ' . PRIVMSGS_SAVED_IN_MAIL;
	$rows = phpbb_acl_rows($db, 'SELECT p.*,t.privmsgs_text,t.privmsgs_bbcode_uid FROM ' . PRIVMSGS_TABLE . ' p,' . PRIVMSGS_TEXT_TABLE
		. ' t WHERE p.privmsgs_id = ' . (int)$id . ' AND p.privmsgs_to_userid = ' . $db->actor
		. ' AND t.privmsgs_text_id = p.privmsgs_id AND p.privmsgs_write_payload IS NULL AND ' . $condition);
	return $rows ? $rows[0] : false;
}

function phpbb_pm_read_key($p)
{
	if (!isset($p['privmsgs_read_token']) || !is_string($p['privmsgs_read_token'])
		|| !preg_match('/^[a-f0-9]{32}$/D', $p['privmsgs_read_token'])) { phpbb_acl_error('PM_journal_changed'); }
	return 'privmsgs_id = ' . (int)$p['privmsgs_id'] . " AND HEX(privmsgs_read_token) = HEX('" . $p['privmsgs_read_token'] . "')"
		. ' AND privmsgs_to_userid = ' . (int)$p['privmsgs_to_userid'] . ' AND privmsgs_from_userid = ' . (int)$p['privmsgs_from_userid']
		. ' AND privmsgs_date = ' . (int)$p['privmsgs_date'] . ' AND privmsgs_read_copy_id = ' . (int)$p['privmsgs_read_copy_id']
		. ' AND privmsgs_write_payload IS NULL AND privmsgs_type IN (' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')';
}

function phpbb_pm_read_guard($p)
{
	return 'EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . phpbb_pm_read_key($p) . ') read_source)';
}

function phpbb_pm_read_copy_key($p, $copy)
{
	return 'privmsgs_id = ' . (int)$copy['privmsgs_id'] . " AND HEX(privmsgs_copy_token) = HEX('" . $p['privmsgs_read_token'] . "')"
		. ' AND privmsgs_from_userid = ' . (int)$p['privmsgs_from_userid'] . ' AND privmsgs_to_userid = ' . (int)$p['privmsgs_to_userid']
		. ' AND privmsgs_date = ' . (int)$p['privmsgs_date'];
}

function phpbb_pm_read_finish($db, $p)
{
	// A cleared intent is not recreated for a legacy/already read message. In
	// particular, deleting a published copy must not cause it to reappear.
	$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . " SET privmsgs_read_token = '',privmsgs_read_copy_id = 0 WHERE " . phpbb_pm_read_key($p));
	if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
}

function phpbb_pm_read_complete($db, $p, $limit)
{
	$source = (int)$p['privmsgs_id']; $token = $p['privmsgs_read_token'];
	$guard = phpbb_pm_read_guard($p);
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
	$copies = phpbb_acl_rows($db, 'SELECT * FROM ' . PRIVMSGS_TABLE . " WHERE HEX(privmsgs_copy_token) = HEX('" . $token . "')");
	if ((int)$p['privmsgs_read_copy_id'] > 0 && (!$copies || (int)$copies[0]['privmsgs_id'] !== (int)$p['privmsgs_read_copy_id']))
	{
		phpbb_pm_read_finish($db, $p); return;
	}
	if (!$copies)
	{
		$fields = array('privmsgs_subject','privmsgs_from_userid','privmsgs_to_userid','privmsgs_date','privmsgs_ip',
			'privmsgs_enable_html','privmsgs_enable_bbcode','privmsgs_enable_smilies','privmsgs_attach_sig');
		$values = array(); foreach ($fields as $field) { $values[] = 'p.' . $field; }
		$db->insert_select(PRIVMSGS_TABLE, 'privmsgs_type,privmsgs_copy_token,' . implode(',', $fields),
			PRIVMSGS_PENDING_SENT_MAIL . ",'" . $token . "'," . implode(',', $values), PRIVMSGS_TABLE . ' p',
			'p.privmsgs_id = ' . $source . ' AND ' . $guard
			. ' AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $source . ')'
			. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_copy_token FROM ' . PRIVMSGS_TABLE
			. ") read_copy WHERE HEX(privmsgs_copy_token) = HEX('" . $token . "'))");
		$copies = phpbb_acl_rows($db, 'SELECT * FROM ' . PRIVMSGS_TABLE . " WHERE HEX(privmsgs_copy_token) = HEX('" . $token . "')");
	}
	if (count($copies) !== 1) { phpbb_acl_error('PM_journal_changed'); }
	$copy = $copies[0]; $id = (int)$copy['privmsgs_id'];
	foreach (array('privmsgs_from_userid','privmsgs_to_userid','privmsgs_date') as $field)
	{
		if ((int)$copy[$field] !== (int)$p[$field]) { phpbb_acl_error('PM_journal_changed'); }
	}
	$copy_key = phpbb_pm_read_copy_key($p, $copy);
	if ((int)$p['privmsgs_read_copy_id'] === 0)
	{
		// Bind before publishing: a lost acknowledgement or later deletion of
		// the visible copy cannot authorize allocating a second copy on retry.
		$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_read_copy_id = ' . $id . ' WHERE ' . phpbb_pm_read_key($p)
			. ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $copy_key . ') allocated_copy)');
		$p['privmsgs_read_copy_id'] = $id; $guard = phpbb_pm_read_guard($p);
		if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('PM_journal_changed'); }
	}
	if ((int)$copy['privmsgs_type'] === PRIVMSGS_PENDING_SENT_MAIL)
	{
		$pending = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $copy_key
			. ' AND privmsgs_type = ' . PRIVMSGS_PENDING_SENT_MAIL . ') pending_copy)';
		$db->insert_select(PRIVMSGS_TEXT_TABLE, 'privmsgs_text_id,privmsgs_bbcode_uid,privmsgs_text',
			$id . ',t.privmsgs_bbcode_uid,t.privmsgs_text', PRIVMSGS_TEXT_TABLE . ' t', 't.privmsgs_text_id = ' . $source
			. ' AND ' . $guard . ' AND ' . $pending . ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_text_id FROM '
			. PRIVMSGS_TEXT_TABLE . ') copy_text WHERE privmsgs_text_id = ' . $id . ')');
		$db->insert_select(ATTACHMENTS_TABLE, 'attach_id,post_id,privmsgs_id,user_id_1,user_id_2',
			'DISTINCT a.attach_id,0,' . $id . ',' . (int)$p['privmsgs_from_userid'] . ',' . (int)$p['privmsgs_to_userid'],
			ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d',
			'a.privmsgs_id = ' . $source . ' AND d.attach_id = a.attach_id AND ' . $guard . ' AND ' . $pending
			. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT attach_id,privmsgs_id FROM ' . ATTACHMENTS_TABLE
			. ') copy_link WHERE copy_link.privmsgs_id = ' . $id . ' AND copy_link.attach_id = a.attach_id)');
		$body_ready = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' original,' . PRIVMSGS_TEXT_TABLE
			. ' copied WHERE original.privmsgs_text_id = ' . $source . ' AND copied.privmsgs_text_id = ' . $id
			. ' AND HEX(original.privmsgs_text) = HEX(copied.privmsgs_text) AND HEX(original.privmsgs_bbcode_uid) = HEX(copied.privmsgs_bbcode_uid))';
		$links_ready = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.privmsgs_id = ' . $source
			. ' AND d.attach_id = a.attach_id AND NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' c WHERE c.privmsgs_id = ' . $id . ' AND c.attach_id = a.attach_id))';
		$db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_type = ' . PRIVMSGS_SENT_MAIL
			. ',privmsgs_attachment = CASE WHEN EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.privmsgs_id = ' . $id . ') THEN 1 ELSE 0 END'
			. ' WHERE ' . $copy_key . ' AND privmsgs_type = ' . PRIVMSGS_PENDING_SENT_MAIL . ' AND ' . $guard . ' AND ' . $body_ready . ' AND ' . $links_ready);
	}
	$published = phpbb_acl_rows($db, 'SELECT privmsgs_type FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $copy_key);
	if ($published && (int)$published[0]['privmsgs_type'] === PRIVMSGS_PENDING_SENT_MAIL) { phpbb_acl_error('PM_journal_changed'); }
	if ($published && (int)$published[0]['privmsgs_type'] === PRIVMSGS_SENT_MAIL && (int)$p['privmsgs_from_userid'] > 0)
	{
		// Reuse the same owning connection; no nested acquisition. A saved or
		// deleted published copy is neither moved back nor used for eviction.
		$quota = new PhpbbMailboxDatabase($db->connection, (int)$p['privmsgs_from_userid'], 'sentbox', 'read', $source);
		phpbb_pm_trim_mailbox_locked($quota, $limit, $id);
	}
	phpbb_pm_read_finish($db, $p);
}

function phpbb_pm_read_message($message_id, $folder, $limit)
{
	global $db;
	$ids = attach_delete_id_array(array($message_id));
	if (!$ids || !in_array($folder, array('inbox','savebox'), true)) { return false; }
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = new PhpbbPmReadDatabase($lock->connection);
		// Fail before changing an old installation if the migration is missing.
		$schema = $database->sql_query('SELECT privmsgs_read_token,privmsgs_read_copy_id,privmsgs_copy_token FROM ' . PRIVMSGS_TABLE . ' WHERE 1 = 0');
		$database->sql_freeresult($schema);
		$p = phpbb_pm_read_source($database, $ids[0], $folder);
		if (!$p)
		{
			phpbb_pm_require_complete_selection($database, 'privmsgs_id = ' . $ids[0] . ' AND privmsgs_to_userid = ' . $database->actor
				. ' AND (' . phpbb_pm_mailbox_condition($database->actor, $folder) . ')');
			return false;
		}
		if ((int)$p['privmsgs_type'] === PRIVMSGS_NEW_MAIL || (int)$p['privmsgs_type'] === PRIVMSGS_UNREAD_MAIL)
		{
			$token = bin2hex(phpbb_random_bytes(16));
			$database->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_type = ' . PRIVMSGS_READ_MAIL
				. ",privmsgs_read_token = '" . $token . "',privmsgs_read_copy_id = 0 WHERE privmsgs_id = " . $ids[0]
				. ' AND privmsgs_to_userid = ' . $database->actor . ' AND privmsgs_write_payload IS NULL AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ')'
				. ' AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE . ' t WHERE t.privmsgs_text_id = ' . $ids[0] . ')');
			$p = phpbb_pm_read_source($database, $ids[0], $folder);
			if (!$p) { return false; }
		}
		// Repeat on already READ messages too. Failure before/after the source
		// transition cannot repeatedly decrement or permanently strand a counter.
		phpbb_pm_recount_recipient($database, $database->actor);
		if ($p['privmsgs_read_token'] !== '') { phpbb_pm_read_complete($database, $p, $limit); }
		return true;
	}
	finally { $lock->release(); }
}

function phpbb_pm_visit_mailbox($session_start)
{
	global $db;
	$session_start = max(0, (int)$session_start);
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = new PhpbbPmReadDatabase($lock->connection);
		$database->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET privmsgs_type = ' . PRIVMSGS_UNREAD_MAIL
			. ' WHERE privmsgs_to_userid = ' . $database->actor . ' AND privmsgs_type = ' . PRIVMSGS_NEW_MAIL . ' AND privmsgs_write_payload IS NULL');
		// Derive counts from the rows after transition instead of transferring
		// possibly stale cached counters before it. A retry is idempotent.
		phpbb_pm_recount_recipient($database, $database->actor);
		$database->sql_query('UPDATE ' . USERS_TABLE . ' SET user_last_privmsg = CASE WHEN user_last_privmsg IS NULL OR user_last_privmsg < '
			. $session_start . ' THEN ' . $session_start . ' ELSE user_last_privmsg END WHERE user_id = ' . $database->actor);
		$rows = phpbb_acl_rows($database, 'SELECT user_new_privmsg,user_unread_privmsg,user_last_privmsg FROM ' . USERS_TABLE . ' WHERE user_id = ' . $database->actor);
		return $rows[0];
	}
	finally { $lock->release(); }
}

function phpbb_pm_resume_reads($limit)
{
	global $db;
	$lock = attach_require_mutation_lock($db);
	try
	{
		$database = new PhpbbPmReadDatabase($lock->connection);
		$pending = phpbb_acl_rows($database, 'SELECT privmsgs_id,privmsgs_type FROM ' . PRIVMSGS_TABLE
			. ' WHERE privmsgs_to_userid = ' . $database->actor . " AND privmsgs_read_token <> '' AND privmsgs_type IN ("
			. PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ') AND EXISTS (SELECT 1 FROM ' . PRIVMSGS_TEXT_TABLE
			. ' t WHERE t.privmsgs_text_id = ' . PRIVMSGS_TABLE . '.privmsgs_id) ORDER BY privmsgs_id LIMIT 100');
		phpbb_pm_recount_recipient($database, $database->actor);
		foreach ($pending as $entry)
		{
			$folder = (int)$entry['privmsgs_type'] === PRIVMSGS_SAVED_IN_MAIL ? 'savebox' : 'inbox';
			$p = phpbb_pm_read_source($database, $entry['privmsgs_id'], $folder);
			if ($p && $p['privmsgs_read_token'] !== '') { phpbb_pm_read_complete($database, $p, $limit); }
		}
		$staging = new PhpbbMailboxDatabase($lock->connection, $database->actor, 'sentbox', 'staging');
		phpbb_mailbox_recover($staging);
		// Bound both discovery and execution to this request's selected IDs.
		$abandoned = phpbb_acl_rows($staging, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_from_userid = '
			. $database->actor . ' AND (' . phpbb_pm_abandoned_copy_condition() . ') ORDER BY privmsgs_id LIMIT 100');
		$ids = array(); foreach ($abandoned as $entry) { $ids[] = (int)$entry['privmsgs_id']; }
		if ($ids)
		{
			phpbb_mailbox_delete_selected($staging, 'privmsgs_id IN (' . implode(',', $ids) . ') AND privmsgs_from_userid = '
				. $database->actor . ' AND (' . phpbb_pm_abandoned_copy_condition() . ')');
		}
	}
	finally { $lock->release(); }
}
