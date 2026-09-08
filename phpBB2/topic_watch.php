<?php
// Session-independent email landing URL; only the authenticated confirmation
// POST can remove the current user's subscription. GET never changes it.
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);
require_once $phpbb_root_path . 'includes/functions_topic_preferences.' . $phpEx;
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
$topic_id = phpbb_topic_preference_id(phpbb_request_scalar($_POST, POST_TOPIC_URL, phpbb_request_scalar($_GET, POST_TOPIC_URL)));
if (!$topic_id) { message_die(GENERAL_MESSAGE, $lang['Topic_post_not_exist']); }
if (empty($userdata['session_logged_in']))
{
	redirect(append_sid("login.$phpEx?redirect=topic_watch.$phpEx&" . POST_TOPIC_URL . "=$topic_id", true));
}
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
if ($method === 'POST' && isset($_POST['cancel'])) { redirect(append_sid("index.$phpEx", true)); }
if ($method === 'POST' && isset($_POST['confirm']))
{
	$sid = phpbb_request_scalar($_POST, 'sid');
	$token = phpbb_request_scalar($_POST, 'action_token');
	$expected = phpbb_session_action_token('topic-preference', 'unwatch', $topic_id, $userdata['session_id']);
	if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid) || $token === '' || !hash_equals($expected, $token))
	{
		message_die(GENERAL_MESSAGE, $lang['Session_invalid']);
	}
	try { phpbb_topic_preference($db, $topic_id, 'watch', false); }
	catch (PhpbbTopicPreferenceException $exception) { message_die(GENERAL_MESSAGE, $exception->getMessage()); }
	message_die(GENERAL_MESSAGE, $lang['No_longer_watching']);
}
$page_title = $lang['Stop_watching_topic'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);
$template->set_filenames(array('body'=>'confirm_body.tpl'));
$token = phpbb_session_action_token('topic-preference', 'unwatch', $topic_id, $userdata['session_id']);
$template->assign_vars(array(
	'MESSAGE_TITLE'=>$lang['Stop_watching_topic'], 'MESSAGE_TEXT'=>$lang['Topic_unwatch_confirm'],
	'L_YES'=>$lang['Yes'], 'L_NO'=>$lang['No'],
	'S_CONFIRM_ACTION'=>append_sid("topic_watch.$phpEx"),
	'S_HIDDEN_FIELDS'=>'<input type="hidden" name="' . POST_TOPIC_URL . '" value="' . $topic_id . '" />'
		. '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />'
		. '<input type="hidden" name="action_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" />'
));
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
