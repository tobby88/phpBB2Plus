<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';

function dbmtnc_post_sync_request($function, $request)
{
	global $userdata;
	if (!is_array($request) || empty($userdata['session_id'])) { phpbb_acl_error('Invalid_dbmtnc_request'); }
	$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
	if ($function === 'synchronize_post' || $function === 'synchronize_user')
	{
		if ($method !== 'POST' || !isset($request['sid']) || !is_string($request['sid'])
			|| !hash_equals((string) $userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	}
	elseif ($function === 'synchronize_post_direct')
	{
		if ($method !== 'GET' || !isset($request['db_state'], $request['dbmtnc_token'])
			|| !is_string($request['db_state']) || !in_array($request['db_state'], array('0','1'), true)
			|| !is_string($request['dbmtnc_token'])
			|| !hash_equals(hash_hmac('sha256', $function . '|' . $request['db_state'], (string) $userdata['session_id']), $request['dbmtnc_token']))
		{ phpbb_acl_error('Invalid_dbmtnc_request'); }
	}
	else { phpbb_acl_error('Invalid_dbmtnc_request'); }
}

// Derived counters only: no topic, redirect, post or attachment deletion.
function dbmtnc_synchronize_posts($database, $function, $request)
{
	if (!in_array($function, array('synchronize_post', 'synchronize_post_direct'), true)) { phpbb_acl_error('Invalid_dbmtnc_request'); }
	dbmtnc_post_sync_request($function, $request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_post_sync_failed');
		phpbb_acl_actor($db, 'maintenance');
		$output = array('topics' => array(), 'redirects' => array(), 'forums' => array(), 'review' => array());
		$rows = phpbb_acl_rows($db, 'SELECT topic_id, forum_id, topic_title, topic_status, topic_moved_id, topic_last_post_id FROM ' . TOPICS_TABLE . ' ORDER BY topic_id');
		foreach ($rows as $row)
		{
			$id = (int) $row['topic_id'];
			$forum = (int) $row['forum_id'];
			$moved = (int) $row['topic_moved_id'];
			$status = (int) $row['topic_status'];
			$actor = phpbb_acl_actor($db, 'maintenance');
			$redirect = $status === TOPIC_MOVED && $moved > 0 && $moved !== $id;
			if ($id <= 0 || ($status === TOPIC_MOVED && !$redirect) || ($status !== TOPIC_MOVED && $moved !== 0))
			{
				$output['review'][] = $id;
				continue;
			}
			$where = 'topic_id = ' . $id . ' AND forum_id = ' . $forum . ' AND topic_status = ' . $status . ' AND topic_moved_id = ' . $moved;
			$post_where = 'topic_id = ' . ($redirect ? $moved : $id);
			if ($redirect)
			{
				// Retain the historical move-time cutoff, not new destination replies.
				$where .= ' AND topic_last_post_id = ' . (int) $row['topic_last_post_id'];
				$post_where .= ' AND post_id <= ' . (int) $row['topic_last_post_id'];
				$where .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT topic_id, topic_status, topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $moved . ') sync_destination WHERE topic_status <> ' . TOPIC_MOVED . ' AND topic_moved_id = 0)';
			}
			$exists = 'EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . $post_where . ')';
			$eligible = phpbb_acl_rows($db, 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE ' . $where . ' AND ' . $exists);
			if (!$eligible) { $output['review'][] = $id; continue; }
			$values = array(
				'topic_replies' => '(SELECT COUNT(*) - 1 FROM ' . POSTS_TABLE . ' WHERE ' . $post_where . ')',
				'topic_first_post_id' => '(SELECT COALESCE(MIN(post_id),0) FROM ' . POSTS_TABLE . ' WHERE ' . $post_where . ')',
				'topic_last_post_id' => '(SELECT COALESCE(MAX(post_id),0) FROM ' . POSTS_TABLE . ' WHERE ' . $post_where . ')'
			);
			$sets = array(); $different = array();
			foreach ($values as $field => $value) { $sets[] = $field . ' = ' . $value; $different[] = $field . ' <> ' . $value; }
			// Aggregate inside this write, never publish counters from the ID snapshot.
			$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where . ' AND ' . $exists . ' AND (' . implode(' OR ', $different) . ') AND ' . $actor['guard']);
			if ((int) $db->sql_affectedrows() === 1) { $output[$redirect ? 'redirects' : 'topics'][] = array('id' => $id, 'name' => $row['topic_title']); }
			elseif (!phpbb_acl_rows($db, 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE ' . $where . ' AND ' . $exists . ' AND NOT (' . implode(' OR ', $different) . ')'))
			{ $output['review'][] = $id; }
			phpbb_acl_actor($db, 'maintenance');
		}
		$rows = phpbb_acl_rows($db, 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE . ' ORDER BY forum_id');
		foreach ($rows as $row)
		{
			$id = (int) $row['forum_id'];
			$actor = phpbb_acl_actor($db, 'maintenance');
			$values = array(
				'forum_topics' => '(SELECT COUNT(*) FROM ' . TOPICS_TABLE . ' WHERE forum_id = ' . $id . ')',
				'forum_posts' => '(SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $id . ')',
				'forum_last_post_id' => '(SELECT COALESCE(MAX(post_id),0) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $id . ')'
			);
			$sets = array(); $different = array();
			foreach ($values as $field => $value) { $sets[] = $field . ' = ' . $value; $different[] = $field . ' <> ' . $value; }
			$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . implode(', ', $sets) . ' WHERE forum_id = ' . $id . ' AND (' . implode(' OR ', $different) . ') AND ' . $actor['guard']);
			if ((int) $db->sql_affectedrows() === 1) { $output['forums'][] = array('id' => $id, 'name' => $row['forum_name']); }
			phpbb_acl_actor($db, 'maintenance');
		}
		phpbb_acl_actor($db, 'maintenance');
		return $output;
	}
	finally { $lock->release(); }
}

function dbmtnc_synchronize_user_counts($database, $request)
{
	dbmtnc_post_sync_request('synchronize_user', $request);
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_user_sync_failed');
		phpbb_acl_actor($db, 'maintenance');
		$counter = '(SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p INNER JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id AND f.count_posts <> 0 WHERE p.poster_id = u.user_id)';
		$rows = phpbb_acl_rows($db, 'SELECT u.user_id FROM ' . USERS_TABLE . ' u WHERE u.user_id > 0 AND u.user_posts <> ' . $counter . ' ORDER BY u.user_id');
		$output = array('changed' => array(), 'skipped' => array());
		foreach ($rows as $row)
		{
			$id = (int) $row['user_id'];
			$actor = phpbb_acl_actor($db, 'maintenance');
			$counter = '(SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p INNER JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id AND f.count_posts <> 0 WHERE p.poster_id = ' . $id . ')';
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts = ' . $counter . ' WHERE user_id = ' . $id . ' AND user_id > 0 AND user_posts <> ' . $counter . ' AND ' . $actor['guard']);
			$changed = (int) $db->sql_affectedrows() === 1;
			phpbb_acl_actor($db, 'maintenance');
			$current = phpbb_acl_rows($db, 'SELECT user_id, username, user_posts FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' AND user_posts = ' . $counter);
			if (!$current) { $output['skipped'][] = $id; }
			elseif ($changed) { $output['changed'][] = $current[0]; }
		}
		phpbb_acl_actor($db, 'maintenance');
		return $output;
	}
	finally { $lock->release(); }
}
