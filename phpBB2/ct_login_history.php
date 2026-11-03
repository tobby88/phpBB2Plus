<?php
/**  
* This file is for the Login History each User can see for his Account.
* 
* @author Christian Knerr (cback)
* @package ctracker
* @version 5.0.0
* @since 17.08.2006 - 02:42:16
* @copyright (c) 2006 www.cback.de
* 
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*/

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);


// Start session management
$userdata = session_pagestart($user_ip, PAGE_LOGIN);
init_userprefs($userdata);
// End session management


// Ensure that a user is logged in and the feature is available
if ( !$userdata['session_logged_in'] )
{
	message_die(GENERAL_MESSAGE, $lang['ctracker_lhistory_err']);
}

// Save before rendering so the header also sees the acknowledged preference.
if (!empty($HTTP_POST_VARS['submit']))
{
	require_once($phpbb_root_path . 'ctracker/classes/class_user_actions.' . $phpEx);
	try
	{
		$changes = ctracker_user_apply($db, 'preference');
		foreach ($changes as $key => $value) { $userdata[$key] = $value; }
	}
	catch (RuntimeException $error)
	{
		message_die(GENERAL_ERROR, isset($lang[$error->getMessage()]) ? $lang[$error->getMessage()] : $lang['ctracker_error_database_op']);
	}
}

// Include the page_header
$page_title = $lang['ctracker_lhistory_nav'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);


// Define the Template for this file
$template->set_filenames(array(
	'body' => 'ctracker/ctracker_login_history.tpl')
);


// Output Login History
if ( $ctracker_config->settings['login_history'] )
{
	$sql = 'SELECT * FROM ' . CTRACKER_LOGINHISTORY . ' WHERE ct_user_id=' . $userdata['user_id'] . ' ORDER BY ct_login_time DESC, ct_login_id DESC';
		
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, $lang['ctracker_error_database_op'], '', __LINE__, __FILE__, $sql);
	}

	$count = 0;
		
	while ( $row = $db->sql_fetchrow($result) )
	{
		$count++;
	
		$template->assign_block_vars('login_output', array(
				'ROW_CLASS'		=> ($count % 2 == 0)? 'row1' : 'row2',
				'VALUE_1'		=> $count,
				'VALUE_2'		=> phpbb_profile_text(date($userdata['user_dateformat'], $row['ct_login_time'])),
				'VALUE_3'		=> phpbb_profile_text($row['ct_login_ip']))
		);
	}
}


// Output settings for Login Checker
if ( $ctracker_config->settings['login_ip_check'] == 1 )
{
	
	$sel1 = '';
	$sel2 = '';
	
	($userdata['ct_enable_ip_warn'] == 1)? $sel1 = ' checked="checked"' : $sel2 = ' checked';
	
	$template->assign_block_vars('log_set', array(
			'S_FORM_ACTION'	=> append_sid('ct_login_history.' . $phpEx),
			'S_FORM_TOKEN'	=> '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
			'L_HEADER_TEXT'	=> $lang['ctracker_ipwarn_prof'],
			'L_DESC'		=> $lang['ctracker_ipwarn_pdes'],
			'L_ON'			=> $lang['ctracker_settings_on'],
			'L_OFF'			=> $lang['ctracker_settings_off'],
			'L_SEND'		=> $lang['ctracker_ipwarn_send'],
			'S_SELECT_ON'	=> $sel1,
			'S_SELECT_OFF'	=> $sel2,
			'IMG_ICON'		=> $images['ctracker_log_manager'])
	);
}


// Send some vars to the template
$template->assign_vars(array(
	'L_HEADER_TEXT'   => $lang['ctracker_lhistory_h'],
	'L_DESCRIPTION'   => ($ctracker_config->settings['login_history'] == 1)? sprintf($lang['ctracker_lhistory_i'], $ctracker_config->settings['login_history_count']) : $lang['ctracker_lhistory_off'],
	'L_TABLEHEAD_1'	  => $lang['ctracker_lhistory_h1'],
	'L_TABLEHEAD_2'	  => $lang['ctracker_lhistory_h2'])
);


// Generate the page
$template->pparse('body');


// Include the page_tail.php file
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);


?>
