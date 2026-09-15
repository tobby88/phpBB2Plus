<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_moderator_identity.php';

class PhpbbAjaxStorageException extends RuntimeException {}

function phpbb_ajax_storage_error($key)
{
	global $lang;
	throw new PhpbbAjaxStorageException(isset($lang[$key]) ? $lang[$key] : $key);
}

// Keep shared auth/search helpers on the owning connection and turn their SQL
// failures into an AJAX error instead of an HTML error page inside an XML reply.
class PhpbbAjaxStorageDatabase
{
	var $connection;
	var $context = array();
	var $transactional = false;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function control($sql)
	{
		$result = $this->connection->sql_query($sql);
		if (!$result) { phpbb_ajax_storage_error('Ajax_edit_storage_failed'); }
		return $result;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql)) { phpbb_ajax_storage_error('Ajax_edit_storage_failed'); }
		if (preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i', $sql))
		{
			if (!$this->transactional) { phpbb_ajax_storage_error('Ajax_edit_storage_failed'); }
			$this->authorize();
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
		if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { phpbb_ajax_storage_error('Edit_own_posts'); }
		$sid = $this->sql_escape($userdata['session_id']);
		if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid')"
			. ' AND session_user_id = ' . (int)$user['user_id'] . ' AND session_logged_in = 1')) { phpbb_ajax_storage_error('Edit_own_posts'); }
		return $user;
	}
	function authorize()
	{
		$user = $this->actor();
		if (!$this->context) { phpbb_ajax_storage_error('Posting_target_changed'); }
		$c = $this->context;
		$rows = $this->rows('SELECT p.poster_id, t.topic_status, f.forum_status FROM ' . POSTS_TABLE . ' p'
			. ' JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id'
			. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id AND t.topic_moved_id = 0'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id WHERE p.post_id = ' . $c['post_id']
			. ' AND p.topic_id = ' . $c['topic_id'] . ' AND p.forum_id = ' . $c['forum_id'] . ' AND p.poster_id = ' . $c['poster_id']);
		if (count($rows) !== 1) { phpbb_ajax_storage_error('Posting_target_changed'); }
		$auth = auth(AUTH_ALL, $c['forum_id'], $user, '', $this);
		if (empty($auth['auth_view']) || empty($auth['auth_read']) || (empty($auth['auth_mod']) && (empty($auth['auth_edit']) || $c['poster_id'] !== (int)$user['user_id']))) { phpbb_ajax_storage_error('Edit_own_posts'); }
		if (empty($auth['auth_mod']) && (int)$rows[0]['forum_status'] === FORUM_LOCKED) { phpbb_ajax_storage_error('Forum_locked'); }
		if (empty($auth['auth_mod']) && (int)$rows[0]['topic_status'] === TOPIC_LOCKED) { phpbb_ajax_storage_error('Topic_locked'); }
	}
	function begin()
	{
		if ($this->transactional) { phpbb_ajax_storage_error('Ajax_edit_storage_failed'); }
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		foreach (array(USERS_TABLE, SESSIONS_TABLE, FORUMS_TABLE, TOPICS_TABLE, POSTS_TABLE, POSTS_TEXT_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, SEARCH_WORD_TABLE, SEARCH_MATCH_TABLE, CONFIG_TABLE) as $table)
		{
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
			// Canonical new installs deliberately give search words a binary
			// collation; converted installs may use unicode_ci. Both are utf8mb4.
			$word_binary = "(c.TABLE_NAME = '" . $this->sql_escape(SEARCH_WORD_TABLE) . "' AND c.COLUMN_NAME = 'word_text' AND c.COLLATION_NAME = 'utf8mb4_bin')";
			$table_name = $this->sql_escape($table);
			// Bound metadata scans without weakening column policy or held locks.
			$rows = $this->rows("SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.TABLES t WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . $table_name . "'"
				. " AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='" . $table_name . "' AND c.CHARACTER_SET_NAME IS NOT NULL AND (c.CHARACTER_SET_NAME <> 'utf8mb4' OR (c.COLLATION_NAME <> 'utf8mb4_unicode_ci' AND NOT " . $word_binary . ")))");
			if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_ajax_storage_error('Ajax_edit_storage_upgrade'); }
		}
	}
	function commit()
	{
		global $userdata;
		if (!$this->transactional) { phpbb_ajax_storage_error('Ajax_edit_storage_failed'); }
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id = '$sid' AND HEX(session_id) = HEX('$sid') LOCK IN SHARE MODE");
		$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id = ' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id = ' . $this->context['forum_id'] . ' ORDER BY group_id LOCK IN SHARE MODE');
		// Authority is pinned through COMMIT. A later revocation or disconnect
		// cannot roll back an acknowledged edit or turn it into a failed save.
		$this->authorize(); $this->control('COMMIT'); $this->transactional = false;
	}
	function rollback()
	{
		if (!$this->transactional) { return; }
		try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {}
		$this->transactional = false;
	}
}

// The stored title column holds at most 60 characters, including HTML entities.
// Keep complete UTF-8 characters and complete entities at that boundary.
function phpbb_ajax_storage_subject($value)
{
	require_once dirname(__FILE__) . '/functions_post_subject.php';
	$result = phpbb_storage_subject($value);
	if ($result === false) { phpbb_ajax_storage_error('Ajax_edit_invalid_text'); }
	return $result;
}

// Internal: ajax.php has verified the POST method and the session token.
// Values are decoded plain UTF-8, not the legacy slash-normalized request form.
function phpbb_ajax_edit_post($database, $post_id, $field, $value)
{
	global $userdata;
	$ids = (is_int($post_id) || is_string($post_id)) && preg_match('/^[0-9]+$/D', (string) $post_id) ? attach_delete_id_array($post_id) : false;
	if ($ids === false || count($ids) !== 1 || !in_array($field, array('subject','text'), true)) { phpbb_ajax_storage_error('Topic_post_not_exist'); }
	$post_id = $ids[0];
	if (!is_string($value) || preg_match('//u', $value) !== 1) { phpbb_ajax_storage_error('Ajax_edit_invalid_text'); }
	if (strlen($value) > ($field === 'subject' ? 4096 : 1048576)) { phpbb_ajax_storage_error('Ajax_edit_too_large'); }
	$value = trim($value);
	if ($field === 'text' && $value === '') { phpbb_ajax_storage_error('Empty_message'); }
	if ($field === 'subject') { $value = phpbb_ajax_storage_subject($value); }
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_ajax_storage_error('Attachment_storage_busy'); }
	$db = new PhpbbAjaxStorageDatabase($lock->connection);
	try
	{
		$db->begin();
		$result = $db->sql_query('SELECT p.post_id, p.topic_id, p.forum_id, p.poster_id, p.post_username, p.post_edit_time, p.post_edit_count, p.enable_bbcode, p.enable_html, p.enable_smilies, t.topic_status, t.topic_title, f.forum_status, pt.post_subject, pt.post_text, pt.bbcode_uid, u.username'
			. ' FROM ' . POSTS_TABLE . ' p JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id'
			. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id AND t.topic_moved_id = 0'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.poster_id WHERE p.post_id = ' . $post_id . ' FOR UPDATE');
		$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$row) { phpbb_ajax_storage_error('Topic_post_not_exist'); }
		$forum_id = (int) $row['forum_id']; $topic_id = (int) $row['topic_id']; $poster_id = (int) $row['poster_id'];
		$db->context = array('post_id'=>$post_id, 'topic_id'=>$topic_id, 'forum_id'=>$forum_id, 'poster_id'=>$poster_id); $db->authorize();
		$db->rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $topic_id . ' ORDER BY post_id FOR UPDATE');
		$result = $db->sql_query('SELECT MIN(post_id) AS first_post, MAX(post_id) AS last_post FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . $topic_id);
		$bounds = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		$first_post = (int) $bounds['first_post'] === $post_id;
		if ($field === 'subject' && $first_post && $value === '') { phpbb_ajax_storage_error('Empty_subject'); }
		$bbcode_uid = (string) $row['bbcode_uid'];
		if ($field === 'text')
		{
			$bbcode_uid = !empty($row['enable_bbcode']) ? make_bbcode_uid() : '';
			try { $value = stripslashes(prepare_message(addslashes($value), $row['enable_html'], $row['enable_bbcode'], $row['enable_smilies'], $bbcode_uid)); }
			catch (PhpbbBbcodeParseException $error) { phpbb_ajax_storage_error('Ajax_edit_invalid_text'); }
			// Ignore only the newly generated parser UID when detecting a retry.
			// Keep the stored UID/text on a no-op, avoiding a second edit counter.
			if ($bbcode_uid !== '' && str_replace(':' . $bbcode_uid, ':' . (string)$row['bbcode_uid'], $value) === (string)$row['post_text'])
			{ $value = (string)$row['post_text']; $bbcode_uid = (string)$row['bbcode_uid']; }
		}
		$changed = $value !== (string)$row['post_' . $field];
		$row['username'] = isset($row['username']) ? $row['username'] : $row['post_username'];
		if (!$changed && !($field === 'subject' && $first_post && $value !== (string)$row['topic_title']))
		{
			$db->commit(); return array('post'=>$row, 'value'=>$value, 'bbcode_uid'=>$bbcode_uid);
		}
		$scope = ' FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id AND t.topic_moved_id = 0'
			. " WHERE p.post_id = $post_id AND p.topic_id = $topic_id AND p.forum_id = $forum_id AND p.poster_id = $poster_id";
		$assignment = $field === 'subject' ? "post_subject = '" . $db->sql_escape($value) . "'" : "post_text = '" . $db->sql_escape($value) . "', bbcode_uid = '" . $db->sql_escape($bbcode_uid) . "'";
		$db->sql_query('UPDATE ' . POSTS_TEXT_TABLE . ' SET ' . $assignment . ' WHERE post_id = ' . $post_id . ' AND EXISTS (SELECT 1' . $scope . ')');
		// An unchanged UPDATE is valid only while both parent and text still exist.
		$result = $db->sql_query('SELECT p.post_id' . $scope . ' AND EXISTS (SELECT 1 FROM ' . POSTS_TEXT_TABLE . ' WHERE post_id = ' . $post_id . ')');
		$current = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$current) { phpbb_ajax_storage_error('Posting_target_changed'); }
		if ($field === 'subject' && $first_post)
		{
			$db->sql_query('UPDATE ' . TOPICS_TABLE . " SET topic_title = '" . $db->sql_escape($value) . "' WHERE topic_id = $topic_id AND forum_id = $forum_id");
		}
		if ($changed && $poster_id === (int) $userdata['user_id'] && $post_id !== (int) $bounds['last_post'])
		{
			$now = time();
			$db->sql_query('UPDATE ' . POSTS_TABLE . " SET post_edit_time = $now, post_edit_count = COALESCE(post_edit_count, 0) + 1 WHERE post_id = $post_id AND topic_id = $topic_id AND forum_id = $forum_id AND poster_id = $poster_id");
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_ajax_storage_error('Posting_target_changed'); }
			$row['post_edit_time'] = $now; $row['post_edit_count'] = (int) $row['post_edit_count'] + 1;
		}
		require_once dirname(__FILE__) . '/functions_search.php';
		remove_search_post($post_id, $field === 'subject', $field === 'text', $db);
		add_search_words('single', $post_id, $field === 'text' ? $value : '', $field === 'subject' ? $value : '', $db);
		$db->commit();
		return array('post' => $row, 'value' => $value, 'bbcode_uid' => $bbcode_uid);
	}
	finally { $db->rollback(); $lock->release(); }
}
