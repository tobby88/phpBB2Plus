<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/php_compat.php';
require_once dirname(__FILE__) . '/functions_topic_highlight.php';
require_once dirname(__FILE__) . '/functions_forum_read_snapshot.php';

function phpbb_shoutbox_visible_from()
{
	return ' FROM ' . SHOUTBOX_TABLE . ' s INNER JOIN ' . USERS_TABLE . ' u ON u.user_id = s.shout_user_id';
}

function phpbb_shoutbox_rows_sql($start, $per_page, $compact, $has_avatar_url = false)
{
	$fields = 's.shout_id, s.shout_user_id, s.shout_username, s.shout_author_removed, s.shout_active,
		s.shout_text, s.shout_bbcode_uid, s.enable_bbcode, s.enable_smilies, s.shout_session_time, u.username';
	if ($compact)
	{
		$fields .= ', u.user_allowsmile';
	}
	else
	{
		$fields .= ', s.shout_ip, u.user_id, u.user_posts, u.user_from, u.user_regdate, u.user_rank,
			u.user_avatar, u.user_avatar_type, u.user_allowavatar';
		if ($has_avatar_url) { $fields .= ', u.user_avatar_url'; }
	}
	return 'SELECT ' . $fields . phpbb_shoutbox_visible_from()
		. ' ORDER BY s.shout_session_time DESC, s.shout_id DESC LIMIT ' . max(0, (int)$start) . ', ' . max(1, (int)$per_page);
}

function phpbb_shoutbox_history_page($database, $start, $per_page, $shout_id)
{
	$per_page = max(1, (int)$per_page);
	$shout_id = (int)$shout_id;
	$from = phpbb_shoutbox_visible_from();
	$reader = new PhpbbForumReadSnapshot($database, array(SHOUTBOX_TABLE, USERS_TABLE));
	try
	{
		// Some older installations also store a link for the author's avatar.
		$count = $reader->rows("SELECT COUNT(*) AS total, EXISTS (SELECT 1 FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $reader->sql_escape(USERS_TABLE)
			. "' AND COLUMN_NAME = 'user_avatar_url') AS has_avatar_url" . $from);
		$total = (int)$count[0]['total'];
		if ($shout_id > 0)
		{
			$target = $reader->rows('SELECT s.shout_session_time' . $from . ' WHERE s.shout_id = ' . $shout_id);
			if (!$target) { return false; }
			$time = (int)$target[0]['shout_session_time'];
			$ahead = $reader->rows('SELECT COUNT(*) AS preceding' . $from . ' WHERE s.shout_session_time > ' . $time
				. ' OR (s.shout_session_time = ' . $time . ' AND s.shout_id > ' . $shout_id . ')');
			$start = (int)$ahead[0]['preceding'];
		}
		$start = max(0, min((int)$start, $total - 1));
		$start -= $start % $per_page;
		return array('total' => $total, 'start' => $start, 'rows' => $total
			? $reader->rows(phpbb_shoutbox_rows_sql($start, $per_page, false, !empty($count[0]['has_avatar_url']))) : array());
	}
	finally { $reader->release(); }
}

/** Decode the request once and retain a complete UTF-8 prefix in the byte budget. */
function phpbb_shoutbox_text($value, $maximum_bytes = 16000)
{
	$value = phpbb_request_raw_value($value);
	if (!is_string($value) || preg_match('//u', $value) !== 1) { return false; }
	$value = (string) substr(trim($value), 0, max(0, min(16000, (int) $maximum_bytes)));
	// The original was valid; only the final, at most four-byte character can
	// have been split. Drop its incomplete suffix without changing earlier text.
	for ($removed = 0; $removed < 3 && preg_match('//u', $value) !== 1; $removed++)
	{
		$value = (string) substr($value, 0, -1);
	}
	return preg_match('//u', $value) === 1 ? $value : false;
}

/** Keep the legacy prepared-message contract, checking the actual TEXT bytes. */
function phpbb_shoutbox_prepare_message($text, $html_on, $bbcode_on, $smilies_on, $bbcode_uid)
{
	if (!is_string($text) || strlen($text) > 16000 || preg_match('//u', $text) !== 1) { return false; }
	$message = prepare_message(addslashes($text), $html_on, $bbcode_on, $smilies_on, $bbcode_uid);
	if (!is_string($message)) { return false; }
	$stored = stripslashes($message);
	return strlen($stored) <= 65535 && preg_match('//u', $stored) === 1 ? $message : false;
}

/** Render stored, already escaped text before linking or marking visible words. */
function phpbb_shoutbox_render($text, $uid, $bbcode_on, $smilies_on, $highlight = '', $color = '000000')
{
	if ($bbcode_on && $uid !== '') { $text = bbencode_second_pass($text, $uid); }
	$orig_word = $replacement_word = array();
	obtain_word_list($orig_word, $replacement_word);
	if ($orig_word) { $text = preg_replace($orig_word, $replacement_word, $text); }
	$text = make_clickable($text);
	if ($smilies_on) { $text = smilies_pass($text); }
	$text = str_replace("\n", '<br />', $text);
	return phpbb_topic_highlight_apply($text, $highlight, $color);
}
