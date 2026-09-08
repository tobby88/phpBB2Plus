<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbAjaxStorageException extends RuntimeException {}

function phpbb_ajax_storage_error($key)
{
	global $lang;
	throw new PhpbbAjaxStorageException($lang[$key]);
}

// Keep shared auth/search helpers on the owning connection and turn their SQL
// failures into an AJAX error instead of an HTML error page inside an XML reply.
class PhpbbAjaxStorageDatabase
{
	var $connection;
	function __construct($connection) { $this->connection = $connection; }
	function __call($method, $args) { return call_user_func_array(array($this->connection, $method), $args); }
	function sql_query($sql, $transaction = false)
	{
		$result = $this->connection->sql_query($sql, $transaction);
		if (!$result) { phpbb_ajax_storage_error('Posting_storage_failed'); }
		return $result;
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
	try
	{
		$db = new PhpbbAjaxStorageDatabase($lock->connection);
		$result = $db->sql_query('SELECT p.post_id, p.topic_id, p.forum_id, p.poster_id, p.post_username, p.post_edit_time, p.post_edit_count, p.enable_bbcode, p.enable_html, p.enable_smilies, t.topic_status, f.forum_status, pt.post_subject, pt.post_text, pt.bbcode_uid, u.username'
			. ' FROM ' . POSTS_TABLE . ' p JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id = p.post_id'
			. ' JOIN ' . TOPICS_TABLE . ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id AND t.topic_moved_id = 0'
			. ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = p.forum_id LEFT JOIN ' . USERS_TABLE . ' u ON u.user_id = p.poster_id WHERE p.post_id = ' . $post_id);
		$row = $db->sql_fetchrow($result); $db->sql_freeresult($result);
		if (!$row) { phpbb_ajax_storage_error('Topic_post_not_exist'); }
		$forum_id = (int) $row['forum_id']; $topic_id = (int) $row['topic_id']; $poster_id = (int) $row['poster_id'];
		$is_auth = auth(AUTH_ALL, $forum_id, $userdata, '', $db);
		if (empty($userdata['session_logged_in']) || empty($is_auth['auth_view']) || empty($is_auth['auth_read']) || (empty($is_auth['auth_mod']) && (empty($is_auth['auth_edit']) || $poster_id !== (int) $userdata['user_id'])))
		{
			phpbb_ajax_storage_error('Edit_own_posts');
		}
		if (empty($is_auth['auth_mod']) && (int) $row['forum_status'] === FORUM_LOCKED) { phpbb_ajax_storage_error('Forum_locked'); }
		if (empty($is_auth['auth_mod']) && (int) $row['topic_status'] === TOPIC_LOCKED) { phpbb_ajax_storage_error('Topic_locked'); }
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
		if ($poster_id === (int) $userdata['user_id'] && $post_id !== (int) $bounds['last_post'])
		{
			$now = time();
			$db->sql_query('UPDATE ' . POSTS_TABLE . " SET post_edit_time = $now, post_edit_count = COALESCE(post_edit_count, 0) + 1 WHERE post_id = $post_id AND topic_id = $topic_id AND forum_id = $forum_id AND poster_id = $poster_id");
			if ((int) $db->sql_affectedrows() !== 1) { phpbb_ajax_storage_error('Posting_target_changed'); }
			$row['post_edit_time'] = $now; $row['post_edit_count'] = (int) $row['post_edit_count'] + 1;
		}
		require_once dirname(__FILE__) . '/functions_search.php';
		remove_search_post($post_id, $field === 'subject', $field === 'text', $db);
		add_search_words('single', $post_id, $field === 'text' ? $value : '', $field === 'subject' ? $value : '', $db);
		$row['username'] = isset($row['username']) ? $row['username'] : $row['post_username'];
		return array('post' => $row, 'value' => $value, 'bbcode_uid' => $bbcode_uid);
	}
	finally { $lock->release(); }
}
