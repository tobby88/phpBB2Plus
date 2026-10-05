<?php
/***************************************************************************
 *                            admin_user_ban.php
 *                            -------------------
 *   begin                : Tuesday, Jul 31, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: admin_user_ban.php,v 1.21.2.5 2004/03/25 15:57:20 acydburn Exp $
 *
 *
 ***************************************************************************/

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

if ( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Users']['Ban_Management'] = $filename;

	return;
}

//
// Load default header
//
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require_once($phpbb_root_path . 'includes/functions_ban.php');

function admin_ban_post_string($name)
{
	return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? trim(stripslashes((string) $_POST[$name])) : '';
}

function admin_ban_add_ip(&$ip_list, $ip)
{
	$rule = phpbb_ip_ban_parse($ip);
	if (!$rule) { message_die(GENERAL_MESSAGE, $GLOBALS['lang']['Ban_invalid_IP']); }
	if (count($ip_list) >= 4096 && !isset($ip_list[$rule['ip'] . ':' . $rule['mask']])) { message_die(GENERAL_MESSAGE, $GLOBALS['lang']['Ban_invalid_IP']); }
	$ip_list[$rule['ip'] . ':' . $rule['mask']] = $rule;
}

//
// Start program
//
if ( isset($_POST['submit']) )
{
	phpbb_admin_require_post_session();
	require_once($phpbb_root_path . 'includes/functions_admin_ban_storage.php');
	$ban_original_db = $db; $ban_scope = null; $ban_error = null;
	try
	{
		$ban_scope = new PhpbbAdminBanScope($db, $_POST); $db = $ban_scope;

	$user_list = array();
	$username = admin_ban_post_string('username');
	if ( $username !== '' )
	{
		$this_userdata = get_userdata($username, true);
		if( !$this_userdata )
		{
			message_die(GENERAL_MESSAGE, $lang['No_user_id_specified'] );
		}

		$user_id = intval($this_userdata['user_id']);
		$ctracker_config->first_admin_protection($user_id);
		$user_list[] = $user_id;
	}

	$ip_list = array();
	$ban_ip_input = admin_ban_post_string('ban_ip');
	if ( $ban_ip_input !== '' )
	{
		$ip_list_temp = array_slice(explode(',', $ban_ip_input), 0, 100);

		for($i = 0; $i < count($ip_list_temp); $i++)
		{
			$ip_entry = trim($ip_list_temp[$i]);
			if ( preg_match('/^([0-9.]+)[ ]*\-[ ]*([0-9.]+)$/D', $ip_entry, $ip_range_explode) )
			{
				if (!filter_var($ip_range_explode[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($ip_range_explode[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
				{
					message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']);
				}
				$range = phpbb_ip_ban_range($ip_range_explode[1], $ip_range_explode[2]);
				if ($range === false)
				{
					message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']);
				}
				foreach ($range as $rule)
				{
					if (count($ip_list) >= 4096 && !isset($ip_list[$rule['ip'] . ':' . $rule['mask']])) { message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']); }
					$ip_list[$rule['ip'] . ':' . $rule['mask']] = $rule;
				}
			}
			else if ( preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $ip_entry) && !preg_match('/^[0-9.*]+$/D', $ip_entry) )
			{
				$ip = gethostbynamel($ip_entry);
				if (!$ip) { message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']); }

				for($j = 0; $j < (is_array($ip) ? count($ip) : 0); $j++)
				{
					if ( filter_var($ip[$j], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) )
					{
						admin_ban_add_ip($ip_list, $ip[$j]);
					}
				}
			}
			else if ( preg_match('/^(\*|[0-9]{1,3})\.(\*|[0-9]{1,3})\.(\*|[0-9]{1,3})\.(\*|[0-9]{1,3})$/D', $ip_entry, $ip_parts) )
			{
				for ($part = 1; $part <= 4; $part++)
				{
					if ($ip_parts[$part] !== '*' && intval($ip_parts[$part]) > 255)
					{
						message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']);
					}
				}
				admin_ban_add_ip($ip_list, $ip_entry);
			}
			else if ($ip_entry !== '') { message_die(GENERAL_MESSAGE, $lang['Ban_invalid_IP']); }
		}
		$ip_list = array_values($ip_list);
	}

	$email_list = array();
	$ban_email_input = admin_ban_post_string('ban_email');
	if ( $ban_email_input !== '' )
	{
		// CrackerTracker v5.x
		$protected_email = '';
		if ( $ban_email_input !== '' )
		{
			$temp_userdata = get_userdata($ctracker_config->first_admin_user_id(), false);
			if ( !$temp_userdata )
			{
				message_die(GENERAL_MESSAGE, $lang['No_user_id_specified']);
			}
			$protected_email = (string) $temp_userdata['user_email'];
		}

		$email_list_temp = array_slice(explode(',', $ban_email_input), 0, 100);

		for($i = 0; $i < count($email_list_temp); $i++)
		{
			//
			// This ereg match is based on one by php@unreelpro.com
			// contained in the annotated php manual at php.com (ereg
			// section)
			//
			if (preg_match('/^(([a-z0-9&\'\.\-_\+])|(\*))+@(([a-z0-9\-])|(\*))+\.([a-z0-9\-]+\.)*?[a-z]+$/is', trim($email_list_temp[$i])))
			{
				$email_value = trim($email_list_temp[$i]);
				if (strlen($email_value) <= 255)
				{
					require_once($phpbb_root_path . 'includes/functions_ban.php');
					if (phpbb_email_ban_matches($email_value, $protected_email))
					{
						message_die(GENERAL_MESSAGE, $lang['ctracker_gmb_1stadmin']);
					}
					$email_list[] = $email_value;
				}
			}
		}
	}

		$ban_scope->save($user_list, $ip_list, $email_list, $_POST);
		$ban_scope->commit();
	}
	catch (PhpbbAclException $e) { $ban_error = $e->getMessage(); }
	catch (PhpbbLoginException $e) { $ban_error = $lang['Ban_storage_failed']; }
	finally { $db = $ban_original_db; if ($ban_scope) { $ban_scope->release(); } }
	if ($ban_error !== null) { message_die(GENERAL_MESSAGE, $ban_error); }

	$message = $lang['Ban_update_sucessful'] . '<br /><br />' . sprintf($lang['Click_return_banadmin'], '<a href="' . append_sid("admin_user_ban.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');

	message_die(GENERAL_MESSAGE, $message);

}
else
{
	$template->set_filenames(array(
		'body' => 'admin/user_ban_body.tpl')
	);

	$template->assign_vars(array(
		'L_BAN_TITLE' => $lang['Ban_control'],
		'L_BAN_EXPLAIN' => $lang['Ban_explain'],
		'L_BAN_EXPLAIN_WARN' => $lang['Ban_explain_warn'],
		'L_IP_OR_HOSTNAME' => $lang['IP_hostname'],
		'L_EMAIL_ADDRESS' => $lang['Email_address'],
		'L_SUBMIT' => $lang['Submit'],
		'L_RESET' => $lang['Reset'],

		'S_BANLIST_ACTION' => append_sid("admin_user_ban.$phpEx"),
		'S_HIDDEN_FIELDS' => phpbb_admin_session_field())
	);

	$template->assign_vars(array(
		'L_BAN_USER' => $lang['Ban_username'],
		'L_BAN_USER_EXPLAIN' => $lang['Ban_username_explain'],
		'L_BAN_IP' => $lang['Ban_IP'],
		'L_BAN_IP_EXPLAIN' => $lang['Ban_IP_explain'],
		'L_BAN_EMAIL' => $lang['Ban_email'],
		'L_BAN_EMAIL_EXPLAIN' => $lang['Ban_email_explain'])
	);

	$userban_count = 0;
	$ipban_count = 0;
	$emailban_count = 0;

	$sql = "SELECT b.ban_id, u.user_id, u.username
		FROM " . BANLIST_TABLE . " b, " . USERS_TABLE . " u
		WHERE u.user_id = b.ban_userid
			AND b.ban_userid <> 0
			AND u.user_id <> " . ANONYMOUS . "
		ORDER BY u.user_id ASC";
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not select current user_id ban list', '', __LINE__, __FILE__, $sql);
	}

	$user_list = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);

	$select_userlist = '';
	for($i = 0; $i < count($user_list); $i++)
	{
		$select_userlist .= '<option value="' . intval($user_list[$i]['ban_id']) . '">' . phpbb_admin_html($user_list[$i]['username']) . '</option>';
		$userban_count++;
	}

	if( $select_userlist == '' )
	{
		$select_userlist = '<option value="-1">' . $lang['No_banned_users'] . '</option>';
	}

	$select_userlist = '<select name="unban_user[]" multiple="multiple" size="5">' . $select_userlist . '</select>';

	$sql = "SELECT ban_id, ban_ip, ban_email, ban_ip_mask
		FROM " . BANLIST_TABLE;
	if ( !($result = $db->sql_query($sql)) )
	{
		message_die(GENERAL_ERROR, 'Could not select current ip ban list', '', __LINE__, __FILE__, $sql);
	}

	$banlist = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);

	$select_iplist = '';
	$select_emaillist = '';

	for($i = 0; $i < count($banlist); $i++)
	{
		$ban_id = intval($banlist[$i]['ban_id']);
		if (!phpbb_ip_ban_valid($banlist[$i]['ban_ip'], $banlist[$i]['ban_ip_mask'])) { message_die(GENERAL_MESSAGE, $lang['Ban_ip_storage_upgrade']); }

		if ( !empty($banlist[$i]['ban_ip']) )
		{
			try { $ban_ip = phpbb_ip_ban_format($banlist[$i]['ban_ip'], $banlist[$i]['ban_ip_mask']); }
			catch (UnexpectedValueException $e) { message_die(GENERAL_MESSAGE, $lang['Ban_ip_storage_upgrade']); }
			$select_iplist .= '<option value="' . $ban_id . '">' . phpbb_admin_html($ban_ip) . '</option>';
			$ipban_count++;
		}
		if ( !empty($banlist[$i]['ban_email']) )
		{
			$ban_email = $banlist[$i]['ban_email'];
			$select_emaillist .= '<option value="' . $ban_id . '">' . phpbb_admin_html($ban_email) . '</option>';
			$emailban_count++;
		}
	}

	if ( $select_iplist == '' )
	{
		$select_iplist = '<option value="-1">' . $lang['No_banned_ip'] . '</option>';
	}

	if ( $select_emaillist == '' )  
	{
		$select_emaillist = '<option value="-1">' . $lang['No_banned_email'] . '</option>';
	}

	$select_iplist = '<select name="unban_ip[]" multiple="multiple" size="5">' . $select_iplist . '</select>';
	$select_emaillist = '<select name="unban_email[]" multiple="multiple" size="5">' . $select_emaillist . '</select>';

	$template->assign_vars(array(
		'L_UNBAN_USER' => $lang['Unban_username'],
		'L_UNBAN_USER_EXPLAIN' => $lang['Unban_username_explain'],
		'L_UNBAN_IP' => $lang['Unban_IP'],
		'L_UNBAN_IP_EXPLAIN' => $lang['Unban_IP_explain'],
		'L_UNBAN_EMAIL' => $lang['Unban_email'],
		'L_UNBAN_EMAIL_EXPLAIN' => $lang['Unban_email_explain'], 
		'L_USERNAME' => $lang['Username'], 
		'L_LOOK_UP' => $lang['Look_up_User'],
		'L_FIND_USERNAME' => $lang['Find_username'],

		'U_SEARCH_USER' => append_sid("./../search.$phpEx?mode=searchuser"), 
		'S_UNBAN_USERLIST_SELECT' => $select_userlist,
		'S_UNBAN_IPLIST_SELECT' => $select_iplist,
		'S_UNBAN_EMAILLIST_SELECT' => $select_emaillist,
		'S_BAN_ACTION' => append_sid("admin_user_ban.$phpEx"))
	);
}

$template->pparse('body');

include('./page_footer_admin.'.$phpEx);

?>
