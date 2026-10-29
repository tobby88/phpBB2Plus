<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_author_visibility.php';

// Called on the edit's owned connection. Pin the actual editor name through
// COMMIT instead of attributing a moderator's change to the original author.
function phpbb_post_edit_snapshot($database, $actor)
{
	if (!is_array($actor) || !isset($actor['user_id']) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$actor['user_id']) || (int)$actor['user_id'] > 8388607) { return false; }
	$id = (int)$actor['user_id'];
	$result = $database->sql_query('SELECT user_id,username FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' AND user_active=1 LOCK IN SHARE MODE');
	try { $rows = $database->sql_fetchrowset($result); } finally { $database->sql_freeresult($result); }
	if (!is_array($rows) || count($rows) !== 1 || (int)$rows[0]['user_id'] !== $id || !is_string($rows[0]['username']) || preg_match('//u', $rows[0]['username']) !== 1 || preg_match_all('/./us', $rows[0]['username']) > 255) { return false; }
	return array('post_edit_time'=>time(), 'post_edit_user_id'=>$id, 'post_edit_username'=>$rows[0]['username']);
}

function phpbb_post_edit_assignments($database, $editor)
{
	return 'post_edit_time = ' . (int)$editor['post_edit_time'] . ', post_edit_count = COALESCE(post_edit_count, 0) + 1, post_edit_user_id = ' . (int)$editor['post_edit_user_id'] . ", post_edit_username = '" . $database->sql_escape($editor['post_edit_username']) . "'";
}

// The fallback is already escaped by its caller. NULL editor fields preserve
// historical captions without guessing who made an old unrecorded change.
// The actual last editor has its own retained identity, distinct from the
// original post author. Apply the same guest-only former-member policy here.
function phpbb_post_edit_caption($post, $fallback)
{
	global $lang, $board_config;
	if (empty($post['post_edit_count'])) { return ''; }
	$name = $fallback;
	if (isset($post['post_edit_user_id'], $post['post_edit_username']) && (int)$post['post_edit_user_id'] > 0 && is_string($post['post_edit_username'])) {
		$name = phpbb_author_name((int)$post['post_edit_user_id'],$post['post_edit_username']);
	} elseif (phpbb_author_privacy_guest() && !empty($post['post_author_removed'])) {
		$name = phpbb_former_author_label();
	}
	$format = (int)$post['post_edit_count'] === 1 ? $lang['Edited_time_total'] : $lang['Edited_times_total'];
	return '<br /><br />' . sprintf($format, $name, create_date($board_config['default_dateformat'], $post['post_edit_time'], $board_config['board_timezone']), $post['post_edit_count']);
}
