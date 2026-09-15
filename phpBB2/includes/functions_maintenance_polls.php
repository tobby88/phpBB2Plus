<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

// Poll maintenance is one database operation, including its review result.
// Only the current ACP session/module may commit; no old-format fallback.
class PhpbbPollMaintenanceDatabase extends PhpbbAclDatabase
{
	var $transactional = false;
	function control($sql) { return parent::sql_query($sql); }
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { phpbb_acl_error($this->failure_key); }
		if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_acl_error($this->failure_key); }
			dbmtnc_date_actor($this);
		}
		return $this->control($sql);
	}
	function begin()
	{
		if ($this->transactional) { phpbb_acl_error($this->failure_key); }
		dbmtnc_date_actor($this);
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, TOPICS_TABLE, VOTE_DESC_TABLE, VOTE_RESULTS_TABLE, VOTE_USERS_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$name = $this->sql_escape($table);
			$rows = phpbb_acl_rows($this,"SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_acl_error('Maintenance_poll_upgrade'); }
		}
		dbmtnc_date_actor($this);
	}
	function commit()
	{
		global $userdata;
		if (!$this->transactional) { phpbb_acl_error($this->failure_key); }
		$actor = dbmtnc_date_actor($this); $sid = $this->sql_escape($userdata['session_id']);
		foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '" . $sid . "' AND HEX(session_id) = HEX('" . $sid . "')",
			'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int)$actor['user_id'],
			'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id = ' . (int)$actor['user_id']) as $sql)
		{
			$r = $this->sql_query($sql . ' LOCK IN SHARE MODE'); $this->sql_freeresult($r);
		}
		dbmtnc_date_actor($this); $this->control('COMMIT'); $this->transactional = false;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false;
	}
}

function dbmtnc_poll_request($request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals((string)$userdata['session_id'],$request['sid'])) { phpbb_acl_error('Session_invalid'); }
}

// Internal, trusted SQL fragments only. Candidate IDs bound each batch; the
// current predicate and actor authorization still decide every changed row.
function dbmtnc_poll_batches($db, $table, $key, $predicate, $assignment = '')
{
	if (!($db instanceof PhpbbPollMaintenanceDatabase) || !$db->transactional) { phpbb_acl_error('Maintenance_poll_unconfirmed'); }
	$cursor = null; $changed = 0;
	while (true)
	{
		dbmtnc_date_actor($db);
		$rows = phpbb_acl_rows($db,'SELECT DISTINCT ' . $key . ' FROM ' . $table . ' WHERE (' . $predicate . ')'
			. ($cursor === null ? '' : ' AND ' . $key . ' > ' . $cursor) . ' ORDER BY ' . $key . ' LIMIT 100');
		if (!$rows) { break; }
		$ids = array(); foreach ($rows as $row) { $ids[] = (int)$row[$key]; }
		$cursor = end($ids); $actor = dbmtnc_date_actor($db);
		$db->sql_query(($assignment === '' ? 'DELETE FROM ' . $table : 'UPDATE ' . $table . ' SET ' . $assignment)
			. ' WHERE ' . $key . ' IN (' . implode(',',$ids) . ') AND (' . $predicate . ') AND ' . $actor['guard']);
		$changed += (int)$db->sql_affectedrows();
		dbmtnc_date_actor($db);
	}
	return $changed;
}

function dbmtnc_maintain_polls($database, $request)
{
	dbmtnc_poll_request($request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$db = new PhpbbPollMaintenanceDatabase($lock->connection,'Maintenance_poll_unconfirmed');
	try
	{
		$db->begin();
		$output = array();
		$output['polls_removed'] = dbmtnc_poll_batches($db,VOTE_DESC_TABLE,'vote_id',
			'NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id = ' . VOTE_DESC_TABLE . '.topic_id)');
		// Keep current parent guards even within the transaction: an independent
		// writer can restore an initially missing source before this statement.
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
		// Missing options, multiple polls per topic and ambiguous option identities
		// cannot be reconstructed from voter counts. Report, never guess or renumber.
		$review = '(NOT EXISTS (SELECT 1 FROM ' . VOTE_RESULTS_TABLE . ' r WHERE r.vote_id = v.vote_id)'
			. ' OR EXISTS (SELECT 1 FROM ' . VOTE_RESULTS_TABLE . ' r WHERE r.vote_id = v.vote_id AND (r.vote_option_id IS NULL OR r.vote_option_id < 1 OR r.vote_option_id > 255))'
			. ' OR EXISTS (SELECT 1 FROM ' . VOTE_RESULTS_TABLE . ' r WHERE r.vote_id = v.vote_id GROUP BY r.vote_option_id HAVING COUNT(*) > 1)'
			. ' OR (SELECT COUNT(*) FROM ' . VOTE_DESC_TABLE . ' other WHERE other.topic_id = v.topic_id) > 1'
			. ' OR NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' t WHERE t.topic_id = v.topic_id))';
		$count = phpbb_acl_rows($db,'SELECT COUNT(*) AS total FROM ' . VOTE_DESC_TABLE . ' v WHERE ' . $review);
		$output['review_count'] = (int)$count[0]['total'];
		$output['review'] = phpbb_acl_rows($db,'SELECT v.vote_id,v.topic_id,v.vote_text FROM ' . VOTE_DESC_TABLE . ' v WHERE ' . $review . ' ORDER BY v.vote_id LIMIT 100');
		$db->commit();
		return $output;
	}
	catch (PhpbbAclException $e) { throw $e; }
	catch (Exception $e) { phpbb_acl_error('Maintenance_poll_unconfirmed'); }
	catch (Error $e) { phpbb_acl_error('Maintenance_poll_unconfirmed'); }
	finally { $db->rollback(); $lock->release(); }
}
