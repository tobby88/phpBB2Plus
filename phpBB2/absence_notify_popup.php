<?php
/***************************************************************************
 *                           profile_view_popup.php
 *                           ---------------------
 *   begin                : Monday, 15. May 2003
 *   copyright            : (C) 2003 OXPUS
 *   email                : webmaster@oxpus.de
 ***************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_PROFILE);
init_userprefs($userdata);
//
// End session management
//

if (!$userdata['session_logged_in'])
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

$submit = isset($_POST['submit']);
$page_title=$lang['User_absence_text'];
$gen_simple_header = TRUE;

include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->assign_vars(array(
	'L_VIEW_TITLE' => $page_title,
	'L_CLOSE' => $lang['Close_window'],
	'S_ACTION' => append_sid("absence_notify_popup.$phpEx"),
	'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />')
);

$template->set_filenames(array(
	'body' => 'absence_notify_body.tpl')
);

if ( $submit )
{
	if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
	}

	$sql = "UPDATE " . USERS_TABLE . "
		SET user_absence = 0
		WHERE user_id = " . (int) $userdata['user_id'];
	if ( !$db->sql_query($sql) )
	{
	   message_die(GENERAL_ERROR, "Could not update user data.", '', __LINE__, __FILE__, $sql);
	}
	
	$template->assign_block_vars('updated', array(
		'L_ABSENCE_DELETED' => $lang['Absence_deleted'])
	);
}

else
{
	$template->assign_block_vars('notify', array(
		'L_ABSENCE_NOTIFY' => $lang['Absence_notify'],
		'L_YES' => $lang['Yes'])
	);
}

$template->pparse('body');

include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
