<?php

/***************************************************************************
 *                               xs_styles.php
 *                               -------------
 *   copyright            : (C) 2003 - 2005 CyberAlien
 *   support              : http://www.phpbbstyles.com
 *
 *   version              : 2.3.1
 *
 *   file revision        : 72
 *   project revision     : 78
 *   last modified        : 05 Dec 2005  13:54:54
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
$phpbb_root_path = "./../";
$no_page_header = true;
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

// check if mod is installed
if(empty($template->xs_version) || $template->xs_version !== 8)
{
	message_die(GENERAL_ERROR, isset($lang['xs_error_not_installed']) ? $lang['xs_error_not_installed'] : 'eXtreme Styles mod is not installed. You forgot to upload includes/template.php');
}

if (!defined('IN_XS')) { define('IN_XS', true); }
include_once('xs_include.' . $phpEx);

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_styles.'.$phpEx) . '">' . $lang['xs_default_style'] . '</a>'));

require_once $phpbb_root_path . 'includes/functions_style_actions.' . $phpEx;

if (isset($HTTP_POST_VARS['style_action']) && !defined('DEMO_MODE'))
{
	phpbb_admin_require_post_session();
	try { phpbb_style_action_save($db, $HTTP_POST_VARS); }
	catch (Exception $error) { xs_error($lang['xs_actions_save_failed']); }
	catch (Error $error) { xs_error($lang['xs_actions_save_failed']); }
}

//
// get list of installed styles
//
$sql = 'SELECT themes_id, template_name, style_name FROM ' . THEMES_TABLE . ' ORDER BY template_name';
if(defined('XS_MODS_ADMIN_TEMPLATES'))
{
	$sql = str_replace(', style_name', ', style_name, theme_public', $sql);
}
if(!$result = $db->sql_query($sql))
{
	xs_error($lang['xs_no_style_info'], __LINE__, __FILE__);
}
$style_rowset = $db->sql_fetchrowset($result);
$db->sql_freeresult($result);

$style_override = $board_config['override_user_style'];
$style_default = $board_config['default_style'];
$num_users = 0;
$style_ids = array();

for($i=0; $i<count($style_rowset); $i++)
{
	$id = $style_rowset[$i]['themes_id'];
	$style_ids[] = $id;
	$sql = 'SELECT count(user_id) as total FROM ' . USERS_TABLE . ' WHERE user_style = ' . $id;
	$result = $db->sql_query($sql);
	if(!$result)
	{
		$total = 0;
	}
	else
	{
		$total = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$total = $total['total'];
		$num_users += $total;
	}

	$row_class = $xs_row_class[$i % 2];
	$template->assign_block_vars('styles', array(
		'ROW_CLASS'			=> $row_class,
		'STYLE'				=> htmlspecialchars($style_rowset[$i]['style_name'], ENT_QUOTES, 'UTF-8'),
		'TEMPLATE'			=> htmlspecialchars($style_rowset[$i]['template_name'], ENT_QUOTES, 'UTF-8'),
		'ID'				=> $id,
		'TOTAL'				=> $total,
		'U_TOTAL'			=> append_sid('xs_styles.' . $phpEx . '?list=' . $id),
		'DEFAULT_ACTION'	=> 'default:' . (int) $id,
		'OVERRIDE_ACTION'	=> 'override:' . ($style_override ? '0' : '1'),
		'SWITCH_ALL_ACTION' => 'moveusers:' . (int) $id,
		'MOVE_AWAY_ACTION' => 'moveaway:' . (int) $id,
		)
	);
	if($total > 0)
	{
		$template->assign_block_vars('styles.users', array());
	}
	if($id == $style_default)
	{
		$template->assign_block_vars('styles.default', array());
		if($style_override)
		{
			$template->assign_block_vars('styles.default.override', array());
		}
		else
		{
			$template->assign_block_vars('styles.default.nooverride', array());
		}
	}
	else
	{
		$template->assign_block_vars('styles.nodefault', array());
		if(defined('XS_MODS_ADMIN_TEMPLATES'))
		{
			if($style_rowset[$i]['theme_public'])
			{
				$template->assign_block_vars('styles.nodefault.admin_only', array(
					'ADMIN_ACTION'	=> 'admin:' . (int) $id . ':0'
				));
			}
			else
			{
				$template->assign_block_vars('styles.nodefault.public', array(
					'ADMIN_ACTION'	=> 'admin:' . (int) $id . ':1'
				));
			}
		}
	}
	if($total)
	{
		$template->assign_block_vars('styles.total', array());
	}
	else
	{
		$template->assign_block_vars('styles.none', array());
	}
}

// get number of users using default style
$num_default = 0;
$sql = 'SELECT count(user_id) as total FROM ' . USERS_TABLE . ' WHERE user_style IS NULL';
$result = $db->sql_query($sql);
if($result)
{
	$total = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$num_default = $total['total'];
	$num_users += $num_default;
}

// get number of users
$sql = 'SELECT count(user_id) as total FROM ' . USERS_TABLE;
$result = $db->sql_query($sql);
if(!$result)
{
	$total_users = 0;
}
else
{
	$total = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$total_users = $total['total'];
}

if($total_users > $num_users)
{
	// Invalid historic style IDs already fall back to the default style. Do not
	// rewrite user records merely by displaying this administration page.
	$num_default += $total_users - $num_users;
}

$template->assign_vars(array(
	'U_SCRIPT'		=> append_sid('xs_styles.' . $phpEx),
	'NUM_DEFAULT'	=> $num_default,
	'L_XS_MOVE_CONFIRM' => htmlspecialchars(addslashes($lang['xs_styles_move_confirm']), ENT_QUOTES, 'UTF-8')
	)
);

//
// get list of users
//
if(isset($HTTP_GET_VARS['list']) && is_scalar($HTTP_GET_VARS['list']))
{
	$id = intval($HTTP_GET_VARS['list']);
	$template->assign_block_vars('list_users', array());
	$sql = "SELECT user_id, username FROM " . USERS_TABLE . " WHERE user_style='{$id}' ORDER BY username ASC";
	if(!$result = $db->sql_query($sql))
	{
		xs_error('Could not get users list!', __LINE__, __FILE__);
	}
	$rowset = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	for($i=0; $i<count($rowset); $i++)
	{
		$template->assign_block_vars('list_users.user', array(
			'NUM'		=> $i + 1,
			'ID'		=> $rowset[$i]['user_id'],
			'NAME'		=> htmlspecialchars($rowset[$i]['username'], ENT_QUOTES, 'UTF-8'),
			)
		);
	}
}

$template->set_filenames(array('body' => XS_TPL_PATH . 'styles.tpl'));
$template->pparse('body');
xs_exit();

?>
