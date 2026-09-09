<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_poll_request($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals((string)$userdata['session_id'],$request['sid'])) { phpbb_acl_error('Session_invalid'); }
}

// Internal, trusted SQL fragments only. Candidate IDs bound each batch; the
// current predicate and actor authorization still decide every changed row.
function dbmtnc_poll_batches($db, $table, $key, $predicate, $assignment = '')
{
	$cursor = null; $changed = 0;
	while (true)
	{
		phpbb_acl_actor($db,'maintenance');
		$rows = phpbb_acl_rows($db,'SELECT DISTINCT ' . $key . ' FROM ' . $table . ' WHERE (' . $predicate . ')'
			. ($cursor === null ? '' : ' AND ' . $key . ' > ' . $cursor) . ' ORDER BY ' . $key . ' LIMIT 100');
		if (!$rows) { break; }
		$ids = array(); foreach ($rows as $row) { $ids[] = (int)$row[$key]; }
		$cursor = end($ids); $actor = phpbb_acl_actor($db,'maintenance');
		$db->sql_query(($assignment === '' ? 'DELETE FROM ' . $table : 'UPDATE ' . $table . ' SET ' . $assignment)
			. ' WHERE ' . $key . ' IN (' . implode(',',$ids) . ') AND (' . $predicate . ') AND ' . $actor['guard']);
		$changed += (int)$db->sql_affectedrows();
		phpbb_acl_actor($db,'maintenance');
	}
	return $changed;
}

function dbmtnc_maintain_polls($database, $request)
{
	dbmtnc_poll_request($request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection,'Maintenance_poll_failed');
		phpbb_acl_actor($db,'maintenance');
		$output = array();
		$output['polls_removed'] = dbmtnc_poll_batches($db,VOTE_DESC_TABLE,'vote_id',
			'NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id = ' . VOTE_DESC_TABLE . '.topic_id)');
		// Remove dependents only if their parent is STILL absent. This is also
		// safe to resume after a failure between the three nontransactional tables.
		$output['options_removed'] = dbmtnc_poll_batches($db,VOTE_RESULTS_TABLE,'vote_id',
			'NOT EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' v WHERE v.vote_id = ' . VOTE_RESULTS_TABLE . '.vote_id)');
		$output['voters_removed'] = dbmtnc_poll_batches($db,VOTE_USERS_TABLE,'vote_id',
			'NOT EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' v WHERE v.vote_id = ' . VOTE_USERS_TABLE . '.vote_id)');
		// Keep vote history and its IP-based guest identity, consistent with the
		// normal account-removal path. Never discard anonymous/deleted voters just
		// because the anonymous users-table sentinel itself needs repair.
		$output['voters_anonymized'] = dbmtnc_poll_batches($db,VOTE_USERS_TABLE,'vote_user_id',
			'vote_user_id <> ' . DELETED . ' AND NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' u WHERE u.user_id = ' . VOTE_USERS_TABLE . '.vote_user_id)',
			'vote_user_id = ' . DELETED);
		$has_poll = 'EXISTS (SELECT 1 FROM ' . VOTE_DESC_TABLE . ' v WHERE v.topic_id = ' . TOPICS_TABLE . '.topic_id)';
		$value = '(CASE WHEN ' . $has_poll . ' THEN 1 ELSE 0 END)';
		$output['topics_updated'] = dbmtnc_poll_batches($db,TOPICS_TABLE,'topic_id','topic_vote <> ' . $value,'topic_vote = ' . $value);
		// Missing option text and multiple polls per topic cannot be reconstructed
		// from voter counts. Report these source records instead of destroying them.
		$review = '(NOT EXISTS (SELECT 1 FROM ' . VOTE_RESULTS_TABLE . ' r WHERE r.vote_id = v.vote_id)'
			. ' OR (SELECT COUNT(*) FROM ' . VOTE_DESC_TABLE . ' other WHERE other.topic_id = v.topic_id) > 1'
			. ' OR NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id = v.topic_id))';
		$count = phpbb_acl_rows($db,'SELECT COUNT(*) AS total FROM ' . VOTE_DESC_TABLE . ' v WHERE ' . $review);
		$output['review_count'] = (int)$count[0]['total'];
		$output['review'] = phpbb_acl_rows($db,'SELECT v.vote_id,v.topic_id,v.vote_text FROM ' . VOTE_DESC_TABLE . ' v WHERE ' . $review . ' ORDER BY v.vote_id LIMIT 100');
		phpbb_acl_actor($db,'maintenance');
		return $output;
	}
	finally { $lock->release(); }
}
