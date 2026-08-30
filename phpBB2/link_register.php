<?php
/***************************************************************************
*                            link_register.php
*                            -------------------
*  MOD add-on page. Contains GPL code copyright of phpBB group.
*  Author: OOHOO < webdev@phpbb-tw.net >
*  Author: Stefan2k1 and ddonker from www.portedmods.com
*  Author: CRLin from http://mail.dhjh.tcc.edu.tw/~gzqbyr/
*  Demo: http://phpbb-tw.net/
*  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
*  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
*  Version: 1.2.0 - 2003/06/15 - Enhanced and Re-packed for phpBB 2.0.4
*  Version: 1.2.1 - 2003/10/15 - Enhanced by CRLin
*  Version: 1.2.2 - 2004-05-10 - Enhanced by CRLin
***************************************************************************/
/***************************************************************************
*
*   This program is free software; you can redistribute it and/or modify
*   it under the terms of the GNU General Public License as published by
*   the Free Software Foundation; either version 2 of the License, or
*   (at your option) any later version.
*
***************************************************************************/ 

define('IN_PHPBB', true);

$phpbb_root_path = "./";
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);
include($phpbb_root_path . 'includes/bbcode.'.$phpEx);
include($phpbb_root_path . 'includes/functions_post.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_LINKS);
init_userprefs($userdata);
$link_language = (isset($board_config['default_lang']) && preg_match('/^[a-z0-9_-]+$/i', (string) $board_config['default_lang'])) ? $board_config['default_lang'] : 'english';
$link_language_file = $phpbb_root_path . 'language/lang_' . $link_language . '/lang_main_link.' . $phpEx;
if (!is_file($link_language_file))
{
	$link_language_file = $phpbb_root_path . 'language/lang_english/lang_main_link.' . $phpEx;
}
require($link_language_file);
//
// End session management
//


// Users Authentication, members only area
if( !$userdata['session_logged_in'] )
{
	header("Location: " . append_sid("login.$phpEx?redirect=links.php", true));
	exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST' ||
	!isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid']))
{
	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
}

function link_register_http_url($value)
{
	$value = trim(stripslashes((string) $value));
	$parts = @parse_url($value);
	if (!$parts || empty($parts['scheme']) || empty($parts['host']) ||
		!in_array(strtolower($parts['scheme']), array('http', 'https'), true) || isset($parts['user']) || isset($parts['pass']) ||
		preg_match('/[\x00-\x20\x7f]/', $value))
	{
		return '';
	}
	return $value;
}

function link_register_post($name)
{
	return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? (string) $_POST[$name] : '';
}

$link_title = substr(trim(stripslashes(link_register_post('link_title'))), 0, 100);
$link_desc = substr(trim(stripslashes(link_register_post('link_desc'))), 0, 255);
$link_category_input = link_register_post('link_category');
$link_category = is_numeric($link_category_input) ? intval($link_category_input) : 0;
$link_url = link_register_http_url(link_register_post('link_url'));
$link_logo_src = link_register_http_url(link_register_post('link_logo_src'));
$link_url = (strlen($link_url) <= 100) ? $link_url : '';
$link_logo_src = (strlen($link_logo_src) <= 120) ? $link_logo_src : '';
$link_title_sql = $db->sql_escape($link_title);
$link_desc_sql = $db->sql_escape($link_desc);
$link_url_sql = $db->sql_escape($link_url);
$link_logo_src_sql = $db->sql_escape($link_logo_src);
$link_joined = time();
$user_id = intval($userdata['user_id']);
$user_ip_sql = $db->sql_escape($user_ip);
$max_inbox_privmsgs = max(1, isset($board_config['max_inbox_privmsgs']) ? intval($board_config['max_inbox_privmsgs']) : 50);

//
// Get Link Config
//
$sql = "SELECT *
		FROM ". LINK_CONFIG_TABLE;
if(!$result = $db->sql_query($sql))
{
	message_die(GENERAL_ERROR, "Could not query Link config information", "", __LINE__, __FILE__, $sql);
}
$link_config = array();
while( $row = $db->sql_fetchrow($result) )
{
	$link_config_name = $row['config_name'];
	$link_config_value = $row['config_value'];
	$link_config[$link_config_name] = $link_config_value;
}
$db->sql_freeresult($result);
$link_config = array_merge(array(
	'lock_submit_site' => 0,
	'allow_no_logo' => 1,
	'email_notify' => 0,
	'pm_notify' => 0,
), $link_config);

if ($link_category > 0)
{
	$sql = 'SELECT cat_id FROM ' . LINK_CATEGORIES_TABLE . ' WHERE cat_id = ' . $link_category;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not query link category', '', __LINE__, __FILE__, $sql);
	}
	$valid_category = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$valid_category)
	{
		$link_category = 0;
	}
}

//
// Check Link config
//
if($link_config['lock_submit_site'] && $userdata['user_level'] != ADMIN)
{
	$message = $lang['Link_lock_submit_site'];
	$message .= '<br /><br />' . sprintf($lang['Click_return_links'], '<a href="' . append_sid("links.$phpEx") . '">', '</a>');
	
	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("links.$phpEx") . '">'
	));

	message_die(GENERAL_MESSAGE, $message);
}

if(!$link_config['allow_no_logo'] && !$link_logo_src)
{	
	$message = $lang['Link_incomplete'];

	$message .= '<br /><br />' . sprintf($lang['Click_return_links'], '<a href="' . append_sid("links.$phpEx") . '">', '</a>');
	$message .= '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("links.$phpEx") . '">'
	));
		
	message_die(GENERAL_MESSAGE, $message);
}

//
// Add new link
//
if($link_title && $link_desc && $link_category && $link_url)
{
	// Check regiter interval
	$sql = "SELECT MAX(link_joined) AS last_link_joined FROM " . LINKS_TABLE . " 
		WHERE " . ($user_id != ANONYMOUS ? "user_id = $user_id" : "user_ip = '$user_ip_sql'");
		
	if ( !($result = $db->sql_query($sql)) )
	{
		$message = $lang['Link_update_fail'];
	}		
	else
	{
		if($row = $db->sql_fetchrow($result))
		{
			$last_link_joined = $row['last_link_joined'];
		}
		else
		{
			$last_link_joined = 0;
		}

		if($link_joined - $last_link_joined > 60)
		{
			$is_admin = ( $userdata['user_level'] == ADMIN ) ? TRUE : 0;
			$sql = "INSERT INTO " . LINKS_TABLE . " (link_title, link_desc, link_category, link_url, link_logo_src, link_joined,link_active , user_id , user_ip)
				VALUES ('$link_title_sql', '$link_desc_sql', $link_category, '$link_url_sql', '$link_logo_src_sql', $link_joined, $is_admin, $user_id, '$user_ip_sql')";

			if ( !$db->sql_query($sql) )
			{
				$message = $lang['Link_update_fail'];
			}
			else
			{
			  if ( $userdata['user_level'] != ADMIN )
			  {
			    $sql = "SELECT user_id, username, user_notify_pm, user_allow_pm, user_email, user_lang, user_active 
				FROM " . USERS_TABLE . "
				WHERE user_level = " . ADMIN . " AND user_active = 1";
				if ( !($admin_result = $db->sql_query($sql)) )
				{
					message_die(GENERAL_ERROR, "Could not query users table", "", __LINE__, __FILE__, $sql);
				}
				$admin_users = $db->sql_fetchrowset($admin_result);
				$db->sql_freeresult($admin_result);
					
				if ( $link_config['email_notify'] )
				{
				  include($phpbb_root_path . 'includes/emailer.'.$phpEx);
				  foreach ($admin_users as $to_userdata)
				  {
				    if ( $to_userdata['user_email'] )
				    {
				      $emailer = new emailer($board_config['smtp_delivery']);
					
					  $emailer->from($board_config['board_email']);
					  $emailer->replyto($board_config['board_email']);

					  $admin_language = preg_match('/^[a-z0-9_-]+$/i', (string) $to_userdata['user_lang']) ? $to_userdata['user_lang'] : '';
					  if ($admin_language === '' || !is_file($phpbb_root_path . 'language/lang_' . $admin_language . '/email/link_add.tpl'))
					  {
						$admin_language = $link_language;
					  }
					  $emailer->use_template('link_add', $admin_language);
					  $emailer->email_address($to_userdata['user_email']);
					
					  $emailer->assign_vars(array(
						  'LINK_URL' => $link_url,
						  'SITENAME' => $board_config['sitename']
						)
					  );

					  $emailer->send();
					  $emailer->reset();
					}
				  }
				}

				if ( empty($board_config['privmsg_disable']) && $link_config['pm_notify'] )
				{
				  $html_on = 0; $bbcode_on = 0; $smilies_on = 0; $attach_sig = 0;
				  foreach ($admin_users as $to_userdata)
				  {
				    //
				    // Has admin prevented user from sending PM's?
				    //
		            if ( $to_userdata['user_allow_pm'] )
					{
					  $bbcode_uid = make_bbcode_uid();
					  $msg_time = time();
					  //
					  // See if recipient is at their inbox limit
					  //
					  $admin_user_id = intval($to_userdata['user_id']);
					  $sql = "SELECT COUNT(privmsgs_id) AS inbox_items
						FROM " . PRIVMSGS_TABLE . " 
						WHERE ( privmsgs_type = " . PRIVMSGS_NEW_MAIL . " 
							OR privmsgs_type = " . PRIVMSGS_READ_MAIL . "  
							OR privmsgs_type = " . PRIVMSGS_UNREAD_MAIL . " ) 
							AND privmsgs_to_userid = $admin_user_id";
					  if ( !($result = $db->sql_query($sql)) )
					  {
						message_die(GENERAL_MESSAGE, $lang['No_such_user']);
					  }

					  if ( $inbox_info = $db->sql_fetchrow($result) )
					  {
						if ( $inbox_info['inbox_items'] >= $max_inbox_privmsgs )
						{
						  // A link notification must never evict an existing private message.
						  continue;
					  }
					}
					$privmsg_subject = $lang['Link_pm_notify_subject'];
					$privmsg_subject_sql = $db->sql_escape($privmsg_subject);
					$bbcode_uid_sql = $db->sql_escape($bbcode_uid);
					$sql_info = "INSERT INTO " . PRIVMSGS_TABLE . " (privmsgs_type, privmsgs_subject, privmsgs_from_userid, privmsgs_to_userid, privmsgs_date, privmsgs_ip, privmsgs_enable_html, privmsgs_enable_bbcode, privmsgs_enable_smilies, privmsgs_attach_sig)
					VALUES (" . PRIVMSGS_NEW_MAIL . ", '$privmsg_subject_sql', $user_id, $admin_user_id, $msg_time, '$user_ip_sql', $html_on, $bbcode_on, $smilies_on, $attach_sig)";
					if ( !($result = $db->sql_query($sql_info, BEGIN_TRANSACTION)) )
					{
						message_die(GENERAL_ERROR, "Could not insert/update private message sent info.", "", __LINE__, __FILE__, $sql_info);
					}
		
					$privmsg_sent_id = $db->sql_nextid();
					$privmsg_message = sprintf($lang['Link_pm_notify_message'], $link_url);
					$privmsg_message_sql = $db->sql_escape($privmsg_message);
		
					$sql = "INSERT INTO " . PRIVMSGS_TEXT_TABLE . " (privmsgs_text_id, privmsgs_bbcode_uid, privmsgs_text)
					VALUES ($privmsg_sent_id, '$bbcode_uid_sql', '$privmsg_message_sql')";
		
					if ( !$db->sql_query($sql, END_TRANSACTION) )
					{
						message_die(GENERAL_ERROR, "Could not insert/update private message sent text.", "", __LINE__, __FILE__, $sql_info);
					}

					//
					// Add to the users new pm counter
					//
					$sql = "UPDATE " . USERS_TABLE . "
						SET user_new_privmsg = user_new_privmsg + 1, user_last_privmsg = " . time() . "  
						WHERE user_id = $admin_user_id";
					if ( !$status = $db->sql_query($sql) )
					{
						message_die(GENERAL_ERROR, 'Could not update private message new/read status for user', '', __LINE__, __FILE__, $sql);
					}
				  }
				}
			  }
			  $message = $lang['Link_update_success'];
			}
		  }	
		}
		else
		{
			$message = $lang['Link_intval_warning'];		
		}
	}
}
else
{
	$message = $lang['Link_incomplete'];
}

$message .= '<br /><br />' . sprintf($lang['Click_return_links'], '<a href="' . append_sid("links.$phpEx") . '">', '</a>');
$message .= '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid("index.$phpEx") . '">', '</a>');

$template->assign_vars(array(
	'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid("links.$phpEx") . '">'
));
		
message_die(GENERAL_MESSAGE, $message);

?>
