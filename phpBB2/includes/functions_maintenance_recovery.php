<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_maintenance_dates.php';

function dbmtnc_recovery_literal($db, $value)
{
	return $value === null ? 'NULL' : "'" . $db->sql_escape((string) $value) . "'";
}

function dbmtnc_recovery_source($db, $row)
{
	$id = phpbb_acl_id($row['post_id']);
	$terms = array('source_text.post_id = ' . $id);
	foreach (array('bbcode_uid', 'post_subject', 'post_text') as $field)
	{
		$terms[] = $row[$field] === null ? 'source_text.' . $field . ' IS NULL'
			: 'HEX(source_text.' . $field . ') = HEX(' . dbmtnc_recovery_literal($db, $row[$field]) . ')';
	}
	return 'EXISTS (SELECT 1 FROM ' . POSTS_TEXT_TABLE . ' source_text WHERE ' . implode(' AND ', $terms) . ')'
		. ' AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT post_id FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $id . ') existing_post)';
}

function dbmtnc_recovery_token_guard($db, $token)
{
	return 'EXISTS (SELECT 1 FROM ' . CONFIG_TABLE . " recovery_config WHERE config_name = 'dbmtnc_orphan_recovery_token' AND HEX(config_value) = HEX('" . $token . "'))";
}

function dbmtnc_recovery_token($db, $source)
{
	$actor = dbmtnc_date_actor($db); // Same current maintenance route and ACP session.
	$rows = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_orphan_recovery_token'");
	if (!$rows)
	{
		$token = bin2hex(phpbb_random_bytes(16));
		$db->sql_query('INSERT INTO ' . CONFIG_TABLE . " (config_name,config_value) SELECT 'dbmtnc_orphan_recovery_token','" . $token
			. "' WHERE " . $source . ' AND ' . $actor['guard'] . ' AND NOT EXISTS (SELECT 1 FROM '
			. CONFIG_TABLE . " previous_token WHERE config_name = 'dbmtnc_orphan_recovery_token')");
		$rows = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_orphan_recovery_token'");
	}
	if (!$rows) { return false; }
	if (count($rows) !== 1 || !preg_match('/^[a-f0-9]{32}$/D', $rows[0]['config_value'])) { phpbb_acl_error('Maintenance_recovery_changed'); }
	return $rows[0]['config_value'];
}

function dbmtnc_recovery_container_guard($db, $token, $ids, $topic_token = null)
{
	if ($topic_token === null) { $topic_token = $token; }
	if (!preg_match('/^[a-f0-9]{32}$/D', $token) || !preg_match('/^[a-f0-9]{32}$/D', $topic_token)) { phpbb_acl_error('Maintenance_recovery_changed'); }
	$guard = dbmtnc_recovery_token_guard($db, $token);
	if (isset($ids['category']))
	{
		$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . CATEGORIES_TABLE . ' WHERE cat_id = ' . $ids['category'] . ') recovery_category WHERE cat_id = ' . $ids['category']
			. " AND maintenance_token = '" . $token . "' AND cat_main = 0 AND cat_main_type = 'c')";
	}
	if (isset($ids['forum']))
	{
		$terms = array('forum_id = ' . $ids['forum'], 'cat_id = ' . $ids['category'], "main_type = 'c'",
			"maintenance_token = '" . $token . "'", 'forum_status = ' . FORUM_LOCKED, "COALESCE(forum_link,'') = ''", "count_posts = '0'");
		foreach (phpbb_acl_fields() as $field) { $terms[] = $field . ' = ' . AUTH_ADMIN; }
		$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $ids['forum'] . ') recovery_forum WHERE ' . implode(' AND ', $terms) . ')';
	}
	if (isset($ids['topic']))
	{
		$guard .= ' AND EXISTS (SELECT 1 FROM (SELECT DISTINCT * FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ') recovery_topic WHERE topic_id = ' . $ids['topic']
			. ' AND forum_id = ' . $ids['forum'] . " AND maintenance_token = '" . $topic_token . "' AND topic_status = " . TOPIC_LOCKED . ' AND topic_moved_id = 0)';
	}
	return $guard;
}

function dbmtnc_recovery_allocation($db, $kind)
{
	// MySQL can cache TABLES.AUTO_INCREMENT. This dedicated connection must
	// read the engine's current high-water mark, not yesterday's statistics.
	$expiry = phpbb_acl_rows($db, "SHOW SESSION VARIABLES WHERE Variable_name = 'information_schema_stats_expiry'");
	if ($expiry) { $db->sql_query('SET SESSION information_schema_stats_expiry = 0'); }
	// Explicit numeric references, including optional bundled modules. Never
	// attach dangling subscriptions, ACLs or posts to a newly created identity.
	if (!preg_match('/^([A-Za-z0-9_]*)categories$/D', CATEGORIES_TABLE, $match)) { phpbb_acl_error('Maintenance_recovery_changed'); }
	$prefix = $match[1];
	$registry = array(
		'category' => array('categories' => array('cat_id' => '', 'cat_main' => "cat_main_type = 'c'"), 'forums' => array('cat_id' => "main_type = 'c'")),
		'forum' => array('forums' => array('forum_id' => '', 'cat_id' => "main_type = 'f'"), 'categories' => array('cat_main' => "cat_main_type = 'f'"),
			'topics' => array('forum_id' => ''), 'posts' => array('forum_id' => ''), 'auth_access' => array('forum_id' => ''), 'forum_prune' => array('forum_id' => '')),
		'topic' => array('topics' => array('topic_id' => '', 'topic_moved_id' => ''), 'posts' => array('topic_id' => ''),
			'topics_watch' => array('topic_id' => ''), 'vote_desc' => array('topic_id' => ''), 'logs' => array('topic_id' => ''),
			'bookmarks' => array('topic_id' => ''), 'topic_view' => array('topic_id' => ''), 'kb_articles' => array('topic_id' => ''), 'sessions' => array('session_topic' => ''))
	);
	if (!isset($registry[$kind])) { phpbb_acl_error('Maintenance_recovery_changed'); }
	$tables = array(); foreach ($registry[$kind] as $suffix => $fields) { $tables[] = "'" . $prefix . $suffix . "'"; }
	$rows = phpbb_acl_rows($db, 'SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $tables) . ')');
	$inventory = array(); foreach ($rows as $row) { $inventory[$row['table_name']][$row['column_name']] = strtolower($row['data_type']); }
	$selects = array();
	foreach ($registry[$kind] as $suffix => $fields)
	{
		$table = $prefix . $suffix;
		foreach ($fields as $field => $where)
		{
			if (!isset($inventory[$table][$field]))
			{
				if (in_array($suffix, array('categories','forums','topics','posts'), true)) { phpbb_acl_error('Maintenance_recovery_changed'); }
				continue;
			}
			if (!in_array($inventory[$table][$field], array('tinyint','smallint','mediumint','int','bigint'), true)) { phpbb_acl_error('Maintenance_recovery_changed'); }
			$selects[] = 'SELECT COALESCE(MAX(`' . $field . '`),0) AS value FROM `' . $table . '`' . ($where === '' ? '' : ' WHERE ' . $where);
		}
	}
	$target = $prefix . ($kind === 'category' ? 'categories' : ($kind === 'forum' ? 'forums' : 'topics'));
	$selects[] = "SELECT COALESCE(AUTO_INCREMENT,1)-1 AS value FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $target . "'";
	$floor = 'SELECT COALESCE(MAX(value),0)+1 AS next_id FROM (' . implode(' UNION ALL ', $selects) . ') recovery_reference_floor';
	return ' FROM (SELECT id_floor.next_id' . ($kind === 'category' ? ',(SELECT COALESCE(MAX(cat_order),0)+10 FROM ' . CATEGORIES_TABLE . ') AS next_order' : '')
		. ' FROM (' . $floor . ') id_floor) allocation';
}

function dbmtnc_recovery_containers($db, $token, $source, $through = 'topic')
{
	global $lang;
	if (!in_array($through, array('category', 'forum', 'topic'), true)) { phpbb_acl_error('Maintenance_recovery_changed'); }
	$ids = array();
	foreach (array('category' => array(CATEGORIES_TABLE, 'cat_id'), 'forum' => array(FORUMS_TABLE, 'forum_id'), 'topic' => array(TOPICS_TABLE, 'topic_id')) as $kind => $table)
	{
		$actor = dbmtnc_date_actor($db);
		$parent_guard = dbmtnc_recovery_container_guard($db, $token, $ids);
		$rows = phpbb_acl_rows($db, 'SELECT ' . $table[1] . ' FROM ' . $table[0] . " WHERE maintenance_token = '" . $token . "'");
		if (!$rows)
		{
			$values = array('maintenance_token' => "'" . $token . "'", $table[1] => 'allocation.next_id');
			$allocation = dbmtnc_recovery_allocation($db, $kind);
			$parent_guard .= ' AND allocation.next_id <= ' . ($kind === 'forum' ? 65535 : 16777215);
			if ($kind === 'category')
			{
				$values += array('cat_title' => dbmtnc_recovery_literal($db, $lang['New_cat_name']), 'cat_order' => 'allocation.next_order',
					'cat_main_type' => "'c'", 'cat_main' => '0', 'cat_desc' => "''", 'icon' => "''");
				$parent_guard .= ' AND allocation.next_order <= 16777215';
			}
			elseif ($kind === 'forum')
			{
				$values += array('forum_id' => 'allocation.next_id', 'cat_id' => (string) $ids['category'],
					'forum_name' => dbmtnc_recovery_literal($db, $lang['New_forum_name']), 'forum_desc' => "''", 'forum_status' => (string) FORUM_LOCKED,
					'forum_order' => '10', 'prune_next' => 'NULL', 'prune_enable' => '0', 'forum_link' => "''", 'main_type' => "'c'", 'count_posts' => "'0'");
				foreach (phpbb_acl_fields() as $field) { $values[$field] = (string) AUTH_ADMIN; }
			}
			else
			{
				$values += array('forum_id' => (string) $ids['forum'], 'topic_title' => dbmtnc_recovery_literal($db, $lang['New_topic_name']),
					'topic_poster' => (string) ANONYMOUS, 'topic_time' => (string) time(), 'topic_status' => (string) TOPIC_LOCKED,
					'topic_type' => (string) POST_NORMAL);
			}
			$db->sql_query('INSERT INTO ' . $table[0] . ' (' . implode(',', array_keys($values)) . ') SELECT ' . implode(',', $values)
				. $allocation . ' WHERE ' . $source . ' AND ' . $actor['guard'] . ' AND ' . $parent_guard
				. ' AND NOT EXISTS (SELECT 1 FROM ' . $table[0] . " previous_container WHERE maintenance_token = '" . $token . "')");
			dbmtnc_date_actor($db);
			$rows = phpbb_acl_rows($db, 'SELECT ' . $table[1] . ' FROM ' . $table[0] . " WHERE maintenance_token = '" . $token . "'");
		}
		if (!$rows && !phpbb_acl_rows($db, 'SELECT 1 AS present WHERE ' . $source)) { return false; }
		if (count($rows) !== 1) { phpbb_acl_error('Maintenance_recovery_changed'); }
		$ids[$kind] = phpbb_acl_id($rows[0][$table[1]]);
		if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . dbmtnc_recovery_container_guard($db, $token, $ids)))
		{ phpbb_acl_error('Maintenance_recovery_changed'); }
		if ($kind === $through) { break; }
	}
	return $ids;
}

function dbmtnc_recover_orphan_text($database, $request)
{
	global $userdata, $lang;
	if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid']) || !is_string($request['sid'])
		|| !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_acl_error('Attachment_storage_busy'); }
	$cache_needed = false;
	try
	{
		$db = new PhpbbAclDatabase($lock->connection, 'Maintenance_recovery_failed');
		dbmtnc_date_actor($db);
		$output = array('restored' => 0, 'skipped' => 0); $after = 0;
		$bound = phpbb_acl_rows($db, 'SELECT COALESCE(MAX(post_id),0) AS last_id FROM ' . POSTS_TEXT_TABLE);
		$last = (int) $bound[0]['last_id'];
		do
		{
			$rows = phpbb_acl_rows($db, 'SELECT pt.post_id,pt.bbcode_uid,pt.post_subject,pt.post_text FROM ' . POSTS_TEXT_TABLE . ' pt'
				. ' WHERE pt.post_id > ' . $after . ' AND pt.post_id <= ' . $last . ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE
				. ' p WHERE p.post_id = pt.post_id) ORDER BY pt.post_id LIMIT 100');
			foreach ($rows as $row)
			{
				$after = phpbb_acl_id($row['post_id']); $source = dbmtnc_recovery_source($db, $row);
				$cache_needed = true;
				$token = dbmtnc_recovery_token($db, $source);
				$ids = $token === false ? false : dbmtnc_recovery_containers($db, $token, $source);
				if (!$ids) { dbmtnc_date_actor($db); $output['skipped']++; continue; }
				$actor = dbmtnc_date_actor($db); $guard = dbmtnc_recovery_container_guard($db, $token, $ids);
				$settings = array();
				foreach (array('html','bbcode','smilies') as $feature)
				{
					$settings[$feature] = 'CASE WHEN EXISTS (SELECT 1 FROM ' . CONFIG_TABLE . " setting WHERE config_name = 'allow_" . $feature . "' AND config_value = '1')";
					if ($feature === 'bbcode') { $settings[$feature] .= " AND " . dbmtnc_recovery_literal($db, $row['bbcode_uid']) . " <> ''"; }
					$settings[$feature] .= ' THEN 1 ELSE 0 END';
				}
				$attached = 'CASE WHEN EXISTS (SELECT 1 FROM ' . ATTACHMENTS_TABLE . ' a JOIN ' . ATTACHMENTS_DESC_TABLE . ' d ON d.attach_id = a.attach_id WHERE a.post_id = ' . $after . ') THEN 1 ELSE 0 END';
				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' (post_id,topic_id,forum_id,poster_id,post_time,poster_ip,post_username,enable_html,enable_bbcode,enable_smilies,enable_sig,post_edit_time,post_edit_count,post_attachment)'
					. ' SELECT ' . $after . ',' . $ids['topic'] . ',' . $ids['forum'] . ',' . ANONYMOUS . ',' . time() . ",'' ," . dbmtnc_recovery_literal($db, $lang['New_poster_name'])
					. ',' . $settings['html'] . ',' . $settings['bbcode'] . ',' . $settings['smilies'] . ',0,NULL,0,' . $attached . ' WHERE ' . $source . ' AND ' . $guard . ' AND ' . $actor['guard']);
				if ((int) $db->sql_affectedrows() === 1) { $output['restored']++; } else { $output['skipped']++; }
				dbmtnc_date_actor($db);
			}
		} while (count($rows) === 100 && $after < $last);
		// Also repair counters after a lost acknowledgement of the final INSERT:
		// that post is no longer an orphan on the next run, but its totals may lag.
		$tokens = phpbb_acl_rows($db, 'SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name = 'dbmtnc_orphan_recovery_token'");
		if ($tokens)
		{
			$token = $tokens[0]['config_value'];
			if (!preg_match('/^[a-f0-9]{32}$/D', $token)) { phpbb_acl_error('Maintenance_recovery_changed'); }
			$ids = dbmtnc_recovery_containers($db, $token, '1 = 0');
			if ($ids)
			{
				$guard = dbmtnc_recovery_container_guard($db, $token, $ids);
				$actor = dbmtnc_date_actor($db);
				$count = '(SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ')';
				$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_replies = CASE WHEN ' . $count . ' > 0 THEN ' . $count . ' - 1 ELSE 0 END'
					. ',topic_first_post_id = (SELECT COALESCE(MIN(post_id),0) FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ')'
					. ',topic_last_post_id = (SELECT COALESCE(MAX(post_id),0) FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ')'
					. ',topic_attachment = CASE WHEN EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $ids['topic'] . ' AND post_attachment = 1) THEN 1 ELSE 0 END'
					. ' WHERE topic_id = ' . $ids['topic'] . ' AND ' . $guard . ' AND ' . $actor['guard']);
				$actor = dbmtnc_date_actor($db);
				$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $ids['forum'] . ')'
					. ',forum_topics = (SELECT COUNT(*) FROM ' . TOPICS_TABLE . ' WHERE forum_id = ' . $ids['forum'] . ')'
					. ',forum_last_post_id = (SELECT COALESCE(MAX(post_id),0) FROM ' . POSTS_TABLE . ' WHERE forum_id = ' . $ids['forum'] . ')'
					. ' WHERE forum_id = ' . $ids['forum'] . ' AND ' . $guard . ' AND ' . $actor['guard']);
				if (!phpbb_acl_rows($db, 'SELECT 1 AS allowed WHERE ' . $guard)) { phpbb_acl_error('Maintenance_recovery_changed'); }
			}
		}
		dbmtnc_date_actor($db);
		return $output;
	}
	finally
	{
		$lock->release();
		// Container creation can have succeeded before a later SQL failure.
		// Refresh navigation only after releasing the database writer.
		if ($cache_needed && function_exists('cache_tree')) { cache_tree(true); }
	}
}
