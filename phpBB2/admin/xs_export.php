<?php

/***************************************************************************
 *                               xs_export.php
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

define('IN_XS', true);
include_once('xs_include.' . $phpEx);

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_export.'.$phpEx) . '">' . $lang['xs_export_styles'] . '</a>'));

$lang['xs_export_back'] = str_replace('{URL}', append_sid('xs_export.'.$phpEx), $lang['xs_export_back']);

//
// Check required functions
//
if(!@function_exists('gzcompress'))
{
	xs_error($lang['xs_import_nogzip']);
}

function xs_export_clean_text($value, $max_characters, $max_bytes)
{
	if(!is_scalar($value))
	{
		return false;
	}
	$value = trim(stripslashes((string) $value));
	$character_count = preg_match_all('/./us', $value, $characters);
	if($character_count === false)
	{
		return false;
	}
	if($character_count > $max_characters)
	{
		$characters[0] = array_slice($characters[0], 0, $max_characters);
	}
	while(count($characters[0]) && strlen(implode('', $characters[0])) > $max_bytes)
	{
		array_pop($characters[0]);
	}
	return implode('', $characters[0]);
}

function xs_export_safe_filename($value, $fallback)
{
	$value = is_scalar($value) ? trim(stripslashes((string) $value)) : '';
	if($value === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/D', $value))
	{
		$value = $fallback;
	}
	if(strtolower(substr($value, -strlen(STYLE_EXTENSION))) !== strtolower(STYLE_EXTENSION))
	{
		$value .= STYLE_EXTENSION;
	}
	return $value;
}


//
// Export page
//
$export = isset($HTTP_GET_VARS['export']) && is_scalar($HTTP_GET_VARS['export']) ? (string) $HTTP_GET_VARS['export'] : '';
$export = xs_tpl_name($export);
if(!empty($export) && @file_exists($phpbb_root_path . $template_dir . $export . '/theme_info.cfg'))
{
	// Get list of styles
	$sql = "SELECT themes_id, style_name FROM " . THEMES_TABLE . " WHERE template_name = '$export' ORDER BY style_name ASC";
	if(!$result = $db->sql_query($sql))
	{
		xs_error($lang['xs_no_theme_data'] . '<br /><br />' . $lang['xs_export_back']);
	}
	$theme_rowset = $db->sql_fetchrowset($result);
	if(count($theme_rowset) == 0)
	{
		xs_error($lang['xs_no_themes'] . '<br /><br />' . $lang['xs_export_back']);
	}
	$template->set_filenames(array('body' => XS_TPL_PATH . 'export2.tpl'));
	$xs_send_method = isset($board_config['xs_export_data']) ? $board_config['xs_export_data'] : '';
	$xs_send = phpbb_safe_unserialize($xs_send_method);
	$xs_send = is_array($xs_send) ? $xs_send : array();
	$stored_method = isset($xs_send['method']) && is_scalar($xs_send['method']) ? (string) $xs_send['method'] : '';
	$xs_send_method = $stored_method === 'ftp' ? 'ftp' : ($stored_method === 'file' ? 'file' : 'save');
	$template->assign_vars(array(
			'FORM_ACTION'		=> append_sid('xs_export.'.$phpEx),
			'EXPORT_TEMPLATE'	=> htmlspecialchars($export, ENT_QUOTES, 'UTF-8'),
			'STYLE_ID'			=> $theme_rowset[0]['themes_id'],
			'STYLE_NAME'		=> htmlspecialchars($theme_rowset[0]['style_name'], ENT_QUOTES, 'UTF-8'),
			'TOTAL'				=> count($theme_rowset),
			'SEND_METHOD_'.strtoupper($xs_send_method)	=> ' checked="checked"',
			'SEND_DATA_DIR'		=> isset($xs_send['dir']) && is_scalar($xs_send['dir']) ? htmlspecialchars((string) $xs_send['dir'], ENT_QUOTES, 'UTF-8') : '',
			'SEND_DATA_HOST'	=> isset($xs_send['host']) && is_scalar($xs_send['host']) ? htmlspecialchars((string) $xs_send['host'], ENT_QUOTES, 'UTF-8') : '',
			'SEND_DATA_LOGIN'	=> isset($xs_send['login']) && is_scalar($xs_send['login']) ? htmlspecialchars((string) $xs_send['login'], ENT_QUOTES, 'UTF-8') : '',
			'SEND_DATA_FTPDIR'	=> isset($xs_send['ftpdir']) && is_scalar($xs_send['ftpdir']) ? htmlspecialchars((string) $xs_send['ftpdir'], ENT_QUOTES, 'UTF-8') : '',
			'L_TITLE'			=> str_replace('{TPL}', htmlspecialchars($export, ENT_QUOTES, 'UTF-8'), $lang['xs_export_style_title']),
			));
	if(count($theme_rowset) == 1)
	{
		$template->assign_block_vars('switch_select_nostyle', array());
	}
	else
	{
		$template->assign_block_vars('switch_select_style', array());
		for($i=0; $i<count($theme_rowset); $i++)
		{
			$template->assign_block_vars('switch_select_style.style', array(
				'NUM'		=> $i,
				'ID'		=> $theme_rowset[$i]['themes_id'],
				'NAME'		=> htmlspecialchars($theme_rowset[$i]['style_name'], ENT_QUOTES, 'UTF-8')
				));
		}
	}
	$template->pparse('body');
	xs_exit();
}

//
// Export style
//
$export = isset($HTTP_POST_VARS['export']) && is_scalar($HTTP_POST_VARS['export']) ? (string) $HTTP_POST_VARS['export'] : '';
$export = xs_tpl_name($export);
if(!empty($export) && @file_exists($phpbb_root_path . $template_dir . $export . '/theme_info.cfg') && !defined('DEMO_MODE'))
{
	phpbb_admin_require_post_session();
	$total = isset($HTTP_POST_VARS['total']) && is_scalar($HTTP_POST_VARS['total']) ? intval($HTTP_POST_VARS['total']) : 0;
	$comment = xs_export_clean_text(isset($HTTP_POST_VARS['export_comment']) ? $HTTP_POST_VARS['export_comment'] : '', 250, 255);
	if($total <= 0 || $total > 1000 || $comment === false)
	{
		xs_error($lang['xs_export_noselect_themes'] . '<br /><br /> ' . $lang['xs_export_back']);
	}
	$list = array();
	$export_style_names = array();
	for($i=0; $i<$total; $i++)
	{
		if(!empty($HTTP_POST_VARS['export_style_'.$i]) && isset($HTTP_POST_VARS['export_style_id_'.$i]) && is_scalar($HTTP_POST_VARS['export_style_id_'.$i]))
		{
			$style_id = intval($HTTP_POST_VARS['export_style_id_'.$i]);
			$style_name = xs_export_clean_text(isset($HTTP_POST_VARS['export_style_name_'.$i]) ? $HTTP_POST_VARS['export_style_name_'.$i] : '', 30, 255);
			if($style_id <= 0 || $style_name === false || $style_name === '')
			{
				xs_error($lang['xs_invalid_style_name'] . '<br /><br />' . $lang['xs_export_back']);
			}
			$list[$style_id] = $style_id;
			$export_style_names[$style_id] = $style_name;
			$HTTP_POST_VARS['export_style_name_'.$i] = $style_name;
		}
	}
	if(!count($list))
	{
		xs_error($lang['xs_export_noselect_themes'] . '<br /><br /> ' . $lang['xs_export_back']);
	}
	// Export as...
	$exportas = empty($HTTP_POST_VARS['export_template']) || !is_scalar($HTTP_POST_VARS['export_template']) ? $export : (string) $HTTP_POST_VARS['export_template'];
	$exportas = xs_tpl_name($exportas);
	$exportas_length = preg_match_all('/./us', $exportas, $exportas_characters);
	if($exportas === '' || $exportas_length === false || $exportas_length > 30)
	{
		xs_error($lang['xs_invalid_style_name'] . '<br /><br />' . $lang['xs_export_back']);
	}
	// Generate theme_info.cfg
	$sql = "SELECT * FROM " . THEMES_TABLE . " WHERE template_name = '$export' AND themes_id IN (" . implode(', ', $list) . ")";
	if(!$result = $db->sql_query($sql))
	{
		xs_error($lang['xs_no_theme_data'] . $lang['xs_export_back']);
	}
	$theme_rowset = $db->sql_fetchrowset($result);
	if(count($theme_rowset) == 0)
	{
		xs_error($lang['xs_no_themes']  . '<br /><br />' . $lang['xs_export_back']);
	}
	// pack style
	if(count($theme_rowset) !== count($list))
	{
		xs_error($lang['xs_no_themes']  . '<br /><br />' . $lang['xs_export_back']);
	}
	for($i=0; $i<count($theme_rowset); $i++)
	{
		$id = $theme_rowset[$i]['themes_id'];
		$theme_name = $theme_rowset[$i]['style_name'];
		$theme_name = isset($export_style_names[$id]) ? $export_style_names[$id] : $theme_name;
		$theme_rowset[$i]['style_name'] = $theme_name;
	}
	$theme_data = xs_generate_themeinfo($theme_rowset, $export, $exportas, 0);

	// prepare to pack
	$pack_error = '';
	$pack_list = array();
	$pack_replace = array('./theme_info.cfg' => $theme_data);

	$data = pack_style($export, $exportas, $theme_rowset, $comment);

	// check errors
	if($pack_error)
	{
		xs_error(str_replace('{TPL}', $export, $lang['xs_export_error']) . $pack_error  . '<br /><br />' . $lang['xs_export_back']);
	}
	if(!$data || strlen($data) > XS_MAX_STYLE_UPLOAD_BYTES)
	{
		xs_error(str_replace('{TPL}', $export, $lang['xs_export_error2']) . '<br /><br />' . $lang['xs_export_back']);
	}

	//
	// Got file. Sending it.
	//
	$send_method = isset($HTTP_POST_VARS['export_to']) && is_scalar($HTTP_POST_VARS['export_to']) ? (string) $HTTP_POST_VARS['export_to'] : 'save';
	$send_method = in_array($send_method, array('save', 'file', 'ftp'), true) ? $send_method : 'save';
	$export_filename = xs_export_safe_filename(isset($HTTP_POST_VARS['export_filename']) ? $HTTP_POST_VARS['export_filename'] : '', $exportas . STYLE_EXTENSION);
	if($send_method === 'file')
	{
		// store on local server
		$send_dir = isset($HTTP_POST_VARS['export_to_dir']) && is_scalar($HTTP_POST_VARS['export_to_dir']) ? str_replace('\\', '/', stripslashes((string) $HTTP_POST_VARS['export_to_dir'])) : '';
		if(empty($send_dir))
		{
			$send_dir = XS_TEMP_DIR;
		}
		if(strlen($send_dir) > 512 || strpos($send_dir, "\0") !== false)
		{
			xs_error(str_replace('{FILE}', '', $lang['xs_error_cannot_create_file']) . '<br /><br />' . $lang['xs_export_back']);
		}
		$cache_root = @realpath(XS_TEMP_DIR);
		$resolved_send_dir = @realpath($send_dir);
		$cache_prefix = $cache_root === false ? '' : rtrim(str_replace('\\', '/', $cache_root), '/') . '/';
		$resolved_prefix = $resolved_send_dir === false ? '' : rtrim(str_replace('\\', '/', $resolved_send_dir), '/') . '/';
		if($cache_prefix === '' || $resolved_prefix === '' || strpos($resolved_prefix, $cache_prefix) !== 0)
		{
			xs_error(str_replace('{FILE}', htmlspecialchars($send_dir, ENT_QUOTES, 'UTF-8'), $lang['xs_error_cannot_create_file']) . '<br /><br />' . $lang['xs_export_back']);
		}
		$filename = rtrim($resolved_send_dir, '/\\') . DIRECTORY_SEPARATOR . $export_filename;
		$tmp_filename = @tempnam($resolved_send_dir, 'xs_export_');
		if($tmp_filename === false || @file_put_contents($tmp_filename, $data, LOCK_EX) !== strlen($data) || !@rename($tmp_filename, $filename))
		{
			if($tmp_filename !== false)
			{
				@unlink($tmp_filename);
			}
			xs_error(str_replace('{FILE}', htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'), $lang['xs_error_cannot_create_file']) . '<br /><br />' . $lang['xs_export_back']);
		}
		@chmod($filename, 0664);
		set_export_method('file', array('dir' => XS_TEMP_DIR));
		xs_message($lang['Information'], str_replace('{FILE}', htmlspecialchars($filename, ENT_QUOTES, 'UTF-8'), $lang['xs_export_saved']) . '<br /><br />' . $lang['xs_export_back']);
	}
	elseif($send_method === 'ftp')
	{
		// upload via ftp
		$ftp_host = isset($HTTP_POST_VARS['export_to_ftp_host']) && is_scalar($HTTP_POST_VARS['export_to_ftp_host']) ? trim((string) $HTTP_POST_VARS['export_to_ftp_host']) : '';
		$ftp_login = isset($HTTP_POST_VARS['export_to_ftp_login']) && is_scalar($HTTP_POST_VARS['export_to_ftp_login']) ? (string) $HTTP_POST_VARS['export_to_ftp_login'] : '';
		$ftp_pass = isset($HTTP_POST_VARS['export_to_ftp_pass']) && is_scalar($HTTP_POST_VARS['export_to_ftp_pass']) ? (string) $HTTP_POST_VARS['export_to_ftp_pass'] : '';
		$ftp_dir = isset($HTTP_POST_VARS['export_to_ftp_dir']) && is_scalar($HTTP_POST_VARS['export_to_ftp_dir']) ? str_replace('\\', '/', (string) $HTTP_POST_VARS['export_to_ftp_dir']) : '';
		if($ftp_host === '' || strlen($ftp_host) > 255 || !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D', $ftp_host) || strlen($ftp_login) > 255 || strpos($ftp_login, "\0") !== false || strlen($ftp_pass) > 1024 || strpos($ftp_pass, "\0") !== false || strlen($ftp_dir) > 512 || strpos($ftp_dir, "\0") !== false || preg_match('#(?:^|/)\.\.(?:/|$)#', $ftp_dir))
		{
			xs_error($lang['xs_export_error_uploading'] . '<br /><br />' . $lang['xs_export_back']);
		}
		// save as temporary file
		$filename = @tempnam(XS_TEMP_DIR, 'xs_export_');
		if($filename === false || @file_put_contents($filename, $data, LOCK_EX) !== strlen($data))
		{
			if($filename !== false)
			{
				@unlink($filename);
			}
			xs_error(str_replace('{FILE}', XS_TEMP_DIR, $lang['xs_error_cannot_create_tmp']) . '<br /><br />' . $lang['xs_export_back']);
		}
		// connect to ftp
		$ftp = @ftp_connect($ftp_host, 21, 10);
		if(!$ftp)
		{
			@unlink($filename);
			xs_error($lang['xs_ftp_error_noconnect'] . '<br /><br />' . $lang['xs_export_back']);
		}
		$res = @ftp_login($ftp, $ftp_login, $ftp_pass);
		if(!$res)
		{
			@ftp_close($ftp);
			@unlink($filename);
			xs_error($lang['xs_ftp_error_login2'] . '<br /><br />' . $lang['xs_export_back']);
		}
		if($ftp_dir)
		{
			if(!@ftp_chdir($ftp, $ftp_dir))
			{
				@ftp_close($ftp);
				@unlink($filename);
				xs_error($lang['xs_export_error_uploading'] . '<br /><br />' . $lang['xs_export_back']);
			}
		}
		$res = @ftp_put($ftp, $export_filename, $filename, FTP_BINARY);
		@ftp_close($ftp);
		@unlink($filename);
		if(!$res)
		{
			xs_error($lang['xs_export_error_uploading'] . '<br /><br />' . $lang['xs_export_back']);
		}
		set_export_method('ftp', array('host' => $ftp_host, 'login' => $ftp_login, 'ftpdir' => $ftp_dir));
		xs_message($lang['Information'], $lang['xs_export_uploaded'] . '<br /><br />' . $lang['xs_export_back']);
	}
	set_export_method('save', array());
	// send file
	xs_download_file($export_filename, $data, 'application/phpbbstyle');
	xs_exit();
}

$template->set_filenames(array('body' => XS_TPL_PATH . 'export.tpl'));

//
// get list of installed styles
//
$sql = 'SELECT themes_id, template_name, style_name FROM ' . THEMES_TABLE . ' ORDER BY template_name';
if(!$result = $db->sql_query($sql))
{
	xs_error($lang['xs_no_style_info'], __LINE__, __FILE__);
}
$style_rowset = $db->sql_fetchrowset($result);

$prev_id = -1;
$prev_tpl = '';
$style_names = array();
$j = 0;
for($i=0; $i<count($style_rowset); $i++)
{
	$item = $style_rowset[$i];
	if($item['template_name'] === $prev_tpl)
	{
		$style_names[] = htmlspecialchars($item['style_name'], ENT_QUOTES, 'UTF-8');
	}
	else
	{
		if($prev_id > 0)
		{
			$str = implode('<br />', $style_names);
			$str2 = urlencode($prev_tpl);
			$row_class = $xs_row_class[$j % 2];
			$j++;
			$template->assign_block_vars('styles', array(
					'ROW_CLASS'	=> $row_class,
					'TPL'		=> htmlspecialchars($prev_tpl, ENT_QUOTES, 'UTF-8'),
					'STYLES'	=> $str,
					'U_EXPORT'	=> "xs_export.{$phpEx}?export={$str2}&sid={$userdata['session_id']}",
				)
			);
		}
		$prev_id = $item['themes_id'];
		$prev_tpl = $item['template_name'];
		$style_names = array(htmlspecialchars($item['style_name'], ENT_QUOTES, 'UTF-8'));
	}
}

if($prev_id > 0)
{
	$str = implode('<br />', $style_names);
	$str2 = urlencode($prev_tpl);
	$row_class = $xs_row_class[$j % 2];
	$j++;
	$template->assign_block_vars('styles', array(
			'ROW_CLASS'	=> $row_class,
			'TPL'		=> htmlspecialchars($prev_tpl, ENT_QUOTES, 'UTF-8'),
			'STYLES'	=> $str,
			'U_EXPORT'	=> "xs_export.{$phpEx}?export={$str2}&sid={$userdata['session_id']}",
		)
	);
}

$template->pparse('body');
xs_exit();

?>
