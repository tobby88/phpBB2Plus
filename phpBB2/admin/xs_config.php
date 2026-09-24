<?php

/***************************************************************************
 *                               xs_config.php
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

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_config.'.$phpEx) . '">' . $lang['xs_configuration'] . '</a>'));

$lang['xs_config_updated_explain'] = str_replace('{URL}', append_sid('xs_config.'.$phpEx), $lang['xs_config_updated_explain']);
$lang['xs_config_title'] = str_replace('{VERSION}', $template->xs_versiontxt, $lang['xs_config_title']);
$lang['xs_config_warning_explain'] = str_replace('{URL}', append_sid('xs_chmod.'.$phpEx), $lang['xs_config_warning_explain']);
$lang['xs_config_back'] = str_replace('{URL}', append_sid('xs_config.'.$phpEx), $lang['xs_config_back']);

//
// Updating configuration
//
if(isset($HTTP_POST_VARS['submit']) && !defined('DEMO_MODE'))
{
	phpbb_admin_require_post_session();
	require_once $phpbb_root_path . 'includes/functions_xs_config.' . $phpEx;
	$old_navigation = $board_config['xs_shownav'];
	try { phpbb_xs_config_save($db, $HTTP_POST_VARS); }
	catch (Exception $error) { xs_error($lang['xs_config_save_failed'] . '<br /><br />' . $lang['xs_config_back']); }
	catch (Error $error) { xs_error($lang['xs_config_save_failed'] . '<br /><br />' . $lang['xs_config_back']); }
	if ((string)$old_navigation !== $board_config['xs_shownav'])
	{
		$template->assign_block_vars('left_refresh', array('ACTION' => append_sid('index.' . $phpEx . '?pane=left')));
	}
	$template->assign_block_vars('switch_updated', array());
	$template->load_config($template->root, false);
}

// check ftp configuration
$xs_ftp_host = $board_config['xs_ftp_host'];
if(empty($xs_ftp_host) && !empty($HTTP_SERVER_VARS['HTTP_HOST']))
{
	$str = is_scalar($HTTP_SERVER_VARS['HTTP_HOST']) ? (string) $HTTP_SERVER_VARS['HTTP_HOST'] : '';
	if(preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D', $str))
	{
		$click = htmlspecialchars('document.config.xs_ftp_host.value=' . json_encode($str), ENT_QUOTES, 'UTF-8');
		$template->assign_vars(array(
			'HOST_GUESS' => str_replace(array('{HOST}', '{CLICK}'), array(htmlspecialchars($str, ENT_QUOTES, 'UTF-8'), $click), $lang['xs_ftp_host_guess'])
			));
	}
}
$dir = getcwd();
$xs_ftp_login = $board_config['xs_ftp_login'];
if(empty($xs_ftp_login))
{
	if(substr($dir, 0, 6) === '/home/')
	{
		$str = substr($dir, 6);
		$pos = strpos($str, '/');
		if($pos)
		{
			$str = substr($str, 0, $pos);
			$click = htmlspecialchars('document.config.xs_ftp_login.value=' . json_encode($str), ENT_QUOTES, 'UTF-8');
			$template->assign_vars(array(
				'LOGIN_GUESS' => str_replace(array('{LOGIN}', '{CLICK}'), array(htmlspecialchars($str, ENT_QUOTES, 'UTF-8'), $click), $lang['xs_ftp_login_guess'])
			));
		}
	}
}
$xs_ftp_path = $board_config['xs_ftp_path'];
if(empty($xs_ftp_path))
{
	if(substr($dir, 0, 6) === '/home/')
	{
		$str = substr($dir, 6);
		$pos = strpos($str, '/');
		if($pos)
		{
			$str = substr($str, $pos + 1);
			$pos = strrpos($str, 'admin');
			if($pos)
			{
				$str = substr($str, 0, $pos-1);
				$click = htmlspecialchars('document.config.xs_ftp_path.value=' . json_encode($str), ENT_QUOTES, 'UTF-8');
				$template->assign_vars(array(
					'PATH_GUESS' => str_replace(array('{PATH}', '{CLICK}'), array(htmlspecialchars($str, ENT_QUOTES, 'UTF-8'), $click), $lang['xs_ftp_path_guess'])
					));
			}
		}
	}
}

$template->assign_vars(array(
	'XS_USE_CACHE_0'			=> $board_config['xs_use_cache'] ? '' : ' checked="checked"',
	'XS_USE_CACHE_1'			=> $board_config['xs_use_cache'] ? ' checked="checked"' : '',
	'XS_AUTO_COMPILE_0'			=> $board_config['xs_auto_compile'] ? '' : ' checked="checked"',
	'XS_AUTO_COMPILE_1'			=> $board_config['xs_auto_compile'] ? ' checked="checked"' : '',
	'XS_AUTO_RECOMPILE_0'		=> $board_config['xs_auto_recompile'] ? '' : ' checked="checked"',
	'XS_AUTO_RECOMPILE_1'		=> $board_config['xs_auto_recompile'] ? ' checked="checked"' : '',
	'XS_PHP'					=> htmlspecialchars($board_config['xs_php'], ENT_QUOTES, 'UTF-8'),
	'XS_DEF_TEMPLATE'			=> htmlspecialchars($board_config['xs_def_template'], ENT_QUOTES, 'UTF-8'),
	'XS_CHECK_SWITCHES_0'		=> !$board_config['xs_check_switches'] ? ' checked="checked"' : '', // no check
	'XS_CHECK_SWITCHES_1'		=> $board_config['xs_check_switches'] == 1 ? ' checked="checked"' : '', // smart check
	'XS_CHECK_SWITCHES_2'		=> $board_config['xs_check_switches'] == 2 ? ' checked="checked"' : '', // simple check
	'XS_WARN_INCLUDES_0'		=> $board_config['xs_warn_includes'] ? '' : ' checked="checked"',
	'XS_WARN_INCLUDES_1'		=> $board_config['xs_warn_includes'] ? ' checked="checked"' : '',
	'XS_ADD_COMMENTS_0'			=> $board_config['xs_add_comments'] ? '' : ' checked="checked"',
	'XS_ADD_COMMENTS_1'			=> $board_config['xs_add_comments'] ? ' checked="checked"' : '',
	'XS_FTP_HOST'				=> defined('DEMO_MODE') ? '' : htmlspecialchars($xs_ftp_host, ENT_QUOTES, 'UTF-8'),
	'XS_FTP_LOGIN'				=> defined('DEMO_MODE') ? '' : htmlspecialchars($xs_ftp_login, ENT_QUOTES, 'UTF-8'),
	'XS_FTP_PATH'				=> defined('DEMO_MODE') ? '' : htmlspecialchars($xs_ftp_path, ENT_QUOTES, 'UTF-8'),
	'FORM_ACTION'				=> append_sid('xs_config.' . $phpEx),
	));

for($i=0; $i<XS_SHOWNAV_MAX; $i++)
{
	$num = pow(2, $i);
	if($i != XS_SHOWNAV_DOWNLOAD) // downloads feature is disabled
	{
		$template->assign_block_vars('shownav', array(
			'NUM'		=> $i,
			'LABEL'		=> $lang['xs_config_shownav'][$i],
			'CHECKED'	=> (($board_config['xs_shownav'] & $num) > 0) ? 'checked="checked"' : ''
			));
	}
}

// test cache
$tpl_filename = $template->make_filename('_xs_test.tpl');
$cache_filename = $template->make_filename_cache($tpl_filename);
$str = '';
if(!xs_check_cache($cache_filename))
{
	$template->assign_block_vars('switch_xs_warning', array());
}
@unlink($cache_filename);
$debug_data = $str;
$template->assign_vars(array(
					'XS_DEBUG_HDR1'			=> sprintf($lang['xs_check_hdr'], '_xs_test.tpl'),
					'XS_DEBUG_FILENAME1'	=> $tpl_filename,
					'XS_DEBUG_FILENAME2'	=> $cache_filename,
					'XS_DEBUG_DATA'			=> $debug_data,
					));

$template->set_filenames(array('body' => XS_TPL_PATH . 'config.tpl'));
$template->pparse('body');
xs_exit();

?>
