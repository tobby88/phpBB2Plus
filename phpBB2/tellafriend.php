<?php

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);

$topic = isset($_POST['topic']) && is_string($_POST['topic']) ? $_POST['topic'] : (isset($_GET['topic']) && is_string($_GET['topic']) ? $_GET['topic'] : '');
$link = isset($_POST['link']) && is_string($_POST['link']) ? $_POST['link'] : (isset($_GET['link']) && is_string($_GET['link']) ? $_GET['link'] : '');
$PHP_SELF = 'tellafriend.' . $phpEx;

$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);

if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=$PHP_SELF", true));
}

$topic_plain = trim(stripslashes($topic));
if (strlen($topic_plain) > 200)
{
	$topic_plain = substr($topic_plain, 0, 200);
}

$link_plain = trim(stripslashes($link));
$board_url = rtrim(phpbb_board_url(), '/');
if ($link_plain !== '' && !preg_match('#^https?://#i', $link_plain))
{
	$link_plain = $board_url . '/' . ltrim($link_plain, '/');
}
$link_parts = @parse_url($link_plain);
$board_parts = @parse_url($board_url);
if (strlen($link_plain) > 2048 || !$link_parts || !$board_parts || empty($link_parts['scheme']) || empty($link_parts['host']) || strtolower($link_parts['scheme']) !== strtolower($board_parts['scheme']) || strtolower($link_parts['host']) !== strtolower($board_parts['host']))
{
	$link_plain = $board_url;
}

$mail_body = str_replace(array('{TOPIC}', '{LINK}', '{SITENAME}'), array($topic_plain, $link_plain, $board_config['sitename']), $lang['Tell_Friend_Body']);

include($phpbb_root_path . 'includes/page_header.' . $phpEx);

$template->assign_vars(array(
	'L_TELL_FRIEND_TITLE' => $lang['Tell_Friend_Title'],
	'L_TELL_FRIEND_EMAIL_MESSAGE' => $lang['Tell_Friend_Email_Message'],
	'L_TELL_FRIEND_SENDER_USER' => $lang['Tell_Friend_Sender_User'],
	'L_TELL_FRIEND_SENDER_EMAIL' => $lang['Tell_Friend_Sender_Email'],
	'L_TELL_FRIEND_RECIEVER_USER' => $lang['Tell_Friend_Reciever_User'],
	'L_TELL_FRIEND_RECIEVER_EMAIL' => $lang['Tell_Friend_Reciever_Email'],
	'L_TELL_FRIEND_MSG' => $lang['Tell_Friend_Msg'],
	'L_TELL_FRIEND_BODY' => htmlspecialchars($mail_body, ENT_QUOTES, 'UTF-8'),
	'SUBMIT_ACTION' => append_sid($PHP_SELF, true),
	'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
	'L_SUBMIT' => $lang['Send_email'],
	'SITENAME' => htmlspecialchars($board_config['sitename'], ENT_QUOTES, 'UTF-8'),
	'TOPIC' => htmlspecialchars($topic_plain, ENT_QUOTES, 'UTF-8'),
	'LINK' => htmlspecialchars($link_plain, ENT_QUOTES, 'UTF-8'),
	'SENDER_NAME' => htmlspecialchars($userdata['username'], ENT_QUOTES, 'UTF-8'),
	'SENDER_MAIL' => htmlspecialchars($userdata['user_email'], ENT_QUOTES, 'UTF-8')
));

if (isset($_POST['submit']))
{
	$error = false;
	$error_msg = '';
	if (time() - (int) $userdata['user_emailtime'] < (int) $board_config['flood_interval'])
	{
		message_die(GENERAL_MESSAGE, $lang['Flood_email_limit']);
	}
	if (isset($ctracker_config) && is_object($ctracker_config) && !empty($ctracker_config->settings['massmail_protection']) && (int) $userdata['ct_last_mail'] >= time())
	{
		message_die(GENERAL_MESSAGE, sprintf($lang['ctracker_sendmail_info'], (int) $ctracker_config->settings['massmail_time']));
	}
	$sid = isset($_POST['sid']) && is_string($_POST['sid']) ? $_POST['sid'] : '';
	$friendemail = isset($_POST['friendemail']) && is_string($_POST['friendemail']) ? trim(stripslashes($_POST['friendemail'])) : '';
	$friendname = isset($_POST['friendname']) && is_string($_POST['friendname']) ? trim(stripslashes($_POST['friendname'])) : '';
	$user_message = isset($_POST['message']) && is_string($_POST['message']) ? trim(stripslashes($_POST['message'])) : '';

	$friendemail = preg_replace('/[\r\n\x00]+/', '', $friendemail);
	$friendname = trim(preg_replace('/[<>"\x00-\x1f\x7f]+/u', ' ', $friendname));
	if ($friendname === '' && filter_var($friendemail, FILTER_VALIDATE_EMAIL))
	{
		$friendname = substr($friendemail, 0, strpos($friendemail, '@'));
	}

	if ($sid === '' || !hash_equals((string) $userdata['session_id'], $sid) || !filter_var($friendemail, FILTER_VALIDATE_EMAIL) || strlen($friendemail) > 254 || $friendname === '' || strlen($friendname) > 100 || $user_message === '' || strlen($user_message) > 10000)
	{
		$error = true;
		$error_msg = isset($lang['Email_invalid']) ? $lang['Email_invalid'] : 'Invalid email request.';
	}

	if (!$error)
	{
		$new_mailtime = isset($ctracker_config) && is_object($ctracker_config) ? time() + ((int) $ctracker_config->settings['massmail_time'] * 60) : time();
		$sql = 'UPDATE ' . USERS_TABLE . ' SET user_emailtime = ' . time() . ', ct_last_mail = ' . $new_mailtime . ' WHERE user_id = ' . (int) $userdata['user_id'];
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Unable to update email rate limit.', '', __LINE__, __FILE__, $sql);
		}
		include($phpbb_root_path . 'includes/emailer.' . $phpEx);
		$emailer = new emailer($board_config['smtp_delivery']);
		$emailer->from($userdata['user_email']);
		$emailer->replyto($userdata['user_email']);
		$emailer->use_template('tellafriend_email', $userdata['user_lang']);
		$emailer->email_address($friendname . ' <' . $friendemail . '>');
		$emailer->set_subject($topic_plain);
		$emailer->extra_headers('X-AntiAbuse: User_id - ' . (int) $userdata['user_id'] . "\nX-AntiAbuse: User IP - " . decode_ip($user_ip));
		$emailer->assign_vars(array(
			'SITENAME' => $board_config['sitename'],
			'BOARD_EMAIL' => $board_config['board_email'],
			'FROM_USERNAME' => $userdata['username'],
			'TO_USERNAME' => $friendname,
			'MESSAGE' => $user_message
		));
		$emailer->send();
		$emailer->reset();
		message_die(GENERAL_MESSAGE, $lang['Email_sent'] . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>'));
	}

	$template->set_filenames(array('reg_header' => 'error_body.tpl'));
	$template->assign_vars(array('ERROR_MESSAGE' => htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8')));
	$template->assign_var_from_handle('ERROR_BOX', 'reg_header');
}

$template->set_filenames(array('body' => 'tellafriend_body.tpl'));
$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.' . $phpEx);

?>
