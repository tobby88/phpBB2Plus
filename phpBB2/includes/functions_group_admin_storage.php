<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_group_storage.php';
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function phpbb_group_admin_actor($db)
{
	global $phpEx;
	try { return phpbb_acp_actor($db, 'admin_groups.' . $phpEx); }
	catch (PhpbbAclException $e) { throw new PhpbbGroupException($e->getMessage()); }
}

// The attachment mutex serializes group/quota/account cleanup; the dedicated
// transaction additionally makes every group, role and session change atomic.
class PhpbbGroupAdminDatabase extends PhpbbGroupDatabase
{
	var $transactional = false;
	var $finished = false;
	private function control($sql) { return parent::sql_query($sql); }
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql, $command)
			|| (strtoupper($command[1]) !== 'SELECT' && !$this->transactional)) { phpbb_group_error('Group_storage_failed'); }
		return $this->control($sql);
	}
	function begin()
	{
		if ($this->transactional || $this->finished) { phpbb_group_error('Group_storage_failed'); }
		phpbb_group_admin_actor($this);
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, GROUPS_TABLE, USER_GROUP_TABLE,
			AUTH_ACCESS_TABLE, FORUMS_TABLE, PA_AUTH_ACCESS_TABLE, QUOTA_TABLE, QUOTA_LIMITS_TABLE) as $table)
		{
			// Pin metadata before checking it; never silently use nontransactional
			// plugin tables or repair schema with implicit-commit runtime DDL.
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$name = $this->sql_escape($table);
			$rows = phpbb_group_rows($this, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
			{ phpbb_group_error('Group_storage_failed'); }
		}
	}
	function commit()
	{
		global $userdata;
		if (!$this->transactional || $this->finished) { phpbb_group_error('Group_storage_failed'); }
		$actor = phpbb_group_admin_actor($this); $sid = $this->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql)
		{ $r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r); }
		// Current root/delegated authority is pinned through this commit. A
		// later revocation cannot undo it or turn the saved result into failure.
		phpbb_group_admin_actor($this); $this->control('COMMIT');
		$this->transactional = false; $this->finished = true;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false; $this->finished = true;
	}
}
function phpbb_group_admin_target($db, $id)
{
	$rows = phpbb_group_rows($db, 'SELECT * FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $id . ' AND group_single_user = 0');
	if (count($rows) !== 1) { phpbb_group_error('Group_not_exist'); }
	$group = $rows[0];
	$group['guard'] = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT group_id,group_single_user,group_moderator FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $id . ') ga_target WHERE ga_target.group_single_user = 0 AND ga_target.group_moderator = ' . (int) $group['group_moderator'] . ')';
	return $group;
}
function phpbb_group_admin_text($post, $key, $max)
{
	if (!isset($post[$key]) || !is_string($post[$key])) { phpbb_group_error('No_group_action'); }
	// common.php adds request slashes; decode exactly once, before validation.
	$value = trim(stripslashes($post[$key]));
	if (strpos($value, "\0") !== false || !preg_match('//u', $value) || preg_match_all('/./us', $value, $unused) > $max) { phpbb_group_error('No_group_action'); }
	return $value;
}
function phpbb_group_admin_leader($db, $name)
{
	$names = array_unique(array($name, htmlspecialchars($name, ENT_COMPAT, 'UTF-8'), htmlspecialchars($name, ENT_QUOTES, 'UTF-8')));
	$escaped = array(); foreach ($names as $value) { $escaped[] = "'" . $db->sql_escape($value) . "'"; }
	$rows = phpbb_group_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE username IN (' . implode(',', $escaped) . ') AND user_id > 0 AND user_active <> 0');
	if (count($rows) !== 1) { phpbb_group_error('No_group_moderator'); }
	return phpbb_group_id($rows[0]['user_id']);
}
function phpbb_group_admin_roles($db, $ids, $guard)
{
	foreach ($ids as $id)
	{
		$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level = CASE WHEN ' . phpbb_group_moderator_membership($id) . ' THEN ' . MOD . ' ELSE ' . USER . ' END WHERE user_id = ' . $id . ' AND user_level IN (' . USER . ',' . MOD . ') AND ' . $guard);
	}
}
function phpbb_group_admin_save($database, $post)
{
	global $userdata, $table_prefix;
	if (!is_array($post) || !isset($post['mode']) || !is_string($post['mode']) || !in_array($post['mode'], array('editgroup','newgroup'), true)) { phpbb_group_error('No_group_action'); }
	$mode = $post['mode']; $delete = isset($post['group_delete']);
	if ($delete && $mode !== 'editgroup') { phpbb_group_error('No_group_action'); }
	$id = $mode === 'editgroup' ? phpbb_group_id(isset($post[POST_GROUPS_URL]) ? $post[POST_GROUPS_URL] : null) : 0;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !isset($post['sid']) || !is_string($post['sid']) || !hash_equals((string) $userdata['session_id'], $post['sid'])) { phpbb_group_error('Session_invalid'); }
	$quotas = array();
	if (!$delete)
	{
		$name = phpbb_group_admin_text($post, 'group_name', 40);
		$description = phpbb_group_admin_text($post, 'group_description', 255);
		$leader_name = phpbb_group_admin_text($post, 'username', 255);
		if ($name === '') { phpbb_group_error('No_group_name'); }
		if ($leader_name === '') { phpbb_group_error('No_group_moderator'); }
		$type = isset($post['group_type']) ? $post['group_type'] : null;
		if (!(is_int($type) || is_string($type)) || !in_array((string) $type, array((string) GROUP_OPEN,(string) GROUP_CLOSED,(string) GROUP_HIDDEN), true)) { phpbb_group_error('Invalid_group_type'); }
		foreach (array('group_upload_quota'=>QUOTA_UPLOAD_LIMIT,'group_pm_quota'=>QUOTA_PM_LIMIT) as $key=>$quota_type)
		{
			// A new-group form has no quota controls. Missing fields never erase
			// an existing assignment (e.g. an older/custom administration form).
			if (!array_key_exists($key, $post)) { continue; }
			$quotas[$quota_type] = ($post[$key] === 0 || $post[$key] === '0') ? 0 : phpbb_group_id($post[$key]);
		}
	}
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_group_error('Attachment_storage_busy'); }
	$db = new PhpbbGroupAdminDatabase($lock->connection);
	try
	{
		if (!defined('PA_AUTH_ACCESS_TABLE')) { require_once dirname(__DIR__) . '/pafiledb/includes/pafiledb_constants.php'; }
		$db->begin(); $actor = phpbb_group_admin_actor($db);
		if ($id) { phpbb_group_rows($db, 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $id . ' FOR UPDATE'); }
		$group = $id ? phpbb_group_admin_target($db, $id) : null;
		$guard = $actor['guard'] . ($group ? ' AND ' . $group['guard'] : '');
		$members = $id ? phpbb_group_rows($db, 'SELECT DISTINCT user_id FROM ' . USER_GROUP_TABLE . ' WHERE group_id = ' . $id . ' AND user_id > 0') : array();
		$ids = array(); foreach ($members as $member) { $ids[(int) $member['user_id']] = (int) $member['user_id']; }
		if ($group && (int)$group['group_moderator'] > 0) { $ids[(int)$group['group_moderator']] = (int)$group['group_moderator']; }
		if (!$delete)
		{
			$leader = phpbb_group_admin_leader($db, $leader_name); $ids[$leader] = $leader;
			$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . $leader . ') ga_leader WHERE ga_leader.user_active <> 0)';
			foreach ($quotas as $limit)
			{
				if ($limit && !phpbb_group_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id = ' . $limit)) { phpbb_group_error('Group_invalid_selection'); }
				if ($limit) { $guard .= ' AND EXISTS (SELECT 1 FROM ' . QUOTA_LIMITS_TABLE . ' q WHERE q.quota_limit_id = ' . $limit . ')'; }
			}
		}
		if ($ids)
		{
			ksort($ids);
			phpbb_group_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY user_id FOR UPDATE');
			$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id IN (' . implode(',', $ids) . ') AND session_user_id <> ' . (int) $actor['user_id'] . ' AND ' . $guard);
		}
		if ($delete)
		{
			// Remove dependent grants while the guarded group still exists.
			// Any later failure restores the entire group and its dependents.
			foreach (array(AUTH_ACCESS_TABLE, PA_AUTH_ACCESS_TABLE, QUOTA_TABLE) as $table)
			{
				$db->sql_query('DELETE FROM ' . $table . ' WHERE group_id = ' . $id . ' AND ' . $guard);
			}
			phpbb_group_admin_roles($db, $ids, $guard);
			$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id = ' . $id . ' AND ' . $guard);
			$db->sql_query('DELETE FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $id . ' AND ' . $guard);
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_group_error('Group_storage_failed'); }
			$db->commit();
			return 'Deleted_group';
		}
		if (!$id)
		{
			$db->sql_query('INSERT INTO ' . GROUPS_TABLE . ' (group_type,group_name,group_description,group_moderator,group_single_user) SELECT ' . (int) $type . ", '" . $db->sql_escape($name) . "', '" . $db->sql_escape($description) . "', " . $leader . ', 0 WHERE ' . $guard);
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_group_error('Group_storage_failed'); }
			$id = phpbb_group_id($db->sql_nextid()); $group = phpbb_group_admin_target($db, $id); $guard .= ' AND ' . $group['guard'];
		}
		// Approve an existing pending leader as well as inserting a missing one.
		// This also repairs older groups whose current leader has no membership.
		$where = 'group_id = ' . $id . ' AND user_id = ' . $leader;
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id,user_id,user_pending) SELECT ' . $id . ',' . $leader . ',0 WHERE ' . $guard . ' AND NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ga_member WHERE ' . $where . ')');
		$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending = 0 WHERE ' . $where . ' AND user_pending <> 0 AND ' . $guard);
		$approved = phpbb_group_rows($db, 'SELECT user_pending FROM ' . USER_GROUP_TABLE . ' WHERE ' . $where);
		if (!$approved) { phpbb_group_error('Group_storage_failed'); }
		foreach ($approved as $row) { if ((int) $row['user_pending'] !== 0) { phpbb_group_error('Group_storage_failed'); } }
		foreach ($quotas as $quota_type=>$limit)
		{
			$qwhere = 'group_id = ' . $id . ' AND quota_type = ' . $quota_type;
			if (!$limit) { $db->sql_query('DELETE FROM ' . QUOTA_TABLE . ' WHERE ' . $qwhere . ' AND ' . $guard); }
			else
			{
				$db->sql_query('INSERT INTO ' . QUOTA_TABLE . ' (user_id,group_id,quota_type,quota_limit_id) SELECT 0,' . $id . ',' . $quota_type . ',' . $limit . ' WHERE ' . $guard . ' AND NOT EXISTS (SELECT 1 FROM ' . QUOTA_TABLE . ' ga_quota WHERE ' . $qwhere . ')');
				$db->sql_query('UPDATE ' . QUOTA_TABLE . ' SET quota_limit_id = ' . $limit . ' WHERE ' . $qwhere . ' AND ' . $guard);
			}
		}
		// Derive the new leader before publishing the leadership switch. Do not
		// remove the old leader until the new leader and group are both saved.
		phpbb_group_admin_roles($db, array($leader), $guard);
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_type = ' . (int) $type . ", group_name = '" . $db->sql_escape($name) . "', group_description = '" . $db->sql_escape($description) . "', group_moderator = " . $leader . ' WHERE group_id = ' . $id . ' AND ' . $guard);
		$current = phpbb_group_admin_target($db, $id);
		if ((int) $current['group_moderator'] !== $leader || (int) $current['group_type'] !== (int) $type || $current['group_name'] !== $name || $current['group_description'] !== $description) { phpbb_group_error('Group_storage_failed'); }
		$actor = phpbb_group_admin_actor($db); $guard = $actor['guard'] . ' AND ' . $current['guard'];
		if ($mode === 'editgroup' && isset($post['delete_old_moderator']) && (int) $group['group_moderator'] !== $leader)
		{
			$old = (int) $group['group_moderator'];
			$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE group_id = ' . $id . ' AND user_id = ' . $old . ' AND ' . $guard);
			if ($old > 0) { phpbb_group_admin_roles($db, array($old), $guard); }
			if (phpbb_group_rows($db, 'SELECT user_id FROM ' . USER_GROUP_TABLE . ' WHERE group_id = ' . $id . ' AND user_id = ' . $old)) { phpbb_group_error('Group_storage_failed'); }
		}
		phpbb_group_admin_actor($db);
		$final = phpbb_group_admin_target($db, $id);
		if ((int) $final['group_moderator'] !== $leader || !phpbb_group_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $leader . ' AND user_active <> 0')) { phpbb_group_error('Group_storage_failed'); }
		foreach ($quotas as $quota_type=>$limit)
		{
			$rows = phpbb_group_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_TABLE . ' WHERE group_id = ' . $id . ' AND quota_type = ' . $quota_type);
			if (($limit && !$rows) || (!$limit && $rows)) { phpbb_group_error('Group_storage_failed'); }
			foreach ($rows as $row) { if ((int) $row['quota_limit_id'] !== $limit) { phpbb_group_error('Group_storage_failed'); } }
			if ($limit && !phpbb_group_rows($db, 'SELECT quota_limit_id FROM ' . QUOTA_LIMITS_TABLE . ' WHERE quota_limit_id = ' . $limit)) { phpbb_group_error('Group_storage_failed'); }
		}
		$db->commit();
		return $mode === 'newgroup' ? 'Added_new_group' : 'Updated_group';
	}
	finally { $db->rollback(); $lock->release(); }
}
