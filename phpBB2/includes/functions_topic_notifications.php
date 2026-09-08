<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_topic_preferences.php';

function phpbb_topic_notification_context($db, $post_id)
{
	$rows = phpbb_topic_preference_rows($db, 'SELECT t.topic_id, t.forum_id, t.topic_title, p.poster_id FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE
		. ' t ON t.topic_id = p.topic_id AND t.forum_id = p.forum_id JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id'
		. ' WHERE p.post_id = ' . (int) $post_id . ' AND t.topic_moved_id = 0 AND t.topic_status IN (' . TOPIC_UNLOCKED . ',' . TOPIC_LOCKED . ')');
	return $rows ? $rows[0] : false;
}
function phpbb_topic_notification_recipient($db, $user_id, $forum_id)
{
	$rows = phpbb_topic_preference_rows($db, 'SELECT user_id, user_level, user_active, user_email, user_lang, user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id);
	if (!$rows || empty($rows[0]['user_active']) || (int) $rows[0]['user_blocktime'] > time() || !filter_var($rows[0]['user_email'], FILTER_VALIDATE_EMAIL)) { return false; }
	$bans = phpbb_topic_preference_rows($db, 'SELECT ban_userid FROM ' . BANLIST_TABLE . ' WHERE ban_userid = ' . (int) $user_id);
	if ($bans || !phpbb_topic_preference_can_read($db, $forum_id, $rows[0])) { return false; }
	return $rows[0];
}

// Reserve notifications while holding the shared storage lock, but never keep
// that lock across SMTP/mail I/O. A claim makes parallel replies idempotent.
// Expired interrupted claims can be retried by a later reply; this is not an
// exactly-once mail queue (transport acceptance itself cannot be rolled back).
function phpbb_topic_notifications_prepare($database, $post_id)
{
	$post_id = phpbb_topic_preference_id($post_id);
	if (!$post_id) { return array(); }
	$claim = bin2hex(phpbb_random_bytes(16)); $now = time(); $expired = $now - 600;
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_preference_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicPreferenceDatabase($lock->connection);
		$topic = phpbb_topic_notification_context($db, $post_id);
		if (!$topic) { return array(); }
		$topic_id = (int) $topic['topic_id']; $author = (int) $topic['poster_id'];
		$eligible = "(notify_status = 0 OR (notify_claim <> '' AND notify_claimed_at < $expired))";
		$rows = phpbb_topic_preference_rows($db, 'SELECT DISTINCT user_id FROM ' . TOPICS_WATCH_TABLE . " WHERE topic_id = $topic_id AND user_id > 0 AND user_id <> $author AND $eligible ORDER BY user_id");
		$tasks = array();
		foreach ($rows as $row)
		{
			$user_id = (int) $row['user_id'];
			if (!phpbb_topic_notification_recipient($db, $user_id, (int) $topic['forum_id'])) { continue; }
			$db->sql_query('UPDATE ' . TOPICS_WATCH_TABLE . " SET notify_status = 1, notify_claim = '$claim', notify_claimed_at = $now WHERE topic_id = $topic_id AND user_id = $user_id AND $eligible");
			if ((int) $db->sql_affectedrows() > 0) { $tasks[] = array('topic_id'=>$topic_id, 'post_id'=>$post_id, 'user_id'=>$user_id, 'claim'=>$claim); }
		}
		return $tasks;
	}
	finally { $lock->release(); }
}

// Recheck immediately before delivery, including moves, deleted posts, changes
// to recipient addresses/permissions and cancellation/read since preparation.
function phpbb_topic_notification_ready($database, $task)
{
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_preference_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicPreferenceDatabase($lock->connection);
		$where = 'topic_id = ' . (int) $task['topic_id'] . ' AND user_id = ' . (int) $task['user_id'] . " AND notify_status = 1 AND notify_claim = '" . $db->sql_escape($task['claim']) . "'";
		$claims = phpbb_topic_preference_rows($db, 'SELECT topic_id FROM ' . TOPICS_WATCH_TABLE . ' WHERE ' . $where);
		if (!$claims) { return false; }
		$topic = phpbb_topic_notification_context($db, $task['post_id']);
		$user = $topic && (int) $topic['topic_id'] === (int) $task['topic_id'] ? phpbb_topic_notification_recipient($db, $task['user_id'], (int) $topic['forum_id']) : false;
		if (!$user)
		{
			$db->sql_query('UPDATE ' . TOPICS_WATCH_TABLE . " SET notify_status = 0, notify_claim = '', notify_claimed_at = 0 WHERE " . $where);
			return false;
		}
		return array('user'=>$user, 'topic'=>$topic);
	}
	finally { $lock->release(); }
}
function phpbb_topic_notification_finish($database, $task, $sent)
{
	$lock = new attach_mutation_lock($database);
	if (!$lock->acquired) { phpbb_topic_preference_error('Attachment_storage_busy'); }
	try
	{
		$db = new PhpbbTopicPreferenceDatabase($lock->connection);
		$db->sql_query('UPDATE ' . TOPICS_WATCH_TABLE . ' SET notify_status = ' . ($sent ? 1 : 0) . ", notify_claim = '', notify_claimed_at = 0 WHERE topic_id = "
			. (int) $task['topic_id'] . ' AND user_id = ' . (int) $task['user_id'] . " AND notify_status = 1 AND notify_claim = '" . $db->sql_escape($task['claim']) . "'");
	}
	finally { $lock->release(); }
}
function phpbb_send_topic_notifications($database, $post_id)
{
	global $board_config, $lang, $phpbb_root_path, $phpEx;
	$tasks = phpbb_topic_notifications_prepare($database, $post_id);
	if (!$tasks) { return; }
	include_once($phpbb_root_path . 'includes/emailer.' . $phpEx);
	$original = array(); $replacement = array(); obtain_word_list($original, $replacement);
	foreach ($tasks as $task)
	{
		$current = phpbb_topic_notification_ready($database, $task);
		if (!$current) { continue; }
		$emailer = new emailer($board_config['smtp_delivery']);
		$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
		$emailer->email_address($current['user']['user_email']);
		$emailer->use_template('topic_notify', $current['user']['user_lang']);
		$emailer->set_subject($lang['Topic_reply_notification']);
		$emailer->msg = preg_replace('#[ ]?{USERNAME}#', '', $emailer->msg);
		$title = unprepare_message($current['topic']['topic_title']);
		if ($original) { $title = preg_replace($original, $replacement, $title); }
		$emailer->assign_vars(array(
			'EMAIL_SIG'=>!empty($board_config['board_email_sig']) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
			'SITENAME'=>$board_config['sitename'], 'TOPIC_TITLE'=>$title,
			'U_TOPIC'=>phpbb_board_url('viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $task['post_id'] . '#' . $task['post_id']),
			'U_STOP_WATCHING_TOPIC'=>phpbb_board_url('topic_watch.' . $phpEx . '?' . POST_TOPIC_URL . '=' . $task['topic_id'])
		));
		$sent = $emailer->send();
		phpbb_topic_notification_finish($database, $task, (bool) $sent);
	}
}
