<?php
/***************************************************************************
*                    $RCSfile: admin_color_groups.php,v $
*                            -------------------
*   copyright            : (C) 2002-2003 Nivisec.com
*   email                : support@nivisec.com
*
*   $Id: admin_color_groups.php,v 1.4 2003/09/03 21:55:42 nivisec Exp $
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
if (!defined('IN_PHPBB')) define('IN_PHPBB', true);
if (!defined('MOD_VERSION')) { define('MOD_VERSION', '1.2.0'); }
if (!defined('MOD_CODE')) { define('MOD_CODE', 24); }
$phpbb_root_path = '../';
include($phpbb_root_path . 'extension.inc');
include_once($phpbb_root_path."includes/functions_color_groups.$phpEx");
include_once("pagestart.$phpEx");
find_lang_file_nivisec('lang_color_groups');
if (!empty($setmodules))
{
	$filename = basename(__FILE__);
	$module['Groups']['Color_Groups'] = $filename;
	return;
}

/****************************************************************************
/** Module Actual Start
/***************************************************************************/
/* If for some reason you need to disable the version check in THIS HACK ONLY,
change the blow to TRUE instead of FALSE.  No other hacks will be affected
by this change.
*/
define('DISABLE_VERSION_CHECK', FALSE);
/* Debugging for this file */
$debug = false;

/****************************************************************************
/** Main Vars
/***************************************************************************/
$status_message = '';
$next_order_num = get_color_group_order_max() + 1;
$filename = basename(__FILE__);
$order_num_max = get_color_group_order_max();
$order_num_min = get_color_group_order_min();

/****************************************************************************
/** Small Functions
/***************************************************************************/
function count_users_in_color_group($group_id)
{
	global $db, $lang;
	$group_id = (int) $group_id;
	if ($group_id <= 0)
	{
		return 0;
	}
	$sql = 'SELECT COUNT(user_id) as count FROM ' . USERS_TABLE . "
		WHERE user_color_group = $group_id";
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
	$row = $db->sql_fetchrow($result);
	return max(0, $row['count']);
}
function swap_color_group_order_num($g1, $g2)
{
	global $lang, $status_message, $next_order_num;
	$g1 = (int) $g1;
	$g2 = (int) $g2;
	if ($g1 <= 0 || $g2 <= 0 || $g1 === $g2)
	{
		return false;
	}
	
	do_query_nivisec(
	'SELECT group_id, group_name, order_num FROM ' . COLOR_GROUPS_TABLE . "
		WHERE group_id = $g1
		OR group_id = $g2",
	// End query
	$row_items,
	$lang['Error_Group_Table']
	);
	if (count($row_items) !== 2)
	{
		return false;
	}
	
	//On the small chance the two order numbers are equal somehow, fix it
	if ($row_items[0]['order_num'] == $row_items[1]['order_num'])
	{
		do_fast_query_nivisec(
		'UPDATE ' . COLOR_GROUPS_TABLE . "
		SET order_num = $next_order_num
		WHERE group_id = " . $row_items[1]['group_id'],
		
		$lang['Error_Group_Table']
		);
		$status_message .= sprintf($lang['Invalid_Order_Num'], $row_items[1]['group_name']);
	}
	else
	{
		//We know 2 items are returned, if not something is screwed up badly
		do_fast_query_nivisec(
		'UPDATE ' . COLOR_GROUPS_TABLE . '
		SET order_num = ' . $row_items[0]['order_num'] . '
		WHERE group_id = ' . $row_items[1]['group_id'],
		$lang['Error_Group_Table']
		);
		do_fast_query_nivisec(
		'UPDATE ' . COLOR_GROUPS_TABLE . '
		SET order_num = ' . $row_items[1]['order_num'] . '
		WHERE group_id = ' . $row_items[0]['group_id'],
		$lang['Error_Group_Table']
		);
	}
	return true;
}
function hide_toggle_color_group($group_id, $mode)
{
	global $lang;
	$group_id = (int) $group_id;
	if ($group_id <= 0)
	{
		return false;
	}
	
	switch($mode)
	{
		case 'hide':
		$hide_mode = 1;
		break;
		case 'unhide':
		$hide_mode = 0;
		break;
		default:
		return false;
	}
	
	do_fast_query_nivisec(
	'UPDATE ' . COLOR_GROUPS_TABLE . "
	SET hidden = $hide_mode
	WHERE group_id = $group_id",
	$lang['Error_Group_Table']
	);
	return true;
}

function make_color_group_reference()
{
	global $db, $lang;
	
	$c_groups = array();
	
	$sql = 'SELECT group_id, group_name FROM ' . COLOR_GROUPS_TABLE;
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$c_groups[$row['group_id']] = $row['group_name'];
	}
	return $c_groups;
}	
/*******************************************************************************************
/** Get parameters.  'var_name' => 'default'
/******************************************************************************************/
$params = array('mode' => '');
if ($debug)
{
	//Dump out the get and post vars if in debug mode
	echo '<pre><span  class="gensmall"><font color="blue">DEBUG - POST VARS -<br>';
	print_r($_POST);
	echo '</font><br>';
	echo '<font color="red">DEBUG - GET VARS -<br>';
	print_r($_GET);
	echo '</font><br></pre></span>';
}

foreach($params as $var => $default)
{
	$$var = $default;
	if( isset($_POST[$var]) || isset($_GET[$var]) )
	{
		$value = isset($_POST[$var]) ? $_POST[$var] : $_GET[$var];
		$$var = is_scalar($value) ? (string) $value : $default;
	}
}
	$color_group_list = make_color_group_reference();

//*******************************************************************************************
/** Check for edit user lists or deletes
/******************************************************************************************/
$found_list = false;
$found_delete = false;
$found_hide = false;
$found_unhide = false;
$found_move = false;
if (count($_POST))
{
	foreach ($_POST as $key => $val)
	{
		if (preg_match('/^edit_group_([0-9]+)$/D', $key, $matches))
		{
			$group_id = (int) $matches[1];
			$found_list = true;
		}
		elseif (preg_match('/^delete_group_([0-9]+)$/D', $key, $matches))
		{
			$group_id_delete = (int) $matches[1];
			$found_delete = true;
		}
		elseif (preg_match('/^hide_group_([0-9]+)$/D', $key, $matches))
		{
			$group_id_hide = (int) $matches[1];
			$found_hide = true;
		}
		elseif (preg_match('/^unhide_group_([0-9]+)$/D', $key, $matches))
		{
			$group_id_unhide = (int) $matches[1];
			$found_unhide = true;
		}
		elseif (preg_match('/^move_group_([0-9]+)_([0-9]+)$/D', $key, $matches))
		{
			$group_id_move = (int) $matches[1];
			$group_id_swap = (int) $matches[2];
			$found_move = true;
		}
	}
}

$mutating_request = isset($_POST['update_groups']) || isset($_POST['update_group_list']) ||
	isset($_POST['add_new_group']) || $found_delete || $found_hide || $found_unhide || $found_move;
if ($mutating_request)
{
	phpbb_admin_require_post_session();
}
/*******************************************************************************************
/** Edit user lists
/******************************************************************************************/
if ($found_list && isset($group_id))
{
	$group_id = (int) $group_id;
	$page_title = $lang['Color_Group_User_List'];
	$page_desc = $lang['Color_Group_User_List_Desc'];

	
	//Get group info
	$sql = 'SELECT * FROM ' . COLOR_GROUPS_TABLE . "
		WHERE group_id = $group_id";
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
	$group_row = $db->sql_fetchrow($result);
	if (!$group_row)
	{
		message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
	}
	
	//Make Our List
	$user_list = array();
	$sql = 'SELECT username, user_id FROM ' . USERS_TABLE . "
				WHERE user_color_group = $group_id
				ORDER BY username ASC";
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$user_list[] = $row['user_id'];
	}
	
	//Make A full list of all users, non grouped, and grouped
	$sql = 'SELECT user_id, username, user_color_group FROM ' . USERS_TABLE . '
			WHERE user_id <> ' . ANONYMOUS . '
			ORDER BY username ASC';
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
	$user_list_box = '<select class="post" name="user_list_box" size="20" multiple="multiple">';
	while ($row = $db->sql_fetchrow($result))
	{
		$selected = (in_array($row['user_id'], $user_list)) ? 'selected="true"' : '';
		$username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
		$c_group_name = isset($color_group_list[$row['user_color_group']]) ? $color_group_list[$row['user_color_group']] : '';
		$c_group = ($row['user_color_group'] != 0 && $c_group_name !== '') ? '(' . htmlspecialchars($c_group_name, ENT_QUOTES, 'UTF-8') . ')' : '';
		$id = (int) $row['user_id'];
		$user_list_box .= "<option value=\"$id\" $selected>$username $c_group</option>";
	}
	$user_list_box .= '</select>';

	//Make Our List
	$group_list = array();
	$sql = 'SELECT group_name, group_id FROM ' . GROUPS_TABLE . "
				WHERE group_color_group = $group_id
				ORDER BY group_name ASC";
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
	while ($row = $db->sql_fetchrow($result))
	{
		$group_list[] = $row['group_id'];
	}
	
	//Make A full list of all groups, non grouped, and grouped
	$sql = 'SELECT group_id, group_name, group_color_group FROM ' . GROUPS_TABLE . '
		WHERE group_single_user = 0		
		ORDER BY group_name ASC';
	if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
	$group_list_box = '<select class="post" name="group_list_box" size="20" multiple="multiple">';
	while ($row = $db->sql_fetchrow($result))
	{
		$selected = (in_array($row['group_id'], $group_list)) ? 'selected="true"' : '';
		$group_name = htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8');
		$c_group_name = isset($color_group_list[$row['group_color_group']]) ? $color_group_list[$row['group_color_group']] : '';
		$c_group = ($row['group_color_group'] != 0 && $c_group_name !== '') ? '(' . htmlspecialchars($c_group_name, ENT_QUOTES, 'UTF-8') . ')' : '';
		$id = (int) $row['group_id'];
		$group_list_box .= "<option value=\"$id\" $selected>$group_name $c_group</option>";
	}
	$group_list_box .= '</select>';
	
	$template->assign_vars(array(
	'S_USER_LIST'=> $user_list,
	'S_USER_LIST_BOX' => $user_list_box,
	'S_GROUP_LIST_BOX' => $group_list_box,
	'L_EDITING_GROUP' => sprintf($lang['Editing_Group'], htmlspecialchars($group_row['group_name'], ENT_QUOTES, 'UTF-8')),
	'S_GROUP_COLOR' => check_font_color_nivisec($group_row['group_color']) ? htmlspecialchars($group_row['group_color'], ENT_QUOTES, 'UTF-8') : '',
	'S_GROUP_ID' => (int) $group_row['group_id']
	));
	$template->set_filenames(array('body' => 'admin/color_groups_user_list.tpl'));
}
/*******************************************************************************************
/** Main Display
/******************************************************************************************/
else
{
	/*******************************************************************************************
	/** Update groups area
	/******************************************************************************************/
	if (isset($_POST['update_groups']))
	{
		$sql = 'SELECT * FROM ' . COLOR_GROUPS_TABLE;
		if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
		//We have to loop through all the color sets, each group_id
		while ($row = $db->sql_fetchrow($result))
		{
			$color_key = 'color_change_' . (int) $row['group_id'];
			$new_color = isset($_POST[$color_key]) && is_scalar($_POST[$color_key]) ? trim((string) $_POST[$color_key]) : $row['group_color'];
			if ($new_color != $row['group_color'])
			{
				if (!check_font_color_nivisec($new_color))
				{
					$status_message .= $lang['Error_Font_Color'];
					continue;
				}
				$sql = 'UPDATE ' . COLOR_GROUPS_TABLE . "
					SET group_color = '" . $db->sql_escape($new_color) . "'
					WHERE group_id = " . (int) $row['group_id'];
				if (!$db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
				$status_message .= sprintf($lang['Group_Updated'], htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8'));
			}
		}
	}
	
	/*******************************************************************************************
	/** Update group list
	/******************************************************************************************/
	if (isset($_POST['update_group_list']))
	{
		$real_group_list = isset($_POST['real_group_list']) ? $_POST['real_group_list'] : '';
		$real_user_list = isset($_POST['real_user_list']) ? $_POST['real_user_list'] : '';
		$posted_group_id = isset($_POST['group_id']) ? $_POST['group_id'] : 0;
		color_groups_update_group_id($real_group_list, $real_user_list, $posted_group_id);
	}
	
	/*******************************************************************************************
	/** Hide/Unhide a group
	/******************************************************************************************/
	if ($found_hide)
	{
		if (hide_toggle_color_group($group_id_hide, 'hide'))
		{
			$status_message .= $lang['Group_Hidden'];
		}
	}
	if ($found_unhide)
	{
		if (hide_toggle_color_group($group_id_unhide, 'unhide'))
		{
			$status_message .= $lang['Group_Unhidden'];
		}
	}
	
	/*******************************************************************************************
	/** Delete a group
	/******************************************************************************************/
	if ($found_delete)
	{
		$group_id_delete = (int) $group_id_delete;
		if ($group_id_delete <= 0)
		{
			message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
		}
		$sql = 'DELETE FROM ' . COLOR_GROUPS_TABLE . "
			WHERE group_id = $group_id_delete";
		if (!$db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
		$sql = 'UPDATE ' . USERS_TABLE . "
			SET user_color_group = 0
			WHERE user_color_group = $group_id_delete";
		if (!$db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Users_Table'], '', __LINE__, __FILE__, $sql);
		$status_message .= $lang['Deleted_Group'];
	}
	
	/*******************************************************************************************
	/** Add a group
	/******************************************************************************************/
	if (isset($_POST['add_new_group']))
	{
		$new_group_name = isset($_POST['new_group_name']) && is_scalar($_POST['new_group_name']) ? trim((string) $_POST['new_group_name']) : '';
		$new_group_color = isset($_POST['new_group_color']) && is_scalar($_POST['new_group_color']) ? trim((string) $_POST['new_group_color']) : '';
		$new_group_name = substr($new_group_name, 0, 255);
		$invalid_add = false;
		if ($new_group_name === '' || !check_font_color_nivisec($new_group_color)) $invalid_add = true;
		else
		{
			//Check for duplicate name
			$sql = 'SELECT group_name FROM ' . COLOR_GROUPS_TABLE . "
			WHERE group_name = '" . $db->sql_escape($new_group_name) . "'";
			if (!$result = $db->sql_query($sql)) message_die(GENERAL_ERROR, $lang['Error_Group_Table'], '', __LINE__, __FILE__, $sql);
			$group_row = $db->sql_fetchrow($result);
			if ($group_row && $group_row['group_name'] == $new_group_name) $invalid_add = true;
		}
		// Don't try to add it if it is invalid!
		if ($invalid_add)
		{
			$status_message .= sprintf($lang['Invalid_Group_Add'], htmlspecialchars($new_group_name, ENT_QUOTES, 'UTF-8'));
		}
		else
		{
			//Insert it
			do_fast_query_nivisec(
			'INSERT INTO ' . COLOR_GROUPS_TABLE . "
			 	(order_num, group_name, group_color) VALUES 
				(" . (int) $next_order_num . ", '" . $db->sql_escape($new_group_name) . "', '" . $db->sql_escape($new_group_color) . "')",
			
			$lang['Error_Group_Table']
			);
			$next_order_num++;
		}
		
		
	}
	if ($found_move)
	{
		if (swap_color_group_order_num($group_id_move, $group_id_swap))
		{
			$status_message .= $lang['Moved_Group'];
		}
	}
	
	$page_title = $lang['Manage_Color_Groups'];
	$page_desc = $lang['Manage_Color_Groups_Desc'];
	$template->set_filenames(array('body' => 'admin/color_groups_manager.tpl'));
	//Make color name sample
	$color_html = $lang['Color_List'];
	foreach(explode(",", RGB_COLOR_LIST) as $val)
	{
		$val = trim($val);
		$color_html .= "&nbsp;&nbsp;<font color=\"$val\">$val</font>";
	}
	
	//Setup Group Lists
	do_query_nivisec(
	'SELECT * FROM ' . COLOR_GROUPS_TABLE . '
		ORDER BY order_num ASC',
	$result_list,
	$lang['Error_Group_Table']
	);
	for ($i = 0; $i < count($result_list); $i++)
	{
		$empty = false;
		$row_group_id = (int) $result_list[$i]['group_id'];
		$row_color = check_font_color_nivisec($result_list[$i]['group_color']) ? $result_list[$i]['group_color'] : '';
		$move_up = '';
		$move_down = '';
		if ($result_list[$i]['order_num'] > $order_num_min && isset($result_list[$i - 1]))
		{
			$move_up = '<input type="submit" class="liteoption" name="move_group_' . $row_group_id . '_' . (int) $result_list[$i - 1]['group_id'] . '" value="' . htmlspecialchars($lang['Move_Up'], ENT_QUOTES, 'UTF-8') . '" />';
		}
		if ($result_list[$i]['order_num'] < $order_num_max && isset($result_list[$i + 1]))
		{
			$move_down = '<input type="submit" class="liteoption" name="move_group_' . $row_group_id . '_' . (int) $result_list[$i + 1]['group_id'] . '" value="' . htmlspecialchars($lang['Move_Down'], ENT_QUOTES, 'UTF-8') . '" />';
		}
		$template->assign_block_vars('grouprow', array(
		'ID' => $row_group_id,
		'HIDE' => ($result_list[$i]['hidden']) ? 'unhide_group_'.$row_group_id : 'hide_group_'.$row_group_id,
		'L_HIDE' => ($result_list[$i]['hidden']) ? $lang['Un-hide'] : $lang['Hide'],
		'MOVE_UP' => $move_up,
		'MOVE_DOWN' => $move_down,
		'NAME' => htmlspecialchars($result_list[$i]['group_name'], ENT_QUOTES, 'UTF-8'),
		'COUNT' => count_users_in_color_group($row_group_id),
		'COLOR' => htmlspecialchars($row_color, ENT_QUOTES, 'UTF-8'),
		'STATUS' => ($row_color !== '') ? $lang['Color_Ok'] : $lang['Error_Font_Color']
		));
	}
	
	if(!isset($empty))
	{
		$template->assign_block_vars('emptyswitch', array());
	}

	@unlink($phpbb_root_path . 'cache/cg_users.cache');
	
}
//Common Variables
$template->assign_vars(array(
'S_ACTION' => append_sid(basename(__FILE__)),
'S_FORM_TOKEN' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
'S_MODE' => $mode,
'L_USERS_LIST' => $lang['Users_List'],
'L_GROUPS_LIST' => $lang['Groups_List'],
'L_LIST_INFO' => $lang['List_Info'],
'HTML_COLOR_LIST' => $color_html,
'L_ADD_NEW_GROUP' => $lang['Add_New_Group'],
'L_COLOR' => $lang['Color'],
'L_USER_COUNT' => $lang['User_Count'],
'L_STATUS' => $lang['Status'],
'L_GROUP_NAME' => $lang['Group_Name'],
'L_NO_GROUPS' => $lang['No_Groups_Exist'],
'L_UPDATE' => $lang['Update'],
'L_SUBMIT' => $lang['Submit'],
'L_RESET' => $lang['Reset'],
'L_EXAMPLE' => $lang['Example'],
'L_DEFINE_USERS' => $lang['Define_Users'],
'L_DELETE' => $lang['Delete'],
'L_COLORS' => isset($lang['Colors']) ? $lang['Colors'] : $lang['Color'],
'L_USER_LEVELS' => isset($lang['User_Levels']) ? $lang['User_Levels'] : $lang['User_Level'],
'L_USER_LIST' => $lang['User_List'],
'L_ADD' => $lang['Add_Arrow'],
'L_VERSION' => $lang['Version'],
'L_PAGE_NAME' => $page_title,
'L_PAGE_DESC' => $page_desc,
'L_MOVE_UP' => $lang['Move_Up'],
'L_MOVE_DOWN' => $lang['Move_Down'],
'L_NO_GROUP_LIST' => $lang['Unassigned_User_List'],
'L_GROUPED_LIST' => $lang['Assigned_User_List'],
'VERSION' => MOD_VERSION,
));

if (!empty($status_message))
{
	$template->assign_block_vars('statusrow', array());
	$template->assign_vars(array(
	'L_STATUS' => $lang['Status'],
	'STATUS_TIME' => create_date($board_config['default_dateformat'], time(), $board_config['board_timezone']),
	'I_STATUS_MESSAGE' => $status_message)
	);
}
/************************************************************************
** Begin The Version Check Feature
************************************************************************/
$page_title = $lang['Color_Groups'];
if (file_exists($phpbb_root_path.'nivisec_version_check.'.$phpEx) && !DISABLE_VERSION_CHECK)
{
	include($phpbb_root_path.'nivisec_version_check.'.$phpEx);
}
/************************************************************************
** End The Version Check Feature
************************************************************************/

$template->pparse('body');
copyright_nivisec($lang['Color_Groups'], '2003');
include('page_footer_admin.'.$phpEx);

?>
