<?php
/***************************************************************************
*                    $RCSfile: admin_hacks_list.php,v $
*                            -------------------
*   copyright            : (C) 2003 Nivisec.com
*   email                : support@nivisec.com
*
*   $Id: admin_hacks_list.php,v 1.4 2003/07/10 16:50:23 nivisec Exp $
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

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('MOD_VERSION')) { define('MOD_VERSION', '1.20'); }
/* If for some reason you need to disable the version check in THIS HACK ONLY,
change the blow to TRUE instead of FALSE.  No other hacks will be affected
by this change.
*/
define('DISABLE_VERSION_CHECK', FALSE);

if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['General']['Hacks_List'] = $filename;
	
	return;
}

$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include_once($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_hacks_list.' . $phpEx);
include($phpbb_root_path.'includes/functions_hacks_list.'.$phpEx);

/****************************************************************************
/** Constants and Main Vars.
/***************************************************************************/
$page_title = $lang['Hacks_List'];
$required_fields = array('hack_name', 'hack_desc', 'hack_author');
$dbase_fields = array('hack_download_url', 'hack_hide', 'hack_name', 'hack_desc', 'hack_author', 'hack_author_email', 'hack_author_website', 'hack_version');
$status_message = '';
$update_sql = '';
$insert_sql = '';
$insert_val_sql = '';

function admin_hacks_scalar($source, $key, $default = '')
{
	return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : $default;
}

function admin_hacks_limit($value, $length)
{
	if (function_exists('mb_substr'))
	{
		return mb_substr($value, 0, $length, 'UTF-8');
	}
	return substr($value, 0, $length);
}

function admin_hacks_form_values()
{
	$limits = array(
		'hack_download_url' => 2048, 'hack_name' => 191,
		'hack_desc' => 255, 'hack_author' => 255,
		'hack_author_email' => 255, 'hack_author_website' => 255,
		'hack_version' => 32
	);
	$values = array();
	foreach ($limits as $field => $limit)
	{
		$values[$field] = admin_hacks_limit(admin_hacks_scalar($_POST, $field), $limit);
	}
	$values['hack_hide'] = admin_hacks_scalar($_POST, 'hack_hide') === 'Yes' ? 'Yes' : 'No';
	return $values;
}

function admin_hacks_http_url($value)
{
	$parts = $value !== '' ? @parse_url($value) : false;
	if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
	{
		return '';
	}
	$scheme = strtolower($parts['scheme']);
	return ($scheme === 'http' || $scheme === 'https') ? $value : '';
}

/*******************************************************************************************
/** Get parameters.  'var_name' => 'default'
/******************************************************************************************/
$params = array('mode' => '', 'hack_id' => '');

$mode = admin_hacks_scalar($_POST, 'mode', admin_hacks_scalar($_GET, 'mode'));
$mode = in_array($mode, array('', 'display', 'add', 'edit'), true) ? $mode : '';
$hack_id = max(0, intval(admin_hacks_scalar($_POST, 'hack_id', admin_hacks_scalar($_GET, 'hack_id'))));

$actions = array();
foreach (array_keys($_POST) as $key)
{
	if (preg_match('/^(delete|update|add)_id_([0-9]+)$/D', $key, $match))
	{
		$actions[] = array($match[1], intval($match[2]));
	}
}

if (!empty($actions))
{
	phpbb_admin_require_post_session();
}

foreach ($actions as $action)
{
	$action_name = $action[0];
	$action_id = $action[1];
	if ($action_name === 'delete' && $action_id > 0)
	{
		$sql = 'SELECT hack_name FROM ' . HACKS_LIST_TABLE . ' WHERE hack_id = ' . $action_id;
		if (!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		$row = $db->sql_fetchrow($result);
		if (!$row)
		{
			continue;
		}

		$neat_bc_name = str_replace(' ', '_', $row['hack_name']) . '_list_info';
		$sql = "DELETE FROM " . CONFIG_TABLE . " WHERE config_name = '" . $db->sql_escape($neat_bc_name) . "'";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		$sql = 'DELETE FROM ' . HACKS_LIST_TABLE . ' WHERE hack_id = ' . $action_id;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		$status_message .= sprintf($lang['Deleted_Hack'], phpbb_admin_html($row['hack_name']));
		continue;
	}

	if ($action_name !== 'add' && ($action_name !== 'update' || $action_id < 1))
	{
		continue;
	}
	$values = admin_hacks_form_values();
	foreach ($required_fields as $required_field)
	{
		if ($values[$required_field] === '')
		{
			message_die(GENERAL_ERROR, $lang['Required_Field_Missing'], '', __LINE__, __FILE__);
		}
	}
	$assignments = array();
	foreach ($dbase_fields as $field)
	{
		$assignments[] = $field . " = '" . $db->sql_escape($values[$field]) . "'";
	}
	if ($action_name === 'update')
	{
		$sql = 'UPDATE ' . HACKS_LIST_TABLE . ' SET ' . implode(', ', $assignments) . ' WHERE hack_id = ' . $action_id;
	}
	else
	{
		$assignments[] = 'hack_add_date = ' . time();
		$sql = 'INSERT INTO ' . HACKS_LIST_TABLE . ' SET ' . implode(', ', $assignments);
	}
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
	}
	$status_message .= sprintf($action_name === 'update' ? $lang['Updated_Hack'] : $lang['Added_Hack'], phpbb_admin_html($values['hack_name']));
}
/*******************************************************************************************
/** Parse for modes...Two seperate pages (add + edit, display list)
/******************************************************************************************/
setup_hacks_list_array();
scan_hl_files();
switch($mode)
{
	case 'edit':
	{
		if ($hack_id < 1)
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table']);
		}
		/* Fetch the data for the specified ID in edit mode, then do the same thing as add */
		$sql = 'SELECT * FROM ' . HACKS_LIST_TABLE . "
	WHERE hack_id = $hack_id";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		$row = $db->sql_fetchrow($result);
		if (!$row)
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table']);
		}
		
		$template->assign_vars(array(
		'S_HACK_ID' => intval($row['hack_id']),
		'S_HIDDEN' => 'update_id_' . $row['hack_id'],
		'S_HACK_NAME' => phpbb_admin_html($row['hack_name']),
		'S_HACK_DESC' => phpbb_admin_html($row['hack_desc']),
		'S_HACK_DOWNLOAD' => phpbb_admin_html($row['hack_download_url']),
		'S_HACK_AUTHOR' => phpbb_admin_html($row['hack_author']),
		'S_HACK_AUTHOR_EMAIL' => phpbb_admin_html($row['hack_author_email']),
		'S_HACK_WEBSITE' => phpbb_admin_html($row['hack_author_website']),
		'S_HACK_HIDE_NO' => ($row['hack_hide'] == 'No') ? 'checked="checked"' : '',
		'S_HACK_HIDE_YES' => ($row['hack_hide'] == 'Yes') ? 'checked="checked"' : '',
		'S_HACK_VERSION' => phpbb_admin_html($row['hack_version'])));
		
	}
	case 'add':
	{
		if ($mode != 'edit')
		{
			$template->assign_vars(array(
			'S_HACK_ID' => 0,
			'S_HIDDEN' => 'add_id_0',
			'S_HACK_HIDE_NO' => 'checked="checked"'));
		}
		
		$template->set_filenames(array('body' => 'admin/admin_hacks_list_add.tpl'));
		break;
	}
	case 'display':
	default:
	{
		$template->set_filenames(array('body' => 'admin/admin_hacks_list_display.tpl'));
		$sql = 'SELECT * FROM ' . HACKS_LIST_TABLE . "
	ORDER BY hack_name ASC";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['Error_Hacks_List_Table'], '', __LINE__, __FILE__, $sql);
		}
		
		$i = 0;
		while ($row = $db->sql_fetchrow($result))
		{
			$author = phpbb_admin_html($row['hack_author']);
			$email = filter_var($row['hack_author_email'], FILTER_VALIDATE_EMAIL) ? $row['hack_author_email'] : '';
			if ($email !== '')
			{
				$author = '<a href="mailto:' . phpbb_admin_html($email) . '">' . $author . '</a>';
			}
			$website = admin_hacks_http_url($row['hack_author_website']);
			$website_link = $website !== '' ? '<a href="' . phpbb_admin_html($website) . '" rel="noopener noreferrer">' . phpbb_admin_html($website) . '</a>' : $lang['No_Website'];
			$name = phpbb_admin_html($row['hack_name']);
			$download = admin_hacks_http_url($row['hack_download_url']);
			if ($download !== '')
			{
				$name = '<a href="' . phpbb_admin_html($download) . '" rel="noopener noreferrer">' . $name . '</a>';
			}
			$template->assign_block_vars('listrow', array(
			'ROW_CLASS' => (!(++$i% 2)) ? $theme['td_class1'] : $theme['td_class2'],
			'HACK_ID' => intval($row['hack_id']),
			'HACK_AUTHOR' => $author,
			'HACK_WEBSITE' => $website_link,
			'HACK_NAME' => $name,
			'HACK_DESC' => phpbb_admin_html($row['hack_desc']),
			'HACK_VERSION' => ($row['hack_version'] != '') ? ' v' . phpbb_admin_html($row['hack_version']) : '',
			'S_ACTION_EDIT' => '<a href="' . append_sid(basename(__FILE__) . '?mode=edit&hack_id=' . intval($row['hack_id'])) . '">' . $lang['Edit'] . '</a>',
			'HACK_DISPLAY' => isset($lang[$row['hack_hide']]) ? $lang[$row['hack_hide']] : $lang['No'],
			'ADD_DATE' => !empty($row['hack_add_date']) ? create_date($lang['DATE_FORMAT'], $row['hack_add_date'], $board_config['board_timezone']) : ''));
		}
		
		if ($i == 0 || !isset($i))
		{
			$template->assign_block_vars('empty_switch', array());
			$template->assign_var('L_NO_HACKS', $lang['No_Hacks']);
		}
	}
}


$template->assign_vars(array(
'L_VERSION' => $lang['Version'],
'VERSION' => MOD_VERSION,
'L_PAGE_NAME' => $page_title,
'S_ACTION_ADD' => '<a href="' . append_sid(basename(__FILE__) . '?mode=add') . '">' . $lang['Add_New_Hack'] . '</a>',

'S_MODE_ACTION' => append_sid(basename(__FILE__)),
'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),
'L_EDIT' => $lang['Edit'],
'L_DELETE' => $lang['Delete'],
'L_ADD_NEW_HACK' => $lang['Add_New_Hack'],
'L_AUTHOR' => $lang['Author'],
'L_DESCRIPTION' => $lang['Description'],
'L_SUBMIT' => $lang['Submit'],
'L_RESET' => $lang['Reset'],
'L_HACK_NAME' => $lang['Hack_Name'],
'L_AUTHOR_EMAIL' => $lang['Author_Email'],
'L_REQUIRED' => $lang['Required'],
'L_WEBSITE' => $lang['Website'],
'L_DOWNLOAD_URL' => $lang['Download_URL'],
'L_YES' => $lang['Yes'],
'L_NO' => $lang['No'],
'L_VERSION' => $lang['Version'],
'L_USER_VIEWABLE' => $lang['User_Viewable'],
'L_PAGE_DESC' => $lang['Page_Desc']));

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
	if (!defined('MOD_CODE')) { define('MOD_CODE', 17); }
	include($phpbb_root_path.'nivisec_version_check.'.$phpEx);
}
/************************************************************************
** End The Version Check Feature
************************************************************************/

$template->pparse('body');
copyright_nivisec($lang['Hacks_List'], '2003');
include('page_footer_admin.'.$phpEx);

?>
