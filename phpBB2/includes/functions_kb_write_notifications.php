<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Called only after confirmed article commit and release of its SQL owner.
// A failed transport cannot undo or misreport the saved article.
function kb_notify_after_write($outcome)
{
	global $phpbb_root_path, $phpEx, $board_config, $lang;
	if (empty($outcome['notify_email'])) { return true; }
	if (!isset($board_config['board_email']) || !filter_var($board_config['board_email'], FILTER_VALIDATE_EMAIL)) { return false; }
	try {
		require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
		$emailer = new emailer($board_config['smtp_delivery'], true);
		$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
		$emailer->email_address($board_config['board_email']); $emailer->set_subject($lang['New_article']);
		$emailer->use_template('admin_send_email');
		$emailer->assign_vars(array('SITENAME'=>$board_config['sitename'],'BOARD_EMAIL'=>$board_config['board_email'],
			'MESSAGE'=>$lang['Email_body'] . "\n\n" . $outcome['article_title'] . "\n" . phpbb_board_url('admin/admin_kb_art.' . $phpEx)));
		return (bool)$emailer->send();
	} catch (Exception $error) { return false; }
	catch (Error $error) { return false; }
}
