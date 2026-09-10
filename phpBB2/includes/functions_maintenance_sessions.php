<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_session_reset_actor($db)
{
	global $userdata;
	$actor = phpbb_acl_actor($db, 'maintenance');
	$sid = $db->sql_escape($userdata['session_id']);
	$where = "HEX(session_id) = HEX('" . $sid . "') AND session_user_id = " . (int) $actor['user_id']
		. ' AND session_logged_in = 1 AND session_admin = 1';
	if (!phpbb_acl_rows($db, 'SELECT session_id FROM ' . SESSIONS_TABLE . ' WHERE ' . $where))
	{ phpbb_acl_error('Not_Authorised'); }
	// Materialize the session lookup when the DELETE targets this same table.
	// Never recreate an expired/revoked session from cached userdata.
	$actor['guard'] .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT session_id, session_user_id, session_logged_in, session_admin FROM '
		. SESSIONS_TABLE . " WHERE HEX(session_id) = HEX('" . $sid . "')) reset_actor_session WHERE " . $where . ')';
	return $actor;
}

function dbmtnc_reset_sessions($database, $request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid']))
	{ phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_session_reset_failed');
		$actor = dbmtnc_session_reset_actor($db);
		// Clear the disposable search cache first. A failure must not needlessly
		// log users out. Earlier successful work is not transactional/rolled back.
		$db->sql_query('DELETE FROM ' . SEARCH_TABLE . ' WHERE ' . $actor['guard']);
		$output = array('searches' => (int) $db->sql_affectedrows());
		$actor = dbmtnc_session_reset_actor($db);
		$db->sql_query('DELETE FROM ' . SESSIONS_TABLE . " WHERE HEX(session_id) <> HEX('"
			. $db->sql_escape($userdata['session_id']) . "') AND " . $actor['guard']);
		$output['sessions'] = (int) $db->sql_affectedrows();
		dbmtnc_session_reset_actor($db);
		return $output;
	}
	finally { $lock->release(); }
}
