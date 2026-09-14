<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_sessions.php';

// Repair the derived USER/MOD flag, never administrator or other special roles.
// Use the same dedicated writer lock as coordinated account/group/ACL changes.
function dbmtnc_synchronize_mod_state($database, $post)
{
	global $userdata;
	if (!is_array($post) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($post['sid']) || !is_string($post['sid'])
		|| !hash_equals((string) $userdata['session_id'], $post['sid']))
	{
		phpbb_acl_error('Session_invalid');
	}
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$db = new PhpbbAclSaveDatabase($lock->connection, 'Maintenance_role_sync_failed');
	try
	{
		$db->begin('maintenance');
		$actor = dbmtnc_session_reset_actor($db);
		$desired = 'CASE WHEN ' . phpbb_acl_mod_guard('role_user.user_id') . ' THEN ' . MOD . ' ELSE ' . USER . ' END';
		$rows = phpbb_acl_rows($db, 'SELECT role_user.user_id, role_user.username, role_user.user_level FROM ' . USERS_TABLE . ' role_user'
			. ' WHERE role_user.user_id > 0 AND role_user.user_level IN (' . USER . ',' . MOD . ')'
			. ' AND role_user.user_level <> ' . $desired . ' ORDER BY role_user.user_id');
		$output = array('changed' => array(), 'skipped' => array());
		foreach ($rows as $row)
		{
			$id = phpbb_acl_id($row['user_id']);
			// Preserve a target promoted/deleted since the initial inventory.
			// Hold the current account until commit so session expiry cannot race
			// a later administrator promotion or another profile change.
			$current_rows = phpbb_acl_rows($db, 'SELECT user_id, username, user_level FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' FOR UPDATE');
			if (!$current_rows || (int)$current_rows[0]['user_level'] !== (int)$row['user_level'])
			{
				$output['skipped'][] = array('user_id'=>$id, 'username'=>$current_rows ? $current_rows[0]['username'] : $row['username']);
				continue;
			}
			$row = $current_rows[0];
			$actor = dbmtnc_session_reset_actor($db);
			$desired = 'CASE WHEN ' . phpbb_acl_mod_guard($id) . ' THEN ' . MOD . ' ELSE ' . USER . ' END';
			$current = 'user_id = ' . $id . ' AND user_level = ' . (int) $row['user_level'] . ' AND user_level <> ' . $desired;
			// Recheck the target and current ACLs before expiring cached privileges.
			// Do not expire a concurrently promoted administrator's sessions.
			// A delegated administrator can itself need a USER/MOD correction.
			// Keep only its current, freshly verified ACP session: its separate
			// maintenance grant is unchanged, and the next request reloads u.*.
			// Other sessions still expire before the derived role is changed.
			$db->sql_query('SAVEPOINT phpbb_role_target');
			$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id = ' . $id
				. " AND HEX(session_id) <> HEX('" . $db->sql_escape($userdata['session_id']) . "')"
				. ' AND EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' role_target WHERE ' . $current . ') AND ' . $actor['guard']);
			$actor = dbmtnc_session_reset_actor($db);
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level = ' . $desired . ' WHERE ' . $current . ' AND ' . $actor['guard']);
			$changed = (int) $db->sql_affectedrows() === 1;
			dbmtnc_session_reset_actor($db);
			// A grant can legitimately change between the two guarded statements.
			// If no role changed, undo only this target's unnecessary expiration.
			if (!$changed) { $db->sql_query('ROLLBACK TO SAVEPOINT phpbb_role_target'); }
			$db->sql_query('RELEASE SAVEPOINT phpbb_role_target');
			$output[$changed ? 'changed' : 'skipped'][] = array('user_id' => $id, 'username' => $row['username']);
		}
		$db->commit('maintenance');
		return $output;
	}
	finally { $db->rollback(); $lock->release(); }
}
