<?php
/***************************************************************************
 * xs_uninstall.php — eXtreme Styles, (C) 2003-2005 CyberAlien
 * Originally version 2.3.1, file revision 72 / project revision 78.
 * Distributed under the GNU General Public License, version 2 or later.
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version. See the accompanying license for details.
 ***************************************************************************/
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
$phpbb_root_path = './../'; $no_page_header = true;
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
if (empty($template->xs_version) || $template->xs_version !== 8)
{
	message_die(GENERAL_ERROR, isset($lang['xs_error_not_installed']) ? $lang['xs_error_not_installed'] : 'eXtreme Styles is not installed.');
}
if (!defined('IN_XS')) { define('IN_XS', true); }
include_once('xs_include.' . $phpEx);
require_once($phpbb_root_path . 'includes/functions_style_removal.' . $phpEx);
require_once($phpbb_root_path . 'includes/functions_style_files.' . $phpEx);
$template->assign_block_vars('nav_left', array('ITEM'=>'&raquo; <a href="' . append_sid('xs_uninstall.' . $phpEx) . '">' . $lang['xs_uninstall_styles'] . '</a>'));
$lang['xs_uninstall_back'] = str_replace('{URL}', append_sid('xs_uninstall.' . $phpEx), $lang['xs_uninstall_back']);

try
{
	if (isset($HTTP_POST_VARS['remove_id']) && !defined('DEMO_MODE'))
	{
		if (isset($HTTP_POST_VARS['remove'])) { phpbb_acl_error('xs_remove_failed'); }
		phpbb_admin_require_post_session();
		$removed = phpbb_style_unregister($db, $HTTP_POST_VARS);
		if ($removed['config_removed']) { $template->assign_block_vars('left_refresh', array('ACTION'=>append_sid('index.' . $phpEx . '?pane=left'))); }
		if ($removed['files'])
		{
			$HTTP_POST_VARS['remove'] = $removed['template'];
			$HTTP_POST_VARS['remove_token'] = $removed['receipt']['token'];
		}
		else { $template->assign_block_vars('removed', array()); }
	}
	if (isset($HTTP_POST_VARS['remove']) && !defined('DEMO_MODE'))
	{
		phpbb_admin_require_post_session();
		// Validate the target before displaying/accepting the FTP form. The
		// worker repeats current DB/authority checks after any form round trip.
		$name = $HTTP_POST_VARS['remove'];
		$token = isset($HTTP_POST_VARS['remove_token']) ? $HTTP_POST_VARS['remove_token'] : null;
		if (!phpbb_style_removal_name($name) || !is_string($token)) { phpbb_acl_error('xs_remove_failed'); }
		phpbb_style_check_unused_template($db, $HTTP_POST_VARS);
		$params = array('remove'=>$name, 'remove_token'=>$token);
		if (!get_ftp_config(append_sid('xs_uninstall.' . $phpEx), $params, true)) { xs_exit(); }
		xs_ftp_connect(append_sid('xs_uninstall.' . $phpEx), $params, true);
		$files = $ftp === XS_FTP_LOCAL ? new PhpbbStyleLocalFiles($phpbb_root_path . 'templates') : new PhpbbStyleFtpFiles($ftp);
		phpbb_style_remove_files($db, $HTTP_POST_VARS, $files);
		$template->assign_block_vars('removed', array());
	}
}
catch (PhpbbAclException $error) { xs_error($error->getMessage() . '<br /><br />' . $lang['xs_uninstall_back']); }
catch (Exception $error) { xs_error($lang['xs_remove_failed'] . '<br /><br />' . $lang['xs_uninstall_back']); }
catch (Error $error) { xs_error($lang['xs_remove_failed'] . '<br /><br />' . $lang['xs_uninstall_back']); }

$sql = 'SELECT themes_id,template_name,style_name FROM ' . THEMES_TABLE . ' ORDER BY template_name,style_name';
if (!$result = $db->sql_query($sql)) { xs_error($lang['xs_no_style_info'], __LINE__, __FILE__); }
$style_rowset = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
$groups = array();
foreach ($style_rowset as $item) { $groups[$item['template_name']][] = $item; }
$j = 0;
foreach ($groups as $name=>$styles)
{
	$template->assign_block_vars('styles', array('ROW_CLASS'=>$xs_row_class[$j++ % 2], 'TPL'=>htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), 'ROWS'=>count($styles)));
	foreach ($styles as $item)
	{
		$template->assign_block_vars('styles.item', array('ID'=>$item['themes_id'], 'THEME'=>htmlspecialchars($item['style_name'], ENT_QUOTES, 'UTF-8'), 'REMOVE_ID'=>(int)$item['themes_id'], 'KEEP_CONFIG'=>count($styles)>1 ? 1 : 0));
		$may_remove_files = count($styles) === 1 && strcasecmp($name, 'fisubsilversh') !== 0;
		$template->assign_block_vars('styles.item.' . ($may_remove_files ? 'delete' : 'nodelete'), array('REMOVE_ID'=>(int)$item['themes_id']));
	}
}
// A failed/uncertain file cleanup remains retryable after the DB registration
// was already removed. Offer recorded cleanup attempts only; POST rechecks them.
$receipts = $db->sql_query("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name LIKE 'xs\\_removed\\_%'");
if (!$receipts) { xs_error($lang['xs_remove_failed'], __LINE__, __FILE__); }
if ($receipts)
{
	while ($row = $db->sql_fetchrow($receipts))
	{
		$receipt = phpbb_style_removal_receipt($row['config_value']);
		if (!$receipt || $receipt['state'] !== 'pending' || $row['config_name'] !== phpbb_style_removal_receipt_key($receipt['template'])) { continue; }
		$name = $receipt['template'];
		if (strcasecmp($name, 'fisubsilversh') === 0) { continue; }
		$used = false; foreach ($style_rowset as $item) { if (strcasecmp($name, $item['template_name']) === 0) { $used = true; } }
		if (!$used) { $template->assign_block_vars('orphan', array('NAME'=>htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), 'TOKEN'=>$receipt['token'])); }
	}
	$db->sql_freeresult($receipts);
}
$template->assign_vars(array(
	'S_UNINSTALL_ACTION'=>append_sid('xs_uninstall.' . $phpEx),
	'S_FORM_TOKEN'=>'<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
	'L_XS_UNINSTALL_CONFIRM'=>htmlspecialchars(addslashes($lang['xs_uninstall_confirm']), ENT_QUOTES, 'UTF-8'),
	'L_XS_UNINSTALL_FILES_CONFIRM'=>htmlspecialchars(addslashes($lang['xs_uninstall_files_confirm']), ENT_QUOTES, 'UTF-8'),
	'L_XS_ORPHAN_FILES'=>$lang['xs_orphan_files']
));
$template->set_filenames(array('body'=>XS_TPL_PATH . 'uninstall.tpl'));
$template->pparse('body');
xs_exit();
