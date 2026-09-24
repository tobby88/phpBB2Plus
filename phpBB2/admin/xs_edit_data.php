<?php

/***************************************************************************
 *                              xs_edit_data.php
 *                              ----------------
 *   copyright            : (C) 2003 - 2005 CyberAlien
 *   support              : http://www.phpbbstyles.com
 *
 *   version              : 2.3.1
 *
 *   file revision        : 78
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

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_edit_data.'.$phpEx) . '">' . $lang['xs_edit_styles_data'] . '</a>'));

$lang['xs_edittpl_back_list'] = str_replace('{URL}', append_sid('xs_edit_data.'.$phpEx), $lang['xs_edittpl_back_list']);

require_once $phpbb_root_path . 'includes/functions_style_data.' . $phpEx;

//
// submit
//
if(!empty($HTTP_POST_VARS['edit']) && !defined('DEMO_MODE'))
{
	phpbb_admin_require_post_session();
	$id = is_scalar($HTTP_POST_VARS['edit']) ? intval($HTTP_POST_VARS['edit']) : 0;
	$lang['xs_edittpl_back_edit'] = str_replace('{URL}', append_sid('xs_edit_data.'.$phpEx.'?edit='.$id), $lang['xs_edittpl_back_edit']);
	try { phpbb_style_data_save($db, $HTTP_POST_VARS); }
	catch (Exception $error)
	{
		xs_error($lang['xs_data_save_failed'] . '<br /><br />' . $lang['xs_edittpl_back_edit'] . '<br /><br />' . $lang['xs_edittpl_back_list']);
	}
	catch (Error $error)
	{
		xs_error($lang['xs_data_save_failed'] . '<br /><br />' . $lang['xs_edittpl_back_edit'] . '<br /><br />' . $lang['xs_edittpl_back_list']);
	}
	xs_message($lang['Information'], $lang['xs_edittpl_style_updated'] . '<br /><br />' . $lang['xs_edittpl_back_edit'] . '<br /><br />' . $lang['xs_edittpl_back_list']);
}

//
// edit style
//
if(!empty($HTTP_GET_VARS['edit']) && is_scalar($HTTP_GET_VARS['edit']))
{
	$id = intval($HTTP_GET_VARS['edit']);
	$sql = "SELECT * FROM " . THEMES_TABLE . " WHERE themes_id = $id";
	if(!$result = $db->sql_query($sql))
	{
		xs_error($lang['xs_no_style_info'], __LINE__, __FILE__);
	}
	$item = $db->sql_fetchrow($result);
	if(empty($item['themes_id']))
	{
		xs_error($lang['xs_invalid_style_id'] . '<br /><br />' . $lang['xs_edittpl_back_list']);
	}
	$sql = "SELECT * FROM " . THEMES_NAME_TABLE . " WHERE themes_id = $id";
	if(!($result = $db->sql_query($sql)))
	{
		$item_name = array();
	}
	else
	{
		$item_name = $db->sql_fetchrow($result);
	}
	if($item_name === false || !@count($item_name))
	{
		$item_name = xs_empty_name();
	}
	$vars = xs_get_vars($item);
	// show variables
	$template->assign_vars(array(
		'U_ACTION'	=> append_sid('xs_edit_data.'.$phpEx),
		'TPL'		=> htmlspecialchars($item['template_name'], ENT_QUOTES, 'UTF-8'),
		'STYLE'		=> htmlspecialchars($item['style_name'], ENT_QUOTES, 'UTF-8'),
		'ID'		=> $id
		)
	);
	// all variables
	$i = 0;
	foreach($vars as $var => $value)
	{
		$row_class = $xs_row_class[$i % 2];
		$i++;
		if(isset($lang['xs_data_'.$var]))
		{
			$text = $lang['xs_data_'.$var];
		}
		else
		{
           $str = substr($var, 0, strlen($var) - 1);  
           $str_fc = substr($var, 0, strlen($var) - 2);  
           if(isset($lang['xs_data_'.$str_fc]))  
           {  
               $str1 = substr($var, strlen($var) - 2);  
               $text = sprintf($lang['xs_data_'.$str_fc], $str1);  
           }  
           else if(isset($lang['xs_data_'.$str]))  
           {  
               $str1 = substr($var, strlen($var) - 1);  
               $text = sprintf($lang['xs_data_'.$str], $str1);  
           }  
           else  
           {  
               $text = sprintf($lang['xs_data_unknown'], $var);  
           }  
		}
		$template->assign_block_vars('row', array(
			'ROW_CLASS'	=> $row_class,
			'VAR'	=> $var,
			'VALUE'	=> isset($item[$var]) ? htmlspecialchars($item[$var], ENT_QUOTES, 'UTF-8') : '',
			'LEN'	=> $value['len'],
			'SIZE'	=> $value['len'] < 10 ? 10 : 30,
			'TEXT'	=> htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
			'EXPLAIN' => isset($lang['xs_data_' . $var . '_explain']) ? $lang['xs_data_' . $var . '_explain'] : '',
			)
		);
		if($value['color'])
		{
			$template->assign_block_vars('row.color', array());
		}
		if($value['font'])
		{
			$template->assign_block_vars('row.font', array());
		}
		if(isset($item_name[$var.'_name']))
		{
			$template->assign_block_vars('row.name', array(
				'DATA'	=> htmlspecialchars($item_name[$var.'_name'], ENT_QUOTES, 'UTF-8')
				)
			);
		}
		else
		{
			$template->assign_block_vars('row.noname', array());
		}
	}
	$template->set_filenames(array('body' => XS_TPL_PATH . 'edit_data.tpl'));
	$template->pparse('body');
	xs_exit();
}


//
// show list of installed styles
//
$sql = 'SELECT themes_id, template_name, style_name FROM ' . THEMES_TABLE . ' ORDER BY style_name';
if(!$result = $db->sql_query($sql))
{
	xs_error($lang['xs_no_style_info'], __LINE__, __FILE__);
}
$style_rowset = $db->sql_fetchrowset($result);

$template->set_filenames(array('body' => XS_TPL_PATH . 'edit_data_list.tpl'));
for($i=0; $i<count($style_rowset); $i++)
{
	$item = $style_rowset[$i];
	$row_class = $xs_row_class[$i % 2];
	$template->assign_block_vars('styles', array(
		'ROW_CLASS'		=> $row_class,
		'TPL'			=> htmlspecialchars($item['template_name'], ENT_QUOTES, 'UTF-8'),
		'STYLE'			=> htmlspecialchars($item['style_name'], ENT_QUOTES, 'UTF-8'),
		'U_EDIT'		=> append_sid('xs_edit_data.'.$phpEx.'?edit='.$item['themes_id'])
		)
	);
}

$template->pparse('body');
xs_exit();

?>
