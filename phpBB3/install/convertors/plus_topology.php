<?php
if (!defined('IN_PHPBB')) { exit; }

function plus_recovery_post_topic($original, $verified, $status, $destination)
{
	return (int) $verified > 0 && (int) $status !== ITEM_MOVED && !(int) $destination ? -1 : (int) $original;
}

/** Only allocate a locked forum when content has lost its actual parent. */
function plus_stage_recovery_forum()
{
	global $db, $src_db, $convert;
	$result = $db->sql_query('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE plus_recovery_role=2');
	$id = (int) $db->sql_fetchfield('forum_id');
	$db->sql_freeresult($result);
	if ($id) { return $id; }
	$result = $src_db->sql_query('SELECT (SELECT COALESCE(MAX(forum_id),0) FROM ' . $convert->src_table_prefix . 'forums) +
		(SELECT COUNT(*) FROM ' . $convert->src_table_prefix . 'categories) AS last_id');
	$id = max(65536, (int) $src_db->sql_fetchfield('last_id') + 1) + 1;
	$src_db->sql_freeresult($result);
	if ($id > 16777215) { throw new \RuntimeException('No native forum number is available for the recovered topics.'); }
	$result = $db->sql_query('SELECT forum_id,right_id FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_CAT . ' ORDER BY right_id DESC');
	$parent = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$parent) { throw new \RuntimeException('The provisional parent of the recovery forum is missing.'); }
	$right = (int) $parent['right_id'];
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET right_id=right_id+2 WHERE forum_id=' . (int) $parent['forum_id']);
	$row = array('forum_id'=>$id, 'parent_id'=>(int) $parent['forum_id'], 'left_id'=>$right, 'right_id'=>$right+1,
		'forum_type'=>FORUM_POST, 'forum_status'=>ITEM_LOCKED, 'plus_recovery_role'=>2, 'enable_prune'=>0, 'enable_indexing'=>1,
		'forum_name'=>plus_recovery_label('PLUS_RECOVERED_TOPICS'), 'forum_desc'=>'', 'forum_rules'=>'', 'forum_parents'=>'');
	$db->sql_query('INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $row));
	return $id;
}

function plus_recovery_forum_permissions($id)
{
	global $db;
	foreach (array(ACL_USERS_TABLE, ACL_GROUPS_TABLE) as $table) { $db->sql_query("DELETE FROM $table WHERE forum_id=" . (int) $id); }
	// Use the board's full forum role for administrators, leaving recovered posts out of member post counts.
	$result = $db->sql_query('SELECT o.auth_option FROM ' . ACL_ROLES_TABLE . ' r JOIN ' . ACL_ROLES_DATA_TABLE . ' d ON d.role_id=r.role_id
		JOIN ' . ACL_OPTIONS_TABLE . " o ON o.auth_option_id=d.auth_option_id WHERE r.role_name='ROLE_FORUM_FULL'
		AND d.auth_setting=" . ACL_YES . " AND o.auth_option<>'f_postcount'");
	$options = array_column($db->sql_fetchrowset($result), 'auth_option');
	$db->sql_freeresult($result);
	mass_auth('group', (int) $id, 'administrators', array_unique(array_merge(array('f_','f_list','f_read'), $options)), ACL_YES);
}

/** Reserve numbers above both boards' topic history, skipping surviving references to missing topics. */
function plus_recovery_topic_numbers()
{
	global $db, $src_db, $convert;
	$p = $convert->src_table_prefix;
	$references = array('topics'=>array('topic_id','topic_moved_id'), 'posts'=>array('topic_id'), 'topics_watch'=>array('topic_id'),
		'vote_desc'=>array('topic_id'), 'logs'=>array('topic_id'), 'bookmarks'=>array('topic_id'), 'topic_view'=>array('topic_id'),
		'kb_articles'=>array('topic_id'), 'sessions'=>array('session_topic'), 'users'=>array('user_session_topic'));
	$tables = array();
	foreach ($references as $suffix => $columns) { $tables[$p . $suffix] = $columns; }
	$result = $src_db->sql_query('SELECT TABLE_NAME AS source_table,COLUMN_NAME AS source_field FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ' .
		$src_db->sql_in_set('TABLE_NAME', array_keys($tables)));
	$fields = array();
	while ($row = $src_db->sql_fetchrow($result))
	{
		$table = $row['source_table'];
		$field = $row['source_field'];
		if (isset($tables[$table]) && in_array($field, $tables[$table], true)) { $fields[] = array($table,$field); }
	}
	$src_db->sql_freeresult($result);
	if (!$fields) { throw new \RuntimeException('Unable to read the source topic references.'); }
	// MySQL 8 caches information_schema statistics unless the connection opts into current values.
	$result = $src_db->sql_query("SHOW SESSION VARIABLES WHERE Variable_name='information_schema_stats_expiry'");
	$expiry = $src_db->sql_fetchrow($result);
	$src_db->sql_freeresult($result);
	if ($expiry) { $src_db->sql_query('SET SESSION information_schema_stats_expiry=0'); }
	try
	{
		$result = $src_db->sql_query("SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" .
			$src_db->sql_escape($p . 'topics') . "'");
		$next = (int) $src_db->sql_fetchfield('next_id');
		$src_db->sql_freeresult($result);
	}
	finally { if ($expiry) { $src_db->sql_query('SET SESSION information_schema_stats_expiry=' . (int) $expiry['Value']); } }
	$result = $db->sql_query('SELECT COALESCE(MAX(topic_id),0)+1 AS next_id FROM ' . TOPICS_TABLE);
	$next = max($next, (int) $db->sql_fetchfield('next_id'), 1);
	$db->sql_freeresult($result);
	while ($next <= 16777215)
	{
		$end = min($next + 1023, 16777215);
		$queries = array();
		foreach ($fields as list($table,$field)) { $queries[] = "SELECT $field AS used_id FROM $table WHERE $field BETWEEN $next AND $end"; }
		$result = $src_db->sql_query(implode(' UNION ', $queries));
		$reserved = array_flip(array_column($src_db->sql_fetchrowset($result), 'used_id'));
		$src_db->sql_freeresult($result);
		for (; $next <= $end; $next++) { if (!isset($reserved[$next])) { yield $next; } }
	}
}

function plus_make_recovered_topic($original, $new_id, $forum)
{
	global $db;
	$scope = 'plus_recovery_topic=' . (int) $original . ' AND topic_id=' . (int) $original;
	$result = $db->sql_query_limit('SELECT post_id,post_subject,poster_id,post_time,plus_legacy_erased FROM ' . POSTS_TABLE .
		' WHERE ' . $scope . ' ORDER BY plus_legacy_erased,post_id', 1);
	$first = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$first) { return; }
	$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (int) $original);
	$shadow = (bool) $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$erased = (bool) $first['plus_legacy_erased'];
	$title = $first['post_subject'] !== '' ? $first['post_subject'] : plus_recovery_label('PLUS_RECOVERED_TOPIC');
	$row = array('topic_id'=>$new_id, 'forum_id'=>$forum, 'topic_title'=>$erased ? '' : $title,
		'topic_first_post_id'=>(int) $first['post_id'], 'topic_poster'=>$erased ? ANONYMOUS : (int) $first['poster_id'],
		'topic_time'=>(int) $first['post_time'], 'topic_status'=>ITEM_LOCKED, 'topic_type'=>POST_NORMAL,
		'topic_visibility'=>$erased ? ITEM_DELETED : ITEM_APPROVED, 'plus_legacy_removed'=>(int) $erased,
		'plus_recovery_reason'=>$shadow ? 'moved-topic' : 'missing-topic', 'plus_recovery_from'=>(int) $original);
	$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $row));
	$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET plus_recovery_forum=forum_id, topic_id=' . $new_id .
		', forum_id=' . $forum . ', post_postcount=0 WHERE ' . $scope);
}

function plus_lost_topic_batch($after)
{
	global $db;
	$result = $db->sql_query_limit('SELECT DISTINCT plus_recovery_topic FROM ' . POSTS_TABLE .
		' WHERE plus_recovery_topic>' . (int) $after . ' AND plus_recovery_topic=topic_id ORDER BY plus_recovery_topic', 200);
	$groups = array_map('intval', array_column($db->sql_fetchrowset($result), 'plus_recovery_topic'));
	$db->sql_freeresult($result);
	return $groups;
}

/** Run after posts and attachments exist, before search and native synchronization. */
function plus_recover_topic_routes()
{
	global $db;
	$topics = TOPICS_TABLE; $posts = POSTS_TABLE; $forums = FORUMS_TABLE;
	// Only actual posting forums were staged; the provisional category can share a missing old forum's number.
	$missing = " FROM $topics t LEFT JOIN $forums f ON f.forum_id=t.forum_id AND f.forum_type=" . FORUM_POST . ' WHERE f.forum_id IS NULL';
	$result = $db->sql_query_limit('SELECT t.topic_id' . $missing, 1);
	$lost_topics = (bool) $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$groups = plus_lost_topic_batch(-1);
	$route = " JOIN $topics t ON t.topic_id=p.topic_id AND t.topic_moved_id=0 AND t.topic_status<>" . ITEM_MOVED .
		" JOIN $forums f ON f.forum_id=t.forum_id AND f.forum_type=" . FORUM_POST;
	if (!$lost_topics && !$groups)
	{
		$result = $db->sql_query_limit("SELECT p.post_id FROM $posts p $route WHERE p.forum_id<>t.forum_id", 1);
		$misfiled = (bool) $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if (!$misfiled) { return; }
	}
	$db->sql_transaction('begin');
	try
	{
		if ($lost_topics || $groups) { $forum = plus_stage_recovery_forum(); }
		if ($lost_topics)
		{
			$joins = " LEFT JOIN $forums f ON f.forum_id=t.forum_id AND f.forum_type=" . FORUM_POST;
			$db->sql_query("UPDATE $topics t $joins SET t.plus_recovery_reason='missing-forum',t.plus_recovery_from=t.forum_id,
				t.plus_recovery_type=t.topic_type WHERE f.forum_id IS NULL");
			// A global announcement would otherwise appear in healthy forums, irrespective of its new home.
			$db->sql_query("UPDATE $topics SET forum_id=$forum, topic_type=IF(topic_type=" . POST_GLOBAL . ',' . POST_ANNOUNCE .
				",topic_type) WHERE plus_recovery_reason='missing-forum'");
		}
		if ($groups)
		{
			$numbers = plus_recovery_topic_numbers();
			$first_group = true;
			do
			{
				foreach ($groups as $original)
				{
					if (!$first_group) { $numbers->next(); }
					if (!$numbers->valid()) { throw new \RuntimeException('The native topic number range is exhausted. The original posts remain in the source.'); }
					plus_make_recovered_topic($original, $numbers->current(), $forum);
					$first_group = false;
				}
				$groups = count($groups) === 200 ? plus_lost_topic_batch(end($groups)) : array();
			}
			while ($groups);
			$db->sql_query('UPDATE ' . ATTACHMENTS_TABLE . " a JOIN $posts p ON p.post_id=a.post_msg_id SET a.topic_id=p.topic_id
				WHERE p.plus_recovery_topic>=0 AND a.in_message=0 AND a.is_orphan=0 AND a.topic_id<>p.topic_id");
		}
		$db->sql_query("UPDATE $posts p $route SET p.plus_recovery_forum=p.forum_id WHERE p.forum_id<>t.forum_id AND p.plus_recovery_forum=-1");
		$db->sql_query("UPDATE $posts p $route SET p.forum_id=t.forum_id,
			p.post_postcount=IF(f.plus_recovery_role=2,0,p.post_postcount) WHERE p.forum_id<>t.forum_id");
		$db->sql_transaction('commit');
	}
	catch (\Throwable $error) { $db->sql_transaction('rollback'); throw $error; }
}
