<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_session_reset_actor($db)
{
	return phpbb_acl_actor($db, 'maintenance');
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
