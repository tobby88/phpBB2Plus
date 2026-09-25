<?php

/***************************************************************************
 *                              xs_install.php
 *                              --------------
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

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_install.'.$phpEx) . '">' . $lang['xs_install_styles'] . '</a>'));

$lang['xs_install_back'] = str_replace('{URL}', append_sid('xs_install.'.$phpEx), $lang['xs_install_back']);
$lang['xs_goto_default'] = str_replace('{URL}', append_sid('xs_styles.'.$phpEx), $lang['xs_goto_default']);

// remove timeout. useful for forum with 100+ styles
@set_time_limit(XS_MAX_TIMEOUT);

// Validate and commit the complete selection together, not one style at a time.
if ((isset($HTTP_POST_VARS['install_one']) || isset($HTTP_POST_VARS['total'])) && !defined('DEMO_MODE'))
{
	phpbb_admin_require_post_session();
	require_once($phpbb_root_path . 'includes/functions_style_install.' . $phpEx);
	try { $installed_ids = phpbb_style_install($db, $HTTP_POST_VARS); }
	catch (PhpbbAclException $error) { xs_error($error->getMessage() . '<br /><br />' . $lang['xs_install_back']); }
	catch (Exception $error) { xs_error($lang['xs_install_error'] . '<br /><br />' . $lang['xs_install_back']); }
	catch (Error $error) { xs_error($lang['xs_install_error'] . '<br /><br />' . $lang['xs_install_back']); }
	if ($installed_ids) { xs_message($lang['Information'], $lang['xs_install_installed'] . '<br /><br />' . $lang['xs_install_back'] . '<br /><br />' . $lang['xs_goto_default']); }
}


// get all installed styles
$sql = 'SELECT themes_id, template_name, style_name FROM ' . THEMES_TABLE . ' ORDER BY template_name';
if(!$result = $db->sql_query($sql))
{
	xs_error($lang['xs_no_style_info'], __LINE__, __FILE__);
}
$style_rowset = $db->sql_fetchrowset($result);

// find all styles to install
$res = @opendir('../templates/');
$styles = array();
while($res && ($file = readdir($res)) !== false)
{
	if($file !== '.' && $file !== '..' && !is_link('../templates/' . $file) && @file_exists('../templates/'.$file.'/theme_info.cfg') && @file_exists('../templates/'.$file.'/'.$file.'.cfg'))
	{
		$arr = xs_get_themeinfo($file);
		for($i=0; $i<count($arr); $i++)
		{
			if(isset($arr[$i]['template_name']) && $arr[$i]['template_name'] === $file)
			{
				$arr[$i]['num'] = $i;
				$style = $arr[$i]['style_name'];
				$found = false;
				for($j=0; $j<count($style_rowset); $j++)
				{
					if($style_rowset[$j]['style_name'] == $style)
					{
						$found = true;
					}
				}
				if(!$found)
				{
					$styles[$arr[$i]['style_name']] = $arr[$i];
				}
			}
		}
	}
}
if($res)
{
	closedir($res);
}

if(!count($styles))
{
	xs_message($lang['Information'], $lang['xs_install_none'] . '<br /><br />' . $lang['xs_goto_default']);
}

ksort($styles);

$j = 0;
foreach($styles as $var => $value)
{
	$row_class = $xs_row_class[$j % 2];
	$template->assign_block_vars('styles', array(
			'ROW_CLASS'	=> $row_class,
			'STYLE'		=> htmlspecialchars($value['template_name'], ENT_QUOTES, 'UTF-8'),
			'THEME'		=> htmlspecialchars($value['style_name'], ENT_QUOTES, 'UTF-8'),
			'INSTALL_ACTION' => htmlspecialchars($value['template_name'] . ':' . (int) $value['num'], ENT_QUOTES, 'UTF-8'),
			'CB_NAME'	=> 'install_'.$j,
			'NUM'		=> $value['num'],
		)
	);
	$j++;
}

$template->assign_vars(array(
	'U_ACTION'		=> append_sid('xs_install.'.$phpEx),
	'TOTAL'			=> count($styles)
	));

$template->set_filenames(array('body' => XS_TPL_PATH . 'install.tpl'));
$template->pparse('body');
xs_exit();

?>
