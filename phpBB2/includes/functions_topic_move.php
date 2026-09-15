<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';

class PhpbbTopicMoveException extends RuntimeException {}
function phpbb_topic_move_error($key)
{
	global $lang;
	throw new PhpbbTopicMoveException($lang[$key]);
}
class PhpbbTopicMoveDatabase
{
	var $connection;
	var $transactional = false;
	var $source;
	var $target;
	var $types = array();
	var $write_attempted = false;
	function __construct($connection, $source, $target) { $this->connection = $connection; $this->source = $source; $this->target = $target; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function control($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_topic_move_error('Moderation_move_failed'); }
		return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		// Enlisted counter/log helpers cannot commit or perform runtime DDL.
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql)) { phpbb_topic_move_error('Moderation_move_failed'); }
		if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_topic_move_error('Moderation_move_failed'); }
			$this->authorize(); $this->write_attempted = true;
		}
		return $this->control($sql);
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function actor()
	{
		global $userdata;
		$user = phpbb_current_moderator_user($this);
		if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { phpbb_topic_move_error('Not_Moderator'); }
		$sid = $this->sql_escape($userdata['session_id']);
		if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid')"
			. ' AND session_user_id = ' . (int)$user['user_id'] . ' AND session_logged_in = 1')) { phpbb_topic_move_error('Not_Moderator'); }
		// Forum moderation does not require a separate ACP-authenticated session.
		return $user;
	}
	function authorize()
	{
		$user = $this->actor();
		$rows = $this->rows('SELECT forum_id, forum_status, forum_link FROM ' . FORUMS_TABLE . ' WHERE forum_id IN (' . $this->source . ',' . $this->target . ')');
		$forums = array(); foreach ($rows as $row) { $forums[(int)$row['forum_id']] = $row; }
		if (!isset($forums[$this->source], $forums[$this->target]) || !empty($forums[$this->target]['forum_link'])) { phpbb_topic_move_error('Forum_not_exist'); }
		$source = auth(AUTH_ALL, $this->source, $user, '', $this); $target = auth(AUTH_ALL, $this->target, $user, '', $this);
		if (empty($source['auth_mod']) || empty($source['auth_view']) || empty($source['auth_read'])) { phpbb_topic_move_error('Not_Moderator'); }
		if (empty($target['auth_view']) || empty($target['auth_read']) || (empty($target['auth_post']) && empty($target['auth_mod']))) { phpbb_topic_move_error('Moderation_move_denied'); }
		if ((int)$forums[$this->target]['forum_status'] === FORUM_LOCKED && empty($target['auth_mod'])) { phpbb_topic_move_error('Forum_locked'); }
		foreach ($this->types as $permission) { if (empty($target[$permission])) { phpbb_topic_move_error('Moderation_move_denied'); } }
		return array($source, $target);
	}
	function begin()
	{
		if ($this->transactional) { phpbb_topic_move_error('Moderation_move_failed'); }
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, GROUPS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE, LOGS_TABLE, CONFIG_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			$table_name = $this->sql_escape($table);
			// Keep the column policy and metadata locks, but bound the scan.
			$rows = $this->rows("SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table_name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $table_name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR c.COLLATION_NAME <> 'utf8mb4_unicode_ci'))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_topic_move_error('Moderation_move_storage'); }
		}
	}
	function commit()
	{
		global $userdata;
		if (!$this->transactional) { phpbb_topic_move_error('Moderation_move_failed'); }
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') LOCK IN SHARE MODE");
		$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id IN (' . $this->source . ',' . $this->target . ') ORDER BY group_id, forum_id LOCK IN SHARE MODE');
		$this->authorize();
		// Authority stays pinned through COMMIT. Later changes cannot undo
		// an acknowledged operation or its audit/counter updates.
		$this->control('COMMIT'); $this->transactional = false;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false;
	}
}
function phpbb_topic_move_id($id)
{
	$ids = (is_int($id) || is_string($id)) && preg_match('/^[0-9]+$/D', (string) $id) ? attach_delete_id_array($id) : false;
	if ($ids === false || count($ids) !== 1) { phpbb_topic_move_error('None_selected'); }
	return $ids[0];
}

// Internal: modcp has checked the POST confirmation and session. Shared lock
// coordinates current post/poll/attachment/moderator writers on its own DB.
function phpbb_move_topics($database, $source_forum, $target_forum, $topic_ids, $leave_shadow = false)
{
	global $userdata;
	$source_forum = phpbb_topic_move_id($source_forum); $target_forum = phpbb_topic_move_id($target_forum);
	$ids = is_array($topic_ids) ? attach_delete_id_array($topic_ids) : false;
	if ($ids === false || !$ids || !is_bool($leave_shadow)) { phpbb_topic_move_error('None_selected'); }
	sort($ids, SORT_NUMERIC);
	if (empty($userdata['session_logged_in'])) { phpbb_topic_move_error('Not_Moderator'); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_move_error('Attachment_storage_busy'); }
	$db = new PhpbbTopicMoveDatabase($lock->connection, $source_forum, $target_forum);
	try
	{
		$db->begin();
		$result = $db->sql_query('SELECT forum_id, forum_status, forum_link, count_posts FROM ' . FORUMS_TABLE . " WHERE forum_id IN ($source_forum,$target_forum) ORDER BY forum_id FOR UPDATE");
		$forums = array(); while ($row = $db->sql_fetchrow($result)) { $forums[(int) $row['forum_id']] = $row; } $db->sql_freeresult($result);
		if (!isset($forums[$source_forum], $forums[$target_forum]) || !empty($forums[$target_forum]['forum_link'])) { phpbb_topic_move_error('Forum_not_exist'); }
		list($source_auth, $target_auth) = $db->authorize();
		$list = implode(',', $ids);
		$result = $db->sql_query('SELECT topic_id, forum_id, topic_type, topic_status, topic_moved_id FROM ' . TOPICS_TABLE . " WHERE topic_id IN ($list) ORDER BY topic_id FOR UPDATE");
		$topics = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		if (count($topics) !== count($ids)) { phpbb_topic_move_error('Moderation_move_changed'); }
		$type_permissions = array(POST_STICKY => 'auth_sticky', POST_ANNOUNCE => 'auth_announce', POST_GLOBAL_ANNOUNCE => 'auth_global_announce', POST_NEWS => 'auth_news');
		foreach ($topics as $topic)
		{
			if ((int) $topic['forum_id'] !== $source_forum || (int) $topic['topic_moved_id'] !== 0 || !in_array((int) $topic['topic_status'], array(TOPIC_LOCKED, TOPIC_UNLOCKED), true)) { phpbb_topic_move_error('Moderation_move_changed'); }
			$type = (int) $topic['topic_type'];
			if (isset($type_permissions[$type]) && empty($target_auth[$type_permissions[$type]])) { phpbb_topic_move_error('Moderation_move_denied'); }
			if (isset($type_permissions[$type])) { $db->types[$type] = $type_permissions[$type]; }
		}
		$result = $db->sql_query('SELECT post_id, topic_id, forum_id, poster_id FROM ' . POSTS_TABLE . " WHERE topic_id IN ($list) ORDER BY post_id FOR UPDATE");
		$posts = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
		$counts = array_fill_keys($ids, 0); $posters = array();
		foreach ($posts as $post)
		{
			if ((int) $post['forum_id'] !== $source_forum) { phpbb_topic_move_error('Moderation_move_changed'); }
			$counts[(int) $post['topic_id']]++;
			if ((int) $post['poster_id'] > 0) { $posters[(int) $post['poster_id']] = (int) $post['poster_id']; }
		}
		if (in_array(0, $counts, true)) { phpbb_topic_move_error('Moderation_move_changed'); }
		if ($source_forum === $target_forum) { return array(); }
		$target_lock = empty($target_auth['auth_mod']) ? ' AND destination.forum_status <> ' . FORUM_LOCKED : '';
		// One multi-table UPDATE publishes the new forum on the topic AND all
		// its posts. All dependent updates stay in this same InnoDB transaction.
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.topic_id = t.topic_id AND p.forum_id = t.forum_id'
			. ' JOIN ' . FORUMS_TABLE . ' origin ON origin.forum_id = t.forum_id JOIN ' . FORUMS_TABLE . " destination ON destination.forum_id = $target_forum"
			. " SET t.forum_id = $target_forum, p.forum_id = $target_forum WHERE t.topic_id IN ($list) AND t.forum_id = $source_forum AND t.topic_moved_id = 0"
			. ' AND t.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')' . $target_lock . " AND COALESCE(destination.forum_link, '') = ''");
		if ((int) $db->sql_affectedrows() !== count($topics) + count($posts)) { phpbb_topic_move_error('Moderation_move_changed'); }
		require_once dirname(__FILE__) . '/functions_posting_storage.php';
		require_once dirname(__FILE__) . '/functions_log.php';
		foreach ($topics as $topic)
		{
			$topic_id = (int) $topic['topic_id'];
			// Moving back into a forum makes its empty redirect redundant. Never
			// delete a malformed redirect that actually contains user posts.
			$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id = $target_forum AND topic_moved_id = $topic_id"
				. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . ' WHERE ' . POSTS_TABLE . '.topic_id = ' . TOPICS_TABLE . '.topic_id)');
			$stubs = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
			foreach ($stubs as $stub)
			{
				$stub_id = (int) $stub['topic_id'];
				$db->sql_query('DELETE FROM ' . TOPICS_TABLE . " WHERE topic_id = $stub_id AND forum_id = $target_forum AND topic_moved_id = $topic_id"
					. ' AND NOT EXISTS (SELECT 1 FROM ' . POSTS_TABLE . " WHERE topic_id = $stub_id)");
				if ((int) $db->sql_affectedrows() !== 1) { phpbb_topic_move_error('Moderation_move_changed'); }
				foreach (array(BOOKMARK_TABLE, TOPICS_WATCH_TABLE, TOPIC_VIEW_TABLE) as $table) { $db->sql_query('DELETE FROM ' . $table . " WHERE topic_id = $stub_id"); }
			}
			if ($leave_shadow)
			{
				// Copy stored title/metadata directly, without re-escaping or
				// slash-normalizing names already in the database.
				$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' (forum_id, topic_title, topic_desc, topic_poster, topic_time, topic_status, topic_type, topic_vote, topic_views, topic_replies, topic_first_post_id, topic_last_post_id, topic_moved_id, topic_icon, topic_attachment)'
					. " SELECT $source_forum, t.topic_title, t.topic_desc, t.topic_poster, t.topic_time, " . TOPIC_MOVED . ', ' . POST_NORMAL . ', t.topic_vote, t.topic_views, t.topic_replies, t.topic_first_post_id, t.topic_last_post_id, t.topic_id, t.topic_icon, t.topic_attachment'
					. ' FROM ' . TOPICS_TABLE . ' t JOIN ' . FORUMS_TABLE . " f ON f.forum_id = $source_forum LEFT JOIN " . TOPICS_TABLE
					. " existing ON existing.forum_id = $source_forum AND existing.topic_moved_id = $topic_id WHERE t.topic_id = $topic_id AND t.forum_id = $target_forum AND t.topic_moved_id = 0 AND existing.topic_id IS NULL");
				$result = $db->sql_query('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE forum_id = $source_forum AND topic_moved_id = $topic_id");
				$stub = $db->sql_fetchrow($result); $db->sql_freeresult($result);
				if (!$stub) { phpbb_topic_move_error('Moderation_move_changed'); }
			}
		}
		// Recount only affected real users when crossing the count_posts boundary.
		if ((bool) $forums[$source_forum]['count_posts'] !== (bool) $forums[$target_forum]['count_posts'] && $posters)
		{
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_posts = (SELECT COUNT(*) FROM ' . POSTS_TABLE . ' p JOIN ' . FORUMS_TABLE
				. ' f ON f.forum_id = p.forum_id WHERE p.poster_id = ' . USERS_TABLE . '.user_id AND f.count_posts = 1) WHERE user_id IN (' . implode(',', $posters) . ') AND user_id > 0');
		}
		phpbb_posting_sync_forum($db, $source_forum); phpbb_posting_sync_forum($db, $target_forum);
		// Global topic totals also change when redirects are created/removed.
		// Keep these inside the transaction, not in modcp's later board_stats().
		if (count($db->rows('SELECT config_name FROM ' . CONFIG_TABLE . " WHERE config_name IN ('max_topics','max_posts') FOR UPDATE")) !== 2) { phpbb_topic_move_error('Moderation_move_storage'); }
		$db->sql_query('UPDATE ' . CONFIG_TABLE . " SET config_value = CASE WHEN config_name = 'max_topics' THEN (SELECT COALESCE(SUM(forum_topics),0) FROM " . FORUMS_TABLE
			. ') ELSE (SELECT COALESCE(SUM(forum_posts),0) FROM ' . FORUMS_TABLE . ") END WHERE config_name IN ('max_topics','max_posts')");
		if (!log_action('move', $ids, $userdata['user_id'], $userdata['username'], $db)) { phpbb_topic_move_error('Moderation_move_failed'); }
		$db->commit();
		return $ids;
	}
	finally
	{
		$db->rollback(); $lock->release();
		// Invalidate after release even on a lost commit reply. Never publish an
		// uncommitted tree or retain the old tree after a possibly committed move.
		if ($db->write_attempted && defined('CACHE_TREE'))
		{
			global $phpbb_root_path;
			@unlink($phpbb_root_path . 'cache/tree.cache');
		}
	}
}
