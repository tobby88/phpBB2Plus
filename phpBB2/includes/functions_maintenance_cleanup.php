<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';
require_once dirname(__FILE__) . '/functions_maintenance_posts.php';

function dbmtnc_cleanup_predicate($kind, $table)
{
	if ($kind === 'posts') { return 'NOT EXISTS (SELECT 1 FROM ' . POSTS_TEXT_TABLE . ' current_text WHERE current_text.post_id = ' . $table . '.post_id)'; }
	if ($kind === 'topics') { return $table . '.maintenance_token IS NULL AND ' . $table . '.topic_status <> ' . TOPIC_MOVED . ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' current_post WHERE current_post.topic_id = ' . $table . '.topic_id)'; }
	if ($kind === 'redirects')
	{
		return $table . '.maintenance_token IS NULL AND ' . $table . '.topic_status = ' . TOPIC_MOVED
			. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' current_post WHERE current_post.topic_id = ' . $table . '.topic_id)'
			. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT topic_id FROM ' . TOPICS_TABLE . ') current_target WHERE current_target.topic_id = ' . $table . '.topic_moved_id)';
	}
	if ($kind === 'moved') { return $table . '.topic_status <> ' . TOPIC_MOVED . ' AND ' . $table . '.topic_moved_id <> 0'; }
	if ($kind === 'prune_orphans') { return 'NOT EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . ' current_forum WHERE current_forum.forum_id = ' . $table . '.forum_id)'; }
	if ($kind === 'prune_duplicates')
	{
		// Keep the oldest row, and only coalesce a wholly identical current policy.
		// Materialization also supports MySQL's same-target-table restrictions.
		$rows = '(SELECT DISTINCT prune_id,forum_id,prune_days,prune_freq FROM ' . PRUNE_TABLE . ')';
		return 'EXISTS (SELECT 1 FROM ' . $rows . ' older_policy WHERE older_policy.forum_id = ' . $table . '.forum_id AND older_policy.prune_id < ' . $table . '.prune_id)'
			. ' AND NOT EXISTS (SELECT 1 FROM ' . $rows . ' other_policy WHERE other_policy.forum_id = ' . $table . '.forum_id'
			. ' AND (other_policy.prune_days <> ' . $table . '.prune_days OR other_policy.prune_freq <> ' . $table . '.prune_freq))';
	}
	if ($kind === 'prune_disabled') { return $table . '.prune_enable = 1 AND NOT EXISTS (SELECT 1 FROM ' . PRUNE_TABLE . ' current_policy WHERE current_policy.forum_id = ' . $table . '.forum_id)'; }
	if ($kind === 'watch') { return '(NOT EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' cleanup_user WHERE cleanup_user.user_id = ' . $table . '.user_id) OR NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' current_topic WHERE current_topic.topic_id = ' . $table . '.topic_id))'; }
	if ($kind === 'acl') { return '(NOT EXISTS (SELECT 1 FROM ' . GROUPS_TABLE . ' current_group WHERE current_group.group_id = ' . $table . '.group_id) OR NOT EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . ' current_forum WHERE current_forum.forum_id = ' . $table . '.forum_id))'; }
	phpbb_acl_error('Maintenance_cleanup_failed');
}

// Lexicographic keyset bounds also handle malformed zero/negative child keys.
function dbmtnc_cleanup_bound($keys, $row, $upper)
{
	$parts = array(); $equal = array(); $last = count($keys) - 1;
	foreach ($keys as $position => $key)
	{
		$op = $upper ? ($position === $last ? '<=' : '<') : '>';
		$parts[] = '(' . ($equal ? implode(' AND ', $equal) . ' AND ' : '') . $key . ' ' . $op . ' ' . (int) $row[$key] . ')';
		$equal[] = $key . ' = ' . (int) $row[$key];
	}
	return '(' . implode(' OR ', $parts) . ')';
}

function dbmtnc_cleanup_structure($database, $request, $phase)
{
	global $userdata;
	if (!in_array($phase, array('parents', 'references'), true)) { phpbb_acl_error('Maintenance_cleanup_failed'); }
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$cache_needed = false;
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_cleanup_failed');
		dbmtnc_date_actor($db);
		$steps = $phase === 'parents' ? array(
			'posts' => array(POSTS_TABLE, array('post_id'), ''),
			'topics' => array(TOPICS_TABLE, array('topic_id'), '')
		) : array(
			'redirects' => array(TOPICS_TABLE, array('topic_id'), ''),
			'moved' => array(TOPICS_TABLE, array('topic_id'), 'topic_moved_id = 0'),
			'prune_orphans' => array(PRUNE_TABLE, array('prune_id'), ''),
			'prune_duplicates' => array(PRUNE_TABLE, array('prune_id'), ''),
			'prune_disabled' => array(FORUMS_TABLE, array('forum_id'), 'prune_enable = 0'),
			'watch' => array(TOPICS_WATCH_TABLE, array('user_id', 'topic_id'), ''),
			'acl' => array(AUTH_ACCESS_TABLE, array('group_id', 'forum_id'), '')
		);
		$output = array('changed' => 0, 'skipped' => 0, 'conflicts' => array(), 'steps' => array());
		foreach ($steps as $kind => $step)
		{
			list($table, $keys, $sets) = $step;
			$predicate = dbmtnc_cleanup_predicate($kind, $table);
			$output['steps'][$kind] = 0;
			$bound = phpbb_acl_rows($db, 'SELECT ' . implode(',', $keys) . ' FROM ' . $table . ' ORDER BY ' . implode(' DESC,', $keys) . ' DESC LIMIT 1');
			if (!$bound) { continue; }
			$upper = dbmtnc_cleanup_bound($keys, $bound[0], true); $after = null;
			do
			{
				$rows = phpbb_acl_rows($db, 'SELECT DISTINCT ' . implode(',', $keys) . ' FROM ' . $table . ' WHERE ' . $upper
					. ($after === null ? '' : ' AND ' . dbmtnc_cleanup_bound($keys, $after, false)) . ' AND ' . $predicate
					. ' ORDER BY ' . implode(',', $keys) . ' LIMIT 100');
				foreach ($rows as $row)
				{
					$after = $row; $where = array();
					foreach ($keys as $key) { $where[] = $key . ' = ' . (int) $row[$key]; }
					$actor = dbmtnc_date_actor($db); $cache_needed = true;
					$db->sql_query(($sets === '' ? 'DELETE FROM ' . $table : 'UPDATE ' . $table . ' SET ' . $sets)
						. ' WHERE ' . implode(' AND ', $where) . ' AND ' . $predicate . ' AND ' . $actor['guard']);
					$changed = (int) $db->sql_affectedrows();
					$output['steps'][$kind] += $changed; $output['changed'] += $changed;
					if (!$changed) { $output['skipped']++; }
					dbmtnc_date_actor($db);
				}
			} while (count($rows) === 100);
		}
		if ($phase === 'references')
		{
			// Conflicting policies are data, not redundant rows. Auto-prune already
			// refuses non-unique schedules; retain all choices for the administrator.
			$rows = phpbb_acl_rows($db, 'SELECT forum_id FROM ' . PRUNE_TABLE . ' GROUP BY forum_id HAVING COUNT(*) > 1 ORDER BY forum_id');
			foreach ($rows as $row) { $output['conflicts'][] = (int) $row['forum_id']; }
			// Finish counters even when a previous DELETE committed but its reply
			// was lost. No browser continuation or global board-disable flag needed.
			$cache_needed = true;
			$output['synchronization'] = dbmtnc_synchronize_posts_owned($db, true);
		}
		dbmtnc_date_actor($db);
		return $output;
	}
	finally
	{
		$lock->release();
		if ($cache_needed && function_exists('cache_tree')) { cache_tree(true); }
	}
}
