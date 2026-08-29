<?php

/***************************************************************************
 *                              xs_frameset.php
 *                              ---------------
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
define('NO_XS_HEADER', true);
include_once('xs_include.' . $phpEx);

$action = isset($HTTP_GET_VARS['action']) && is_scalar($HTTP_GET_VARS['action']) ? (string) $HTTP_GET_VARS['action'] : '';
$get_data = array();
$get_data_length = 0;
foreach($HTTP_GET_VARS as $var => $value)
{
	if($var !== 'action' && $var !== 'sid' && is_string($var) && preg_match('/^[a-zA-Z0-9_]+$/D', $var) && is_scalar($value))
	{
		$pair = urlencode($var) . '=' . urlencode(stripslashes((string) $value));
		if($get_data_length + strlen($pair) <= 4096)
		{
			$get_data[] = $pair;
			$get_data_length += strlen($pair) + 1;
		}
	}
}

$get_data = count($get_data) ? $phpEx . '?' . implode('&', $get_data) : $phpEx;

$content_url = array(
	'config'		=> append_sid('xs_config.'.$get_data),
	'install'		=> append_sid('xs_install.'.$get_data),
	'uninstall'		=> append_sid('xs_uninstall.'.$get_data),
	'default'		=> append_sid('xs_styles.'.$get_data),
	'cache'			=> append_sid('xs_cache.'.$get_data),
	'import'		=> append_sid('xs_import.'.$get_data),
	'export'		=> append_sid('xs_export.'.$get_data),
	'clone'			=> append_sid('xs_clone.'.$get_data),
	'editdb'		=> append_sid('xs_edit_data.'.$get_data),
	'exportdb'		=> append_sid('xs_export_data.'.$get_data),
	'portal'		=> append_sid('xs_portal.'.$get_data),
	'style_config'	=> append_sid('xs_style_config.'.$get_data),
	);

if(isset($content_url[$action]))
{
	$content = $content_url[$action];
}
else
{
	$content = append_sid('xs_index.'.$get_data);
}

$template->set_filenames(array('body' => XS_TPL_PATH . 'frameset.tpl'));
$template->assign_vars(array(
	'FRAME_TOP'		=> append_sid('xs_frame_top.'.$phpEx),
	'FRAME_MAIN'	=> $content,
	));

$template->pparse('body');
xs_exit();

?>
