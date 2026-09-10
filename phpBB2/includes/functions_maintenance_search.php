<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

function dbmtnc_search_cleanup_request($mode, $request)
{
	global $userdata;
	if (!in_array($mode, array('check_search_wordlist', 'check_search_wordmatch'), true)) { phpbb_acl_error('Invalid_dbmtnc_request'); }
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
}

function dbmtnc_invalid_search_match($alias)
{
	return '(NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' p WHERE p.post_id = ' . $alias . '.post_id)'
		. ' OR NOT EXISTS (SELECT 1 FROM ' . SEARCH_WORD_TABLE . ' w WHERE w.word_id = ' . $alias . '.word_id)'
		. ' OR EXISTS (SELECT 1 FROM ' . SEARCH_WORD_TABLE . ' w WHERE w.word_id = ' . $alias . '.word_id AND w.word_common = 1))';
}

// Only orphan/common-word index cleanup, never source-post or whole-index removal.
function dbmtnc_cleanup_search($database, $mode, $request)
{
	dbmtnc_search_cleanup_request($mode, $request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_search_cleanup_failed');
		dbmtnc_date_actor($db);
		$words = $mode === 'check_search_wordlist';
		$table = $words ? SEARCH_WORD_TABLE : SEARCH_MATCH_TABLE;
		$key = $words ? 'word_id' : 'post_id';
		$predicate = $words
			? 'word_common <> 1 AND NOT EXISTS (SELECT 1 FROM ' . SEARCH_MATCH_TABLE . ' sm WHERE sm.word_id = ' . SEARCH_WORD_TABLE . '.word_id)'
			: dbmtnc_invalid_search_match(SEARCH_MATCH_TABLE);
		$cursor = null; $removed = 0;
		while (true)
		{
			dbmtnc_date_actor($db);
			$rows = phpbb_acl_rows($db, 'SELECT DISTINCT ' . $key . ' FROM ' . $table . ' WHERE ' . $predicate
				. ($cursor === null ? '' : ' AND ' . $key . ' > ' . $cursor) . ' ORDER BY ' . $key . ' LIMIT 100');
			if (!$rows) { break; }
			$ids = array();
			foreach ($rows as $row) { $ids[] = (int) $row[$key]; }
			$cursor = end($ids);
			$actor = dbmtnc_date_actor($db);
			// The candidate IDs bound the batch; current predicates decide deletion.
			$db->sql_query('DELETE FROM ' . $table . ' WHERE ' . $key . ' IN (' . implode(',', $ids) . ') AND ' . $predicate . ' AND ' . $actor['guard']);
			$removed += (int) $db->sql_affectedrows();
			dbmtnc_date_actor($db);
		}
		dbmtnc_date_actor($db);
		return $removed;
	}
	finally { $lock->release(); }
}
