<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
define('IN_PA_CONFIG_ADMIN', 1);
if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['Download']['Settings'] = $file;
	return;
}

$phpbb_root_path = "./../";

require($phpbb_root_path . 'extension.inc');

require('./pagestart.' . $phpEx);

include($phpbb_root_path . 'pafiledb/pafiledb_common.'.$phpEx);

$submit = (isset($_POST['submit'])) ? true : false;
$size = (isset($_POST['max_size']) && is_scalar($_POST['max_size'])) ? strtolower((string) $_POST['max_size']) : '';
if (!in_array($size, array('', 'b', 'kb', 'mb'), true))
{
	$size = '';
}

if ($submit)
{
	phpbb_admin_require_post_session();
}

require_once $phpbb_root_path . 'pafiledb/includes/functions_settings_storage.php';
$default_config = $pafiledb_functions->pafiledb_config();
$new = $default_config;
$settings_error = '';
if ($submit)
{
	try
	{
		$pafiledb_config = pafiledb_settings_save($_POST);
	}
	catch (PaFileDbFileActionFailure $error)
	{
		$key = $error->getMessage();
		$settings_error = isset($lang[$key]) ? $lang[$key] : $lang['PA_settings_failed'];
	}
	catch (PaFileDbFileStorageFailure $error) { $settings_error = $lang['PA_settings_directory']; }
	catch (Exception $error) { $settings_error = $lang['PA_settings_failed']; }
	catch (Error $error) { $settings_error = $lang['PA_settings_failed']; }
	if ($settings_error === '')
	{
		$message = $lang['Settings_changed'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid("admin_pa_settings.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid("index.$phpEx?pane=right") . '">', '</a>');
		message_die(GENERAL_MESSAGE, $message);
	}
	// Keep a rejected draft for correction. This only affects escaped output;
	// persistence validates the whole request against current locked settings.
	foreach (pafiledb_settings_fields() as $name)
	{
		if (isset($_POST[$name]))
		{
			$value = phpbb_request_raw_value($_POST[$name]);
			if (is_string($value) && strlen($value) <= 4096) { $new[$name] = $value; }
		}
	}
}
if (!$submit || !isset($_POST['max_file_size']) || !is_string(phpbb_request_raw_value($_POST['max_file_size'])))
{
	$display_size = pafiledb_settings_display_size($default_config['max_file_size']);
	$new['max_file_size'] = $display_size['value'];
	$size = $display_size['unit'];
}
$new['tpl_php'] = '0';
if ($settings_error !== '')
{
	$template->assign_block_vars('settings_error', array('MESSAGE' => phpbb_admin_html($settings_error)));
}

$template->set_filenames(array(
	'admin' => 'admin/pa_admin_settings.tpl')
);

$cat_auth_levels = array('ALL', 'REG', 'PRIVATE', 'MOD', 'ADMIN');
$cat_auth_const = array(AUTH_ALL, AUTH_REG, AUTH_ACL, AUTH_MOD, AUTH_ADMIN);
$global_auth = array('auth_search', 'auth_stats', 'auth_toplist', 'auth_viewall');
$auth_select = array();

foreach($global_auth as $auth)
{
	$auth_select[$auth]	= '&nbsp;<select name="' . $auth . '">';
	for($k = 0; $k < count($cat_auth_levels); $k++)
	{
		$selected = ( $new[$auth] == $cat_auth_const[$k] ) ? ' selected="selected"' : '';
		$auth_select[$auth] .= '<option value="' . $cat_auth_const[$k] . '"' . $selected . '>' . $lang['Category_' . $cat_auth_levels[$k]] . '</option>';
	}
	$auth_select[$auth] .= '</select>&nbsp;';
}



$view_all_yes = ($new['settings_viewall']) ? ' selected' : '';
$view_all_no = (!$new['settings_viewall']) ? ' selected' : '';

$download_disable_yes = ($new['settings_disable']) ? ' selected' : '';
$download_disable_no = (!$new['settings_disable']) ? ' selected' : '';

$hotlink_prevent_yes = ($new['hotlink_prevent']) ? ' selected' : '';
$hotlink_prevent_no = (!$new['hotlink_prevent']) ? ' selected' : '';

$allow_html_yes = ($new['allow_html']) ? ' selected' : '';
$allow_html_no = (!$new['allow_html']) ? ' selected' : '';

$allow_bbcode_yes = ($new['allow_bbcode']) ? ' selected' : '';
$allow_bbcode_no = (!$new['allow_bbcode']) ? ' selected' : '';

$allow_smilies_yes = ($new['allow_smilies']) ? ' selected' : '';
$allow_smilies_no = (!$new['allow_smilies']) ? ' selected' : '';

$allow_comment_links_yes = ($new['allow_comment_links']) ? ' selected' : '';
$allow_comment_links_no = (!$new['allow_comment_links']) ? ' selected' : '';

$allow_comment_images_yes = ($new['allow_comment_images']) ? ' selected' : '';
$allow_comment_images_no = (!$new['allow_comment_images']) ? ' selected' : '';

// MX Addon
$need_validation_yes = ($new['need_validation']) ? ' selected' : '';
$need_validation_no = (!$new['need_validation']) ? ' selected' : '';

$pm_notify_yes = ($new['pm_notify']) ? ' selected' : '';
$pm_notify_no = (!$new['pm_notify']) ? ' selected' : '';

$template->assign_vars(array(
	'S_SETTINGS_ACTION' => append_sid("admin_pa_settings.$phpEx"),
	'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),

	'L_MAX_FILE_SIZE' => $lang['Max_filesize'],
	'L_MAX_FILE_SIZE_INFO' => $lang['Max_filesize_explain'],
	'L_UPLOAD_DIR' => $lang['Upload_directory'],
	'L_UPLOAD_DIR_EXPLAIN' => $lang['Upload_directory_explain'],
	'L_SCREENSHOT_DIR' => $lang['Screenshots_directory'],
	'L_SCREENSHOT_DIR_EXPLAIN' => $lang['Screenshots_directory_explain'],
	'L_FORBIDDEN_EXTENSIONS' => $lang['Forbidden_extensions'],
	'L_FORBIDDEN_EXTENSIONS_EXPLAIN' => $lang['Forbidden_extensions_explain'],
	'L_PERMISSION_SETTINGS' => $lang['Permission_settings'],
	'L_ATUH_SEARCH' => $lang['Auth_search'],
	'L_ATUH_SEARCH_INFO' => $lang['Auth_search_explain'],
	'L_ATUH_STATS' => $lang['Auth_stats'],
	'L_ATUH_STATS_INFO' => $lang['Auth_stats_explain'],
	'L_ATUH_TOPLIST' => $lang['Auth_toplist'],
	'L_ATUH_TOPLIST_INFO' => $lang['Auth_toplist_explain'],
	'L_ATUH_VIEWALL' => $lang['Auth_viewall'],
	'L_ATUH_VIEWALL_INFO' => $lang['Auth_viewall_explain'],

	'L_FILE_IN_PAGE' => $lang['File_per_page'],
	'L_FILE_IN_PAGE_INFO' => $lang['File_per_page_info'],
	'L_HOTLINK' => $lang['Hotlink_prevent'],
	'L_HOTLINK_INFO' => $lang['Hotlinl_prevent_info'],
	'L_HOTLINK_ALLOWED' => $lang['Hotlink_allowed'],
	'L_HOTLINK_ALLOWED_INFO' => $lang['Hotlink_allowed_info'],
	'L_SETTINGS' => $lang['Settings'],
	'L_SETTINGSTITLE' => $lang['Settingstitle'],
	'L_SETTINGSEXPLAIN' => $lang['Settingsexplain'],
	'L_PHP_TPL' => $lang['Php_template'],
	'L_PHP_TPL_INFO' => $lang['Php_template_info'],
	'L_DBNAME' => $lang['Dbname'],
	'L_DBNAMEINFO' => $lang['Dbnameinfo'],
	'L_TOPNUM' => $lang['Topnum'],
	'L_TOPNUMINFO' => $lang['Topnuminfo'],
	'L_NFDAYS' => $lang['Nfdays'],
	'L_NFDAYSINFO' => $lang['Nfdaysinfo'],
	'L_COMMENT_SETTINGS' => $lang['Com_settings'],
	'L_SHOW_VIEWALL' => $lang['Showva'],
	'L_VIEWALL_INFO' => $lang['Showvainfo'],
	'L_DISABLE' => $lang['Dbdl'],
	'L_DISABLE_INFO' => $lang['Dbdlinfo'],
	'L_ALLOW_HTML' => $lang['Com_allowh'],
	'L_ALLOW_BBCODE' => $lang['Com_allowb'],
	'L_ALLOW_SMILIES' => $lang['Com_allows'],
	'L_ALLOW_LINKS' => $lang['Com_allowl'],
	'L_LINKS_MESSAGE' => $lang['Com_messagel'],
	'L_LINKS_MESSAGE_INFO' => $lang['Com_messagel_info'],
	'L_ALLOW_IMAGE' => $lang['Com_allowi'],
	'L_IMAGE_MESSAGE' => $lang['Com_messagei'],
	'L_MAX_CHAR' => $lang['Max_char'],
	'L_MAX_CHAR_INFO' => $lang['Max_char_info'],
	'L_IMAGE_MESSAGE_INFO' => $lang['Com_messagei_info'],
	'L_RESET' => $lang['Reset'],
	'L_SUBMIT' => $lang['Submit'],
	'L_YES' => $lang['Yes'],
	'L_NO' => $lang['No'],
	'L_DEFAULT_SORT_METHOD' => $lang['Default_sort_method'],
	'L_DEFAULT_SORT_ORDER' => $lang['Default_sort_order'],
	'L_RATING' => $lang['DlRating'],
	'L_DOWNLOADS' => $lang['Dls'],
	'L_DATE' => $lang['Date'],
	'L_NAME' => $lang['Name'],
	'L_UPDATE_TIME' => $lang['Update_time'],
	'L_ASC' => $lang['Sort_Ascending'],
	'L_DESC' => $lang['Sort_Descending'],

	// MX Addon
	'L_VALIDATION_SETTINGS' => $lang['Validation_settings'],
	'L_NEED_VALIDATION' => $lang['Need_validate'],
	'L_VALIDATOR' => $lang['Validator'],
	'L_PM_NOTIFY' => $lang['PM_notify'],

	'L_VALIDATOR_ADMIN_OPTION' => $lang['Validator_admin_option'],
	'L_VALIDATOR_MOD_OPTION' => $lang['Validator_mod_option'],

	'SETTINGS_DBNAME' => phpbb_admin_html($new['settings_dbname']),
	'SETTINGS_FILE_PAGE' => phpbb_admin_html($new['settings_file_page']),
	'HOTLINK_ALLOWED' => phpbb_admin_html($new['hotlink_allowed']),
	'SETTINGS_TOPNUMBER' => phpbb_admin_html($new['settings_topnumber']),
	'SETTINGS_NEWDAYS' => phpbb_admin_html($new['settings_newdays']),
	'MESSAGE_LINK' => phpbb_admin_html($new['no_comment_link_message']),
	'MAX_CHAR' => phpbb_admin_html($new['max_comment_chars']),
	'MESSAGE_IMAGE' => phpbb_admin_html($new['no_comment_image_message']),

	'SORT_NAME' => ($new['sort_method'] == 'file_name') ? 'selected="selected"' : '',
	'SORT_TIME' => ($new['sort_method'] == 'file_time') ? 'selected="selected"' : '',
	'SORT_RATING' => ($new['sort_method'] == 'file_rating') ? 'selected="selected"' : '',
	'SORT_DOWNLOADS' => ($new['sort_method'] == 'file_dls') ? 'selected="selected"' : '',
	'SORT_UPDATE_TIME' => ($new['sort_method'] == 'file_update_time') ? 'selected="selected"' : '',
	'SORT_ASC' => ($new['sort_order'] == 'ASC') ? 'selected="selected"' : '',
	'SORT_DESC' => ($new['sort_order'] == 'DESC') ? 'selected="selected"' : '',

	// MX addon
	'VALIDATOR_ADMIN' => ($new['validator'] == 'validator_admin') ? 'selected="selected"' : '',
	'VALIDATOR_MOD' => ($new['validator'] == 'validator_mod') ? 'selected="selected"' : '',

	'MAX_FILE_SIZE' => phpbb_admin_html($new['max_file_size']),
	'UPLOAD_DIR' => phpbb_admin_html($new['upload_dir']),
	'SCREENSHOT_DIR' => phpbb_admin_html($new['screenshots_dir']),
	'FORBIDDEN_EXTENSIONS' => phpbb_admin_html($new['forbidden_extensions']),

	'S_FILESIZE' => pa_size_select('max_size', $size),
	'S_ATUH_SEARCH' => $auth_select['auth_search'],
	'S_ATUH_STATS' => $auth_select['auth_stats'],
	'S_ATUH_TOPLIST' => $auth_select['auth_toplist'],
	'S_ATUH_VIEWALL' => $auth_select['auth_viewall'],
	'S_VIEW_ALL_YES' => $view_all_yes,
	'S_VIEW_ALL_NO' => $view_all_no,
	'S_DISABLE_YES' => $download_disable_yes,
	'S_DISABLE_NO' => $download_disable_no,
	'S_HOTLINK_YES' => $hotlink_prevent_yes,
	'S_HOTLINK_NO' => $hotlink_prevent_no,
	'S_ALLOW_HTML_YES' => $allow_html_yes,
	'S_ALLOW_HTML_NO' => $allow_html_no,
	'S_ALLOW_BBCODE_YES' => $allow_bbcode_yes,
	'S_ALLOW_BBCODE_NO' => $allow_bbcode_no,
	'S_ALLOW_SMILIES_YES' => $allow_smilies_yes,
	'S_ALLOW_SMILIES_NO' => $allow_smilies_no,
	'S_ALLOW_LINKS_YES' => $allow_comment_links_yes,
	'S_ALLOW_LINKS_NO' => $allow_comment_links_no,

	// MX Addon
	'S_NEED_VALIDATION_YES' => $need_validation_yes,
	'S_NEED_VALIDATION_NO' => $need_validation_no,
	'S_PM_NOTIFY_YES' => $pm_notify_yes,
	'S_PM_NOTIFY_NO' => $pm_notify_no,

	'S_ALLOW_IMAGES_YES' => $allow_comment_images_yes,
	'S_ALLOW_IMAGES_NO' => $allow_comment_images_no)
);

$template->pparse('admin');

$pafiledb->_pafiledb();
$cache->unload();

include('./page_footer_admin.'.$phpEx);


function pa_size_select($select_name, $size_compare)
{
	global $lang;

	$size_types_text = array($lang['Bytes'], $lang['KB'], $lang['MB']);
	$size_types = array('b', 'kb', 'mb');

	$select_field = '<select name="' . $select_name . '">';

	for ($i = 0; $i < count($size_types_text); $i++)
	{
		$selected = ($size_compare == $size_types[$i]) ? ' selected="selected"' : '';

		$select_field .= '<option value="' . $size_types[$i] . '"' . $selected . '>' . $size_types_text[$i] . '</option>';
	}

	$select_field .= '</select>';

	return ($select_field);
}

?>
