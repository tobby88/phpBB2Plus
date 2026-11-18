<?php
/***************************************************************************
*                               admin_smilies.php
*                              -------------------
*     begin                : Thu May 31, 2001
*     copyright            : (C) 2001 The phpBB Group
*     email                : support@phpbb.com
*
*     $Id: admin_smilies.php,v 1.22.2.13 2004/03/25 15:57:20 acydburn Exp $
*
****************************************************************************/

/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

/**************************************************************************
*	This file will be used for modifying the smiley settings for a board.
**************************************************************************/
// Tell the Security Scanner that reachable code in this file is not a security issue


if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', 1);
}

//
// First we do the setmodules stuff for the admin cp.
//
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['General']['Smilies'] = $filename;

	return;
}

$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');

$cancel = isset($_POST['cancel']);
$no_page_header = $cancel;

//
// Load default header
//
$export_pack_get = (isset($_GET['export_pack']) && is_scalar($_GET['export_pack'])) ? (string) $_GET['export_pack'] : '';
if ($export_pack_get === 'send')
{
	$no_page_header = true;
}

require('./pagestart.' . $phpEx);
if ($cancel)
{
	redirect('admin/' . append_sid("admin_smilies.$phpEx", true));
}

require_once dirname(__DIR__) . '/includes/functions_smilies_storage.php';

//
// Check to see what mode we should operate in.
//
$mode = admin_smiley_request_string($_POST, 'mode');
if ($mode === '')
{
	$mode = admin_smiley_request_string($_GET, 'mode');
}
$mode = in_array($mode, array('delete', 'edit', 'save', 'savenew'), true) ? $mode : '';

$confirm = isset($_POST['confirm']);
if ($mode === 'save' || $mode === 'savenew' || ($mode === 'delete' && $confirm))
{
	phpbb_admin_require_post_session();
}

$delimeter  = '=+:';

//
// Read a listing of uploaded smilies for use in the add or edit smliey code...
//
$smiley_images = array();
$smiley_paks = array();
$smiles = array();
$smilies_dir = $phpbb_root_path . $board_config['smilies_path'];
$dir = @opendir($smilies_dir);

while($dir && ($file = @readdir($dir)) !== false)
{
	$safe_file = phpbb_profile_image_name($file);
	if ($safe_file !== '' && !@is_dir(phpbb_realpath($smilies_dir . '/' . $safe_file)))
	{
		$img_size = @getimagesize($smilies_dir . '/' . $safe_file);

		if( is_array($img_size) && !empty($img_size[0]) && !empty($img_size[1]) )
		{
			$smiley_images[] = $safe_file;
		}
		else if( preg_match('/\.pak$/iD', $safe_file) )
		{	
			$smiley_paks[] = $safe_file;
		}
	}
}

if ($dir)
{
	@closedir($dir);
}
sort($smiley_images, SORT_STRING);
sort($smiley_paks, SORT_STRING);

//
// Select main mode
//
if( isset($_GET['import_pack']) || isset($_POST['import_pack']) )
{
	//
	// Import a list a "Smiley Pack"
	//
	$smile_pak = admin_smiley_request_string($_POST, 'smile_pak');

	if ( !empty($smile_pak) )
	{
		phpbb_admin_require_post_session();
		try { phpbb_smilies_admin_change($db, 'import', $_POST, $phpbb_root_path); }
		catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

		$message = $lang['smiley_import_success'] . "<br /><br />" . sprintf($lang['Click_return_smileadmin'], "<a href=\"" . append_sid("admin_smilies.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

		message_die(GENERAL_MESSAGE, $message);
		
	}
	else
	{
		//
		// Display the script to get the smile_pak cfg file...
		//
		$smile_paks_select = "<select name='smile_pak'><option value=''>" . $lang['Select_pak'] . "</option>";
		foreach ($smiley_paks as $key => $value)
		{
			if ( !empty($value) ) 
			{
				$smile_paks_select .= '<option value="' . admin_smiley_html($value) . '">' . admin_smiley_html($value) . '</option>';
			}
		}
		$smile_paks_select .= "</select>";

		$hidden_vars = '<input type="hidden" name="mode" value="import" />' . phpbb_admin_session_field();

		$template->set_filenames(array(
			"body" => "admin/smile_import_body.tpl")
		);

		$template->assign_vars(array(
			"L_SMILEY_TITLE" => $lang['smiley_title'],
			"L_SMILEY_EXPLAIN" => $lang['smiley_import_inst'],
			"L_SMILEY_IMPORT" => $lang['smiley_import'],
			"L_SELECT_LBL" => $lang['choose_smile_pak'],
			"L_IMPORT" => $lang['import'],
			"L_CONFLICTS" => $lang['smile_conflicts'],
			"L_DEL_EXISTING" => $lang['del_existing_smileys'], 
			"L_REPLACE_EXISTING" => $lang['replace_existing'], 
			"L_KEEP_EXISTING" => $lang['keep_existing'], 

			"S_SMILEY_ACTION" => append_sid("admin_smilies.$phpEx"),
			"S_SMILE_SELECT" => $smile_paks_select,
			"S_HIDDEN_FIELDS" => $hidden_vars)
		);

		$template->pparse("body");
	}
}
else if( isset($_POST['export_pack']) || isset($_GET['export_pack']) )
{
	//
	// Export our smiley config as a smiley pak...
	//
	if ($export_pack_get === 'send')
	{	
		$sql = "SELECT * 
			FROM " . SMILIES_TABLE;
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, "Could not get smiley list", "", __LINE__, __FILE__, $sql);
		}

		$resultset = $db->sql_fetchrowset($result);
		$db->sql_freeresult($result);

		$smile_pak = "";
		for($i = 0; $i < count($resultset); $i++ )
		{
			$smile_url = str_replace(array("\r", "\n", $delimeter), ' ', (string) $resultset[$i]['smile_url']);
			$smile_emotion = str_replace(array("\r", "\n", $delimeter), ' ', (string) $resultset[$i]['emoticon']);
			$smile_code = str_replace(array("\r", "\n", $delimeter), ' ', (string) $resultset[$i]['code']);
			$smile_pak .= $smile_url . $delimeter . $smile_emotion . $delimeter . $smile_code . "\n";
		}

		header('Content-Type: text/plain; charset=UTF-8');
		header('Content-Disposition: attachment; filename="smiles.pak"');
		header('X-Content-Type-Options: nosniff');

		echo $smile_pak;

		exit;
	}

	$message = sprintf($lang['export_smiles'], "<a href=\"" . append_sid("admin_smilies.$phpEx?export_pack=send", true) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_smileadmin'], "<a href=\"" . append_sid("admin_smilies.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);

}
else if( isset($_POST['add']) || isset($_GET['add']) )
{
	//
	// Admin has selected to add a smiley.
	//

	$template->set_filenames(array(
		"body" => "admin/smile_edit_body.tpl")
	);

	if (empty($smiley_images))
	{
		message_die(GENERAL_MESSAGE, $lang['No_smiley_images']);
	}

	$filename_list = "";
	for( $i = 0; $i < count($smiley_images); $i++ )
	{
		$filename_list .= '<option value="' . admin_smiley_html($smiley_images[$i]) . '">' . admin_smiley_html($smiley_images[$i]) . '</option>';
	}

	$s_hidden_fields = '<input type="hidden" name="mode" value="savenew" />' . phpbb_admin_session_field();

	$template->assign_vars(array(
		"L_SMILEY_TITLE" => $lang['smiley_title'],
		"L_SMILEY_CONFIG" => $lang['smiley_config'],
		"L_SMILEY_EXPLAIN" => $lang['smile_desc'],
		"L_SMILEY_CODE" => $lang['smiley_code'],
		"L_SMILEY_URL" => $lang['smiley_url'],
		"L_SMILEY_EMOTION" => $lang['smiley_emot'],
		"L_SUBMIT" => $lang['Submit'],
		"L_RESET" => $lang['Reset'],

		"SMILEY_CODE" => '',
		"SMILEY_EMOTICON" => '',
		"SMILEY_IMG" => admin_smiley_html($phpbb_root_path . $board_config['smilies_path'] . '/' . $smiley_images[0]),

		"S_SMILEY_ACTION" => append_sid("admin_smilies.$phpEx"), 
		"S_HIDDEN_FIELDS" => isset($s_hidden_fields) ? $s_hidden_fields : '', 
		"S_FILENAME_OPTIONS" => $filename_list, 
		"S_SMILEY_BASEDIR" => admin_smiley_html($phpbb_root_path . $board_config['smilies_path']))
	);

	$template->pparse("body");
}
else if ( $mode != "" )
{
	switch( $mode )
	{
		case 'delete':
			//
			// Admin has selected to delete a smiley.
			//

			$smiley_id = $confirm ? admin_smiley_request_int($_POST, 'id') : admin_smiley_request_int($_GET, 'id');
			if (!$smiley_id)
			{
				message_die(GENERAL_MESSAGE, $lang['No_smiley_selected']);
			}

			if( $confirm )
			{
				try { phpbb_smilies_admin_change($db, 'delete', $_POST, $phpbb_root_path); }
				catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

				$message = $lang['smiley_del_success'] . "<br /><br />" . sprintf($lang['Click_return_smileadmin'], "<a href=\"" . append_sid("admin_smilies.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

				message_die(GENERAL_MESSAGE, $message);
			}
			else
			{
				// Present the confirmation screen to the user
				$template->set_filenames(array(
					'body' => 'admin/confirm_body.tpl')
				);

				$hidden_fields = '<input type="hidden" name="mode" value="delete" /><input type="hidden" name="id" value="' . $smiley_id . '" />' . phpbb_admin_session_field();

				$template->assign_vars(array(
					'MESSAGE_TITLE' => $lang['Confirm'],
					'MESSAGE_TEXT' => $lang['Confirm_delete_smiley'],

					'L_YES' => $lang['Yes'],
					'L_NO' => $lang['No'],

					'S_CONFIRM_ACTION' => append_sid("admin_smilies.$phpEx"),
					'S_HIDDEN_FIELDS' => $hidden_fields)
				);
				$template->pparse('body');
			}
			break;

		case 'edit':
			//
			// Admin has selected to edit a smiley.
			//

			$smiley_id = admin_smiley_request_int($_POST, 'id');
			if (!$smiley_id)
			{
				$smiley_id = admin_smiley_request_int($_GET, 'id');
			}
			if (!$smiley_id)
			{
				message_die(GENERAL_MESSAGE, $lang['No_smiley_selected']);
			}

			$sql = "SELECT *
				FROM " . SMILIES_TABLE . "
				WHERE smilies_id = " . $smiley_id;
			$result = $db->sql_query($sql);
			if( !$result )
			{
				message_die(GENERAL_ERROR, 'Could not obtain emoticon information', "", __LINE__, __FILE__, $sql);
			}
			$smile_data = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if (!$smile_data)
			{
				message_die(GENERAL_MESSAGE, $lang['No_smiley_selected']);
			}

			if (empty($smiley_images))
			{
				message_die(GENERAL_MESSAGE, $lang['No_smiley_images']);
			}
			$filename_list = "";
			$smiley_edit_img = $smiley_images[0];
			for( $i = 0; $i < count($smiley_images); $i++ )
			{
				if( $smiley_images[$i] == $smile_data['smile_url'] )
				{
					$smiley_selected = "selected=\"selected\"";
					$smiley_edit_img = $smiley_images[$i];
				}
				else
				{
					$smiley_selected = "";
				}

				$filename_list .= '<option value="' . admin_smiley_html($smiley_images[$i]) . '" ' . $smiley_selected . '>' . admin_smiley_html($smiley_images[$i]) . '</option>';
			}

			$template->set_filenames(array(
				"body" => "admin/smile_edit_body.tpl")
			);

			$s_hidden_fields = '<input type="hidden" name="mode" value="save" /><input type="hidden" name="smile_id" value="' . intval($smile_data['smilies_id']) . '" />' . phpbb_admin_session_field();

			$template->assign_vars(array(
				"SMILEY_CODE" => admin_smiley_html(str_replace(array('&lt;', '&gt;'), array('<', '>'), (string)$smile_data['code'])),
				"SMILEY_EMOTICON" => admin_smiley_html($smile_data['emoticon']),

				"L_SMILEY_TITLE" => $lang['smiley_title'],
				"L_SMILEY_CONFIG" => $lang['smiley_config'],
				"L_SMILEY_EXPLAIN" => $lang['smile_desc'],
				"L_SMILEY_CODE" => $lang['smiley_code'],
				"L_SMILEY_URL" => $lang['smiley_url'],
				"L_SMILEY_EMOTION" => $lang['smiley_emot'],
				"L_SUBMIT" => $lang['Submit'],
				"L_RESET" => $lang['Reset'],

				"SMILEY_IMG" => admin_smiley_html($phpbb_root_path . $board_config['smilies_path'] . '/' . $smiley_edit_img),

				"S_SMILEY_ACTION" => append_sid("admin_smilies.$phpEx"),
				"S_HIDDEN_FIELDS" => $s_hidden_fields, 
				"S_FILENAME_OPTIONS" => $filename_list, 
				"S_SMILEY_BASEDIR" => admin_smiley_html($phpbb_root_path . $board_config['smilies_path']))
			);

			$template->pparse("body");
			break;

		case "save":
			try { phpbb_smilies_admin_change($db, 'save', $_POST, $phpbb_root_path); }
			catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

			$message = $lang['smiley_edit_success'] . "<br /><br />" . sprintf($lang['Click_return_smileadmin'], "<a href=\"" . append_sid("admin_smilies.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
			break;

		case "savenew":
			try { phpbb_smilies_admin_change($db, 'add', $_POST, $phpbb_root_path); }
			catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }

			$message = $lang['smiley_add_success'] . "<br /><br />" . sprintf($lang['Click_return_smileadmin'], "<a href=\"" . append_sid("admin_smilies.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
			break;
	}
}
else
{

	//
	// This is the main display of the page before the admin has selected
	// any options.
	//
	$sql = "SELECT *
		FROM " . SMILIES_TABLE;
	$result = $db->sql_query($sql);
	if( !$result )
	{
		message_die(GENERAL_ERROR, "Couldn't obtain smileys from database", "", __LINE__, __FILE__, $sql);
	}

	$smilies = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);

	$template->set_filenames(array(
		"body" => "admin/smile_list_body.tpl")
	);

	$template->assign_vars(array(
		"L_ACTION" => $lang['Action'],
		"L_SMILEY_TITLE" => $lang['smiley_title'],
		"L_SMILEY_TEXT" => $lang['smile_desc'],
		"L_DELETE" => $lang['Delete'],
		"L_EDIT" => $lang['Edit'],
		"L_SMILEY_ADD" => $lang['smile_add'],
		"L_CODE" => $lang['Code'],
		"L_EMOT" => $lang['Emotion'],
		"L_SMILE" => $lang['Smile'],
		"L_IMPORT_PACK" => $lang['import_smile_pack'],
		"L_EXPORT_PACK" => $lang['export_smile_pack'],
		
		"S_HIDDEN_FIELDS" => phpbb_admin_session_field(),
		"S_SMILEY_ACTION" => append_sid("admin_smilies.$phpEx"))
	);

	//
	// Loop throuh the rows of smilies setting block vars for the template.
	//
	for($i = 0; $i < count($smilies); $i++)
	{
		//
		// Replace htmlentites for < and > with actual character.
		//
		$smiley_code_display = str_replace(array('&lt;', '&gt;'), array('<', '>'), (string) $smilies[$i]['code']);
		$smiley_image = phpbb_profile_image_name($smilies[$i]['smile_url']);
		
		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

		$template->assign_block_vars("smiles", array(
			"ROW_COLOR" => "#" . $row_color,
			"ROW_CLASS" => $row_class,
			
			"SMILEY_IMG" => admin_smiley_html($phpbb_root_path . $board_config['smilies_path'] . '/' . $smiley_image),
			"CODE" => admin_smiley_html($smiley_code_display),
			"EMOT" => admin_smiley_html($smilies[$i]['emoticon']),
			
			"U_SMILEY_EDIT" => append_sid("admin_smilies.$phpEx?mode=edit&amp;id=" . intval($smilies[$i]['smilies_id'])),
			"U_SMILEY_DELETE" => append_sid("admin_smilies.$phpEx?mode=delete&amp;id=" . intval($smilies[$i]['smilies_id'])))
		);
	}

	//
	// Spit out the page.
	//
	$template->pparse("body");
}

//
// Page Footer
//
include('./page_footer_admin.'.$phpEx);

?>
