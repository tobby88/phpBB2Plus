<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbGroupException extends RuntimeException {}
function phpbb_group_error($key)
{
	global $lang;
	throw new PhpbbGroupException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbGroupDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_group_error('Group_storage_failed'); }
		return $result;
	}
}
function phpbb_group_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { phpbb_group_error('Group_invalid_selection'); }
	$value = ltrim((string) $value, '0');
	if ($value === '' || strlen($value) > 8 || (int) $value > 16777215) { phpbb_group_error('Group_invalid_selection'); }
	return (int) $value;
}
function phpbb_group_rows($db, $sql)
{
	$result = $db->sql_query($sql); $rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
}
function phpbb_group_context($db, $group_id, $action)
{
	global $userdata;
	$user = phpbb_current_moderator_user($db);
	if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { phpbb_group_error('Not_Authorised'); }
	$rows = phpbb_group_rows($db, 'SELECT group_id, group_name, group_type, group_moderator FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $group_id . ' AND group_single_user = 0');
	if (!$rows) { phpbb_group_error('Group_not_exist'); }
	$group = $rows[0];
	$managed = !in_array($action, array('join','unsubscribe','unsubscribe_pending'), true);
	if ($managed && (int) $user['user_level'] !== ADMIN && (int) $group['group_moderator'] !== (int) $user['user_id']) { phpbb_group_error('Not_group_moderator'); }
	if ($action === 'join' && (int) $group['group_type'] !== GROUP_OPEN) { phpbb_group_error('This_closed_group'); }
	// Materialized, ID-filtered derived tables also work when the statement
	// updates users/groups themselves. Never trust a previous root/leader flag.
	$guard = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id, user_active, user_level FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user['user_id'] . ') group_actor,'
		. ' (SELECT DISTINCT group_id, group_type, group_moderator, group_single_user FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $group_id . ') group_policy'
		. ' WHERE group_actor.user_active <> 0 AND group_policy.group_single_user = 0';
	if ($managed) { $guard .= ' AND (group_actor.user_level = ' . ADMIN . ' OR group_policy.group_moderator = group_actor.user_id)'; }
	if ($action === 'join') { $guard .= ' AND group_policy.group_type = ' . GROUP_OPEN; }
	$group['guard'] = $guard . ') AND EXISTS (SELECT 1 FROM (SELECT DISTINCT session_id,session_user_id,session_logged_in FROM ' . SESSIONS_TABLE
		. " WHERE HEX(session_id)=HEX('" . $db->sql_escape($userdata['session_id']) . "')) group_session WHERE group_session.session_user_id=" . (int)$user['user_id'] . ' AND group_session.session_logged_in=1)';
	if (!phpbb_group_rows($db, 'SELECT 1 AS allowed WHERE ' . $group['guard'])) { phpbb_group_error('Not_Authorised'); }
	$group['actor_id'] = (int) $user['user_id'];
	return $group;
}
function phpbb_group_moderator_membership($id)
{
	return 'EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug, ' . GROUPS_TABLE . ' g, ' . AUTH_ACCESS_TABLE . ' a, ' . FORUMS_TABLE . ' f'
		. ' WHERE ug.user_id = ' . $id . ' AND ug.user_pending = 0 AND g.group_id = ug.group_id AND a.group_id = g.group_id AND a.auth_mod = 1 AND f.forum_id = a.forum_id)';
}

class PhpbbGroupMemberDatabase extends PhpbbGroupDatabase
{
	var $transactional = false;
	var $finished = false;
	private function control($sql) { return parent::sql_query($sql); }
	function sql_query($sql, $transaction = false)
	{
		// Context reads also run before begin; mutations belong exclusively to
		// this one transaction. Helpers may never issue implicit commits.
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql, $command)
			|| (strtoupper($command[1]) !== 'SELECT' && !$this->transactional)) { phpbb_group_error('Group_storage_failed'); }
		return $this->control($sql);
	}
	function begin()
	{
		if ($this->transactional || $this->finished) { phpbb_group_error('Group_storage_failed'); }
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, GROUPS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, FORUMS_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$name = $this->sql_escape($table);
			$rows = phpbb_group_rows($this, "SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
			{ phpbb_group_error('Group_storage_failed'); }
		}
	}
	function commit($group_id, $action)
	{
		global $userdata;
		if (!$this->transactional || $this->finished) { phpbb_group_error('Group_storage_failed'); }
		$group = phpbb_group_context($this, $group_id, $action); $sid = $this->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $group['actor_id']) as $sql)
		{ $r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r); }
		// The group row (including current leader/status) was locked at entry.
		// Public self-service needs a current login, not an ACP-session grant.
		phpbb_group_context($this, $group_id, $action); $this->control('COMMIT');
		// These locks protected the acknowledged outcome. Later revocation or
		// disconnect must not withhold its notification recipients or success.
		$this->transactional = false; $this->finished = true;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false; $this->finished = true;
	}
}

// Only this function owns the lock/transaction. Notifications and rendering
// happen after successful commit and connection release, never on partial save.
function phpbb_group_change($database, $action, $group_id, $value = null)
{
	global $userdata;
	$actions = array('status','join','unsubscribe','unsubscribe_pending','add','remove','approve','deny');
	if (!is_string($action) || !in_array($action, $actions, true)) { phpbb_group_error('Group_invalid_selection'); }
	$group_id = phpbb_group_id($group_id); $ids = array();
	if (in_array($action, array('remove','approve','deny'), true))
	{
		if (!is_array($value) || !$value || count($value) > 1000) { phpbb_group_error('Group_invalid_selection'); }
		foreach ($value as $id) { $id = phpbb_group_id($id); $ids[$id] = $id; }
	}
	if ($action === 'status' && (!(is_int($value) || is_string($value)) || !in_array((string) $value, array((string) GROUP_OPEN,(string) GROUP_CLOSED,(string) GROUP_HIDDEN), true))) { phpbb_group_error('Invalid_group_type'); }
	if ($action === 'add' && (!is_string($value) || trim($value) === '' || strlen($value) > 255)) { phpbb_group_error('Could_not_add_user'); }
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { phpbb_group_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_group_error('Attachment_storage_busy'); }
	$db = new PhpbbGroupMemberDatabase($lock->connection);
	try
	{
		phpbb_group_context($db, $group_id, $action); $db->begin();
		phpbb_group_rows($db, 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $group_id . ' FOR UPDATE');
		$group = phpbb_group_context($db, $group_id, $action);
		$output = array('group_id'=>$group_id,'group_name'=>$group['group_name'],'action'=>$action,'changed'=>array(),'recipients'=>array());
		if ($action === 'status')
		{
			$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_type = ' . (int) $value . ' WHERE group_id = ' . $group_id . ' AND group_type <> ' . (int) $value . ' AND ' . $group['guard']);
			if ($db->sql_affectedrows()) { $output['changed'][] = $group_id; }
			phpbb_group_context($db, $group_id, $action);
			$db->commit($group_id, $action);
			return $output;
		}
		if (in_array($action, array('join','unsubscribe','unsubscribe_pending'), true)) { $ids = array($group['actor_id']); }
		if ($action === 'add')
		{
			// common.php adds legacy request slashes. Remove them exactly once;
			// do not truncate UTF-8 or depend on PHP's changing HTML quote defaults.
			$username = stripslashes(trim($value));
			if (!preg_match('//u', $username)) { phpbb_group_error('Could_not_add_user'); }
			$names = array_unique(array($username, htmlspecialchars($username, ENT_COMPAT, 'UTF-8'), htmlspecialchars($username, ENT_QUOTES, 'UTF-8')));
			$escaped = array(); foreach ($names as $name) { $escaped[] = "'" . $db->sql_escape($name) . "'"; }
			// Legacy entity-stored names remain usable, but an ambiguous match
			// must never silently select a different account.
			$rows = phpbb_group_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE username IN (' . implode(',', $escaped) . ') AND user_id > 0');
			if (count($rows) !== 1) { phpbb_group_error('Could_not_add_user'); }
			$ids = array(phpbb_group_id($rows[0]['user_id']));
		}
		sort($ids, SORT_NUMERIC);
		if ($ids) { phpbb_group_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY user_id FOR UPDATE'); }
		foreach ($ids as $id)
		{
			$group = phpbb_group_context($db, $group_id, $action);
			$member = 'user_id = ' . $id . ' AND group_id = ' . $group_id;
			$guard = $group['guard'] . ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ') target_user WHERE target_user.user_id > 0)';
			$removing = in_array($action, array('remove','deny','unsubscribe','unsubscribe_pending'), true);
			if ($removing) { $guard .= ' AND NOT EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' leader_group WHERE leader_group.group_id = ' . $group_id . ' AND leader_group.group_moderator = ' . $id . ')'; }
			$pending = in_array($action, array('approve','deny','unsubscribe_pending'), true) ? 1 : 0;
			$existing = phpbb_group_rows($db, 'SELECT user_pending FROM ' . USER_GROUP_TABLE . ' WHERE ' . $member);
			if ($action === 'join' && $existing) { continue; }
			if (!in_array($action, array('join','add'), true) && (!$existing || (int) $existing[0]['user_pending'] !== $pending)) { continue; }
			if ($action === 'add' && $existing && !(int) $existing[0]['user_pending']) { continue; }
			// Existing sessions of other affected members may cache permissions.
			// Do not log out an actor who legitimately leaves a group themselves.
			if ($action !== 'join' && $id !== $group['actor_id'])
			{
				$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . $id . ' AND ' . $guard);
			}
			if ($action === 'join' || ($action === 'add' && !$existing))
			{
				$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (user_id, group_id, user_pending) SELECT ' . $id . ', ' . $group_id . ', ' . ($action === 'join' ? 1 : 0)
					. ' WHERE ' . $guard . ' AND NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' previous_member WHERE ' . $member . ')');
			}
			elseif ($removing) { $db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE ' . $member . ' AND user_pending = ' . $pending . ' AND ' . $guard); }
			else { $db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending = 0 WHERE ' . $member . ' AND user_pending = 1 AND ' . $guard); }
			$applied = (int) $db->sql_affectedrows() > 0;
			// Recheck after mutation too: do not announce success after a group
			// disappeared or a manager lost their authority during this request.
			phpbb_group_context($db, $group_id, $action);
			if (!$applied) { continue; }
			$output['changed'][] = $id;
			if ($action !== 'join')
			{
				$moderator = phpbb_group_moderator_membership($id);
				$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level = CASE WHEN ' . $moderator . ' THEN ' . MOD . ' ELSE ' . USER . ' END'
					. ' WHERE user_id = ' . $id . ' AND user_level IN (' . USER . ',' . MOD . ') AND ' . $guard);
				phpbb_group_context($db, $group_id, $action);
			}
			if (in_array($action, array('join','add','approve'), true))
			{
				$recipient = $action === 'join' ? (int) $group['group_moderator'] : $id;
				$rows = phpbb_group_rows($db, 'SELECT user_id, username, user_email, user_lang FROM ' . USERS_TABLE . ' WHERE user_id = ' . $recipient . ' AND user_active <> 0');
				if ($rows) { $output['recipients'][] = $rows[0]; }
			}
		}
		$db->commit($group_id, $action);
		return $output;
	}
	finally { $db->rollback(); $lock->release(); }
}
