<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

function phpbb_merge_id($value)
{
	if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)) { return 0; }
	$value = ltrim((string) $value, '0');
	return $value !== '' && strlen($value) <= 8 && (int) $value <= 16777215 ? (int) $value : 0;
}
function phpbb_merge_read_row($database, $sql)
{
	$result = $database->sql_query($sql);
	if (!$result) { message_die(GENERAL_ERROR, 'Could not read merge selection'); }
	$row = $database->sql_fetchrow($result); $database->sql_freeresult($result);
	return $row;
}

// Input remains plain text until rendered. Never parse HTML-escaped URLs: an
// ordinary second query argument (&t=...) would otherwise become "amp;t".
function phpbb_merge_topic_id($database, $value)
{
	if (!(is_int($value) || is_string($value))) { return 0; }
	$value = trim((string) $value);
	if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) { return 0; }
	if (ctype_digit($value)) { return phpbb_merge_id($value); }
	$value = explode('#', $value, 2); $parts = explode('?', $value[0], 2);
	parse_str(count($parts) === 2 ? $parts[1] : $parts[0], $query);
	$topic = isset($query[POST_TOPIC_URL]) ? phpbb_merge_id($query[POST_TOPIC_URL]) : 0;
	if (isset($query[POST_TOPIC_URL]) && !$topic) { return 0; }
	if (isset($query[POST_POST_URL]))
	{
		$post = phpbb_merge_id($query[POST_POST_URL]);
		if (!$post) { return 0; }
		$row = phpbb_merge_read_row($database, 'SELECT topic_id FROM ' . POSTS_TABLE . ' WHERE post_id = ' . $post);
		$resolved = $row ? phpbb_merge_id($row['topic_id']) : 0;
		if (!$resolved || ($topic && $topic !== $resolved)) { return 0; }
		$topic = $resolved;
	}
	return $topic;
}

// The picker and preview are read paths, but must enforce the same view/read/
// moderation boundary as a merge. A moderator of one forum is not a moderator
// of every forum. Re-read account and group rights instead of trusting a title
// lookup or the session's old user_level alone.
function phpbb_merge_forum_allowed($database, $forum_id)
{
	global $userdata;
	$forum_id = phpbb_merge_id($forum_id);
	$user_id = isset($userdata['user_id']) ? phpbb_merge_id($userdata['user_id']) : 0;
	if (!$forum_id || !$user_id || empty($userdata['session_logged_in'])) { return false; }
	$user = phpbb_merge_read_row($database, 'SELECT user_id, user_level, user_active FROM ' . USERS_TABLE . ' WHERE user_id = ' . $user_id);
	if (!$user || empty($user['user_active'])) { return false; }
	$forum = phpbb_merge_read_row($database, 'SELECT forum_id, forum_link FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . $forum_id);
	if (!$forum || !empty($forum['forum_link'])) { return false; }
	$user['session_logged_in'] = true;
	$rights = auth(AUTH_ALL, $forum_id, $user, '', $database);
	return !empty($rights['auth_view']) && !empty($rights['auth_read']) && !empty($rights['auth_mod']);
}
function phpbb_merge_topic_preview($database, $topic_id)
{
	$topic_id = phpbb_merge_id($topic_id);
	if (!$topic_id) { return false; }
	$scope = phpbb_merge_read_row($database, 'SELECT forum_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id . ' AND topic_moved_id = 0');
	if (!$scope || !phpbb_merge_forum_allowed($database, $scope['forum_id'])) { return false; }
	return phpbb_merge_read_row($database, 'SELECT topic_title FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id
		. ' AND forum_id = ' . (int) $scope['forum_id'] . ' AND topic_moved_id = 0');
}
function phpbb_merge_html($value, $stored = false)
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', !$stored);
}
