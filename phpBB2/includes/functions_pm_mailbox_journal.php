<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

// This is a mailbox capability, never an ACP permission. Quota actions may
// legitimately run for another user's mailbox, but only from their current
// delivery/read context. Recovery itself never deletes a surviving parent.
class PhpbbMailboxDatabase extends PhpbbAclDatabase
{
	var $owner; var $folder; var $actor; var $policy; var $source; var $affected = 0;
	function __construct($connection, $owner, $folder, $policy, $source = 0)
	{
		global $userdata;
		parent::__construct($connection, 'PM_cleanup_failed');
		$this->owner = (int)$owner; $this->folder = $folder; $this->policy = $policy; $this->source = (int)$source;
		$this->actor = isset($userdata['user_id']) ? (int)$userdata['user_id'] : 0;
		if (!phpbb_pm_mailbox_condition($this->owner, $folder)
			|| !in_array($policy, array('owner','recover','send','read','staging'), true)) { phpbb_acl_error('Not_Authorised'); }
		if (in_array($policy, array('owner','recover','staging'), true) && $this->actor !== $this->owner) { phpbb_acl_error('Not_Authorised'); }
		if ($policy === 'staging' && $folder !== 'sentbox') { phpbb_acl_error('Not_Authorised'); }
		if ($policy === 'send' && $folder !== 'inbox') { phpbb_acl_error('Not_Authorised'); }
		if ($policy === 'read' && ($folder !== 'sentbox' || $this->source <= 0)) { phpbb_acl_error('Not_Authorised'); }
		if ($policy === 'owner' || $policy === 'send') { phpbb_mailbox_post_request(); }
		$this->authority();
	}
	function authority()
	{
		global $userdata;
		if ($this->actor <= 0 || empty($userdata['session_logged_in']) || !isset($userdata['user_id'])
			|| (int)$userdata['user_id'] !== $this->actor) { phpbb_acl_error('Not_Authorised'); }
		$guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_active,user_allow_pm FROM ' . USERS_TABLE
			. ' WHERE user_id = ' . $this->actor . ') mailbox_actor WHERE user_active <> 0'
			. ($this->policy === 'send' ? ' AND user_allow_pm <> 0' : '') . ')';
		$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $this->owner . ') mailbox_owner)';
		if ($this->policy === 'read')
		{
			$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $this->source
				. ' AND privmsgs_to_userid = ' . $this->actor . ' AND privmsgs_from_userid = ' . $this->owner
				. ' AND privmsgs_type IN (' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')) mailbox_source)';
		}
		$result = parent::sql_query('SELECT 1 AS allowed WHERE ' . $guard);
		$allowed = $this->connection->sql_fetchrow($result); $this->connection->sql_freeresult($result);
		if (!$allowed) { phpbb_acl_error('Not_Authorised'); }
		return $guard;
	}
	function sql_query($sql, $transaction = false)
	{
		$guard = $this->authority();
		$write = preg_match('/^\s*(UPDATE|DELETE FROM)\b/i', $sql);
		if ($write)
		{
			if ($this->policy === 'staging' && preg_match('/^\s*UPDATE\s+' . preg_quote(PRIVMSGS_TABLE, '/') . '\s/i', $sql)) { phpbb_acl_error('Not_Authorised'); }
			if (!preg_match('/\bWHERE\b/i', $sql)) { phpbb_acl_error('PM_cleanup_failed'); }
			$sql .= ' AND (' . $guard . ')';
			if ($this->policy === 'staging' && preg_match('/^\s*DELETE FROM\s+' . preg_quote(PRIVMSGS_TABLE, '/') . '\s+WHERE\b/i', $sql))
			{
				// This GET recovery capability never authorizes deleting a visible
				// mailbox message or another participant's staging copy.
				$sql .= ' AND privmsgs_from_userid = ' . $this->owner . ' AND (' . phpbb_pm_abandoned_copy_condition() . ')';
			}
		}
		elseif (!preg_match('/^\s*SELECT\b/i', $sql)) { phpbb_acl_error('PM_cleanup_failed'); }
		$result = parent::sql_query($sql, $transaction);
		if ($write) { $this->affected = (int)$this->connection->sql_affectedrows(); }
		$this->authority(); return $result;
	}
	function sql_affectedrows() { return $this->affected; }
	function insert_guarded($table, $columns, $select, $condition)
	{
		$guard = $this->authority();
		parent::sql_query('INSERT INTO ' . $table . ' (' . $columns . ') SELECT ' . $select . ' WHERE (' . $condition . ') AND (' . $guard . ')');
		$this->affected = (int)$this->connection->sql_affectedrows(); $this->authority();
		return $this->affected;
	}
}

function phpbb_mailbox_post_request()
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !isset($_POST['sid']) || !is_string($_POST['sid'])
		|| !hash_equals((string)$userdata['session_id'], $_POST['sid'])) { phpbb_acl_error('Session_invalid'); }
}

function phpbb_mailbox_journal_ready($db)
{
	if (!defined('PM_DELETE_JOBS_TABLE') || !defined('PM_DELETE_ITEMS_TABLE')) { phpbb_acl_error('PM_journal_unavailable'); }
	foreach (array(PM_DELETE_JOBS_TABLE, PM_DELETE_ITEMS_TABLE) as $table)
	{
		try { $result = $db->sql_query('SELECT job_id FROM ' . $table . ' LIMIT 1'); $db->sql_freeresult($result); }
		catch (PhpbbAclException $error)
		{
			// Preserve a genuine current-permission failure, not just table errors.
			$db->authority(); phpbb_acl_error('PM_journal_unavailable');
		}
	}
}

function phpbb_mailbox_job_key($db, $job)
{
	if (!is_array($job) || !isset($job['job_id'],$job['folder'],$job['job_state']) || !is_string($job['job_id'])
		|| !preg_match('/^[a-f0-9]{32}$/D', $job['job_id']) || $job['folder'] !== $db->folder
		|| !in_array($job['job_state'], array('planning','prepared'), true)) { phpbb_acl_error('PM_journal_changed'); }
	foreach (array('message_id','owner_id','from_user_id','to_user_id','message_type','message_date','created_by') as $field)
	{
		if (!isset($job[$field]) || !(is_int($job[$field]) || is_string($job[$field]))
			|| !preg_match('/^-?[0-9]+$/D', (string)$job[$field])) { phpbb_acl_error('PM_journal_changed'); }
	}
	if ((int)$job['message_id'] <= 0 || (int)$job['owner_id'] !== $db->owner || (int)$job['message_date'] < 0) { phpbb_acl_error('PM_journal_changed'); }
	return "job_id = '" . $job['job_id'] . "'";
}

function phpbb_mailbox_identity($db, $job)
{
	return phpbb_mailbox_job_key($db, $job) . ' AND message_id = ' . (int)$job['message_id'] . ' AND owner_id = ' . $db->owner
		. " AND HEX(folder) = HEX('" . $db->folder . "') AND HEX(job_state) = HEX('" . $job['job_state'] . "')"
		. ' AND from_user_id = ' . (int)$job['from_user_id'] . ' AND to_user_id = ' . (int)$job['to_user_id']
		. ' AND message_type = ' . (int)$job['message_type'] . ' AND message_date = ' . (int)$job['message_date']
		. ' AND created_by = ' . (int)$job['created_by'];
}
function phpbb_mailbox_guard($db, $job) { return 'EXISTS (SELECT 1 FROM ' . PM_DELETE_JOBS_TABLE . ' mailbox_job WHERE ' . phpbb_mailbox_identity($db, $job) . ')'; }
function phpbb_mailbox_missing($job) { return 'NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' mailbox_parent WHERE privmsgs_id = ' . (int)$job['message_id'] . ')'; }
function phpbb_mailbox_parent($job)
{
	return 'privmsgs_id = ' . (int)$job['message_id'] . ' AND privmsgs_from_userid = ' . (int)$job['from_user_id']
		. ' AND privmsgs_to_userid = ' . (int)$job['to_user_id'] . ' AND privmsgs_type = ' . (int)$job['message_type']
		. ' AND privmsgs_date = ' . (int)$job['message_date'];
}
function phpbb_mailbox_assert($db, $job, $condition)
{
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . phpbb_mailbox_guard($db, $job) . ' AND (' . $condition . ')')) { phpbb_acl_error('PM_journal_changed'); }
}
function phpbb_mailbox_finish($db, $job, $cancel = false)
{
	$guard = phpbb_mailbox_guard($db, $job) . ($cancel ? '' : ' AND ' . phpbb_mailbox_missing($job));
	$db->sql_query('DELETE FROM ' . PM_DELETE_ITEMS_TABLE . ' WHERE ' . phpbb_mailbox_job_key($db, $job) . ' AND ' . $guard);
	$db->sql_query('DELETE FROM ' . PM_DELETE_JOBS_TABLE . ' WHERE ' . phpbb_mailbox_identity($db, $job)
		. ($cancel ? '' : ' AND ' . phpbb_mailbox_missing($job)));
	if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
}
function phpbb_mailbox_inventory_complete($job)
{
	return 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.privmsgs_id = ' . (int)$job['message_id']
		. ' AND d.attach_id = a.attach_id AND NOT EXISTS (SELECT 1 FROM ' . PM_DELETE_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id']
		. "' AND i.attach_id = d.attach_id AND HEX(i.physical_filename) = HEX(d.physical_filename)))";
}
function phpbb_mailbox_inventory($db, $job, $where)
{
	$parent = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' WHERE ' . phpbb_mailbox_parent($job) . ' AND (' . $where . '))';
	$guard = phpbb_mailbox_guard($db, $job) . ' AND ' . $parent; $cursor = 0;
	while (true)
	{
		$rows = phpbb_acl_rows($db, 'SELECT DISTINCT d.attach_id,d.physical_filename FROM ' . ATTACHMENTS_DESC_TABLE . ' d,' . ATTACHMENTS_TABLE
			. ' a WHERE a.privmsgs_id = ' . (int)$job['message_id'] . ' AND a.attach_id = d.attach_id AND d.attach_id > ' . $cursor . ' ORDER BY d.attach_id LIMIT 100');
		if (!$rows) { break; }
		foreach ($rows as $item)
		{
			$cursor = (int)$item['attach_id']; $name = $db->sql_escape($item['physical_filename']);
			$current = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE a.privmsgs_id = ' . (int)$job['message_id']
				. ' AND a.attach_id = ' . $cursor . " AND d.attach_id = a.attach_id AND HEX(d.physical_filename) = HEX('" . $name . "'))";
			$db->insert_guarded(PM_DELETE_ITEMS_TABLE, 'job_id,attach_id,physical_filename', "'" . $job['job_id'] . "'," . $cursor . ",'" . $name . "'", $guard . ' AND ' . $current);
		}
	}
	$db->sql_query('UPDATE ' . PM_DELETE_JOBS_TABLE . " SET job_state = 'prepared' WHERE " . phpbb_mailbox_identity($db, $job) . ' AND ' . $parent . ' AND ' . phpbb_mailbox_inventory_complete($job));
	if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
	$job['job_state'] = 'prepared'; return $job;
}

function phpbb_mailbox_file($db, $job, $item)
{
	$id = (int)$item['attach_id']; $name = $db->sql_escape($item['physical_filename']);
	$missing = phpbb_mailbox_missing($job) . ' AND EXISTS (SELECT 1 FROM ' . PM_DELETE_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id']
		. "' AND i.attach_id = " . $id . " AND HEX(i.physical_filename) = HEX('" . $name . "'))";
	phpbb_mailbox_assert($db, $job, $missing);
	$rows = phpbb_acl_rows($db, 'SELECT attach_id,physical_filename,thumbnail FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id);
	if (!$rows) { return false; }
	if ($rows[0]['physical_filename'] !== $item['physical_filename']) { return true; }
	$unreferenced = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ')';
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $unreferenced)) { return false; }
	$same = 'EXISTS (SELECT 1 FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . $id . " AND HEX(d.physical_filename) = HEX('" . $name . "'))";
	$unique = 'NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_DESC_TABLE . " d WHERE HEX(d.physical_filename) = HEX('" . $name . "') AND d.attach_id <> " . $id . ')';
	if (phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $unique))
	{
		$bytes = $missing . ' AND ' . $unreferenced . ' AND ' . $same . ' AND ' . $unique;
		phpbb_mailbox_assert($db, $job, $bytes);
		if ((int)$rows[0]['thumbnail'] === 1)
		{
			if (!attach_delete_file($item['physical_filename'], MODE_THUMBNAIL)) { phpbb_acl_error('PM_cleanup_failed'); }
			$db->sql_query('UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE attach_id = ' . $id
				. " AND HEX(physical_filename) = HEX('" . $name . "') AND " . $unreferenced . ' AND ' . $missing . ' AND ' . phpbb_mailbox_guard($db, $job));
		}
		phpbb_mailbox_assert($db, $job, $bytes);
		if (!attach_delete_file($item['physical_filename'])) { phpbb_acl_error('PM_cleanup_failed'); }
	}
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id . " AND HEX(physical_filename) = HEX('" . $name . "') AND "
		. $unreferenced . ' AND ' . $missing . ' AND ' . phpbb_mailbox_guard($db, $job));
	phpbb_mailbox_assert($db, $job, $missing); return false;
}

function phpbb_mailbox_complete($db, $job)
{
	$key = phpbb_mailbox_job_key($db, $job); $id = (int)$job['message_id'];
	// An old intent is never authority to delete a surviving/restored message.
	if ($job['job_state'] !== 'prepared' || phpbb_acl_rows($db, 'SELECT privmsgs_id FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id))
	{
		phpbb_mailbox_finish($db, $job, true); return 0;
	}
	$guard = phpbb_mailbox_missing($job) . ' AND ' . phpbb_mailbox_guard($db, $job);
	$db->sql_query('DELETE FROM ' . PRIVMSGS_TEXT_TABLE . ' WHERE privmsgs_text_id = ' . $id . ' AND ' . $guard);
	$inventoried = '(EXISTS (SELECT 1 FROM ' . PM_DELETE_ITEMS_TABLE . " i WHERE i.job_id = '" . $job['job_id'] . "' AND i.attach_id = " . ATTACHMENTS_TABLE
		. '.attach_id) OR NOT EXISTS (SELECT 1 FROM ' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = ' . ATTACHMENTS_TABLE . '.attach_id))';
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $id . ' AND (post_id = 0 OR post_id IS NULL) AND ' . $inventoried . ' AND ' . $guard);
	$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . ' SET privmsgs_id = 0 WHERE privmsgs_id = ' . $id . ' AND post_id <> 0 AND ' . $inventoried . ' AND ' . $guard);
	phpbb_pm_recount_recipient($db, (int)$job['to_user_id']);
	$changed = (bool)phpbb_acl_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $id . ' LIMIT 1'); $cursor = 0;
	while (true)
	{
		$items = phpbb_acl_rows($db, 'SELECT attach_id,physical_filename FROM ' . PM_DELETE_ITEMS_TABLE . ' WHERE ' . $key . ' AND attach_id > ' . $cursor . ' ORDER BY attach_id LIMIT 100');
		if (!$items) { break; }
		foreach ($items as $item) { $cursor = (int)$item['attach_id']; $changed = phpbb_mailbox_file($db, $job, $item) || $changed; }
	}
	phpbb_mailbox_assert($db, $job, phpbb_mailbox_missing($job));
	phpbb_mailbox_finish($db, $job);
	if ($changed) { phpbb_acl_error('PM_journal_changed'); }
	return 1;
}

function phpbb_mailbox_recover($db)
{
	phpbb_mailbox_journal_ready($db); $recovered = 0;
	// At most 100 old jobs per request. Further jobs remain durable for the next
	// visit; candidate-specific recovery below also handles a selected old job.
	$jobs = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_DELETE_JOBS_TABLE . ' WHERE owner_id = ' . $db->owner
		. " AND HEX(folder) = HEX('" . $db->folder . "') ORDER BY job_id LIMIT 100");
	foreach ($jobs as $job) { $recovered += phpbb_mailbox_complete($db, $job); }
	return $recovered;
}

function phpbb_mailbox_delete_selected($db, $where)
{
	if ($db->policy === 'recover') { phpbb_acl_error('Not_Authorised'); }
	phpbb_mailbox_journal_ready($db); $cursor = 0; $removed = 0;
	$ceiling = phpbb_acl_rows($db, 'SELECT MAX(privmsgs_id) AS last_id FROM ' . PRIVMSGS_TABLE . ' WHERE (' . $where . ')');
	$last_id = $ceiling && $ceiling[0]['last_id'] !== null ? (int)$ceiling[0]['last_id'] : 0;
	// Do not turn delete-all pagination into an expanding selection of mail
	// delivered after this request started. New higher IDs belong to later work.
	while (true)
	{
		$rows = phpbb_acl_rows($db, 'SELECT privmsgs_id,privmsgs_from_userid,privmsgs_to_userid,privmsgs_type,privmsgs_date FROM ' . PRIVMSGS_TABLE
			. ' WHERE (' . $where . ') AND privmsgs_id > ' . $cursor . ' AND privmsgs_id <= ' . $last_id . ' ORDER BY privmsgs_id LIMIT 100');
		if (!$rows) { break; }
		foreach ($rows as $p)
		{
			$id = $cursor = (int)$p['privmsgs_id'];
			$jobs = phpbb_acl_rows($db, 'SELECT * FROM ' . PM_DELETE_JOBS_TABLE . ' WHERE message_id = ' . $id);
			foreach ($jobs as $old)
			{
				if ((int)$old['owner_id'] === $db->owner && $old['folder'] === $db->folder) { phpbb_mailbox_complete($db, $old); }
				else
				{
					// NEW/UNREAD has both an inbox and outbox owner. A current
					// authorized selection can supersede an interrupted intent
					// from the other mailbox, but may only discard its metadata
					// while the selected parent still exists. Never adopt its
					// dependent cleanup authority when the parent is absent.
					$scope = new stdClass(); $scope->owner = (int)$old['owner_id']; $scope->folder = $old['folder'];
					if (!phpbb_pm_mailbox_condition($scope->owner, $scope->folder)) { phpbb_acl_error('PM_journal_changed'); }
					$current = 'EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id . ' AND (' . $where . '))';
					$db->sql_query('DELETE FROM ' . PM_DELETE_ITEMS_TABLE . ' WHERE ' . phpbb_mailbox_job_key($scope, $old) . ' AND '
						. phpbb_mailbox_guard($scope, $old) . ' AND ' . $current);
					$db->sql_query('DELETE FROM ' . PM_DELETE_JOBS_TABLE . ' WHERE ' . phpbb_mailbox_identity($scope, $old) . ' AND ' . $current);
					if ($db->sql_affectedrows() !== 1) { phpbb_acl_error('PM_journal_changed'); }
				}
			}
			require_once dirname(__FILE__) . '/php_compat.php';
			$job = array('job_id'=>bin2hex(phpbb_random_bytes(16)), 'message_id'=>$id, 'owner_id'=>$db->owner, 'folder'=>$db->folder,
				'job_state'=>'planning', 'from_user_id'=>(int)$p['privmsgs_from_userid'], 'to_user_id'=>(int)$p['privmsgs_to_userid'],
				'message_type'=>(int)$p['privmsgs_type'], 'message_date'=>(int)$p['privmsgs_date'], 'created_by'=>$db->actor);
			$parent = phpbb_mailbox_parent($job) . ' AND (' . $where . ')';
			$db->insert_guarded(PM_DELETE_JOBS_TABLE, 'job_id,message_id,owner_id,folder,job_state,from_user_id,to_user_id,message_type,message_date,created_by,created_at',
				"'" . $job['job_id'] . "'," . $id . ',' . $db->owner . ",'" . $db->folder . "','planning'," . $job['from_user_id'] . ',' . $job['to_user_id']
				. ',' . $job['message_type'] . ',' . $job['message_date'] . ',' . $db->actor . ',' . time() . ' FROM ' . PRIVMSGS_TABLE,
				$parent . ' AND NOT EXISTS (SELECT 1 FROM ' . PM_DELETE_JOBS_TABLE . ' old_job WHERE message_id = ' . $id . ')');
			if ($db->sql_affectedrows() !== 1) { continue; }
			$job = phpbb_mailbox_inventory($db, $job, $where);
			$db->sql_query('DELETE FROM ' . PRIVMSGS_TABLE . ' WHERE ' . $parent . ' AND ' . phpbb_mailbox_guard($db, $job) . ' AND ' . phpbb_mailbox_inventory_complete($job));
			$count = $db->sql_affectedrows();
			phpbb_mailbox_complete($db, $job); $removed += $count;
		}
	}
	return $removed;
}

function phpbb_pm_recover_mailbox($user_id, $folder)
{
	global $db;
	$lock = new attach_mutation_lock($db);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try { return phpbb_mailbox_recover(new PhpbbMailboxDatabase($lock->connection, $user_id, $folder, 'recover')); }
	finally { $lock->release(); }
}
