<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';
require_once dirname(__FILE__) . '/functions_acl_storage.php';
require_once dirname(__FILE__) . '/functions_search.php';
require_once dirname(__FILE__) . '/functions_maintenance_search.php';

// One atomic, bounded config value is authoritative; legacy cursor fields are
// display mirrors only. No ID-counter reset or whole-index truncation is needed.
function dbmtnc_rebuild_decode($raw)
{
	if ($raw === null || $raw === '') { return null; }
	$s = json_decode($raw, true);
	if (!is_array($s) || count($s) !== 6 || !isset($s['v'],$s['g'],$s['p'],$s['e'],$s['b'],$s['s'])
		|| $s['v'] !== 1 || !is_string($s['g']) || !preg_match('/^[a-f0-9]{32}$/D', $s['g'])
		|| !is_int($s['p']) || !is_int($s['e']) || $s['p'] < 0 || $s['e'] < $s['p'] || $s['e'] > 2147483647
		|| !in_array($s['b'],array(0,1),true) || !in_array($s['s'],array('reset','run','finish','release','done'),true))
	{ phpbb_acl_error('Maintenance_rebuild_state_invalid'); }
	return $s;
}

function dbmtnc_rebuild_read($db)
{
	$rows = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_rebuild_job'");
	if (count($rows) > 1) { phpbb_acl_error('Maintenance_rebuild_state_invalid'); }
	$raw = $rows ? (string) $rows[0]['config_value'] : null;
	return array('raw' => $raw, 'state' => dbmtnc_rebuild_decode($raw));
}

function dbmtnc_rebuild_job_guard($raw)
{
	return 'EXISTS (SELECT 1 FROM ' . CONFIG_TABLE . " rebuild_job WHERE config_name = 'dbmtnc_rebuild_job' AND HEX(config_value) = '" . strtoupper(bin2hex($raw)) . "')";
}

function dbmtnc_rebuild_guard($db, $raw)
{
	$actor = phpbb_acl_actor($db, 'maintenance');
	$current = dbmtnc_rebuild_read($db);
	if ($current['raw'] !== $raw) { phpbb_acl_error('Maintenance_rebuild_changed'); }
	return $actor['guard'] . ' AND ' . dbmtnc_rebuild_job_guard($raw);
}

function dbmtnc_rebuild_store($db, &$job, $state)
{
	$raw = json_encode($state);
	if (!is_string($raw) || strlen($raw) > 255) { phpbb_acl_error('Maintenance_rebuild_state_invalid'); }
	dbmtnc_rebuild_decode($raw);
	$actor = phpbb_acl_actor($db, 'maintenance');
	if ($job['raw'] === null)
	{
		$sql = 'INSERT INTO ' . CONFIG_TABLE . " (config_name,config_value) SELECT 'dbmtnc_rebuild_job','" . $db->sql_escape($raw) . "' WHERE " . $actor['guard']
			. ' AND NOT EXISTS (SELECT 1 FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_rebuild_job')";
	}
	else
	{
		$sql = 'UPDATE ' . CONFIG_TABLE . " SET config_value = '" . $db->sql_escape($raw) . "' WHERE config_name = 'dbmtnc_rebuild_job' AND HEX(config_value) = '"
			. strtoupper(bin2hex($job['raw'])) . "' AND " . $actor['guard'];
	}
	$db->sql_query($sql);
	if ((int) $db->sql_affectedrows() !== 1) { phpbb_acl_error('Maintenance_rebuild_changed'); }
	$job = array('raw' => $raw, 'state' => $state);
	dbmtnc_rebuild_guard($db, $raw);
}

function dbmtnc_rebuild_setting($db, $name)
{
	$rows = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = '" . $db->sql_escape($name) . "'");
	if (count($rows) !== 1) { phpbb_acl_error('Maintenance_rebuild_state_invalid'); }
	return (string) $rows[0]['config_value'];
}

function dbmtnc_rebuild_set_setting($db, $job, $name, $value)
{
	$old = dbmtnc_rebuild_setting($db, $name);
	if ($old === (string) $value) { return; }
	$guard = dbmtnc_rebuild_guard($db, $job['raw']);
	// The job guard reads a different config row. Materialize it for MySQL's
	// same-table UPDATE restriction instead of relying on derived-table merging.
	$guard = str_replace('FROM ' . CONFIG_TABLE . ' rebuild_job', 'FROM (SELECT DISTINCT config_name,config_value FROM ' . CONFIG_TABLE . ') rebuild_job', $guard);
	$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value = '" . $db->sql_escape((string) $value) . "' WHERE config_name = '" . $db->sql_escape($name)
		. "' AND HEX(config_value) = '" . strtoupper(bin2hex($old)) . "' AND " . $guard);
	if ((int) $db->sql_affectedrows() !== 1) { phpbb_acl_error('Maintenance_rebuild_changed'); }
}

function dbmtnc_rebuild_token($generation, $position, $sid)
{
	return hash_hmac('sha256', 'dbmtnc_rebuild|' . $generation . '|' . $position, $sid);
}

function dbmtnc_rebuild_url($state)
{
	global $phpEx, $userdata;
	return append_sid('admin_db_maintenance.' . $phpEx . '?mode=perform&amp;function=perform_rebuild&amp;job=' . $state['g']
		. '&amp;pos=' . $state['p'] . '&amp;token=' . dbmtnc_rebuild_token($state['g'],(string)$state['p'],(string)$userdata['session_id']));
}

function dbmtnc_rebuild_request($mode, $request)
{
	global $userdata;
	if (!is_array($request) || empty($userdata['session_id']) || !in_array($mode,array('start','resume','step'),true)) { phpbb_acl_error('Invalid_dbmtnc_request'); }
	$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
	if ($mode !== 'step')
	{
		if ($method !== 'POST' || !isset($request['sid']) || !is_string($request['sid']) || !hash_equals((string)$userdata['session_id'],$request['sid'])) { phpbb_acl_error('Session_invalid'); }
		if (isset($request['job']) && (!is_string($request['job']) || !preg_match('/^(?:[a-f0-9]{32})?$/D',$request['job']))) { phpbb_acl_error('Invalid_dbmtnc_request'); }
		return;
	}
	if ($method !== 'GET' || !isset($request['job'],$request['pos'],$request['token']) || !is_string($request['job'])
		|| !preg_match('/^[a-f0-9]{32}$/D',$request['job']) || !is_string($request['pos']) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$request['pos'])
		|| (float)$request['pos'] > 2147483647 || !is_string($request['token'])
		|| !hash_equals(dbmtnc_rebuild_token($request['job'],$request['pos'],(string)$userdata['session_id']),$request['token']))
	{ phpbb_acl_error('Invalid_dbmtnc_request'); }
}

function dbmtnc_rebuild_tokens($text, $subject, $stopwords, $synonyms)
{
	$output = array();
	foreach (array(0 => $text, 1 => $subject) as $title => $content)
	{
		$words = split_words(clean_words('post',$content,$stopwords,$synonyms));
		foreach ($words as $word)
		{
			$word = trim($word);
			if ($word !== '') { $output[$title . ':' . $word] = array('word' => $word, 'title' => $title); }
		}
	}
	return array_values($output);
}

function dbmtnc_rebuild_source_guard($row)
{
	return 'EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' rebuild_post INNER JOIN ' . POSTS_TEXT_TABLE . ' rebuild_text ON rebuild_text.post_id = rebuild_post.post_id'
		. ' WHERE rebuild_post.post_id = ' . (int)$row['post_id'] . ' AND rebuild_post.topic_id = ' . (int)$row['topic_id']
		. ' AND rebuild_post.forum_id = ' . (int)$row['forum_id'] . ' AND rebuild_post.poster_id = ' . (int)$row['poster_id']
		. " AND SHA2(rebuild_text.post_text,256) = '" . hash('sha256',$row['post_text']) . "' AND SHA2(rebuild_text.post_subject,256) = '" . hash('sha256',$row['post_subject']) . "')";
}

function dbmtnc_rebuild_post($db, $job, $row, $stopwords, $synonyms)
{
	$id = (int)$row['post_id'];
	$scope = dbmtnc_rebuild_source_guard($row);
	$guard = dbmtnc_rebuild_guard($db,$job['raw']) . ' AND ' . $scope;
	$db->sql_query('DELETE FROM ' . SEARCH_MATCH_TABLE . ' WHERE post_id = ' . $id . ' AND ' . $guard);
	foreach (dbmtnc_rebuild_tokens($row['post_text'],$row['post_subject'],$stopwords,$synonyms) as $token)
	{
		// Reuse the SQL predicate, not an authorization result: the database
		// evaluates current actor, job and source on every statement. Avoid
		// repeating several metadata reads for every word in a long post.
		$word = $db->sql_escape($token['word']);
		$db->sql_query('INSERT INTO ' . SEARCH_WORD_TABLE . " (word_text,word_common) SELECT '" . $word . "',0 WHERE " . $guard
			. ' AND NOT EXISTS (SELECT 1 FROM ' . SEARCH_WORD_TABLE . " WHERE word_text = '" . $word . "')");
		$db->sql_query('INSERT INTO ' . SEARCH_MATCH_TABLE . ' (post_id,word_id,title_match) SELECT ' . $id . ',word_id,' . (int)$token['title']
			. ' FROM ' . SEARCH_WORD_TABLE . " WHERE word_text = '" . $word . "' AND word_common = 0 AND " . $guard
			. ' AND NOT EXISTS (SELECT 1 FROM ' . SEARCH_MATCH_TABLE . ' existing_match WHERE existing_match.post_id = ' . $id
			. ' AND existing_match.word_id = ' . SEARCH_WORD_TABLE . '.word_id AND existing_match.title_match = ' . (int)$token['title'] . ')');
		$words = phpbb_acl_rows($db,'SELECT word_id FROM ' . SEARCH_WORD_TABLE . " WHERE word_text = '" . $word . "'");
		if (!$words) { dbmtnc_rebuild_guard($db,$job['raw']); phpbb_acl_error('Maintenance_rebuild_changed'); }
	}
	$valid = phpbb_acl_rows($db,'SELECT post_id FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $id . ' AND ' . $scope);
	if (!$valid) { phpbb_acl_error('Maintenance_rebuild_changed'); }
	dbmtnc_rebuild_guard($db,$job['raw']);
}

// Finalization is replayable on nontransactional tables too. Keep common flags
// once classified: their matches may already have been removed by an earlier
// attempt. Each request removes at most one bounded batch of obsolete rows.
function dbmtnc_rebuild_finish($db, &$job)
{
	if ($job['state']['s'] === 'finish')
	{
		$total = '(SELECT COUNT(*) FROM ' . POSTS_TABLE . ')';
		$common = 'word_common = 0 AND ' . $total . ' >= 100 AND (SELECT COUNT(DISTINCT sm.post_id) FROM ' . SEARCH_MATCH_TABLE
			. ' sm INNER JOIN ' . POSTS_TABLE . ' p ON p.post_id = sm.post_id WHERE sm.word_id = ' . SEARCH_WORD_TABLE . '.word_id) > (' . $total . ' * 0.4)';
		$rows = phpbb_acl_rows($db,'SELECT word_id FROM ' . SEARCH_WORD_TABLE . ' WHERE ' . $common . ' ORDER BY word_id LIMIT 100');
		if ($rows)
		{
			$ids = array(); foreach ($rows as $row) { $ids[] = (int)$row['word_id']; }
			$db->sql_query('UPDATE ' . SEARCH_WORD_TABLE . ' SET word_common = 1 WHERE word_id IN (' . implode(',',$ids) . ') AND '
				. $common . ' AND ' . dbmtnc_rebuild_guard($db,$job['raw']));
			dbmtnc_rebuild_guard($db,$job['raw']);
			return;
		}
		foreach (array(false,true) as $words)
		{
			$table = $words ? SEARCH_WORD_TABLE : SEARCH_MATCH_TABLE;
			$key = $words ? 'word_id' : 'post_id';
			$predicate = $words
				? 'word_common <> 1 AND NOT EXISTS (SELECT 1 FROM ' . SEARCH_MATCH_TABLE . ' sm WHERE sm.word_id = ' . SEARCH_WORD_TABLE . '.word_id)'
				: dbmtnc_invalid_search_match(SEARCH_MATCH_TABLE);
			$rows = phpbb_acl_rows($db,'SELECT DISTINCT ' . $key . ' FROM ' . $table . ' WHERE ' . $predicate . ' ORDER BY ' . $key . ' LIMIT 100');
			if (!$rows) { continue; }
			$ids = array(); foreach ($rows as $row) { $ids[] = (int)$row[$key]; }
			$db->sql_query('DELETE FROM ' . $table . ' WHERE ' . $key . ' IN (' . implode(',',$ids) . ') AND ' . $predicate
				. ' AND ' . dbmtnc_rebuild_guard($db,$job['raw']));
			dbmtnc_rebuild_guard($db,$job['raw']);
			return;
		}
		// Save a separate release phase BEFORE restoring settings. A failed final
		// checkpoint can then be retried without closing an already reopened board.
		$state = $job['state']; $state['s'] = 'release'; dbmtnc_rebuild_store($db,$job,$state);
	}
	if ($job['state']['s'] === 'release')
	{
		dbmtnc_rebuild_set_setting($db,$job,'dbmtnc_rebuild_pos','-1');
		dbmtnc_rebuild_set_setting($db,$job,'dbmtnc_rebuild_end','0');
		dbmtnc_rebuild_set_setting($db,$job,'board_disable',(string)$job['state']['b']);
		$state = $job['state']; $state['s'] = 'done'; dbmtnc_rebuild_store($db,$job,$state);
	}
}

// The controller must keep following signed continuations through finish and
// release. Merely reaching the last post is not a completed rebuild.
function dbmtnc_rebuild_batch($database, $mode, $request, $stopwords, $synonyms)
{
	dbmtnc_rebuild_request($mode,$request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection,'Maintenance_rebuild_failed');
		phpbb_acl_actor($db,'maintenance'); $job = dbmtnc_rebuild_read($db);
		if ($mode === 'start' || ($mode === 'resume' && !$job['state']))
		{
			$expected = $job['state'] && $job['state']['s'] !== 'done' ? $job['state']['g'] : '';
			if ((isset($request['job']) ? $request['job'] : '') !== $expected) { phpbb_acl_error('Maintenance_rebuild_changed'); }
			$end = phpbb_acl_rows($db,'SELECT COALESCE(MAX(post_id),0) AS last_id FROM ' . POSTS_TABLE);
			$bytes = phpbb_random_bytes(16);
			if (!is_string($bytes) || strlen($bytes) !== 16) { phpbb_acl_error('Maintenance_rebuild_failed'); }
			$state = array('v'=>1,'g'=>bin2hex($bytes),'p'=>0,'e'=>(int)$end[0]['last_id'],'b'=>dbmtnc_rebuild_setting($db,'board_disable') === '1' ? 1 : 0,'s'=>'reset');
			if ($job['state'] && $job['state']['s'] !== 'done') { $state['b'] = $job['state']['b']; }
			if ($mode === 'resume')
			{
				$pos = dbmtnc_rebuild_setting($db,'dbmtnc_rebuild_pos'); $last = dbmtnc_rebuild_setting($db,'dbmtnc_rebuild_end');
				if (!preg_match('/^[0-9]+$/D',$pos) || !preg_match('/^[0-9]+$/D',$last) || (float)$last > 2147483647 || (float)$pos > (float)$last) { phpbb_acl_error('Maintenance_rebuild_state_invalid'); }
				$state['p'] = (int)$pos; $state['e'] = (int)$last; $state['s'] = 'run';
			}
			dbmtnc_rebuild_store($db,$job,$state);
		}
		elseif (!$job['state']) { phpbb_acl_error('Maintenance_rebuild_changed'); }
		if ($mode === 'resume' && !empty($request['job']) && $request['job'] !== $job['state']['g']) { phpbb_acl_error('Maintenance_rebuild_changed'); }
		if ($mode === 'step')
		{
			if ($request['job'] !== $job['state']['g']) { phpbb_acl_error('Maintenance_rebuild_changed'); }
			if ((int)$request['pos'] !== $job['state']['p']) { return $job; }
		}
		if ($job['state']['s'] === 'done') { return $job; }
		if ($job['state']['s'] === 'release') { dbmtnc_rebuild_finish($db,$job); return $job; }
		dbmtnc_rebuild_set_setting($db,$job,'board_disable','1');
		if ($job['state']['s'] === 'finish') { dbmtnc_rebuild_finish($db,$job); return $job; }
		if ($job['state']['s'] === 'reset')
		{
			$db->sql_query('UPDATE ' . SEARCH_WORD_TABLE . ' SET word_common = 0 WHERE word_common <> 0 AND ' . dbmtnc_rebuild_guard($db,$job['raw']));
			$db->sql_query('DELETE FROM ' . SEARCH_TABLE . ' WHERE ' . dbmtnc_rebuild_guard($db,$job['raw']));
			$state = $job['state']; $state['s'] = 'run'; dbmtnc_rebuild_store($db,$job,$state);
		}
		$ids = phpbb_acl_rows($db,'SELECT post_id FROM ' . POSTS_TABLE . ' WHERE post_id > ' . $job['state']['p'] . ' AND post_id <= ' . $job['state']['e'] . ' ORDER BY post_id LIMIT 25');
		$deadline = microtime(true) + 2;
		foreach ($ids as $id)
		{
			$rows = phpbb_acl_rows($db,'SELECT p.post_id,p.topic_id,p.forum_id,p.poster_id,t.post_subject,t.post_text FROM ' . POSTS_TABLE . ' p LEFT JOIN ' . POSTS_TEXT_TABLE . ' t ON t.post_id = p.post_id WHERE p.post_id = ' . (int)$id['post_id']);
			if ($rows)
			{
				if (!isset($rows[0]['post_text'],$rows[0]['post_subject'])) { phpbb_acl_error('Maintenance_rebuild_text_missing'); }
				dbmtnc_rebuild_post($db,$job,$rows[0],$stopwords,$synonyms);
			}
			$state = $job['state']; $state['p'] = (int)$id['post_id']; dbmtnc_rebuild_store($db,$job,$state);
			if (microtime(true) >= $deadline) { break; }
		}
		if (!$ids) { $state=$job['state'];$state['s']='finish';dbmtnc_rebuild_store($db,$job,$state); }
		dbmtnc_rebuild_set_setting($db,$job,'dbmtnc_rebuild_pos',(string)$job['state']['p']);
		dbmtnc_rebuild_set_setting($db,$job,'dbmtnc_rebuild_end',(string)$job['state']['e']);
		dbmtnc_rebuild_guard($db,$job['raw']);
		return $job;
	}
	finally { $lock->release(); }
}
