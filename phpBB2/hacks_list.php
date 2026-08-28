<?php
/***************************************************************************
*                    $RCSfile: hacks_list.php,v $
*                            -------------------
*   copyright            : (C) 2003 Nivisec.com
*   email                : support@nivisec.com
*
*   $Id: hacks_list.php,v 1.3 2003/07/10 16:50:23 nivisec Exp $
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

define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path.'extension.inc');
include($phpbb_root_path.'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
//
// End session management
//
include($phpbb_root_path.'includes/functions_hacks_list.'.$phpEx);
$credits_lang = !empty($userdata['user_lang']) ? $userdata['user_lang'] : $board_config['default_lang'];
$credits_lang_file = $phpbb_root_path.'language/lang_'.$credits_lang.'/lang_admin_hacks_list.'.$phpEx;
if (!file_exists($credits_lang_file))
{
	$credits_lang_file = $phpbb_root_path.'language/lang_'.$board_config['default_lang'].'/lang_admin_hacks_list.'.$phpEx;
}
include($credits_lang_file);

/****************************************************************************
/** Constants and Main Vars.
/***************************************************************************/
$page_title = $lang['Hacks_List'];

/*******************************************************************************************
/** Get parameters.  'var_name' => 'default'
/******************************************************************************************/
$params = array('mode' => '');

foreach($params as $var => $default)
{
	$$var = $default;
	if( isset($_POST[$var]) || isset($_GET[$var]) )
	{
		$$var = ( isset($_POST[$var]) ) ? $_POST[$var] : $_GET[$var];
	}
}
/*******************************************************************************************
/** Parse for modes...
/******************************************************************************************/
setup_hacks_list_array();
scan_hl_files();
switch($mode)
{
	default:
	{
		$template->set_filenames(array('body' => 'hacks_list_display.tpl'));
		$sql = 'SELECT * FROM ' . HACKS_LIST_TABLE . "
			   WHERE hack_hide = 'No'
			   ORDER BY hack_name ASC";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		$i = 0;
		while ($row = $db->sql_fetchrow($result))
		{
			$author = htmlspecialchars(stripslashes($row['hack_author']), ENT_QUOTES, 'UTF-8');
			$email = stripslashes($row['hack_author_email']);
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				$author = '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '">' . $author . '</a>';
			}
			$website = stripslashes($row['hack_author_website']);
			$website_link = ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $website))
				? '<a href="' . htmlspecialchars($website, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">' . htmlspecialchars($website, ENT_QUOTES, 'UTF-8') . '</a>'
				: $lang['No_Website'];
			$name = htmlspecialchars(stripslashes($row['hack_name']), ENT_QUOTES, 'UTF-8');
			$download = stripslashes($row['hack_download_url']);
			if ($download !== '' && filter_var($download, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $download))
			{
				$name = '<a href="' . htmlspecialchars($download, ENT_QUOTES, 'UTF-8') . '" rel="noopener noreferrer">' . $name . '</a>';
			}
			$template->assign_block_vars('listrow', array(
			'ROW_CLASS' => (!(++$i% 2)) ? $theme['td_class1'] : $theme['td_class2'],
			'HACK_ID' => $row['hack_id'],
			'HACK_AUTHOR' => $author,
			'HACK_WEBSITE' => $website_link,
			'HACK_NAME' => $name,
			'HACK_DESC' => htmlspecialchars(stripslashes($row['hack_desc']), ENT_QUOTES, 'UTF-8'),
			'HACK_VERSION' => ($row['hack_version'] != '') ? ' v' . htmlspecialchars(stripslashes($row['hack_version']), ENT_QUOTES, 'UTF-8') : ''));
		}
		
		if ($i == 0 || !isset($i))
		{
			$template->assign_block_vars('empty_switch', array());
			$template->assign_var('L_NO_HACKS', $lang['No_Hacks']);
		}
	}
}
$template->assign_vars(array(
'L_PAGE_NAME' => $page_title,
'S_MODE_ACTION' => append_sid(basename(__FILE__)),
'L_AUTHOR' => $lang['Author'],
'L_DESCRIPTION' => $lang['Description'],
'L_HACK_NAME' => $lang['Hack_Name'],
'L_WEBSITE' => $lang['Website']));

include($phpbb_root_path . 'includes/page_header.'.$phpEx);

$template->pparse('body');
copyright_nivisec($page_title, '2003');
include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
?>
