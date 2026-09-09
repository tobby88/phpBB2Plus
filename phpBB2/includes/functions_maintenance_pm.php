<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_pm_counter_request($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals((string)$userdata['session_id'],$request['sid'])) { phpbb_acl_error('Session_invalid'); }
}

// Derived counters only: no message, mailbox, read-state or attachment changes.
function dbmtnc_synchronize_pm_counters($database, $request)
{
	dbmtnc_pm_counter_request($request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection,'Maintenance_pm_counter_failed');
		phpbb_acl_actor($db,'maintenance');
		$new_count = '(SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' pm WHERE pm.privmsgs_to_userid = ' . USERS_TABLE . '.user_id AND pm.privmsgs_type = ' . PRIVMSGS_NEW_MAIL . ')';
		$unread_count = '(SELECT COUNT(*) FROM ' . PRIVMSGS_TABLE . ' pm WHERE pm.privmsgs_to_userid = ' . USERS_TABLE . '.user_id AND pm.privmsgs_type = ' . PRIVMSGS_UNREAD_MAIL . ')';
		$different = '(user_new_privmsg IS NULL OR user_unread_privmsg IS NULL OR user_new_privmsg <> ' . $new_count . ' OR user_unread_privmsg <> ' . $unread_count . ')';
		$cursor = 0; $changed = 0;
		while (true)
		{
			phpbb_acl_actor($db,'maintenance');
			$rows = phpbb_acl_rows($db,'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id > ' . $cursor . ' AND ' . $different . ' ORDER BY user_id LIMIT 100');
			if (!$rows) { break; }
			$ids = array(); foreach ($rows as $row) { $ids[] = (int)$row['user_id']; }
			$cursor = end($ids); $actor = phpbb_acl_actor($db,'maintenance');
			// Compute both current counts inside the same write. Candidate IDs may
			// be stale after delivery/read/delete; their earlier counts never are
			// published. Nonpositive anonymous/deleted-user sentinels stay untouched.
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_new_privmsg = ' . $new_count . ', user_unread_privmsg = ' . $unread_count
				. ' WHERE user_id > 0 AND user_id IN (' . implode(',',$ids) . ') AND ' . $different . ' AND ' . $actor['guard']);
			$changed += (int)$db->sql_affectedrows();
			phpbb_acl_actor($db,'maintenance');
		}
		phpbb_acl_actor($db,'maintenance');
		return $changed;
	}
	finally { $lock->release(); }
}
