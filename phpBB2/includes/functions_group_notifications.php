<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Storage has already returned and released its connection. These optional
// mails never undo membership changes or turn a delivery error into a retry
// of a successful approval. Only actual transitions supplied recipients.
function phpbb_group_notify($outcome)
{
	global $phpbb_root_path, $phpEx, $board_config, $lang;
	$templates = array('join'=>'group_request', 'add'=>'group_added', 'approve'=>'group_approved');
	$subjects = array('join'=>'Group_request', 'add'=>'Group_added', 'approve'=>'Group_approved');
	$action = $outcome['action']; $success = true;
	if (!$outcome['recipients'] || !isset($templates[$action])) { return true; }
	require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
	foreach ($outcome['recipients'] as $user)
	{
		if (!filter_var($user['user_email'], FILTER_VALIDATE_EMAIL)) { continue; }
		try
		{
			$emailer = new emailer($board_config['smtp_delivery'], true);
			$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
			$emailer->email_address($user['user_email']);
			$emailer->use_template($templates[$action], $user['user_lang']);
			$emailer->set_subject($lang[$subjects[$action]]);
			$emailer->assign_vars(array(
				'SITENAME'=>$board_config['sitename'],
				'GROUP_NAME'=>html_entity_decode($outcome['group_name'], ENT_QUOTES, 'UTF-8'),
				'GROUP_MODERATOR'=>html_entity_decode($user['username'], ENT_QUOTES, 'UTF-8'),
				'EMAIL_SIG'=>!empty($board_config['board_email_sig']) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
				'U_GROUPCP'=>phpbb_board_url('groupcp.' . $phpEx . '?' . POST_GROUPS_URL . '=' . (int) $outcome['group_id'] . ($action === 'join' ? '&validate=true' : ''))
			));
			if (!$emailer->send()) { $success = false; }
		}
		catch (PhpbbMailException $error) { $success = false; }
	}
	return $success;
}
