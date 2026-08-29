<?php

/***************************************************************************
 *                                xs_chmod.php
 *                                ------------
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

$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_config.'.$phpEx) . '">' . $lang['xs_configuration'] . '</a>'));
$template->assign_block_vars('nav_left',array('ITEM' => '&raquo; <a href="' . append_sid('xs_chmod.'.$phpEx) . '">' . $lang['xs_chmod'] . '</a>'));

$lang['xs_chmod_return'] = str_replace('{URL}', append_sid('xs_config.'.$phpEx), $lang['xs_chmod_return']);
$lang['xs_chmod_message1'] .= $lang['xs_chmod_return'];
$lang['xs_chmod_error1'] .= $lang['xs_chmod_return'];

if(defined('DEMO_MODE'))
{
	xs_error($lang['xs_permission_denied']);
}

if(!get_ftp_config(append_sid('xs_chmod.'.$phpEx), array(), false))
{
	xs_exit();
}
xs_ftp_connect(append_sid('xs_chmod.'.$phpEx), array(), false);

$res = @ftp_chmod($ftp, 0775, 'cache');
if(!$res)
{
	@ftp_mkdir($ftp, 'cache');
	$res = @ftp_chmod($ftp, 0775, 'cache');
}
@ftp_close($ftp);
if($res)
{
	xs_message($lang['Information'], $lang['xs_chmod_message1']);
}
else
{
	xs_error($lang['xs_chmod_error1']);
}

?>
