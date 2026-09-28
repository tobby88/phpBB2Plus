<?php
/***************************************************************************
 *                              admin_ranks.php
 *                            -------------------
 *   begin                : Thursday, Jul 12, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: admin_ranks.php,v 1.13.2.4 2004/03/25 15:57:20 acydburn Exp $
 *
 ***************************************************************************/
// Tell the Security Scanner that reachable code in this file is not a security issue


/***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

if( !empty($setmodules) )
{
	$file = basename(__FILE__);
	$module['Users']['Ranks'] = $file;
	return;
}

if (!defined('IN_PHPBB'))
{
    define( 'IN_PHPBB', 1);
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
$cancel = isset($_POST['cancel']);
$no_page_header = $cancel;

require('./pagestart.' . $phpEx);
require_once dirname(__DIR__) . '/includes/functions_ranks_storage.php';

function admin_ranks_apply($request)
{
	global $db, $lang;
	try { return phpbb_ranks_change($db, $request); }
	catch (Exception $error)
	{
		message_die(GENERAL_ERROR, $error instanceof PhpbbAclException ? $error->getMessage() : $lang['Ranks_storage_failed']);
	}
	catch (Error $error) { message_die(GENERAL_ERROR, $lang['Ranks_storage_failed']); }
}
if ($cancel)
{
	redirect('admin/' . append_sid("admin_ranks.$phpEx", true));
}

if( isset($_POST['mode']) || isset($_GET['mode']) )
{
	$mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] :
		((isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '');
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
// Restrict mode input to valid options
$mode = in_array($mode, array('add', 'edit', 'save', 'delete'), true) ? $mode : '';
$confirm = isset($_POST['confirm']);
if ($mode == 'save' || ($mode == 'delete' && $confirm))
{
	phpbb_admin_require_post_session();
}


if( $mode != "" )
{
	if( $mode == "edit" || $mode == "add" )
	{
		//
		// They want to add a new rank, show the form.
		//
		$rank_id = (isset($_GET['id']) && is_scalar($_GET['id'])) ? max(0, intval($_GET['id'])) : 0;
		
		$s_hidden_fields = phpbb_admin_session_field();
		
		if( $mode == "edit" )
		{
			if( empty($rank_id) )
			{
				message_die(GENERAL_MESSAGE, $lang['Must_select_rank']);
			}

			$sql = "SELECT * FROM " . RANKS_TABLE . "
				WHERE rank_id = $rank_id";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, "Couldn't obtain rank data", "", __LINE__, __FILE__, $sql);
			}
			
			$rank_info = $db->sql_fetchrow($result);
			$db->sql_freeresult($result);
			if (!$rank_info)
			{
				message_die(GENERAL_MESSAGE, $lang['Must_select_rank']);
			}
			$s_hidden_fields .= '<input type="hidden" name="id" value="' . $rank_id . '" />';

		}
		else
		{
			$rank_info = array('rank_title' => '', 'rank_special' => 0, 'rank_min' => 0, 'rank_image' => '');
		}

		$s_hidden_fields .= '<input type="hidden" name="mode" value="save" />';

		$rank_is_special = ( $rank_info['rank_special'] ) ? "checked=\"checked\"" : "";
		$rank_is_not_special = ( !$rank_info['rank_special'] ) ? "checked=\"checked\"" : "";
		
		$template->set_filenames(array(
			"body" => "admin/ranks_edit_body.tpl")
		);
		$rank_image_display = !empty($rank_info['rank_image']) ? phpbb_profile_image_name(basename((string) $rank_info['rank_image'])) : '';

		$template->assign_vars(array(
			"RANK" => phpbb_profile_text(isset($rank_info['rank_title']) ? $rank_info['rank_title'] : ''),
			"SPECIAL_RANK" => $rank_is_special,
			"NOT_SPECIAL_RANK" => $rank_is_not_special,
			"MINIMUM" => ( $rank_is_special ) ? "" : $rank_info['rank_min'],
			"IMAGE" => $rank_image_display,
			"IMAGE_DISPLAY" => ($rank_image_display !== '') ? '<img src="../' . $images['rank_path'] . $rank_image_display . '" alt="" />' : "",
			
			"L_RANKS_TITLE" => $lang['Ranks_title'],
			"L_RANKS_TEXT" => $lang['Ranks_explain'],
			"L_RANK_TITLE" => $lang['Rank_title'],
			"L_RANK_SPECIAL" => $lang['Rank_special'],
			"L_RANK_MINIMUM" => $lang['Rank_minimum'],
			"L_RANK_IMAGE" => $lang['Rank_image'],
			"L_RANK_IMAGE_EXPLAIN" => $lang['Rank_image_explain'],
			"L_SUBMIT" => $lang['Submit'],
			"L_RESET" => $lang['Reset'],
			"L_YES" => $lang['Yes'],
			"L_NO" => $lang['No'],
			
			"S_RANK_ACTION" => append_sid("admin_ranks.$phpEx"),
			"S_HIDDEN_FIELDS" => $s_hidden_fields)
		);
		
	}
	else if( $mode == "save" )
	{
		//
		// Ok, they sent us our info, let's update it.
		//
		
		$rank_request = $_POST; $rank_request['mode'] = 'save';
		admin_ranks_apply($rank_request);
		$message = isset($_POST['id']) && (int)$_POST['id'] > 0 ? $lang['Rank_updated'] : $lang['Rank_added'];

		$message .= "<br /><br />" . sprintf($lang['Click_return_rankadmin'], "<a href=\"" . append_sid("admin_ranks.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

		message_die(GENERAL_MESSAGE, $message);

	}
	else if( $mode == "delete" )
	{
		//
		// Ok, they want to delete their rank
		//
		
		$rank_id = ($confirm && isset($_POST['id']) && is_scalar($_POST['id']))
			? max(0, intval($_POST['id']))
			: ((isset($_GET['id']) && is_scalar($_GET['id'])) ? max(0, intval($_GET['id'])) : 0);
		
		if( $rank_id && $confirm )
		{
			$rank_request = $_POST; $rank_request['mode'] = 'delete';
			admin_ranks_apply($rank_request);

			$message = $lang['Rank_removed'] . "<br /><br />" . sprintf($lang['Click_return_rankadmin'], "<a href=\"" . append_sid("admin_ranks.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);

		}
		elseif( $rank_id && !$confirm)
		{
			// Present the confirmation screen to the user
			$template->set_filenames(array(
				'body' => 'admin/confirm_body.tpl')
			);

			$hidden_fields = '<input type="hidden" name="mode" value="delete" /><input type="hidden" name="id" value="' . $rank_id . '" />' . phpbb_admin_session_field();

			$template->assign_vars(array(
				'MESSAGE_TITLE' => $lang['Confirm'],
				'MESSAGE_TEXT' => $lang['Confirm_delete_rank'],

				'L_YES' => $lang['Yes'],
				'L_NO' => $lang['No'],

				'S_CONFIRM_ACTION' => append_sid("admin_ranks.$phpEx"),
				'S_HIDDEN_FIELDS' => $hidden_fields)
			);
		}
		else
		{
			message_die(GENERAL_MESSAGE, $lang['Must_select_rank']);
		}
	}

	$template->pparse("body");

	include('./page_footer_admin.'.$phpEx);
}

//
// Show the default page
//
$template->set_filenames(array(
	"body" => "admin/ranks_list_body.tpl")
);

$sql = "SELECT * FROM " . RANKS_TABLE . "
	ORDER BY rank_min ASC, rank_special ASC";
if( !$result = $db->sql_query($sql) )
{
	message_die(GENERAL_ERROR, "Couldn't obtain ranks data", "", __LINE__, __FILE__, $sql);
}
$rank_count = $db->sql_numrows($result);

$rank_rows = $db->sql_fetchrowset($result);

$template->assign_vars(array(
	"L_RANKS_TITLE" => $lang['Ranks_title'],
	"L_RANKS_TEXT" => $lang['Ranks_explain'],
	"L_RANK" => $lang['Rank_title'],
	"L_RANK_MINIMUM" => $lang['Rank_minimum'],
	"L_SPECIAL_RANK" => $lang['Rank_special'],
	"L_EDIT" => $lang['Edit'],
	"L_DELETE" => $lang['Delete'],
	"L_ADD_RANK" => $lang['Add_new_rank'],
	"L_ACTION" => $lang['Action'],
	
	"S_RANKS_ACTION" => append_sid("admin_ranks.$phpEx"))
);

for($i = 0; $i < $rank_count; $i++)
{
	$rank = $rank_rows[$i]['rank_title'];
	$special_rank = $rank_rows[$i]['rank_special'];
	$rank_id = $rank_rows[$i]['rank_id'];
	$rank_min = $rank_rows[$i]['rank_min'];
	
	if( $special_rank == 1 )
	{
		$rank_min = $rank_max = "-";
	}

	$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
	$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];

	$rank_is_special = ( $special_rank ) ? $lang['Yes'] : $lang['No'];
	
	$template->assign_block_vars("ranks", array(
		"ROW_COLOR" => "#" . $row_color,
		"ROW_CLASS" => $row_class,
		"RANK" => phpbb_profile_text($rank),
		"SPECIAL_RANK" => $rank_is_special,
		"RANK_MIN" => $rank_min,

		"U_RANK_EDIT" => append_sid("admin_ranks.$phpEx?mode=edit&amp;id=$rank_id"),
		"U_RANK_DELETE" => append_sid("admin_ranks.$phpEx?mode=delete&amp;id=$rank_id"))
	);
}

$template->pparse("body");

include('./page_footer_admin.'.$phpEx);

?>
