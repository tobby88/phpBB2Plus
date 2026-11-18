<?php
/***************************************************************************
*                             admin_mass_email.php
*                              -------------------
*     begin                : Thu May 31, 2001
*     copyright            : (C) 2001 The phpBB Group
*     email                : support@phpbb.com
*
*     $Id: admin_mass_email.php,v 1.15.2.7 2003/05/03 23:24:01 acydburn Exp $
*
****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', 1);
}

if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['General']['Mass_Email'] = $filename;
	
	return;
}

//
// Load default header
//
$no_page_header = TRUE;
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require_once dirname(__DIR__) . '/includes/functions_mass_mail.php';
$error = false;
$error_msg = '';
$subject = isset($_POST['subject']) && is_string($_POST['subject']) ? phpbb_request_raw_value($_POST['subject']) : '';
$message = isset($_POST['message']) && is_string($_POST['message']) ? phpbb_request_raw_value($_POST['message']) : '';
$selected_group = -1;
@set_time_limit(1200);

if (isset($_POST['submit']))
{
	phpbb_admin_require_post_session();
	$selected_group = '';
	try { $selected_group = phpbb_mass_mail_group(isset($_POST[POST_GROUPS_URL]) ? $_POST[POST_GROUPS_URL] : null); }
	catch (PhpbbMassMailFailure $ignored) {}
	$service = new PhpbbMassMailController();
	try
	{
		$sent = $service->send($db, $_POST);
		$message = sprintf($lang['Mass_mail_sent'], $sent['recipients'], $sent['batches']);
		$message .= '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');
		message_die(GENERAL_MESSAGE, $message);
	}
	catch (PhpbbMassMailFailure $failure)
	{
		if ($failure->attempted)
		{
			$key = $failure->uncertain ? 'Mass_mail_partial_unknown' : 'Mass_mail_partial_stopped';
			$message = sprintf($lang[$key], $failure->recipients, $failure->batches);
			$message .= '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');
			message_die(GENERAL_MESSAGE, $message);
		}
		$key = $failure->getMessage();
		$error_msg = isset($lang[$key]) ? $lang[$key] : $lang['Mass_mail_unavailable'];
		if (in_array($key, array('Session_invalid','Not_Authorised'), true)) { message_die(GENERAL_MESSAGE, $error_msg); }
		$error = true;
	}
}

if ($error)
{
	$template->set_filenames(array('reg_header'=>'error_body.tpl'));
	$template->assign_vars(array('ERROR_MESSAGE'=>phpbb_admin_html($error_msg)));
	$template->assign_var_from_handle('ERROR_BOX', 'reg_header');
}

//
// Initial selection
//

$sql = "SELECT group_id, group_name 
	FROM ".GROUPS_TABLE . "  
	WHERE group_single_user <> 1";
if ( !($result = $db->sql_query($sql)) ) 
{
	message_die(GENERAL_ERROR, 'Could not obtain list of groups', '', __LINE__, __FILE__, $sql);
}

$groups = $db->sql_fetchrowset($result);
$db->sql_freeresult($result);
$available = $selected_group === -1;
foreach ($groups as $group) { if ((int)$group['group_id'] === $selected_group) { $available = true; } }
$select_list = '<select name="' . POST_GROUPS_URL . '">';
// A removed/invalid group must never silently redisplay as "all users".
if (!$available) { $select_list .= '<option value="" selected="selected">' . phpbb_admin_html($lang['Mass_mail_select_group']) . '</option>'; }
$select_list .= '<option value="-1"' . ($selected_group === -1 ? ' selected="selected"' : '') . '>' . phpbb_admin_html($lang['All_users']) . '</option>';
foreach ($groups as $group)
{
	$id = (int)$group['group_id'];
	$select_list .= '<option value="' . $id . '"' . ($selected_group === $id ? ' selected="selected"' : '') . '>' . phpbb_admin_html($group['group_name']) . '</option>';
}
$select_list .= '</select>';

//
// Generate page
//
include('./page_header_admin.'.$phpEx);

$template->set_filenames(array(
	'body' => 'admin/user_email_body.tpl')
);

$template->assign_vars(array(
	'MESSAGE' => phpbb_admin_html($message),
	'SUBJECT' => phpbb_admin_html($subject),

	'L_EMAIL_TITLE' => $lang['Email'],
	'L_EMAIL_EXPLAIN' => $lang['Mass_email_explain'],
	'L_COMPOSE' => $lang['Compose'],
	'L_RECIPIENTS' => $lang['Recipients'],
	'L_EMAIL_SUBJECT' => $lang['Subject'],
	'L_EMAIL_MSG' => $lang['Message'],
	'L_EMAIL' => $lang['Email'],

	'S_USER_ACTION' => append_sid('admin_mass_email.'.$phpEx),
	'S_FORM_TOKEN' => phpbb_admin_session_field(),
	'S_GROUP_SELECT' => $select_list)
);

$template->pparse('body');

include('./page_footer_admin.'.$phpEx);

?>
