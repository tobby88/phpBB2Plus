<?php
/***************************************************************************
 *                            admin_links_config.php
 *                            -------------------
 *  MOD add-on page. Contains GPL code copyright of phpBB group.
 *  Author: OOHOO < webdev@phpbb-tw.net >
 *  Author: Stefan2k1 and ddonker from www.portedmods.com
 *  Author: CRLin from http://mail.dhjh.tcc.edu.tw/~gzqbyr/
 *  Demo: http://phpbb-tw.net/
 *  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
 *  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
 *  Version: 1.2.0 - 2003/06/15 - Enhanced and Re-packed for phpBB 2.0.4
 *  Version: 1.2.1 - 2003/10/15 - Enhanced by CRLin
 *  Version: 1.2.2 - 2004/05/10 - Enhanced by CRLin
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
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Links']['Configuration'] = $filename;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_link.' . $phpEx);

require_once($phpbb_root_path . 'includes/functions_link_settings.' . $phpEx);
$submit = isset($_POST['submit']);
if ($submit) { phpbb_admin_require_post_session(); }
try
{
	$new = $submit ? phpbb_link_settings_save($db, $_POST, $userdata) : phpbb_link_settings_load($db);
}
catch (PhpbbLinkException $error)
{
	$key = $error->getMessage();
	message_die(GENERAL_MESSAGE, isset($lang[$key]) ? $lang[$key] : $lang['Link_storage_failed']);
}
catch (Exception $error) { message_die(GENERAL_MESSAGE, $lang['Link_storage_failed']); }
catch (Error $error) { message_die(GENERAL_MESSAGE, $lang['Link_storage_failed']); }
if ($submit)
{
	$message = $lang['Link_config_updated'] . '<br /><br />' . sprintf($lang['Click_return_link_config'], '<a href="' . append_sid("admin_links_config.$phpEx") . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}

$template->set_filenames(array(
	"body" => "admin/admin_link_config_body.tpl")
);

$lock_submit_site_yes = ( $new['lock_submit_site'] ) ? "checked=\"checked\"" : "";
$lock_submit_site_no = ( !$new['lock_submit_site'] ) ? "checked=\"checked\"" : "";
// $allow_guest_submit_site_yes = ( $new['allow_guest_submit_site'] ) ? "checked=\"checked\"" : "";
// $allow_guest_submit_site_no = ( !$new['allow_guest_submit_site'] ) ? "checked=\"checked\"" : "";
$allow_no_logo_yes = ( $new['allow_no_logo'] ) ? "checked=\"checked\"" : "";
$allow_no_logo_no = ( !$new['allow_no_logo'] ) ? "checked=\"checked\"" : "";
$display_links_logo_yes = ( $new['display_links_logo'] ) ? "checked=\"checked\"" : "";
$display_links_logo_no = ( !$new['display_links_logo'] ) ? "checked=\"checked\"" : "";
$email_yes = ( $new['email_notify'] ) ? "checked=\"checked\"" : "";
$email_no = ( !$new['email_notify'] ) ? "checked=\"checked\"" : "";
$pm_yes = ( $new['pm_notify'] ) ? "checked=\"checked\"" : "";
$pm_no = ( !$new['pm_notify'] ) ? "checked=\"checked\"" : "";

$template->assign_vars(array(
	'L_LINK_CONFIG' => $lang['Link_Config'],
	'L_LINK_CONFIG_EXPLAIN' => $lang['Link_config_explain'],
	'S_LINK_CONFIG_ACTION' => append_sid('admin_links_config.'.$phpEx),
	'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),

	'LOCK_SUBMIT_SITE_YES' => $lock_submit_site_yes,
	'LOCK_SUBMIT_SITE_NO' => $lock_submit_site_no,
	'L_LOCK_SUBMIT_SITE' => $lang['lock_submit_site'],
    'L_SITE_LOGO' => $lang['site_logo'],
	'L_SITE_URL' => $lang['site_url'],
	'L_WIDTH' => $lang['width'],
	'L_HEIGHT' => $lang['height'],
	'L_LINKSPP' => $lang['linkspp'],
	'L_DISPLAY_INTERVAL' => $lang['interval'],
	'L_DISPLAY_LOGO_NUM' => $lang['display_logo'],
	'INTERVAL' => phpbb_admin_html($new['display_interval']),
	'LOGO_NUM' => phpbb_admin_html($new['display_logo_num']),
	'SITE_LOGO' => phpbb_admin_html($new['site_logo']),
	'SITE_URL' => phpbb_admin_html($new['site_url']),
	'WIDTH' => phpbb_admin_html($new['width']),
	'HEIGHT' => phpbb_admin_html($new['height']),
	'LINKSPP' => phpbb_admin_html($new['linkspp']),

	// 'ALLOW_GUEST_SUBMIT_SITE_YES' => $allow_guest_submit_site_yes,
	// 'ALLOW_GUEST_SUBMIT_SITE_NO' => $allow_guest_submit_site_no,
	// 'L_ALLOW_GUEST_SUBMIT_SITE' => $lang['allow_guest_submit_site'],
	'ALLOW_NO_LOGO_YES' => $allow_no_logo_yes,
	'ALLOW_NO_LOGO_NO' => $allow_no_logo_no,
	'L_ALLOW_NO_LOGO' => $lang['allow_no_logo'],
	'DISLAY_LINKS_LOGO_YES' => $display_links_logo_yes,
	'DISLAY_LINKS_LOGO_NO' => $display_links_logo_no,
	'L_DISPLAY_LINKS_LOGO' => $lang['Link_display_links_logo'],
	'EMAIL_YES' => $email_yes,
	'EMAIL_NO' => $email_no,
	'L_LINK_EMAIL_NOTIFY' => $lang['Link_email_notify'],
	'PM_YES' => $pm_yes,
	'PM_NO' => $pm_no,
	'L_LINK_PM_NOTIFY' => $lang['Link_pm_notify'],
	'L_YES' => $lang['Yes'],
	'L_NO' => $lang['No'],
	'L_SUBMIT' => $lang['Submit'],
	'L_RESET' => $lang['Reset'])
);

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);

?>
