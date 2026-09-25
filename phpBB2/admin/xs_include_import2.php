<?php

/***************************************************************************
 *                           xs_include_import2.php
 *                           ----------------------
 *   copyright            : (C) 2003 - 2005 CyberAlien
 *   support              : http://www.phpbbstyles.com
 *
 *   version              : 2.3.1
 *
 *   file revision        : 75
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

if (!defined('IN_PHPBB') || !defined('IN_XS'))
{
	die("Hacking attempt");
}

/*

Import style.

$filename			= style filename. it should be in temporary directory.
$write_local		= false if style should be uploaded via ftp, true if written directory to disk
$write_local_dir	= directory where to write. only if $write_local = true.
$list_only			= true if only list files
$get_file			= filename to get. empty if do not return any files

$HTTP_POST_VARS['total']				= total number of themes
$HTTP_POST_VARS['import_install_0']		= non-empty if install theme
$HTTP_POST_VARS['import_default']		= number of default style or -1 or empty

*/

if(empty($list_only))
{
	$list_only = false;
}

$lang['xs_import_back'] = str_replace('{URL}', append_sid('xs_import.'.$phpEx), $lang['xs_import_back']);

// list of text types. only last 4 characters of filename
$text_types = array('.tpl', '.htm', 'html', '.txt', '.css', '.cfg', '.php', '.xml');
// list of image types. if you add type make sure you add content-type header in code below
$img_types = array('.gif', '.jpg', '.jpe', 'jpeg', '.png');

$file = XS_TEMP_DIR . xs_fix_dir($filename);
$header = xs_get_style_header($file);
if($header === false)
{
	if(defined('XS_CLONING'))
	{
		@unlink($tmp_filename);
	}
	xs_error($lang['xs_style_header_error_reason'] . $xs_header_error . '<br /><br />' . $lang['xs_import_back']);
}
$style_filesize = @filesize($file);
if($style_filesize === false || $style_filesize > XS_MAX_STYLE_UPLOAD_BYTES || $header['filesize'] != $style_filesize)
{
	if(defined('XS_CLONING'))
	{
		@unlink($tmp_filename);
	}
	xs_error($lang['xs_style_header_error_incomplete'] . '<br /><br />' . $lang['xs_import_back']);
}
$safe_template = isset($header['template']) ? xs_tpl_name($header['template']) : '';
if($safe_template === '' || $safe_template !== $header['template'])
{
	xs_error($lang['xs_invalid_style_name'] . '<br /><br />' . $lang['xs_import_back']);
}
$header['template'] = $safe_template;
$f = @fopen($file, 'rb');
if(!$f)
{
	if(defined('XS_CLONING'))
	{
		@unlink($tmp_filename);
	}
	xs_error($lang['xs_error_cannot_open'] . '<br /><br />' . $lang['xs_import_back']);
}
fseek($f, $header['offset'], 0);
$str = fread($f, $style_filesize - $header['offset']);
fclose($f);
$str = is_string($str) ? $str : '';
$str = @gzuncompress($str, XS_MAX_STYLE_UNPACKED_BYTES);
if($str === false || !strlen($str))
{
	if(defined('XS_CLONING'))
	{
		@unlink($tmp_filename);
	}
	xs_error($lang['xs_error_decompress_style'] . '<br /><br />' . $lang['xs_import_back']);
}
//
// unpack tar file
//
require_once($phpbb_root_path . 'includes/functions_style_archive.' . $phpEx);
try { $archive_entries = phpbb_style_archive_entries($str); }
catch (Exception $error)
{
	if(defined('XS_CLONING')) { @unlink($tmp_filename); }
	xs_error($lang['xs_import_invalid_file'] . '<br /><br />' . $lang['xs_import_back']);
}
if (!$list_only)
{
	require_once($phpbb_root_path . 'includes/functions_style_import_recovery.' . $phpEx);
	$import_error = false;
	$import_error_message = $lang['xs_recovery_failed'];
	try
	{
		list($publisher, $recovery_base, $recovery_identity) = phpbb_style_import_recovery_environment($phpbb_root_path, $write_local, $write_local ? $write_local_dir : '', isset($ftp) ? $ftp : null, $board_config);
		$recovery_request = $HTTP_POST_VARS; $recovery_request['recovery_action'] = 'start'; $recovery_request['recovery_template'] = $header['template'];
		$recovered = phpbb_style_import_recovery($db, $recovery_request, $publisher, $recovery_base, $recovery_identity, array('header'=>$header, 'entries'=>$archive_entries, 'archive'=>$str, 'create_only'=>defined('XS_CLONING')));
		$installed = $recovered['installed'];
	}
	catch (PhpbbAclException $error)
	{
		$import_error = true;
		if ($error->getMessage() === $lang['xs_clone_style_exists']) { $import_error_message = $lang['xs_clone_style_exists']; }
	}
	catch (Exception $error) { $import_error = true; }
	catch (Error $error) { $import_error = true; }
	finally { if (defined('XS_CLONING')) { @unlink($tmp_filename); } }
	if ($import_error) { xs_error($import_error_message . '<br /><br /><a href="' . append_sid('xs_import.' . $phpEx) . '">' . $lang['xs_recovery_title'] . '</a><br /><br />' . $lang['xs_import_back']); }
	xs_message($lang['Information'], $lang[$recovered['retry'] ? 'xs_recovery_committed' : ($installed ? 'xs_import_installed' : 'xs_import_uploaded')] . '<br /><br />' . $lang['xs_import_back']);
}

// Preview never opens a writer/FTP connection or creates a directory.
$list_data = array();
foreach ($archive_entries as $data)
{
	if ($data['typeflag'] !== 0) { continue; }
	$f = $data['filename']; $ext = strtolower(substr($f, -4));
	if (!empty($get_file) && $get_file === $f)
	{
		$contents = substr($str, $data['offset'], $data['size']);
		if (empty($HTTP_GET_VARS['get_content']) && xs_in_array($ext, $text_types))
		{
			$html = '<div align="left">' . $lang['xs_import_list_contents'] . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') .
				' [<a href="' . append_sid('xs_import.' . $phpEx . '?list=1&import=' . urlencode($filename) . '&get_file=' . urlencode($f) . '&get_content=1') . '">' . $lang['xs_import_download_lc'] . '</a>]<br /><br />';
			$html .= '<textarea cols="120" rows="30" style="width: 100%">' . htmlspecialchars($contents, ENT_QUOTES, 'UTF-8') . '</textarea></div>';
			xs_message($lang['Information'], $html);
		}
		$types = array('.gif'=>'image/gif','.jpg'=>'image/jpeg','.jpe'=>'image/jpeg','jpeg'=>'image/jpeg','.png'=>'image/png');
		$inline = empty($HTTP_GET_VARS['get_content']) && isset($types[$ext]);
		xs_download_file($inline ? '' : basename($f), $contents, $inline ? $types[$ext] : '');
		xs_exit();
	}
	$list_data[$f] = $data;
}
$html = '<div align="left">';
$html .= $lang['xs_import_list_filename'] . htmlspecialchars($header['filename'], ENT_QUOTES, 'UTF-8') . '<br />';
$html .= $lang['xs_import_list_template'] . htmlspecialchars($header['template'], ENT_QUOTES, 'UTF-8') . '<br />';
$html .= $lang['xs_import_list_comment'] . htmlspecialchars($header['comment'], ENT_QUOTES, 'UTF-8') . '<br />';
$html .= $lang['xs_import_list_styles'] . htmlspecialchars(implode(', ', $header['styles']), ENT_QUOTES, 'UTF-8') . '<br />';
ksort($list_data);
$html .= '<br />' . str_replace('{NUM}', count($list_data), $lang['xs_import_list_files']) . '<br />';
$html .= '<table border="0" cellspacing="0" cellpadding="1" align="left">';
foreach ($list_data as $data)
{
	$f = $data['filename']; $ext = strtolower(substr($f, -4));
	$html .= '<tr><td>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</td><td>';
	if ($data['size'] > 0)
	{
		$url = 'xs_import.' . $phpEx . '?list=1&import=' . urlencode($filename) . '&get_file=' . urlencode($f);
		if (xs_in_array($ext, $text_types) || xs_in_array($ext, $img_types)) { $html .= '[<a href="' . append_sid($url) . '">' . $lang['xs_import_view_lc'] . '</a>] '; }
		$html .= '[<a href="' . append_sid($url . '&get_content=1') . '">' . $lang['xs_import_download_lc'] . '</a>] ';
	}
	$html .= str_replace('{NUM}', $data['size'], $lang['xs_import_file_size']) . '</td></tr>';
}
xs_message($lang['Information'], $html . '</table></div>');
