<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_recovery.php';
require_once dirname(__FILE__) . '/functions_maintenance_posts.php';

function dbmtnc_topology_invalid($kind, $alias)
{
	if ($kind === 'forums') { return $alias . ".main_type = 'c' AND NOT EXISTS (SELECT 1 FROM " . CATEGORIES_TABLE . ' parent_category WHERE parent_category.cat_id = ' . $alias . '.cat_id)'; }
	if ($kind === 'topics') { return 'NOT EXISTS (SELECT 1 FROM ' . FORUMS_TABLE . ' parent_forum WHERE parent_forum.forum_id = ' . $alias . '.forum_id)'; }
	if ($kind === 'posts')
	{
		return 'NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' parent_topic WHERE parent_topic.topic_id = ' . $alias . '.topic_id AND parent_topic.topic_status <> ' . TOPIC_MOVED . ')'
			. ' AND EXISTS (SELECT 1 FROM ' . POSTS_TEXT_TABLE . ' source_text WHERE source_text.post_id = ' . $alias . '.post_id)';
	}
	return 'EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . ' route_topic JOIN ' . FORUMS_TABLE . ' route_forum ON route_forum.forum_id = route_topic.forum_id'
		. ' WHERE route_topic.topic_id = ' . $alias . '.topic_id AND route_topic.topic_status <> ' . TOPIC_MOVED . ' AND route_topic.forum_id <> ' . $alias . '.forum_id)';
}

function dbmtnc_topology_source($kind, $fields, $row)
{
	list($table, $key, $parent) = $fields;
	$id = phpbb_acl_id($row[$key]);
	$guard = 'source_item.' . $parent . ' = ' . (int) $row[$parent];
	if ($kind === 'posts' || $kind === 'routes') { $guard .= ' AND source_item.forum_id = ' . (int) $row['forum_id']; }
	return 'EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . $table . ' WHERE ' . $key . ' = ' . $id . ') source_item WHERE '
		. $guard . ' AND ' . dbmtnc_topology_invalid($kind, 'source_item') . ')';
}

function dbmtnc_topology_topic($db, $token, $ids, $source, $original_topic)
{
	global $lang;
	$marker = substr(hash('sha256', $token . ':orphan-topic:' . (int) $original_topic), 0, 32);
	$rows = phpbb_acl_rows($db, 'SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE maintenance_token = '" . $marker . "'");
	if (!$rows)
	{
		$actor = dbmtnc_date_actor($db);
		// Current first surviving post supplies the title/author/date. Its old
		// topic ID defines the group; different old topics never share a marker.
		$first = 'SELECT first_post.post_id FROM ' . POSTS_TABLE . ' first_post JOIN ' . POSTS_TEXT_TABLE . ' first_text ON first_text.post_id = first_post.post_id'
			. ' WHERE first_post.topic_id = ' . (int) $original_topic . ' ORDER BY first_post.post_id LIMIT 1';
		$title = 'COALESCE(NULLIF((SELECT post_subject FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = (' . $first . ")),'')," . dbmtnc_recovery_literal($db, $lang['Restored_topic_name']) . ')';
		$author = 'COALESCE((SELECT CASE WHEN EXISTS (SELECT 1 FROM ' . USERS_TABLE . ' author_user WHERE author_user.user_id = author_post.poster_id)'
			. ' THEN author_post.poster_id ELSE ' . ANONYMOUS . ' END FROM ' . POSTS_TABLE . ' author_post WHERE post_id = (' . $first . ')),' . ANONYMOUS . ')';
		$date = '(SELECT post_time FROM ' . POSTS_TABLE . ' WHERE post_id = (' . $first . '))';
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_status,topic_type,maintenance_token)'
			. ' SELECT allocation.next_id,' . $ids['forum'] . ',' . $title . ',' . $author . ',' . $date . ',' . TOPIC_LOCKED . ',' . POST_NORMAL . ",'" . $marker . "'"
			. dbmtnc_recovery_allocation($db, 'topic') . ' WHERE allocation.next_id <= 16777215 AND ' . $source . ' AND ' . $actor['guard']
			. ' AND ' . dbmtnc_recovery_container_guard($db, $token, $ids)
			. ' AND NOT EXISTS (SELECT 1 FROM ' . TOPICS_TABLE . " previous_group WHERE maintenance_token = '" . $marker . "')");
		dbmtnc_date_actor($db);
		$rows = phpbb_acl_rows($db, 'SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE maintenance_token = '" . $marker . "'");
	}
	if (!$rows && !phpbb_acl_rows($db, 'SELECT 1 AS present WHERE ' . $source)) { return false; }
	if (count($rows) !== 1) { phpbb_acl_error('Maintenance_recovery_changed'); }
	$ids['topic'] = phpbb_acl_id($rows[0]['topic_id']);
	$guard = dbmtnc_recovery_container_guard($db, $token, $ids, $marker);
	if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Maintenance_recovery_changed'); }
	return array($ids, $guard);
}

function dbmtnc_repair_topology($database, $request)
{
	global $userdata;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$cache_needed = false;
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_topology_failed');
		dbmtnc_date_actor($db);
		$output = array('forums' => 0, 'topics' => 0, 'posts' => 0, 'routes' => 0, 'skipped' => 0);
		$phases = array('forums' => array(FORUMS_TABLE,'forum_id','cat_id'), 'topics' => array(TOPICS_TABLE,'topic_id','forum_id'),
			'posts' => array(POSTS_TABLE,'post_id','topic_id'), 'routes' => array(POSTS_TABLE,'post_id','topic_id'));
		foreach ($phases as $kind => $fields)
		{
			list($table, $key, $parent) = $fields;
			$bound = phpbb_acl_rows($db, 'SELECT COALESCE(MAX(' . $key . '),0) AS last_id FROM ' . $table);
			$last = (int) $bound[0]['last_id']; $after = 0;
			do
			{
				$rows = phpbb_acl_rows($db, 'SELECT * FROM ' . $table . ' item WHERE item.' . $key . ' > ' . $after . ' AND item.' . $key . ' <= ' . $last
					. ' AND ' . dbmtnc_topology_invalid($kind, 'item') . ' ORDER BY item.' . $key . ' LIMIT 100');
				foreach ($rows as $row)
				{
					$after = phpbb_acl_id($row[$key]); $source = dbmtnc_topology_source($kind, $fields, $row);
					$target_guard = '1 = 1'; $cache_needed = true;
					if ($kind !== 'routes')
					{
						$token = dbmtnc_recovery_token($db, $source);
						$ids = $token === false ? false : dbmtnc_recovery_containers($db, $token, $source, $kind === 'forums' ? 'category' : 'forum');
						if (!$ids) { dbmtnc_date_actor($db); $output['skipped']++; continue; }
						$target_guard = dbmtnc_recovery_container_guard($db, $token, $ids);
						$sets = $kind === 'forums' ? 'cat_id = ' . $ids['category'] : 'forum_id = ' . $ids['forum'];
						if ($kind === 'posts')
						{
							$target = dbmtnc_topology_topic($db, $token, $ids, $source, $row['topic_id']);
							if (!$target) { dbmtnc_date_actor($db); $output['skipped']++; continue; }
							list($ids, $target_guard) = $target;
							$sets .= ',topic_id = ' . $ids['topic'];
						}
					}
					else { $sets = 'forum_id = (SELECT route_parent.forum_id FROM ' . TOPICS_TABLE . ' route_parent WHERE route_parent.topic_id = ' . POSTS_TABLE . '.topic_id)'; }
					$actor = dbmtnc_date_actor($db);
					$db->sql_query('UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $key . ' = ' . $after . ' AND ' . $source . ' AND ' . $target_guard . ' AND ' . $actor['guard']);
					if ((int) $db->sql_affectedrows() === 1) { $output[$kind]++; } else { $output['skipped']++; }
					dbmtnc_date_actor($db);
					if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $target_guard)) { phpbb_acl_error('Maintenance_recovery_changed'); }
				}
			} while (count($rows) === 100 && $after < $last);
		}
		// Always finish derived counters on retry, even after the last routing
		// UPDATE succeeded but its acknowledgement was lost. Reuse the canonical
		// counter worker under this same owner; never acquire a nested writer.
		$output['synchronization'] = dbmtnc_synchronize_posts_owned($db, true);
		$tokens = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_orphan_recovery_token'");
		if ($tokens)
		{
			$token = $tokens[0]['config_value'];
			$ids = dbmtnc_recovery_containers($db, $token, '1 = 0', 'forum');
			if ($ids)
			{
				$rows = phpbb_acl_rows($db, 'SELECT topic_id,maintenance_token FROM ' . TOPICS_TABLE . ' WHERE forum_id = ' . $ids['forum'] . ' AND maintenance_token IS NOT NULL');
				foreach ($rows as $row)
				{
					$ids['topic'] = phpbb_acl_id($row['topic_id']); $guard = dbmtnc_recovery_container_guard($db, $token, $ids, $row['maintenance_token']);
					$actor = dbmtnc_date_actor($db);
					$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_attachment = CASE WHEN EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ' AND post_attachment = 1) THEN 1 ELSE 0 END'
						. ' WHERE topic_id = ' . $ids['topic'] . ' AND ' . $guard . ' AND ' . $actor['guard']);
					dbmtnc_date_actor($db);
					if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Maintenance_recovery_changed'); }
				}
			}
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
