<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_write.php';

function phpbb_pm_revision($value)
{
	if ($value === '') { return ''; }
	return phpbb_pm_write_nonce($value);
}

function phpbb_pm_form_revision($message_id)
{
	global $db, $userdata;
	$id = phpbb_pm_compose_attachment_source($db, 'edit', $message_id);
	$reader = new PhpbbPmReadDatabase($db);
	$rows = phpbb_acl_rows($reader, 'SELECT privmsgs_write_token FROM ' . PRIVMSGS_TABLE . ' WHERE privmsgs_id = ' . $id
		. ' AND (' . phpbb_pm_mailbox_condition($userdata['user_id'], 'outbox') . ') AND privmsgs_write_payload IS NULL');
	if (!$rows) { phpbb_acl_error('No_such_post'); }
	return phpbb_pm_revision((string)$rows[0]['privmsgs_write_token']);
}

function phpbb_pm_pending_writes_html()
{
	global $db, $lang, $userdata, $phpEx;
	$reader = new PhpbbPmReadDatabase($db);
	$rows = phpbb_acl_rows($reader, 'SELECT privmsgs_id,privmsgs_write_token FROM ' . PRIVMSGS_TABLE
		. ' WHERE privmsgs_from_userid = ' . $reader->actor . ' AND privmsgs_write_payload IS NOT NULL ORDER BY privmsgs_id LIMIT 100');
	if (!$rows) { return ''; }
	$html = '<table class="forumline" width="100%"><tr><th>' . phpbb_profile_text($lang['PM_resume_title']) . '</th></tr><tr><td class="row1">'
		. '<p class="genmed">' . phpbb_profile_text($lang['PM_resume_explain']) . '</p>';
	foreach ($rows as $row)
	{
		if (!is_string($row['privmsgs_write_token']) || !preg_match('/^[a-f0-9]{32}$/D', $row['privmsgs_write_token'])) { continue; }
		$html .= '<form method="post" action="' . phpbb_profile_text(append_sid('privmsg.' . $phpEx . '?folder=outbox')) . '"><p class="genmed">#' . (int)$row['privmsgs_id']
			. ' <input type="hidden" name="sid" value="' . phpbb_profile_text($userdata['session_id']) . '" />'
			. '<input type="hidden" name="pm_write_nonce" value="' . $row['privmsgs_write_token'] . '" />'
			. '<button class="mainoption" type="submit" name="pm_retry" value="1">' . phpbb_profile_text($lang['PM_resume']) . '</button></p></form>';
	}
	return $html . '</td></tr></table><br />';
}

function phpbb_pm_write_error($error)
{
	global $lang, $phpEx;
	$message = phpbb_profile_text($error->getMessage());
	try { $message .= '<br />' . phpbb_pm_pending_writes_html(); }
	catch (Exception $ignored) {}
	$message .= '<br /><a href="' . phpbb_profile_text(append_sid('privmsg.' . $phpEx . '?folder=outbox')) . '">' . phpbb_profile_text($lang['Outbox']) . '</a>';
	message_die(GENERAL_ERROR, $message);
}

function phpbb_pm_complete_response($nonce)
{
	global $board_config, $lang, $template, $phpbb_root_path, $phpEx;
	$user = phpbb_pm_claim_notification($nonce);
	if ($user !== false)
	{
		$script_name = trim($board_config['script_path'], '/') . '/privmsg.' . $phpEx;
		$protocol = $board_config['cookie_secure'] ? 'https://' : 'http://';
		$port = (int)$board_config['server_port'];
		$authority = trim($board_config['server_name']) . (($port > 0 && $port !== ($board_config['cookie_secure'] ? 443 : 80)) ? ':' . $port : '');
		require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
		$emailer = new emailer($board_config['smtp_delivery']);
		$emailer->from($board_config['board_email']);
		$emailer->replyto($board_config['board_email']);
		$emailer->use_template('privmsg_notify', $user['user_lang']);
		$emailer->email_address($user['user_email']);
		$emailer->set_subject($lang['Notification_subject']);
		$emailer->assign_vars(array('USERNAME'=>$user['username'], 'SITENAME'=>$board_config['sitename'],
			'EMAIL_SIG'=>!empty($board_config['board_email_sig']) ? str_replace('<br />', "\n", '-- ' . "\n" . $board_config['board_email_sig']) : '',
			'U_INBOX'=>$protocol . $authority . '/' . ltrim($script_name, '/') . '?folder=inbox'));
		$emailer->send(); $emailer->reset();
	}
	$template->assign_vars(array('META'=>'<meta http-equiv="refresh" content="3;url=' . phpbb_profile_text(append_sid('privmsg.' . $phpEx . '?folder=inbox')) . '" />'));
	$message = $lang['Message_sent'] . '<br /><br />' . sprintf($lang['Click_return_inbox'], '<a href="' . append_sid('privmsg.' . $phpEx . '?folder=inbox') . '">', '</a> ')
		. '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid('index.' . $phpEx) . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
