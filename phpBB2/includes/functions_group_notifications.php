<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_group_storage.php';
require_once dirname(__FILE__) . '/functions_login_storage.php';

// Resolve the committed transition against current membership and identity,
// independently of any caller snapshot. No database owner survives mail I/O.
function phpbb_group_notification_ready($database, $outcome, $member_id)
{
	$group_id = phpbb_group_id($outcome['group_id']);
	$member_id = phpbb_group_id($member_id);
	$owner = new PhpbbLoginDatabase($database, array(GROUPS_TABLE, USER_GROUP_TABLE));
	try
	{
		$groups = $owner->rows('SELECT group_id,group_name,group_moderator FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $group_id . ' AND group_single_user=0 LOCK IN SHARE MODE');
		if (count($groups) !== 1) { return false; }
		$group = $groups[0]; $pending = $outcome['action'] === 'join' ? 1 : 0;
		$members = $owner->rows('SELECT ug.user_id FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . USERS_TABLE . ' u ON u.user_id=ug.user_id WHERE ug.group_id=' . $group_id
			. ' AND ug.user_id=' . $member_id . ' AND ug.user_pending=' . $pending . ' AND u.user_active=1 LOCK IN SHARE MODE');
		if (count($members) !== 1) { return false; }
		$recipient_id = $pending ? (int) $group['group_moderator'] : $member_id;
		$users = $owner->rows('SELECT user_id,username,user_email,user_lang FROM ' . USERS_TABLE . ' WHERE user_id=' . $recipient_id . ' AND user_id>0 AND user_active=1 LOCK IN SHARE MODE');
		if (count($users) !== 1) { return false; }
		return array('recipient'=>$users[0], 'group'=>$group);
	}
	finally { $owner->release(); }
}

// Storage has already returned and released its connection. These optional
// mails never undo membership changes or turn a delivery error into a retry
// of a successful approval. Only actual transitions supply changed member IDs.
function phpbb_group_notify($outcome)
{
	global $phpbb_root_path, $phpEx, $board_config, $lang, $db;
	$templates = array('join'=>'group_request', 'add'=>'group_added', 'approve'=>'group_approved');
	$subjects = array('join'=>'Group_request', 'add'=>'Group_added', 'approve'=>'Group_approved');
	$action = $outcome['action']; $success = true;
	if (!$outcome['changed'] || !isset($templates[$action])) { return true; }
	require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
	foreach (array_unique($outcome['changed']) as $member_id)
	{
		try
		{
			$ready = phpbb_group_notification_ready($db, $outcome, $member_id);
			if ($ready === false) { continue; }
			$user = $ready['recipient'];
			if (!filter_var($user['user_email'], FILTER_VALIDATE_EMAIL)) { $success = false; continue; }
			$emailer = new emailer($board_config['smtp_delivery'], true);
			$emailer->from($board_config['board_email']); $emailer->replyto($board_config['board_email']);
			$emailer->email_address($user['user_email']);
			$language = $user['user_lang'];
			if (!is_string($language) || !preg_match('/^[a-z0-9_-]+$/iD', $language)) { $language = ''; }
			$emailer->use_template($templates[$action], $language);
			$emailer->set_subject($lang[$subjects[$action]]);
			$emailer->assign_vars(array(
				'SITENAME'=>$board_config['sitename'],
				'GROUP_NAME'=>html_entity_decode($ready['group']['group_name'], ENT_QUOTES, 'UTF-8'),
				'GROUP_MODERATOR'=>html_entity_decode($user['username'], ENT_QUOTES, 'UTF-8'),
				'EMAIL_SIG'=>!empty($board_config['board_email_sig']) ? str_replace('<br />', "\n", "-- \n" . $board_config['board_email_sig']) : '',
				'U_GROUPCP'=>phpbb_board_url('groupcp.' . $phpEx . '?' . POST_GROUPS_URL . '=' . (int) $outcome['group_id'] . ($action === 'join' ? '&validate=true' : ''))
			));
			if (!$emailer->send()) { $success = false; }
		}
		catch (Exception $error) { $success = false; }
		catch (Error $error) { $success = false; }
	}
	return $success;
}
