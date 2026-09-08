<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbUserlistException extends RuntimeException {}
function phpbb_userlist_error($key)
{
	global $lang;
	throw new PhpbbUserlistException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbUserlistDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
		return $result;
	}
}
function phpbb_userlist_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
	$value = ltrim((string) $value, '0');
	if ($value === '' || strlen($value) > 8 || (int) $value > 16777215) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
	return (int) $value;
}
function phpbb_userlist_rows($db, $sql)
{
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function phpbb_userlist_actor($db)
{
	global $userdata, $phpEx;
	$user = phpbb_current_moderator_user($db);
	if (!$user || empty($userdata['session_admin'])) { phpbb_userlist_error('Not_Authorised'); }
	// DISTINCT makes the account snapshot a derived table even on MySQL:
	// UPDATE users may not directly subselect the same target table there.
	$user['write_guard'] = ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id, user_level, user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user['user_id'] . ') userlist_actor'
		. ' WHERE userlist_actor.user_id = ' . (int) $user['user_id'] . ' AND userlist_actor.user_active <> 0 AND userlist_actor.user_level = ' . (int) $user['user_level'] . ')';
	if ((int) $user['user_level'] === ADMIN) { return $user; }
	require_once dirname(__FILE__) . '/functions_jr_admin.php';
	$rows = phpbb_userlist_rows($db, 'SELECT user_jr_admin FROM ' . JR_ADMIN_TABLE . ' WHERE user_id = ' . (int) $user['user_id']);
	$routes = jr_admin_authorization_routes();
	if ($rows && $routes !== false)
	{
		foreach (explode(EXPLODE_SEPERATOR_CHAR, $rows[0]['user_jr_admin']) as $hash)
		{
			if (isset($routes[$hash]) && jr_admin_route_matches_file($routes[$hash], 'admin_users_list.' . $phpEx))
			{
				$user['write_guard'] .= ' AND EXISTS (SELECT 1 FROM ' . JR_ADMIN_TABLE . ' j WHERE j.user_id = ' . (int) $user['user_id']
					. " AND j.user_jr_admin = '" . $db->sql_escape($rows[0]['user_jr_admin']) . "')";
				return $user;
			}
		}
	}
	phpbb_userlist_error('Not_Authorised');
}

// The shared writer connection also coordinates with moderated content changes.
// Other legacy permission writers still need the same boundary; a lock alone
// is not a substitute for repeating protected-target predicates on each write.
function phpbb_userlist_apply($database, $action, $selection, $group = null)
{
	global $userdata;
	if (!is_string($action) || !in_array($action, array('activate', 'deactivate', 'ban', 'unban', 'group'), true)
		|| !is_array($selection) || !$selection || count($selection) > 1000) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
	$ids = array();
	foreach ($selection as $value) { $id = phpbb_userlist_id($value); $ids[$id] = $id; }
	$group_id = $action === 'group' ? phpbb_userlist_id($group) : 0;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals((string) $userdata['session_id'], $_POST['sid'])) { phpbb_userlist_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_userlist_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbUserlistDatabase($lock->connection);
		$user = phpbb_userlist_actor($db);
		$group_guard = $group_id ? ' AND EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' g WHERE g.group_id = ' . $group_id . ' AND g.group_single_user = 0)' : '';
		if ($group_id && !phpbb_userlist_rows($db, 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $group_id . ' AND group_single_user = 0')) { phpbb_userlist_error('Admin_userlist_invalid_group'); }
		$changed = array();
		foreach ($ids as $id)
		{
			$user = phpbb_userlist_actor($db);
			$guard = 'user_id = ' . $id . ' AND user_id > 0 AND user_id <> ' . (int) $user['user_id'] . ' AND user_level <> ' . ADMIN . $user['write_guard'];
			$eligible = 'EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' WHERE ' . $guard . ')' . $group_guard;
			// Invalidate BEFORE any change. A failed invalidation must not leave a
			// successfully disabled/banned account with a usable cached session.
			// Repeated/no-op submissions may expire eligible targets' sessions too.
			$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . $id . ' AND ' . $eligible);
			$count = 0;
			if ($action === 'activate' || $action === 'deactivate')
			{
				$active = $action === 'activate' ? 1 : 0;
				$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_active = ' . $active . ' WHERE ' . $guard . ' AND user_active <> ' . $active);
				$count += (int) $db->sql_affectedrows();
			}
			elseif ($action === 'ban')
			{
				$db->sql_query('INSERT INTO ' . BANLIST_TABLE . " (ban_userid, ban_ip, ban_email) SELECT user_id, '', '' FROM " . USERS_TABLE . ' WHERE ' . $guard
					. ' AND NOT EXISTS (SELECT 1 FROM ' . BANLIST_TABLE . ' b WHERE b.ban_userid = ' . $id . ')');
				$count += (int) $db->sql_affectedrows();
			}
			elseif ($action === 'unban')
			{
				$db->sql_query('DELETE FROM ' . BANLIST_TABLE . ' WHERE ban_userid = ' . $id . ' AND ' . $eligible);
				$count += (int) $db->sql_affectedrows();
			}
			else
			{
				$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id, user_id, user_pending) SELECT ' . $group_id . ', user_id, 0 FROM ' . USERS_TABLE
					. ' WHERE ' . $guard . $group_guard . ' AND NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.user_id = ' . $id . ' AND ug.group_id = ' . $group_id . ')');
				$count += (int) $db->sql_affectedrows();
				// An explicit administrator addition approves a pending request too.
				$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending = 0 WHERE user_id = ' . $id . ' AND group_id = ' . $group_id . ' AND user_pending <> 0 AND ' . $eligible);
				$count += (int) $db->sql_affectedrows();
				$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level = ' . MOD . ' WHERE ' . $guard . ' AND user_level = ' . USER . $group_guard
					. ' AND EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug, ' . AUTH_ACCESS_TABLE . ' a, ' . FORUMS_TABLE . ' f WHERE ug.user_id = ' . $id
					. ' AND ug.group_id = ' . $group_id . ' AND ug.user_pending = 0 AND a.group_id = ug.group_id AND a.auth_mod = 1 AND f.forum_id = a.forum_id)');
				$count += (int) $db->sql_affectedrows();
				if (!phpbb_userlist_rows($db, 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $group_id . ' AND group_single_user = 0')) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
			}
			if ($count) { $changed[] = $id; }
		}
		return array('changed' => count($changed), 'unchanged' => count($ids) - count($changed));
	}
	finally { $lock->release(); }
}
