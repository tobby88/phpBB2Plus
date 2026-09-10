<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_date_actor($db)
{
	global $userdata;
	$actor = phpbb_acl_actor($db, 'maintenance');
	$actor['guard'] .= ' AND EXISTS (SELECT 1 FROM ' . SESSIONS_TABLE
		. " date_session WHERE HEX(date_session.session_id) = HEX('" . $db->sql_escape($userdata['session_id'])
		. "') AND date_session.session_user_id = " . (int) $actor['user_id']
		. ' AND date_session.session_logged_in = 1 AND date_session.session_admin = 1)';
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $actor['guard'])) { phpbb_acl_error('Not_Authorised'); }
	return $actor;
}

function dbmtnc_date_pm_ready()
{
	// A published sent copy may still belong to an interrupted read operation.
	// Both sides must retain their original equal dates until that intent clears.
	return "privmsgs_write_payload IS NULL AND privmsgs_read_token = '' AND privmsgs_type <> " . PRIVMSGS_PENDING_SENT_MAIL
		. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT privmsgs_read_token,privmsgs_read_copy_id FROM ' . PRIVMSGS_TABLE
		. " WHERE privmsgs_read_token <> '') date_pending WHERE date_pending.privmsgs_read_copy_id = " . PRIVMSGS_TABLE . '.privmsgs_id'
		. ' OR HEX(date_pending.privmsgs_read_token) = HEX(' . PRIVMSGS_TABLE . '.privmsgs_copy_token))';
}

function dbmtnc_reset_dates($database, $request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($request['sid']) || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid']))
	{ phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_date_reset_failed');
		dbmtnc_date_actor($db);
		// Constants/columns are internal, not request-controlled. Repair only future
		// timestamps; the statement uses current values, never selected snapshots.
		$steps = array(
			'posts' => array(POSTS_TABLE, 'post_time', '1 = 1'),
			'pm' => array(PRIVMSGS_TABLE, 'privmsgs_date', dbmtnc_date_pm_ready()),
			'email' => array(USERS_TABLE, 'user_emailtime', 'user_id > 0'),
			'login' => array(USERS_TABLE, 'user_last_login_try', 'user_id > 0'),
			'search' => array(SEARCH_TABLE, 'search_time', '1 = 1')
		);
		$output = array();
		foreach ($steps as $name => $step)
		{
			$actor = dbmtnc_date_actor($db);
			$now = time();
			$sql = $name === 'search' ? 'DELETE FROM ' . $step[0] : 'UPDATE ' . $step[0] . ' SET ' . $step[1] . ' = ' . $now;
			$db->sql_query($sql . ' WHERE ' . $step[1] . ' > ' . $now . ' AND ' . $step[2] . ' AND ' . $actor['guard']);
			$output[$name] = (int) $db->sql_affectedrows();
			dbmtnc_date_actor($db);
		}
		$rows = phpbb_acl_rows($db, 'SELECT COUNT(*) AS deferred FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_date > ' . time()
			. ' AND NOT (' . dbmtnc_date_pm_ready() . ')');
		$output['deferred'] = (int) $rows[0]['deferred'];
		dbmtnc_date_actor($db);
		return $output;
	}
	finally { $lock->release(); }
}
