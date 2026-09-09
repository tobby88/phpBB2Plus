<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_group_storage.php';
require_once dirname(__FILE__) . '/functions_privmsgs.php';
require_once dirname(__FILE__) . '/functions_user_cleanup.php';

class PhpbbRemovalException extends RuntimeException {}
function phpbb_removal_error($key)
{
	global $lang;
	throw new PhpbbRemovalException(isset($lang[$key]) ? $lang[$key] : $key);
}
class PhpbbRemovalDatabase
{
	var $connection; var $guard = '';
	function __construct($connection) { $this->connection = $connection; }
	function __call($name, $args) { return call_user_func_array(array($this->connection, $name), $args); }
	function sql_query($sql, $transaction = false)
	{
		// These internal cleanup workers use WHERE-qualified UPDATE/DELETE,
		// without trailing ORDER/LIMIT. Repeat the actor/absence guard even in
		// existing helper SQL; an initial authorization read is not sufficient.
		if ($this->guard !== '' && preg_match('/^\s*(UPDATE|DELETE FROM)\b/i', $sql))
		{
			if (!preg_match('/\bWHERE\b/i', $sql)) { phpbb_removal_error('Removal_storage_failed'); }
			$sql .= ' AND (' . $this->guard . ')';
		}
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_removal_error('Removal_storage_failed'); }
		return $result;
	}
}
function phpbb_removal_rows($db, $sql)
{
	$result = $db->sql_query($sql); $rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
}
function phpbb_removal_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { phpbb_removal_error('Removal_invalid'); }
	$value = ltrim((string) $value, '0');
	if ($value === '' || strlen($value) > 8 || (int) $value > 16777215) { phpbb_removal_error('Removal_invalid'); }
	return (int) $value;
}
function phpbb_removal_actor($db)
{
	global $userdata, $phpEx;
	$user = phpbb_current_moderator_user($db);
	if (!$user || empty($userdata['session_admin'])) { phpbb_removal_error('Not_Authorised'); }
	$grant = 'removal_actor.user_level = ' . ADMIN;
	if ((int) $user['user_level'] !== ADMIN)
	{
		require_once dirname(__FILE__) . '/functions_jr_admin.php';
		$rows = phpbb_removal_rows($db, 'SELECT user_jr_admin FROM ' . JR_ADMIN_TABLE . ' WHERE user_id = ' . (int) $user['user_id']);
		$routes = jr_admin_authorization_routes(); $allowed = false;
		if ($rows && $routes !== false)
		{
			foreach (explode(EXPLODE_SEPERATOR_CHAR, $rows[0]['user_jr_admin']) as $hash)
			{
				if (isset($routes[$hash]) && $routes[$hash] === 'admin_account.' . $phpEx) { $allowed = true; break; }
			}
		}
		if (!$allowed) { phpbb_removal_error('Not_Authorised'); }
		$grant .= ' OR EXISTS (SELECT 1 FROM ' . JR_ADMIN_TABLE . ' j WHERE j.user_id = ' . (int) $user['user_id'] . " AND j.user_jr_admin = '" . $db->sql_escape($rows[0]['user_jr_admin']) . "')";
	}
	$user['guard'] = 'EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id,user_active,user_level FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user['user_id'] . ') removal_actor WHERE removal_actor.user_active <> 0 AND (' . $grant . '))';
	return $user;
}
function phpbb_removal_assert($db)
{
	if (!phpbb_removal_rows($db, 'SELECT 1 AS allowed WHERE ' . $db->guard)) { phpbb_removal_error('Removal_account_changed'); }
}
function phpbb_removal_pm_where($id)
{
	// Inactive/pruned accounts retain other people's delivered and saved
	// copies. Do not silently replace this with the ACP user's all-copy policy.
	return '((privmsgs_from_userid = ' . $id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_SENT_MAIL . ',' . PRIVMSGS_SAVED_OUT_MAIL . ')) OR (privmsgs_to_userid = ' . $id . ' AND privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ',' . PRIVMSGS_READ_MAIL . ',' . PRIVMSGS_SAVED_IN_MAIL . ')))';
}
function phpbb_removal_capture($db, $job)
{
	$id = (int) $job['user_id']; $key = "job_id = '" . $job['job_id'] . "'";
	$db->sql_query('DELETE FROM ' . USER_REMOVAL_ITEMS_TABLE . ' WHERE ' . $key);
	$prefix = 'INSERT INTO ' . USER_REMOVAL_ITEMS_TABLE . ' (job_id,item_type,item_id,related_id,item_name) SELECT DISTINCT ' . "'" . $job['job_id'] . "',";
	$db->sql_query($prefix . "'group',g.group_id,0,'' FROM " . GROUPS_TABLE . ' g,' . USER_GROUP_TABLE . ' ug WHERE ug.user_id = ' . $id . ' AND g.group_id = ug.group_id AND g.group_single_user = 1 AND ' . $db->guard);
	$db->sql_query($prefix . "'pm',privmsgs_id,privmsgs_to_userid,'' FROM " . PRIVMSGS_TABLE . ' WHERE ' . phpbb_removal_pm_where($id) . ' AND ' . $db->guard);
	$db->sql_query($prefix . "'attachment',a.attach_id,0,d.physical_filename FROM " . ATTACHMENTS_TABLE . ' a,' . ATTACHMENTS_DESC_TABLE . ' d WHERE d.attach_id = a.attach_id AND a.privmsgs_id IN (SELECT item_id FROM ' . USER_REMOVAL_ITEMS_TABLE . ' WHERE ' . $key . " AND item_type = 'pm') AND " . $db->guard);
	phpbb_removal_assert($db);
}
function phpbb_removal_attachment($db, $item)
{
	$id = (int) $item['item_id'];
	if (phpbb_removal_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_TABLE . ' WHERE attach_id = ' . $id)) { return; }
	$rows = phpbb_removal_rows($db, 'SELECT physical_filename,thumbnail FROM ' . ATTACHMENTS_DESC_TABLE . ' WHERE attach_id = ' . $id);
	if (!$rows) { return; }
	if ($rows[0]['physical_filename'] !== $item['item_name']) { phpbb_removal_error('Removal_account_changed'); }
	$name = $db->sql_escape($item['item_name']);
	$shared = phpbb_removal_rows($db, 'SELECT attach_id FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE physical_filename = '" . $name . "' AND attach_id <> " . $id);
	if (!$shared)
	{
		// The retained description reserves the filename across a failed
		// unlink/connection loss, as in normal attachment removal.
		phpbb_removal_assert($db);
		if ((int) $rows[0]['thumbnail'] === 1)
		{
			if (!attach_delete_file($item['item_name'], MODE_THUMBNAIL)) { phpbb_removal_error('Removal_storage_failed'); }
			$db->sql_query('UPDATE ' . ATTACHMENTS_DESC_TABLE . ' SET thumbnail = 0 WHERE attach_id = ' . $id);
		}
		phpbb_removal_assert($db);
		if (!attach_delete_file($item['item_name'])) { phpbb_removal_error('Removal_storage_failed'); }
	}
	$db->sql_query('DELETE FROM ' . ATTACHMENTS_DESC_TABLE . " WHERE attach_id = " . $id . " AND physical_filename = '" . $name . "' AND NOT EXISTS (SELECT 1 FROM " . ATTACHMENTS_TABLE . ' a WHERE a.attach_id = ' . $id . ')');
}
function phpbb_removal_cleanup($db, $job, $actor)
{
	global $table_prefix;
	$id = (int) $job['user_id']; $key = "job_id = '" . $job['job_id'] . "'";
	phpbb_removal_assert($db);
	phpbb_cleanup_removed_user_references($db, $id);
	phpbb_anonymize_removed_user_content($db, $id, $job['username'], (int) $actor['user_id'], false);
	// Transfer actual remaining leadership only after approving the successor's
	// membership. Retry naturally skips groups that were already transferred.
	$groups = phpbb_removal_rows($db, 'SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_moderator = ' . $id . ' AND group_single_user = 0');
	foreach ($groups as $row)
	{
		$gid = (int) $row['group_id']; $uid = (int) $actor['user_id'];
		$policy = 'EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' g WHERE g.group_id = ' . $gid . ' AND g.group_moderator = ' . $id . ' AND g.group_single_user = 0)';
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id,user_id,user_pending) SELECT ' . $gid . ',' . $uid . ',0 WHERE ' . $db->guard . ' AND ' . $policy . ' AND NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.group_id = ' . $gid . ' AND ug.user_id = ' . $uid . ')');
		$db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending = 0 WHERE group_id = ' . $gid . ' AND user_id = ' . $uid . ' AND ' . $policy);
		$db->sql_query('UPDATE ' . GROUPS_TABLE . ' SET group_moderator = ' . $uid . ' WHERE group_id = ' . $gid . ' AND group_moderator = ' . $id . ' AND group_single_user = 0');
	}
	$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level = CASE WHEN ' . phpbb_group_moderator_membership((int) $actor['user_id']) . ' THEN ' . MOD . ' ELSE ' . USER . ' END WHERE user_id = ' . (int) $actor['user_id'] . ' AND user_level IN (' . USER . ',' . MOD . ')');
	$items = phpbb_removal_rows($db, 'SELECT item_type,item_id,related_id,item_name FROM ' . USER_REMOVAL_ITEMS_TABLE . ' WHERE ' . $key);
	// Journaled PM IDs remain available even if the parent vanished before its
	// text/attachment cleanup. Never delete a recreated or moved mailbox copy.
	foreach ($items as $item)
	{
		if ($item['item_type'] !== 'pm') { continue; }
		$mid = (int) $item['item_id'];
		$db->sql_query('DELETE FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $mid . ' AND ' . phpbb_removal_pm_where($id));
		$missing = 'NOT EXISTS (SELECT 1 FROM ' . PRIVMSGS_TABLE . ' p WHERE p.privmsgs_id = ' . $mid . ')';
		$db->sql_query('DELETE FROM ' . PRIVMSGS_TEXT_TABLE . ' WHERE privmsgs_text_id = ' . $mid . ' AND ' . $missing);
		$db->sql_query('DELETE FROM ' . ATTACHMENTS_TABLE . ' WHERE privmsgs_id = ' . $mid . ' AND ' . $missing);
		if ((int) $item['related_id'] > 0) { phpbb_pm_recount_recipient($db, (int) $item['related_id']); }
	}
	foreach ($items as $item) { if ($item['item_type'] === 'attachment') { phpbb_removal_attachment($db, $item); } }
	foreach (array('privmsgs_from_userid','privmsgs_to_userid') as $field) { $db->sql_query('UPDATE ' . PRIVMSGS_TABLE . ' SET ' . $field . ' = ' . DELETED . ' WHERE ' . $field . ' = ' . $id); }
	$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id);
	$db->sql_query('DELETE FROM ' . QUOTA_TABLE . ' WHERE user_id = ' . $id);
	if (!defined('PA_AUTH_ACCESS_TABLE')) { require_once dirname(__DIR__) . '/pafiledb/includes/pafiledb_constants.php'; }
	foreach ($items as $item)
	{
		if ($item['item_type'] !== 'group') { continue; }
		$gid = (int) $item['item_id'];
		$empty = 'NOT EXISTS (SELECT 1 FROM ' . USER_GROUP_TABLE . ' ug WHERE ug.group_id = ' . $gid . ') AND NOT EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' g WHERE g.group_id = ' . $gid . ' AND g.group_single_user <> 1)';
		foreach (array(AUTH_ACCESS_TABLE,PA_AUTH_ACCESS_TABLE,QUOTA_TABLE) as $table) { $db->sql_query('DELETE FROM ' . $table . ' WHERE group_id = ' . $gid . ' AND ' . $empty); }
		$db->sql_query('DELETE FROM ' . GROUPS_TABLE . ' WHERE group_id = ' . $gid . ' AND group_single_user = 1 AND ' . $empty);
	}
	phpbb_removal_assert($db);
}

function phpbb_inactive_user_remove($database, $post)
{
	global $userdata;
	if (!is_array($post) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !isset($post['sid']) || !is_string($post['sid']) || !hash_equals((string) $userdata['session_id'], $post['sid'])) { phpbb_removal_error('Session_invalid'); }
	$actions = array_intersect(array('delete','removal_resume','removal_cancel'), array_keys($post));
	if (count($actions) !== 1) { phpbb_removal_error('Removal_invalid'); }
	$action = reset($actions); $id = 0; $token = '';
	if ($action === 'delete') { $id = phpbb_removal_id($post[$action]); }
	else { $token = $post[$action]; if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) { phpbb_removal_error('Removal_invalid'); } }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_removal_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbRemovalDatabase($lock->connection); $actor = phpbb_removal_actor($db); $db->guard = $actor['guard'];
		if ($id)
		{
			if ($id === (int) $actor['user_id']) { phpbb_removal_error('Not_Authorised'); }
			if (phpbb_removal_rows($db, 'SELECT job_id FROM ' . USER_REMOVALS_TABLE . ' WHERE user_id = ' . $id)) { phpbb_removal_error('Removal_pending_exists'); }
			$users = phpbb_removal_rows($db, 'SELECT user_id,username,user_regdate,user_password FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' AND user_active = 0 AND user_level <> ' . ADMIN);
			if (!$users) { phpbb_removal_error('Not_Authorised'); }
			$user = $users[0]; $token = bin2hex(phpbb_random_bytes(16));
			$fingerprint = hash('sha256', $user['username'] . "\0" . $user['user_regdate'] . "\0" . $user['user_password']);
			$db->sql_query('INSERT INTO ' . USER_REMOVALS_TABLE . ' (job_id,user_id,removal_mode,removal_state,username,identity_hash,created_by,created_at) SELECT ' . "'" . $token . "'," . $id . ",'inactive','prepared','" . $db->sql_escape($user['username']) . "','" . $fingerprint . "'," . (int) $actor['user_id'] . ',' . time() . ' WHERE ' . $db->guard);
		}
		$key = "job_id = '" . $token . "' AND removal_mode = 'inactive'";
		$jobs = phpbb_removal_rows($db, 'SELECT * FROM ' . USER_REMOVALS_TABLE . ' WHERE ' . $key);
		if (count($jobs) !== 1) { phpbb_removal_error('Removal_invalid'); }
		$job = $jobs[0]; $id = (int) $job['user_id'];
		if ($id <= 0 || $id === (int) $actor['user_id']) { phpbb_removal_error('Not_Authorised'); }
		$users = phpbb_removal_rows($db, 'SELECT user_id,username,user_regdate,user_password,user_active,user_level FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id);
		if ($action === 'removal_cancel')
		{
			// Discard metadata only if no removal was claimed, or a user row
			// exists again. Never discard the only recovery record for a deletion.
			if (!$users && $job['removal_state'] !== 'prepared') { phpbb_removal_error('Removal_account_changed'); }
			$db->guard .= " AND EXISTS (SELECT 1 FROM " . USER_REMOVALS_TABLE . " j WHERE j.job_id = '" . $token . "' AND (j.removal_state = 'prepared' OR EXISTS (SELECT 1 FROM " . USERS_TABLE . ' u WHERE u.user_id = ' . $id . ')))';
			$db->sql_query('DELETE FROM ' . USER_REMOVAL_ITEMS_TABLE . " WHERE job_id = '" . $token . "'");
			// Remove the self-reference for the journal DELETE itself.
			$db->guard = $actor['guard'];
			$db->sql_query('DELETE FROM ' . USER_REMOVALS_TABLE . ' WHERE ' . $key . " AND (removal_state = 'prepared' OR EXISTS (SELECT 1 FROM " . USERS_TABLE . ' u WHERE u.user_id = ' . $id . '))');
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_removal_error('Removal_account_changed'); }
			return 'Removal_cancelled';
		}
		if ($job['removal_state'] === 'prepared')
		{
			if (!$users) { phpbb_removal_error('Removal_account_changed'); }
			$user = $users[0];
			if ((int) $user['user_active'] !== 0 || (int) $user['user_level'] === ADMIN || !hash_equals($job['identity_hash'], hash('sha256', $user['username'] . "\0" . $user['user_regdate'] . "\0" . $user['user_password']))) { phpbb_removal_error('Removal_account_changed'); }
			// Forum collations commonly ignore case and trailing spaces. The
			// destructive identity comparison must match the fingerprint bytes.
			$identity = 'user_id = ' . $id . ' AND user_active = 0 AND user_level <> ' . ADMIN . " AND HEX(username) = HEX('" . $db->sql_escape($user['username']) . "') AND user_regdate = " . (int) $user['user_regdate'] . " AND HEX(user_password) = HEX('" . $db->sql_escape($user['user_password']) . "')";
			$db->guard = $actor['guard'] . ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' WHERE ' . $identity . ')';
			phpbb_removal_capture($db, $job);
			$db->sql_query('UPDATE ' . USER_REMOVALS_TABLE . " SET removal_state = 'removing' WHERE " . $key . " AND removal_state = 'prepared'");
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_removal_error('Removal_account_changed'); }
			$db->guard = $actor['guard'];
			$db->sql_query('DELETE FROM ' . USERS_TABLE . ' WHERE ' . $identity);
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_removal_error('Removal_account_changed'); }
			$job['removal_state'] = 'removing'; $users = array();
		}
		elseif ($users) { phpbb_removal_error('Removal_account_changed'); }
		if (!in_array($job['removal_state'], array('removing','removed','complete'), true)) { phpbb_removal_error('Removal_invalid'); }
		$db->guard = $actor['guard'] . ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ') removal_user)';
		if ($job['removal_state'] !== 'complete')
		{
			$db->sql_query('UPDATE ' . USER_REMOVALS_TABLE . " SET removal_state = 'removed' WHERE " . $key);
			phpbb_removal_cleanup($db, $job, $actor);
			$db->sql_query('UPDATE ' . USER_REMOVALS_TABLE . " SET removal_state = 'complete' WHERE " . $key);
		}
		phpbb_removal_assert($db);
		$db->sql_query('DELETE FROM ' . USER_REMOVAL_ITEMS_TABLE . " WHERE job_id = '" . $token . "'");
		$db->sql_query('DELETE FROM ' . USER_REMOVALS_TABLE . ' WHERE ' . $key . " AND removal_state = 'complete'");
		if ((int) $db->sql_affectedrows() !== 1) { phpbb_removal_error('Removal_storage_failed'); }
		return 'Removal_completed';
	}
	finally { $lock->release(); }
}

function phpbb_removal_pending_html($database)
{
	global $lang, $userdata, $phpEx;
	$db = new PhpbbRemovalDatabase($database); phpbb_removal_actor($db);
	$sql = 'SELECT j.job_id,j.user_id,j.username,j.removal_state,u.user_id AS existing_user FROM ' . USER_REMOVALS_TABLE . ' j LEFT JOIN ' . USERS_TABLE . " u ON u.user_id = j.user_id WHERE j.removal_mode = 'inactive' ORDER BY j.created_at,j.job_id LIMIT 100";
	$result = $database->sql_query($sql);
	if (!$result) { phpbb_removal_error('Removal_jobs_unavailable'); }
	$rows = $database->sql_fetchrowset($result); $database->sql_freeresult($result);
	if (!$rows) { return ''; }
	$html = '<h2>' . phpbb_admin_html($lang['Removal_pending_title']) . '</h2><p class="genmed">' . phpbb_admin_html($lang['Removal_pending_explain']) . '</p>';
	foreach ($rows as $row)
	{
		if (!preg_match('/^[a-f0-9]{32}$/D', $row['job_id'])) { continue; }
		$state = 'Removal_state_' . $row['removal_state'];
		$html .= '<form method="post" action="' . phpbb_admin_html(append_sid('admin_account.' . $phpEx)) . '"><p class="genmed"><strong>' . phpbb_admin_html($row['username']) . ' (#' . (int) $row['user_id'] . ')</strong> — ' . phpbb_admin_html(isset($lang[$state]) ? $lang[$state] : $row['removal_state']) . ' ';
		$html .= '<button type="submit" class="liteoption" name="removal_resume" value="' . $row['job_id'] . '">' . phpbb_admin_html($lang['Removal_resume']) . '</button> ';
		if ($row['existing_user'] !== null || $row['removal_state'] === 'prepared')
		{
			$html .= '<button type="submit" class="liteoption" name="removal_cancel" value="' . $row['job_id'] . '">' . phpbb_admin_html($lang['Removal_cancel']) . '</button>';
		}
		$html .= phpbb_admin_session_field() . '</p></form>';
	}
	return $html;
}
