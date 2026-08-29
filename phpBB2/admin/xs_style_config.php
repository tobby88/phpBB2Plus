<?php

/***************************************************************************
 *                            xs_style_config.php
 *                            -------------------
 *   Original module copyright (C) 2003 - 2005 CyberAlien
 *
 *   Per-template xs_config.cfg files were executable PHP. phpBB2 Plus no
 *   longer supports that unsafe optional feature; this endpoint remains as
 *   a compatibility notice for historic links and configuration entries.
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = './../';
$no_page_header = true;
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

if (empty($template->xs_version) || $template->xs_version !== 8)
{
	message_die(GENERAL_ERROR, isset($lang['xs_error_not_installed']) ? $lang['xs_error_not_installed'] : 'eXtreme Styles mod is not installed.');
}

define('IN_XS', true);
include_once('xs_include.' . $phpEx);

$tpl_value = isset($HTTP_POST_VARS['tpl']) && is_scalar($HTTP_POST_VARS['tpl']) ? $HTTP_POST_VARS['tpl'] : (isset($HTTP_GET_VARS['tpl']) && is_scalar($HTTP_GET_VARS['tpl']) ? $HTTP_GET_VARS['tpl'] : '');
$tpl = xs_tpl_name($tpl_value);
if ($tpl === '')
{
	xs_error($lang['xs_invalid_style_name']);
}

$template->assign_block_vars('nav_left', array(
	'ITEM' => '&raquo; ' . $lang['xs_style_configuration'] . ': ' . htmlspecialchars($tpl, ENT_QUOTES, 'UTF-8')
));
xs_error($lang['xs_style_config_disabled']);

?>
