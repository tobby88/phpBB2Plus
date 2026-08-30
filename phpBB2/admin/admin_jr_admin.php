<?php
/***************************************************************************
*                    $RCSfile: admin_jr_admin.php,v $
*                            -------------------
*   copyright            : (C) 2002-2003 Nivisec.com
*   email                : support@nivisec.com
*
*   $Id: admin_jr_admin.php,v 1.7 2003/09/01 01:59:33 nivisec Exp $
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

/****************************************************************************
/** Module Setup
/***************************************************************************/
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('MOD_VERSION')) { define('MOD_VERSION', '2.0.5'); }
if (!defined('MOD_CODE')) { define('MOD_CODE', 1); }
if (!empty($setmodules))
{
	$filename = basename(__FILE__);
	$module['Users']['Jr_Admin'] = $filename;
	return;
}

$phpbb_root_path = './../';
include($phpbb_root_path . 'extension.inc');
include_once($phpbb_root_path."includes/functions_jr_admin.$phpEx");
include_once("pagestart.$phpEx");
find_lang_file_nivisec('lang_jr_admin');
/****************************************************************************
/** Module Actual Start
/***************************************************************************/
/* If for some reason you need to disable the version check in THIS HACK ONLY,
change the blow to TRUE instead of FALSE.  No other hacks will be affected
by this change.
*/
define('DISABLE_VERSION_CHECK', FALSE);
/****************************************************************************
/** Constants and Main Vars.
/***************************************************************************/
$status_message = '';
//Check for color groups mod
$color_group = (defined('COLOR_GROUPS_TABLE')) ? 'user_color_group, ' : '';
define('UPDATE_MODULE_PREFIX', 'update_module_');

/****************************************************************************
/** Functions
/***************************************************************************/
function jr_admin_user_exist($user_id)
{
	global $db, $lang;
	$user_id = max(0, intval($user_id));
	
	//Do a query and see if our user exists with isset
	$row = sql_query_nivisec(
	'SELECT start_date FROM ' . JR_ADMIN_TABLE . " WHERE user_id = $user_id",
	$lang['Error_Module_Table'],
	false,
	1
	);
	return (isset($row['start_date']));
}

function jr_admin_safe_color($value)
{
	$value = ltrim(trim((string) $value), '#');
	return preg_match('/^(?:[A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/D', $value) ? $value : '000000';
}

function jr_admin_make_rank_list($user_id, $user_rank)
{
	global $lang;
	$user_id = max(0, intval($user_id));
	$user_rank = max(0, intval($user_rank));
	
	/****************
	** Due to a damn bug in some browsers (mozilla firebird for sure)
	** this needs to be disabled for drop down!  return only the name
	** for now.
	****************/
	/*
	//Get a list of ranks and make a nice select box
	$rowset = sql_query_nivisec(
	'SELECT * FROM ' . RANKS_TABLE . " WHERE rank_special = 1
	ORDER BY rank_title ASC",
	$lang['Error_Users_Table'],
	false
	);
	
	$rank_list = '<select name="user_rank_list_"'.$user_id.'" class="post" size="1">';
	$selected = (0 == $user_rank) ? 'selected="selected"' : '';
	$rank_list .= '<option value="0" '.$selected.'>'.$lang['No_assigned_rank'].'</option>\n';
	for($i = 0; $i < count($rowset); $i++)
	{
	$selected = ($rowset[$i]['rank_id'] == $user_rank) ? ' selected="selected"' : '';
	$rank_list .= '<option value="'.$rowset[$i]['rank_id'].'"'.$selected.'>'.$rowset[$i]['rank_title'].'</option>\n';
	}
	$rank_list .= '</selected>';
	*/
	
	if (empty($user_rank)) return '';
	
	$row = sql_query_nivisec(
	'SELECT rank_title FROM ' . RANKS_TABLE . " WHERE rank_id = $user_rank",
	$lang['Error_Users_Table'],
	false,
	1
	);
	
	$rank_list = isset($row['rank_title']) ? phpbb_admin_html($row['rank_title']) : '';
	
	return $rank_list;
}

function jr_admin_make_bookmark_heading($letters_list, $start)
{
	global $lang, $order, $sort_item;
	
	$seperator = ' | ';
	$startb = '[ <a href="'.append_sid("admin_jr_admin.php?sort_item=" . ( ( isset($_GET['sort_item']) || isset($_POST['sort_item']) ) ? $sort_item : 'username' ) . "&amp;start=0&amp;order=$order&amp;alphanum=0").'" class="nav">All</a> | ';
	$end = ' ]';
	
	$list = '';
	
	$search_list = explode(',', $lang['ASCII_Search_Codes']);
	
	//Go through each char group
	foreach($search_list as $ord_value)
	{
		//Trim spaces
		$ord_value = trim($ord_value);
		$first_link = false;
		
		//Check & first
		if (preg_match("/^.+\&.+$/", $ord_value))
		{
			$make_link = false;
			$items = explode('&', $ord_value);
			for($i = $items[0]; $i <= $items[1]; $i++)
			{
				if (isset($letters_list[$i]))
				{
					$make_link = true;
					$first_link = (!$first_link) ? $i : $first_link;
				}
			}
			if ($make_link)
			{
				$list .= '<a href="'.append_sid("admin_jr_admin.php?sort_item=" . ( ( isset($_GET['sort_item']) || isset($_POST['sort_item']) ) ? $sort_item : 'username' ) . "&amp;start=0&amp;order=$order&amp;alphanum=0").'" class="nav">0 - 9</a>';
			}
			else
			{
				$list .= strtoupper(chr($items[0])).' - '.strtoupper(chr($items[1]));
			}
			$list .= $seperator;
		}
		//Check for - now
		elseif (preg_match("/^.+\-.+$/", $ord_value))
		{
			$items = explode('-', $ord_value);
			for($i = $items[0]; $i <= $items[1]; $i++)
			{
				if (isset($letters_list[$i]))
				{
					$list .= '<a href="'.append_sid("admin_jr_admin.php?sort_item=" . ( ( isset($_GET['sort_item']) || isset($_POST['sort_item']) ) ? $sort_item : 'username' ) . "&amp;start=0&amp;order=$order&amp;alphanum=" . strtoupper(chr($i))).'" class="nav">'.strtoupper(chr($i)).'</a>';
				}
				else
				{
					$list .= strtoupper(chr($i));
				}
				$list .= $seperator;
			}
		}
		else
		{
			if (isset($letters_list[$ord_value]))
			{
				$list .= '<a href="'.append_sid("admin_jr_admin.php?sort_item=" . ( ( isset($_GET['sort_item']) || isset($_POST['sort_item']) ) ? $sort_item : 'username' ) . "&amp;start=0&amp;order=$order&amp;alphanum=" . strtoupper(chr($ord_value))).'" class="nav">'.strtoupper(chr($ord_value)).'</a>';
			}
			else
			{
				$list .= strtoupper(chr($ord_value));
			}
			$list .= $seperator;
		}
	}
	
	//Replace the last seperator with the ending item
	$list = preg_replace('/'.addcslashes($seperator, '|').'$/', $end, $list);
	
	return ($startb . $list);
}
/*******************************************************************************************
/** Get parameters.  'var_name' => 'default'
/******************************************************************************************/
$user_id_value = (isset($_POST['user_id']) && is_scalar($_POST['user_id'])) ? $_POST['user_id'] :
	((isset($_GET['user_id']) && is_scalar($_GET['user_id'])) ? $_GET['user_id'] : 0);
$user_id = max(0, intval($user_id_value));
$color_group_id = (isset($_POST['color_group_id']) && is_scalar($_POST['color_group_id'])) ? max(0, intval($_POST['color_group_id'])) : 0;
$order_value = (isset($_POST['order']) && is_scalar($_POST['order'])) ? $_POST['order'] :
	((isset($_GET['order']) && is_scalar($_GET['order'])) ? $_GET['order'] : 'ASC');
$order = strtoupper((string) $order_value) === 'DESC' ? 'DESC' : 'ASC';
$sort_item_value = (isset($_POST['sort_item']) && is_scalar($_POST['sort_item'])) ? (string) $_POST['sort_item'] :
	((isset($_GET['sort_item']) && is_scalar($_GET['sort_item'])) ? (string) $_GET['sort_item'] : 'username');
$allowed_sort_items = array('username', 'user_color_group', 'user_rank', 'user_active', 'user_allowavatar', 'user_allow_pm');
$sort_item = in_array($sort_item_value, $allowed_sort_items, true) ? $sort_item_value : 'username';
$start_value = (isset($_POST['start']) && is_scalar($_POST['start'])) ? $_POST['start'] :
	((isset($_GET['start']) && is_scalar($_GET['start'])) ? $_GET['start'] : 0);
$start = max(0, intval($start_value));
$alphanum_value = (isset($_POST['alphanum']) && is_scalar($_POST['alphanum'])) ? (string) $_POST['alphanum'] :
	((isset($_GET['alphanum']) && is_scalar($_GET['alphanum'])) ? (string) $_GET['alphanum'] : '');
$alphanum = preg_match('/^(?:0|[A-Z])$/D', strtoupper($alphanum_value)) ? strtoupper($alphanum_value) : '';

//*******************************************************************************************
/** Check for edit user
/******************************************************************************************/
if (count($_POST))
{
	foreach ($_POST as $key => $val)
	{
		if (preg_match("/^edit_user_/", $key))
		{
			$user_id = max(0, intval(str_replace('edit_user_', '', $key)));
		}
	}
}
$page_title = $lang['Jr_Admin'];
$page_desc = $lang['Permissions_Page_Desc'];


if (!empty($user_id) && !isset($_POST['update_user']))
{
	$sql = "SELECT $color_group username, user_id, user_level  FROM " . USERS_TABLE . "
		WHERE user_id = $user_id
		ORDER BY username ASC";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row)
	{
		message_die(GENERAL_MESSAGE, $lang['Error_User_Table']);
	}
	$jr_admin_row = jr_admin_get_user_info($user_id);
	if (!is_array($jr_admin_row))
	{
		$jr_admin_row = array(
			'user_jr_admin' => '',
			'start_date' => 0,
			'update_date' => 0,
			'admin_notes' => '',
			'notes_view' => 0
		);
	}
	$module_list = jr_admin_get_module_list();
	$user_module_list = !empty($jr_admin_row['user_jr_admin']) ? explode(EXPLODE_SEPERATOR_CHAR, $jr_admin_row['user_jr_admin']) : array();
	
	jr_admin_include_all_lang_files();
	
	$i = 0;
	foreach($module_list as $cat => $info_array)
	{
		$template->assign_block_vars('catrow', array(
		'CAT' => phpbb_admin_html((isset($lang[$cat])) ? $lang[$cat] : preg_replace("/_/", ' ', $cat)),
		'NUM' => $i,
		));
		foreach($info_array as $module_name => $file_array)
		{
			$file_hash = $file_array['file_hash'];
			$checked = (in_array($file_hash, $user_module_list)) ? 'checked="checked"' : '';
			$template->assign_block_vars('catrow.modulerow', array(
			'ROW' => ($i % 2) ? 'row1' : 'row2',
			'NAME' => phpbb_admin_html((isset($lang[$module_name])) ? $lang[$module_name] : preg_replace("/_/", ' ', $module_name)),
			'FILENAME' => phpbb_admin_html($file_array['filename']),
			'FILE_HASH' => phpbb_admin_html($file_hash),
			'CHECKED' => $checked
			));
		}
		$i++;
	}
	
	if (!empty($color_group))
	{
		$disabled = '';
		$disabled_text = '';
		$sql = 'SELECT * FROM ' . COLOR_GROUPS_TABLE . '
			ORDER BY group_name ASC';	
		if (!$cresult = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
		}
		$found_selected = false;
		while ($crow = $db->sql_fetchrow($cresult))
		{
			$group_color = jr_admin_safe_color($crow['group_color']);
			$selected = ($row['user_color_group'] == $crow['group_id']) ? 'SELECTED' : '';
			if (!empty($selected)) $found_selected = true;
			$template->assign_block_vars('grouprow', array(
			'NAME' => phpbb_admin_html($crow['group_name']),
			'ID' => intval($crow['group_id']),
			'SELECTED' => $selected
			));
			if ($row['user_color_group'] == $crow['group_id'])
			{
				$template->assign_vars(array(
				'GROUP_NAME' => '[&nbsp;<span style="color:#' . $group_color . '">' . phpbb_admin_html($crow['group_name']) . '</span>&nbsp;]',
				'GROUP_COLOR' => $group_color
				));
			}
		}
		if (!$found_selected)
		{
			$template->assign_var('DEFAULT_SELECT', 'selected');
		}
	}
	else
	{
		$disabled = 'disabled';
		$disabled_text = $lang['Disabled_Color_Groups'];
	}
	$template->assign_vars(array(
	'USER_ID' => $user_id,
	'USERNAME' => phpbb_admin_html($row['username']),
	'DISABLED' => $disabled,
	'DISABLED_TEXT' => $disabled_text,
	'START_DATE' => create_date($board_config['default_dateformat'], $jr_admin_row['start_date'], $board_config['board_timezone']),
	'UPDATE_DATE' => create_date($board_config['default_dateformat'], $jr_admin_row['update_date'], $board_config['board_timezone']),
	'NOTES' => phpbb_admin_html(isset($jr_admin_row['admin_notes']) ? $jr_admin_row['admin_notes'] : ''),
	'NOTES_VIEW_CHECKED' => ($jr_admin_row['notes_view']) ? 'checked="checked"' : '',
	'ADMIN_TEXT' => ($row['user_level'] == ADMIN) ? $lang['Admin_Note'] : ''
	));
	
	$template->set_filenames(array('body' => 'admin/jr_admin_user_permissions.tpl'));
}
else
{
	//Update info like module list and color groups
	if (isset($_POST['update_user']) && !empty($user_id))
	{
		phpbb_admin_require_post_session();
		$sql = "SELECT user_id FROM " . USERS_TABLE . " WHERE user_id = $user_id AND user_id <> " . ANONYMOUS;
		if (!($result = $db->sql_query($sql)) || !$db->sql_fetchrow($result))
		{
			message_die(GENERAL_MESSAGE, $lang['Error_User_Table']);
		}
		$db->sql_freeresult($result);

		$allowed_module_hashes = array();
		foreach (jr_admin_get_module_list() as $module_category)
		{
			foreach ($module_category as $module_data)
			{
				if (isset($module_data['file_hash']) && preg_match('/^[a-f0-9]{32}$/D', $module_data['file_hash']))
				{
					$allowed_module_hashes[$module_data['file_hash']] = true;
				}
			}
		}
		$user_update_hashes = array();
		foreach ($_POST as $key => $val)
		{
			if (preg_match('/^[0-9]+_' . UPDATE_MODULE_PREFIX . '([a-f0-9]{32})$/D', (string) $key, $module_match) && isset($allowed_module_hashes[$module_match[1]]))
			{
				$user_update_hashes[$module_match[1]] = true;
			}
		}
		$user_update_list = implode(EXPLODE_SEPERATOR_CHAR, array_keys($user_update_hashes));
		
		if (!jr_admin_user_exist($user_id))
		{
			//If the user_id doesn't exist in the table, we need to add it
			//before we can update!
			sql_query_nivisec(
			'INSERT INTO ' . JR_ADMIN_TABLE . "
			(user_id, start_date) VALUES ($user_id, " . time() . ')',
			$lang['Error_Module_Table']
			);
		}
		
		if (!empty($color_group) && isset($color_group_id))
		{
			if ($color_group_id > 0)
			{
				$sql = 'SELECT group_id FROM ' . COLOR_GROUPS_TABLE . " WHERE group_id = $color_group_id";
				if (!($result = $db->sql_query($sql)) || !$db->sql_fetchrow($result))
				{
					message_die(GENERAL_MESSAGE, $lang['Error_User_Table']);
				}
				$db->sql_freeresult($result);
			}
			$sql = 'UPDATE ' . USERS_TABLE . "
			SET user_color_group = $color_group_id
			WHERE user_id = $user_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
			}
		}
		
		$notes_view = (isset($_POST['notes_view'])) ? 1 : 0;
		$admin_notes = (isset($_POST['admin_notes']) && is_scalar($_POST['admin_notes'])) ? trim(stripslashes((string) $_POST['admin_notes'])) : '';
		if (strlen($admin_notes) > 60000)
		{
			message_die(GENERAL_MESSAGE, $lang['Error_User_Table']);
		}
		
		//Do the information update
		$sql = 'UPDATE ' . JR_ADMIN_TABLE . "
			SET user_jr_admin = '" . $db->sql_escape($user_update_list) . "',
			update_date = " . time() . ",
			admin_notes = '" . $db->sql_escape($admin_notes) . "',
			notes_view = $notes_view
			WHERE user_id = $user_id";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
		}
		$status_message .= $lang['Updated_Permissions'];
	}
	
	//No user_id was found or we are done updating, take them to the info page
	$alpha_where = '';
	$proof = '';
	if (!$sort_item) $sort_item = 'username'; 
	for ($i = 97; $i <= 122; $i++)
	{
		$proof .= " AND username NOT LIKE '" . chr($i) . "%' ";
	}
	$alpha_where = ( $alphanum == '0' ) ? $proof : (($alphanum != '') ? "AND username LIKE '$alphanum%'" : ''); 

	$user_search_value = (isset($_POST['user_search']) && is_scalar($_POST['user_search'])) ? $_POST['user_search'] :
		((isset($_GET['user_search']) && is_scalar($_GET['user_search'])) ? $_GET['user_search'] : '');
	$user_search = trim(stripslashes((string) $user_search_value));
	if (strlen($user_search) > 50)
	{
		$user_search = substr($user_search, 0, 50);
	}
	$user_where = ($user_search !== '') ? " AND username LIKE '" . $db->sql_escape($user_search) . "'" : '';

	$per_page = max(1, min(200, intval($board_config['topics_per_page'])));
	$sql = "SELECT $color_group username, user_id, user_rank, user_allow_pm, user_allowavatar, user_active
		FROM " . USERS_TABLE . "
		WHERE user_id <> " . ANONYMOUS . "
		$alpha_where
		$user_where
		ORDER BY $sort_item $order
		LIMIT $start, $per_page";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
	}
	$current_letter = '';
	$assigned_current_letter_link = true;
	$row_index = 0;
	while ($row = $db->sql_fetchrow($result))
	{
		$row_letter = strtoupper(substr($row['username'], 0, 1));
		if ($row_letter !== $current_letter)
		{
			$current_letter = $row_letter;
			$assigned_current_letter_link = false;
		}
		$jr_admin_row = jr_admin_get_user_info($row['user_id']);
		$module_count = (!empty($jr_admin_row['user_jr_admin'])) ? count(explode(EXPLODE_SEPERATOR_CHAR, $jr_admin_row['user_jr_admin'])) : 0;
		$block_text = 'userrow';
		$has_bookmark = (!$assigned_current_letter_link && preg_match('/^[A-Z0-9]$/D', $current_letter));
		
		$template->assign_block_vars($block_text, array(
		'NAME' => phpbb_admin_html($row['username']),
		'ID' => intval($row['user_id']),
		'ALLOW_PM' => ($row['user_allow_pm']) ? 'checked="checked"' : '',
		'ALLOW_AVATAR' => ($row['user_allowavatar']) ? 'checked="checked"' : '',
		'ACTIVE' =>($row['user_active']) ? 'checked="checked"' : '',
		'ROW_CLASS' => ($row_index++ % 2) ? 'row1' : 'row2',
		'RANK_LIST' => jr_admin_make_rank_list($row['user_id'], $row['user_rank']),
		'BOOKMARK' => $has_bookmark ? '<a id="jr-user-' . $current_letter . '">' : '',
		'BOOKMARK_END' => $has_bookmark ? '</a>' : '',
		'MODULE_COUNT' => ($module_count != 0) ? sprintf($lang['Modules_Owned'], $module_count) : ''
		));
		
		//We 'know' we assigned it if it wasn't already now
		$assigned_current_letter_link = true;
		
		if (!empty($color_group))
		{
			if ($row['user_color_group'] != 0)
			{
				//If we have the color groups mod installed, make use of it here.
				$sql = 'SELECT * FROM ' . COLOR_GROUPS_TABLE . '
				WHERE group_id = ' . intval($row['user_color_group']);
				if (!$cresult = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
				}
				$crow = $db->sql_fetchrow($cresult);
				$db->sql_freeresult($cresult);
				if ($crow)
				{
					$template->assign_block_vars($block_text.'.colorrow', array(
					'GROUP_COLOR' => jr_admin_safe_color($crow['group_color']),
					'GROUP_NAME' => phpbb_admin_html($crow['group_name'])
					));
				}
				else
				{
					$template->assign_block_vars($block_text.'.blank_colorrow', array());
				}
			}
			else
			{
				$template->assign_block_vars($block_text.'.blank_colorrow', array());
			}
		}
	}
	
	$sql = "SELECT user_id FROM " . USERS_TABLE . "
		WHERE user_id <> " . ANONYMOUS . "
		$alpha_where
		$user_where";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_numrows($result);
	$total_users_count = $row;

	$user_search_query = ($user_search !== '') ? '&amp;user_search=' . rawurlencode($user_search) : '';
	$template->assign_vars(array(
		'PAGINATION' => generate_pagination("admin_jr_admin.$phpEx?sort_item=$sort_item&amp;order=$order&amp;alphanum=$alphanum$user_search_query", $total_users_count, $per_page, $start),
		'PAGE_NUMBER' => sprintf($lang['Page_of'], ( floor( $start / $per_page ) + 1 ), ceil( $total_users_count / $per_page )))
	);

	//Make sort image choice and sorting links
	$base_order = ($order == 'ASC') ? 'order=DESC' : 'order=ASC';
	$base_filename = append_sid(basename(__FILE__) . '?' . $base_order);
	$desc_img = '<img src="'.$phpbb_root_path.$lang['DESC_Image'].'" border="0">';
	$asc_img = '<img src="'.$phpbb_root_path.$lang['ASC_Image'].'" border="0">';
	$template->assign_vars(array(
	'IMG_USERNAME' => ($sort_item == 'username') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'IMG_COLORGROUP' => ($sort_item == 'user_color_group') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'IMG_RANK' => ($sort_item == 'user_rank') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'IMG_ACTIVE' => ($sort_item == 'user_active') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'IMG_AVATAR' => ($sort_item == 'user_allowavatar') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'IMG_PM' => ($sort_item == 'user_allow_pm') ? ($order == 'ASC') ? $asc_img : $desc_img : '',
	'S_USERNAME' => $base_filename . '&sort_item=username&alphanum='.$alphanum.$user_search_query,
	'S_COLORGROUP' => $base_filename . '&sort_item=user_color_group&alphanum='.$alphanum.$user_search_query,
	'S_RANK' => $base_filename . '&sort_item=user_rank&alphanum='.$alphanum.$user_search_query,
	'S_ACTIVE' => $base_filename . '&sort_item=user_active&alphanum='.$alphanum.$user_search_query,
	'S_AVATAR' => $base_filename . '&sort_item=user_allowavatar&alphanum='.$alphanum.$user_search_query,
	'S_PM' => $base_filename . '&sort_item=user_allow_pm&alphanum='.$alphanum.$user_search_query
	));
	
	$letter_list = array();
	$current_letter = '';
	$sql = "SELECT username	FROM " . USERS_TABLE . "
		WHERE user_id <> " . ANONYMOUS;
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_User_Table'], '', __LINE__, __FILE__, $sql);
	}
	while ($row = $db->sql_fetchrow($result))
	{
		$test_letter = strtoupper(substr($row['username'], 0, 1));
		if ($test_letter != $current_letter)
		{
			//If we have a new letter, get it here.
			$current_letter = $test_letter;
			$assigned_current_letter_link = false;
			$letter_list[ord($current_letter)] = true;
		}
	}

	if ($sort_item == 'username')
	{
		$template->assign_var('LETTER_HEADING', jr_admin_make_bookmark_heading($letter_list, $start));
	}
	
	if (!empty($color_group))
	{
		$template->assign_block_vars('colorgroup_switch', array());
	}
	
	$template->set_filenames(array('body' => 'admin/jr_admin_user_list.tpl'));
}
//Common Variables
$template->assign_vars(array(
'S_ACTION' => append_sid(basename(__FILE__)),
'S_USER_PERM' => append_sid('admin_ug_auth.'.$phpEx),
'S_PROFILE' => append_sid($phpbb_root_path.'profile.'.$phpEx),
'S_MANAGEMENT' => append_sid('admin_users.'.$phpEx),
'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),
'USER_SEARCH' => phpbb_admin_html(isset($user_search) ? $user_search : ''),
'S_USER_POST_URL' => POST_USERS_URL,
'L_SEARCH' => $lang['Search'],
'L_NONE' => $lang['None'],
'L_ALLOW' => $lang['Allow_Access'],
'L_VERSION' => $lang['Version'],
'L_PAGE_NAME' => $page_title,
'L_PAGE_DESC' => $page_desc,
'MOD_NUMBER' => MOD,
'L_COLOR_GROUP' => $lang['Color_Group'],
'VERSION' => MOD_VERSION,
'L_USERS_W_ACCESS' => $lang['Users_with_Access'],
'L_USERS_WOUT_ACCESS' => $lang['Users_without_Access'],
'L_MODULE_COUNT' => $lang['Module_Count'],
'L_EDIT' => $lang['Edit'],
'L_UPDATE' => $lang['Update'],
'L_SUBMIT' => $lang['Submit'],
'L_RESET' => $lang['Reset'],
'L_EXAMPLE' => $lang['Example'],
'L_MODULE_INFO' => $lang['Module_Info'],
'L_CHECK_ALL_IN_CAT' => $lang['Cat_Check_All'],
'L_CHECK_ALL' => $lang['Check_All'],
'L_OPTIONS' => $lang['Options'],
'L_EDIT_PERMISSIONS' => $lang['Edit_Permissions'],
'L_VIEW_PROFILE' => $lang['View_Profile'],
'L_EDIT_USER_DETAILS' => $lang['Edit_User_Details'],
'L_NOTES' => $lang['Notes'],
'L_ALLOW_VIEW' => $lang['Allow_View'],
'L_START_DATE' => $lang['Start_Date'],
'L_UPDATE_DATE' => $lang['Update_Date'],
'L_USERNAME' => $lang['Username'],
'L_EDIT_LIST' => $lang['Edit_Modules'],
'L_USER_STATS' => $lang['User_Stats'],
'L_USER_INFO' => $lang['User_Info'],
'L_ACTIVE' => $lang['User_Active'],
'L_PM' => $lang['Allow_PM'],
'L_AVATAR' => $lang['Allow_Avatar'],
'L_COLOR_GROUP' => $lang['Color_Group'],
'L_RANK' => $lang['Rank'],
'L_ADMIN_NOTES' => $lang['Admin_Notes']
));

if ($status_message != '')
{
	$template->assign_block_vars('statusrow', array());
	$template->assign_vars(array(
	'L_STATUS' => $lang['Status'],
	'I_STATUS_MESSAGE' => $status_message)
	);
}
/************************************************************************
** Begin The Version Check Feature
************************************************************************/
if (file_exists($phpbb_root_path.'nivisec_version_check.'.$phpEx) && !DISABLE_VERSION_CHECK)
{
	include($phpbb_root_path.'nivisec_version_check.'.$phpEx);
}
/************************************************************************
** End The Version Check Feature
************************************************************************/

$template->pparse('body');
copyright_nivisec($page_title, '2002-2003');
include('page_footer_admin.'.$phpEx);

?>
