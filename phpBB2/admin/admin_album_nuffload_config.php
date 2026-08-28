<?php
/***************************************************************************
 *                       admin_album_nuffload_config.php
 *                             -------------------
 *   Author              : Nuffmon
 *   Version             : 1.1.0
 *   Modified            : 21/09/2005
 *   email               : nuffmon@hotmail.com
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Photo_Album']['Nuffload'] = $filename;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
define('CT_SECLEVEL', 'MEDIUM');
$ct_ignorepvar = array('path_to_bin');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_main_album.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_album.' . $phpEx);

//
// Pull all config data
//
$sql = "SELECT * FROM " . ALBUM_CONFIG_TABLE;
if(!$result = $db->sql_query($sql))
{
	message_die(CRITICAL_ERROR, "Could not query Album config information", "", __LINE__, __FILE__, $sql);
}
else
{
	while( $row = $db->sql_fetchrow($result) )
	{
		$config_name = $row['config_name'];
		$config_value = $row['config_value'];
		$default_config[$config_name] = isset($HTTP_POST_VARS['submit']) ? str_replace("'", "\'", $config_value) : $config_value;
		
		$new[$config_name] = ( isset($HTTP_POST_VARS[$config_name]) ) ? $HTTP_POST_VARS[$config_name] : $default_config[$config_name];

		if( isset($HTTP_POST_VARS['submit']) )
		{
			$sql = "UPDATE " . ALBUM_CONFIG_TABLE . " SET
				config_value = '" . str_replace("\'", "''", $new[$config_name]) . "'
				WHERE config_name = '$config_name'";
			if( !$db->sql_query($sql) )
			{
				message_die(GENERAL_ERROR, "Failed to update Album configuration for $config_name", "", __LINE__, __FILE__, $sql);
			}
		}
	}

	if( isset($HTTP_POST_VARS['submit']) )
	{
		$message = "Nuffload - " . $lang['Album_config_updated'] . "<br /><br />" . sprintf($lang['Click_return_album_config'], "<a href=\"" . append_sid("admin_album_nuffload_config.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

		message_die(GENERAL_MESSAGE, $message);
	}
}

$template->set_filenames(array(
	"body" => "admin/admin_album_nuffload_config_body.tpl")
);

$template->assign_vars(array(
	'L_PROGRESS_BAR_CONFIG' => $lang['progress_bar_configuration'],
	'L_MULTIPLE_UPLOADS_CONFIG' => $lang['multiple_uploads_configuration'],
	'L_RESIZE_PICS_CONFIG' => $lang['image_resizing_configuration'],
	'L_ALBUM_NUFFLOAD_CONFIG' => "Nuffload - " . $lang['Album_config'],
	'L_ALBUM_NUFFLOAD_CONFIG_EXPLAIN' => $lang['Album_config_explain'],
	'S_ALBUM_NUFFLOAD_CONFIG_ACTION' => append_sid('admin_album_nuffload_config.'.$phpEx),

	'PERL_UPLOADER_ENABLED' => ($new['perl_uploader'] == 1) ? 'checked="checked"' : '',
	'PERL_UPLOADER_DISABLED' => ($new['perl_uploader'] == 0) ? 'checked="checked"' : '',
	'PATH_TO_BIN' => $new['path_to_bin'],
	'SHOW_PROGRESS_BAR_ENABLED' => ($new['show_progress_bar'] == 1) ? 'checked="checked"' : '',
	'SHOW_PROGRESS_BAR_DISABLED' => ($new['show_progress_bar'] == 0) ? 'checked="checked"' : '',
	'CLOSE_ON_FINISH_ENABLED' => ($new['close_on_finish'] == 1) ? 'checked="checked"' : '',
	'CLOSE_ON_FINISH_DISABLED' => ($new['close_on_finish'] == 0) ? 'checked="checked"' : '',
	'MAX_PAUSE' => $new['max_pause'],
	'SIMPLE_FORMAT_ENABLED' => ($new['simple_format'] == 1) ? 'checked="checked"' : '',
	'SIMPLE_FORMAT_DISABLED' => ($new['simple_format'] == 0) ? 'checked="checked"' : '',
	'MULTIPLE_UPLOADS_ENABLED' => ($new['multiple_uploads'] == 1) ? 'checked="checked"' : '',
	'MULTIPLE_UPLOADS_DISABLED' => ($new['multiple_uploads'] == 0) ? 'checked="checked"' : '',
	'MAX_UPLOADS' => $new['max_uploads'],
	'ZIP_UPLOADS_ENABLED' => ($new['zip_uploads'] == 1) ? 'checked="checked"' : '',
	'ZIP_UPLOADS_DISABLED' => ($new['zip_uploads'] == 0) ? 'checked="checked"' : '',
	'RESIZE_PIC_ENABLED' => ($new['resize_pic'] == 1) ? 'checked="checked"' : '',
	'RESIZE_PIC_DISABLED' => ($new['resize_pic'] == 0) ? 'checked="checked"' : '',
	'RESIZE_WIDTH' => $new['resize_width'],
	'RESIZE_HEIGHT' => $new['resize_height'],
	'RESIZE_QUALITY' => $new['resize_quality'],

	'S_GUEST' => ALBUM_GUEST,
	'S_USER' => ALBUM_USER,
	'S_PRIVATE' => ALBUM_PRIVATE,
	'S_MOD' => ALBUM_MOD,
	'S_ADMIN' => ALBUM_ADMIN,

	'L_PERL_UPLOADER' => $lang['perl_uploader'],
	'L_PATH_TO_BIN' => $lang['path_to_bin'],
	'L_SHOW_PROGRESS_BAR' => $lang['show_progress_bar'],
	'L_CLOSE_ON_FINISH' => $lang['close_progress_bar'],
	'L_MAX_PAUSE' => $lang['activity_timeout'],
	'L_SIMPLE_FORMAT' => $lang['simple_format'],
	'L_MULTIPLE_UPLOADS' => $lang['multiple_uploads'],
	'L_MAX_UPLOADS' => $lang['max_uploads'],
	'L_ZIP_UPLOADS' => $lang['zip_uploads'],
	'L_RESIZE_PIC' => $lang['image_resizing'],
	'L_RESIZE_WIDTH' => $lang['image_width'],
	'L_RESIZE_HEIGHT' => $lang['image_height'],
	'L_RESIZE_QUALITY' => $lang['image_quality'],

	'L_GUEST' => $lang['Forum_ALL'],
	'L_REG' => $lang['Forum_REG'], 
	'L_PRIVATE' => $lang['Forum_PRIVATE'], 
	'L_MOD' => $lang['Forum_MOD'], 
	'L_ADMIN' => $lang['Forum_ADMIN'],

	'L_DISABLED' => $lang['Disabled'],
	'L_ENABLED' => $lang['Enabled'],
	'L_YES' => $lang['Yes'],
	'L_NO' => $lang['No'],
	'L_SUBMIT' => $lang['Submit'],
	'L_RESET' => $lang['Reset'])
);

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);
?>
