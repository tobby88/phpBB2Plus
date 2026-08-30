<?php
/***************************************************************************
 *                              admin_acronyms.php
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
	$file = basename(__FILE__);
	$module['General']['Acronyms'] = $file;
	return;
}

//
// Load default header
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

if( (isset($_POST['mode']) && is_scalar($_POST['mode'])) || (isset($_GET['mode']) && is_scalar($_GET['mode'])) )
{
	$mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] : (string) $_GET['mode'];
}
else 
{
	//
	// These could be entered via a form button
	//
	if( isset($_POST['add']) )
	{
		$mode = "add";
	}
	else if( isset($_POST['save']) )
	{
		$mode = "save";
	}
	else
	{
		$mode = "";
	}
}
$mode = in_array($mode, array('', 'add', 'edit', 'save', 'delete'), true) ? $mode : '';

if( $mode != "" )
{
	if( $mode == "edit" || $mode == "add" )
	{
		$acronym_id = (isset($_GET['id']) && is_scalar($_GET['id'])) ? max(0, intval($_GET['id'])) : 0;
		$acronym_info = array('acronym' => '', 'description' => '');

		$template->set_filenames(array(
			"body" => "admin/acronyms_edit_body.tpl")
		);

		$s_hidden_fields = phpbb_admin_session_field();

		if( $mode == "edit" )
		{
			if( $acronym_id )
			{
				$sql = 'SELECT * 
					FROM ' . ACRONYMS_TABLE . "
					WHERE acronym_id = $acronym_id";
					
				if(!$result = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, "Could not query acronym table", "Error", __LINE__, __FILE__, $sql);
				}

				$acronym_info = $db->sql_fetchrow($result);
				$db->sql_freeresult($result);
				if (!$acronym_info)
				{
					message_die(GENERAL_MESSAGE, $lang['No_acronym_selected']);
				}
				$s_hidden_fields .= '<input type="hidden" name="id" value="' . $acronym_id . '" />';
			}
			else
			{
				message_die(GENERAL_MESSAGE, $lang['No_acronym_selected']);
			}
		}

		$template->assign_vars(array(
			'ACRONYM' => phpbb_admin_html(html_entity_decode((string) $acronym_info['acronym'], ENT_QUOTES, 'UTF-8')),
			'DESCRIPTION' => phpbb_admin_html(html_entity_decode((string) $acronym_info['description'], ENT_QUOTES, 'UTF-8')),

			'L_ACRONYMS_TITLE' => $lang['Acronyms_title'],
			'L_ACRONYMS_TEXT' => $lang['Acronyms_explain'],
			'L_ACRONYM_EDIT' => $lang['Edit_acronym'],
			'L_ACRONYM' => $lang['Acronym'],
			'L_DESCRIPTION' => $lang['Description'],
			'L_SUBMIT' => $lang['Submit'],

			'S_ACRONYMS_ACTION' => append_sid("admin_acronyms.$phpEx"),
			'S_HIDDEN_FIELDS' => $s_hidden_fields)
		);

		$template->pparse("body");

		include('./page_footer_admin.'.$phpEx);
	}
	else if( $mode == "save" )
	{
		phpbb_admin_require_post_session();
		$acronym_id = ( isset($_POST['id']) ) ? intval($_POST['id']) : 0;
		$acronym = ( isset($_POST['acronym']) && is_scalar($_POST['acronym']) ) ? trim((string) $_POST['acronym']) : "";
		$description = ( isset($_POST['description']) && is_scalar($_POST['description']) ) ? trim((string) $_POST['description']) : "";

		if($acronym == "" || $description == "" || strlen($acronym) > 80 || strlen($description) > 255)
		{
			message_die(GENERAL_MESSAGE, $lang['Must_enter_acronym']);
		}
		$acronym_sql = $db->sql_escape($acronym);
		$description_sql = $db->sql_escape($description);

		if( $acronym_id )
		{
			$sql = "UPDATE " . ACRONYMS_TABLE . "
				SET acronym = '" . $acronym_sql . "', description = '" . $description_sql . "'
				WHERE acronym_id = $acronym_id";
			$message = $lang['Acronym_updated'];
		}
		else
		{
			$sql = 'SELECT acronym FROM ' . ACRONYMS_TABLE . " WHERE acronym = '" . $acronym_sql . "'";
			
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, "Could not insert data into words table", $lang['Error'], __LINE__, __FILE__, $sql);
			}
			
			if( $db->sql_fetchrow( $result ) )
			{
				$message = 'Acronym already in Database.';
				$message .= "<br /><br />" . sprintf($lang['Click_return_acronymadmin'], "<a href=\"" . append_sid("admin_acronyms.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
				
				$db->sql_freeresult( $result );
				
				message_die(GENERAL_MESSAGE, $message );
			}
			
			$db->sql_freeresult( $result );
			
			$sql = "INSERT INTO " . ACRONYMS_TABLE . " (acronym, description)
				VALUES ('" . $acronym_sql . "', '" . $description_sql . "')";
			
			$message = $lang['Acronym_added'];
		}

		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, "Could not insert data into words table", $lang['Error'], __LINE__, __FILE__, $sql);
		}

		$message .= "<br /><br />" . sprintf($lang['Click_return_acronymadmin'], "<a href=\"" . append_sid("admin_acronyms.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

		message_die(GENERAL_MESSAGE, $message);
	}
	else if( $mode == "delete" )
	{
		$confirmed = isset($_POST['confirm']);
		$acronym_id = ($confirmed && isset($_POST['id']) && is_scalar($_POST['id']))
			? max(0, intval($_POST['id']))
			: ((isset($_GET['id']) && is_scalar($_GET['id'])) ? max(0, intval($_GET['id'])) : 0);
		if (isset($_POST['cancel']))
		{
			redirect(append_sid("admin_acronyms.$phpEx"));
		}

		if( $acronym_id && $confirmed )
		{
			phpbb_admin_require_post_session();
			$sql = "DELETE FROM " . ACRONYMS_TABLE . "
				WHERE acronym_id = $acronym_id";

			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, "Could not remove data from words table", $lang['Error'], __LINE__, __FILE__, $sql);
			}

			$message = $lang['Acronym_removed'] . "<br /><br />" . sprintf($lang['Click_return_acronymadmin'], "<a href=\"" . append_sid("admin_acronyms.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
		}
		else if( $acronym_id )
		{
			$template->set_filenames(array(
				'body' => 'admin/confirm_body.tpl')
			);

			$hidden_fields = '<input type="hidden" name="mode" value="delete" />' .
				'<input type="hidden" name="id" value="' . $acronym_id . '" />' .
				phpbb_admin_session_field();

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $lang['Confirm'],
				'MESSAGE_TEXT' => $lang['Confirm_delete_acronym'],
				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],
				'S_CONFIRM_ACTION' => append_sid("admin_acronyms.$phpEx"),
				'S_HIDDEN_FIELDS' => $hidden_fields)
			);
		}
		else
		{
			message_die(GENERAL_MESSAGE, $lang['No_acronym_selected']);
		}
	}
}
else
{
	$template->set_filenames(array(
		"body" => "admin/acronyms_list_body.tpl")
	);

	$sql = "SELECT *
		FROM " . ACRONYMS_TABLE . "
		ORDER BY acronym";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Could not query words table", $lang['Error'], __LINE__, __FILE__, $sql);
	}

	$word_rows = $db->sql_fetchrowset($result);
	$word_count = count($word_rows);

	$template->assign_vars(array(
		'L_ACRONYMS_TITLE' => $lang['Acronyms_title'],
		'L_ACRONYMS_TEXT' => $lang['Acronyms_explain'],
		'L_ACRONYM' => $lang['Acronym'],
		'L_DESCRIPTION' => $lang['Description'],
		'L_EDIT' => $lang['Edit'],
		'L_DELETE' => $lang['Delete'],
		'L_ADD_ACRONYM' => $lang['Add_new_acronym'],
		'L_ACTION' => $lang['Action'],

		'S_ACRONYM_ACTION' => append_sid("admin_acronyms.$phpEx"),
		'S_HIDDEN_FIELDS' => '')
	);

	for($i = 0; $i < $word_count; $i++)
	{
		$acronym = $word_rows[$i]['acronym'];
		$description = $word_rows[$i]['description'];
		$acronym_id = $word_rows[$i]['acronym_id'];

		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

		$template->assign_block_vars('acronyms', array(
			'ROW_COLOR' => "#" . $row_color,
			'ROW_CLASS' => $row_class,
			'ACRONYM' => phpbb_admin_html($acronym),
			'DESCRIPTION' => phpbb_admin_html($description),

			'U_ACRONYM_EDIT' => append_sid("admin_acronyms.$phpEx?mode=edit&amp;id=$acronym_id"),
			'U_ACRONYM_DELETE' => append_sid("admin_acronyms.$phpEx?mode=delete&amp;id=$acronym_id"))
		);
	}
}

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);

?>
