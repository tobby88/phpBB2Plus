<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pm_write.php';
require_once dirname(__FILE__) . '/functions_login_storage.php';

function phpbb_pm_revision($value)
{
	if ($value === '') { return ''; }
	return phpbb_pm_write_nonce($value);
}

function phpbb_pm_form_revision($message_id)
{
	global $db;
	phpbb_pm_compose_attachment_request('edit', $message_id);
	$reader = null;
	try {
		$reader = new PhpbbPmAttachmentReader($db);
		// Authorization and revision belong to the same current SELECT. A
		// second query through the page connection could return its old token.
		$context = phpbb_pm_compose_attachment_context($reader, 'edit', $message_id, true);
		return phpbb_pm_revision((string)$context['revision']);
	} catch (PhpbbAclException $error) { throw $error; }
	catch (Exception $error) { phpbb_acl_error('PM_cleanup_failed'); }
	catch (Error $error) { phpbb_acl_error('PM_cleanup_failed'); }
	finally { if ($reader !== null) { $reader->release(); } }
}

function phpbb_pm_pending_writes_html()
{
	global $db, $lang, $userdata, $phpEx;
	$actor = isset($userdata['user_id']) ? attach_delete_id_array(array($userdata['user_id'])) : false;
	if (!$actor || empty($userdata['session_logged_in']) || !isset($userdata['session_id']) || !is_string($userdata['session_id'])
		|| $userdata['session_id'] === '' || strlen($userdata['session_id']) > 32) { phpbb_acl_error('Not_Authorised'); }
	$sid = $userdata['session_id']; $reader = null;
	try {
		$reader = new PhpbbPmAttachmentReader($db);
		// The outer account row distinguishes no pending writes from revoked
		// access. Disabling PM sending hides retries without blocking the inbox.
		$rows = $reader->rows('SELECT p.privmsgs_id,p.privmsgs_write_token FROM ' . USERS_TABLE . ' u LEFT JOIN ' . PRIVMSGS_TABLE
			. ' p ON p.privmsgs_from_userid=u.user_id AND p.privmsgs_write_payload IS NOT NULL AND u.user_allow_pm<>0'
			. ' WHERE u.user_id=' . $actor[0] . ' AND u.user_active<>0 AND u.user_blocktime<' . time()
			. ' AND ' . phpbb_pm_current_session_guard($reader->connection, $actor[0], $sid) . ' ORDER BY p.privmsgs_id LIMIT 100');
		if (!$rows) { phpbb_acl_error('Not_Authorised'); }
	} catch (PhpbbAclException $error) { throw $error; }
	catch (Exception $error) { phpbb_acl_error('PM_cleanup_failed'); }
	catch (Error $error) { phpbb_acl_error('PM_cleanup_failed'); }
	finally { if ($reader !== null) { $reader->release(); } }
	$html = '';
	foreach ($rows as $row)
	{
		if (!is_string($row['privmsgs_write_token']) || !preg_match('/^[a-f0-9]{32}$/D', $row['privmsgs_write_token'])) { continue; }
		$html .= '<form method="post" action="' . phpbb_profile_text(append_sid('privmsg.' . $phpEx . '?folder=outbox')) . '"><p class="genmed">#' . (int)$row['privmsgs_id']
			. ' <input type="hidden" name="sid" value="' . phpbb_profile_text($sid) . '" />'
			. '<input type="hidden" name="pm_write_nonce" value="' . $row['privmsgs_write_token'] . '" />'
			. '<button class="mainoption" type="submit" name="pm_retry" value="1">' . phpbb_profile_text($lang['PM_resume']) . '</button></p></form>';
	}
	if ($html === '') { return ''; }
	return '<table class="forumline" width="100%"><tr><th>' . phpbb_profile_text($lang['PM_resume_title']) . '</th></tr><tr><td class="row1">'
		. '<p class="genmed">' . phpbb_profile_text($lang['PM_resume_explain']) . '</p>' . $html . '</td></tr></table><br />';
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

// The receipt is already claimed at most once. Refresh the complete, still
// unread message and recipient independently; release all reads before mail.
function phpbb_pm_notification_ready($database, $nonce, $claimed, $sender_id)
{
	$nonce = phpbb_pm_write_nonce($nonce);
	$recipient = is_array($claimed) && isset($claimed['user_id']) ? attach_delete_id_array(array($claimed['user_id'])) : false;
	$sender = attach_delete_id_array(array($sender_id));
	if (!$recipient || !$sender) { phpbb_acl_error('PM_journal_changed'); }
	$owner = new PhpbbLoginDatabase($database, array(PRIVMSGS_TABLE, PRIVMSGS_TEXT_TABLE, PM_WRITE_RECEIPTS_TABLE));
	try {
		$token = $owner->sql_escape($nonce);
		$rows = $owner->rows('SELECT u.user_id,u.username,u.user_email,u.user_lang FROM ' . PM_WRITE_RECEIPTS_TABLE . ' r'
			. ' JOIN ' . PRIVMSGS_TABLE . ' p ON p.privmsgs_id=r.message_id AND p.privmsgs_from_userid=r.user_id'
			. ' AND HEX(p.privmsgs_write_token)=HEX(r.request_token) AND HEX(p.privmsgs_write_hash)=HEX(r.request_hash)'
			. ' JOIN ' . PRIVMSGS_TEXT_TABLE . ' t ON t.privmsgs_text_id=p.privmsgs_id'
			. ' JOIN ' . USERS_TABLE . ' u ON u.user_id=p.privmsgs_to_userid'
			. " WHERE HEX(r.request_token)=HEX('" . $token . "') AND r.user_id=" . $sender[0] . ' AND r.notify_state=2'
			. ' AND u.user_id=' . $recipient[0] . ' AND u.user_active=1 AND u.user_notify_pm=1'
			. ' AND p.privmsgs_write_payload IS NULL AND p.privmsgs_type IN (' . PRIVMSGS_NEW_MAIL . ',' . PRIVMSGS_UNREAD_MAIL . ') LOCK IN SHARE MODE');
		return count($rows) === 1 ? $rows[0] : false;
	} finally { $owner->release(); }
}

// Saving the PM succeeded before this optional side effect. Never replay its
// claim or turn a notification failure into a failed/duplicate message send.
function phpbb_pm_optional_notification($nonce)
{
	global $db, $userdata, $board_config, $lang, $phpbb_root_path, $phpEx;
	try {
		$sender = $userdata['user_id'];
		$user = phpbb_pm_claim_notification($nonce);
		if ($user === false) { return true; }
		$user = phpbb_pm_notification_ready($db, $nonce, $user, $sender);
		if ($user === false) { return true; }
		if (!filter_var($user['user_email'], FILTER_VALIDATE_EMAIL)) { return false; }
		$script_name = trim($board_config['script_path'], '/') . '/privmsg.' . $phpEx;
		$protocol = $board_config['cookie_secure'] ? 'https://' : 'http://';
		$port = (int)$board_config['server_port'];
		$authority = trim($board_config['server_name']) . (($port > 0 && $port !== ($board_config['cookie_secure'] ? 443 : 80)) ? ':' . $port : '');
		require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
		$emailer = new emailer($board_config['smtp_delivery'], true);
		$emailer->from($board_config['board_email']);
		$emailer->replyto($board_config['board_email']);
		$emailer->use_template('privmsg_notify', $user['user_lang']);
		$emailer->email_address($user['user_email']);
		$emailer->set_subject($lang['Notification_subject']);
		$emailer->assign_vars(array('USERNAME'=>html_entity_decode($user['username'], ENT_QUOTES, 'UTF-8'), 'SITENAME'=>$board_config['sitename'],
			'EMAIL_SIG'=>!empty($board_config['board_email_sig']) ? str_replace('<br />', "\n", '-- ' . "\n" . $board_config['board_email_sig']) : '',
			'U_INBOX'=>$protocol . $authority . '/' . ltrim($script_name, '/') . '?folder=inbox'));
		$sent = $emailer->send(); $emailer->reset(); return (bool)$sent;
	} catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
}

function phpbb_pm_complete_response($nonce)
{
	global $board_config, $lang, $template, $phpbb_root_path, $phpEx;
	$notified = phpbb_pm_optional_notification($nonce);
	$template->assign_vars(array('META'=>'<meta http-equiv="refresh" content="3;url=' . phpbb_profile_text(append_sid('privmsg.' . $phpEx . '?folder=inbox')) . '" />'));
	$message = $lang['Message_sent'] . ($notified ? '' : '<br /><br />' . $lang['PM_notification_failed']) . '<br /><br />' . sprintf($lang['Click_return_inbox'], '<a href="' . append_sid('privmsg.' . $phpEx . '?folder=inbox') . '">', '</a> ')
		. '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid('index.' . $phpEx) . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
