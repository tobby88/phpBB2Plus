<?php
/***************************************************************************
 *                          album_comment_delete.php
 *                            -------------------
 *   begin                : Saturday, February 15, 2003
 *   copyright            : (C) 2003 Smartor
 *   email                : smartor_xp@hotmail.com
 *
 *   $Id: album_comment_delete.php,v 2.0.4 2003/04/03 21:22:39 ngoctu Exp $
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

 /***************************************************************************
 *                            MODIFICATIONS
 *                           ---------------
 *   started            : Saturday, January 18, 2004
 *   copyright          : © Volodymyr (CLowN) Skoryk
 *   email              : blaatimmy72@yahoo.com
 *	 version            : 1.5
 *
 *	 MODIFICATIONS:
 *		-fixed links to link to album_showpage.php
 *
 *
 ***************************************************************************/

define('IN_PHPBB', true);
$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ALBUM);
init_userprefs($userdata);
//
// End session management
//


//
// Get general album information
//
include($album_root_path . 'album_common.'.$phpEx);
require_once $album_root_path . 'album_picture_discussion.php';


// ------------------------------------
// Check feature enabled
// ------------------------------------

if( $album_config['comment'] == 0 )
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}


// ------------------------------------
// Check the request
// ------------------------------------

if (isset($_GET['comment_id']) && is_scalar($_GET['comment_id']))
{
	$comment_id = album_read_id($_GET['comment_id']);
}
else if (isset($_POST['comment_id']) && is_scalar($_POST['comment_id']))
{
	$comment_id = album_read_id($_POST['comment_id']);
}
else
{
	message_die(GENERAL_ERROR, 'No comment_id specified');
}


// ------------------------------------
// Get the comment info
// ------------------------------------
$sql = "SELECT comment_id, comment_pic_id, comment_user_id
		FROM ". ALBUM_COMMENT_TABLE ."
		WHERE comment_id = '$comment_id'";

if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Could not query this comment information', '', __LINE__, __FILE__, $sql);
}

$thiscomment = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if( empty($thiscomment) )
{
	message_die(GENERAL_ERROR, 'This comment does not exist');
}


$pic_id = $thiscomment['comment_pic_id'];

// ------------------------------------
//--- Album Category Hierarchy : begin
//--- version : 1.1.0
// ------------------------------------
// Get this pic info and current category info
// ------------------------------------
$sql = album_picture_details_sql($pic_id);
if( !($result = $db->sql_query($sql)) )
{
	message_die(GENERAL_ERROR, 'Could not query pic information', '', __LINE__, __FILE__, $sql);
}
$thispic = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if (empty($thispic)) { message_die(GENERAL_ERROR, $lang['Pic_not_exist']); }
$cat_id = $thispic['pic_cat_id'];
$album_user_id = $thispic['cat_user_id'];

// ------------------------------------
// Check the permissions
// ------------------------------------
$album_user_access = album_permissions($album_user_id, $cat_id, ALBUM_AUTH_VIEW|ALBUM_AUTH_COMMENT|ALBUM_AUTH_DELETE|ALBUM_AUTH_UPLOAD, $thispic);
//--- Album Category Hierarchy : end

if (empty($album_user_access['view']) || empty($album_user_access['comment']) || empty($album_user_access['delete']))
{
	if (!$userdata['session_logged_in'])
	{
		redirect(append_sid("login.$phpEx?redirect=album_comment_delete.$phpEx?comment_id=$comment_id"));
	}
	else
	{
		message_die(GENERAL_ERROR, $lang['Not_Authorised']);
	}
}
else
{
	if (!$album_user_access['moderator'] && $userdata['user_level'] != ADMIN)
	{
		if ($thiscomment['comment_user_id'] != $userdata['user_id'])
		{
			message_die(GENERAL_ERROR, $lang['Not_Authorised']);
		}
	}
}


/*
+----------------------------------------------------------
| Main work here...
+----------------------------------------------------------
*/


$confirmed = isset($_POST['confirm']);
if ($confirmed && (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'POST' || !isset($_POST['sid']) || !is_scalar($_POST['sid']) || !hash_equals((string) $userdata['session_id'], (string) $_POST['sid'])))
{
	message_die(GENERAL_ERROR, $lang['Not_Authorised']);
}

if( !$confirmed )
{
	/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
               Confirm Screen
	   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

	// --------------------------------
	// If user give up deleting...
	// --------------------------------
	if( isset($_POST['cancel']) )
	{
		redirect(album_comment_link($comment_id, 'ASC', true));
		exit;
	}

	//
	// Start output of page
	//
	$page_title = $lang['Album'];
	include($phpbb_root_path . 'includes/page_header.'.$phpEx);

	$template->set_filenames(array(
		'body' => 'confirm_body.tpl')
	);

	$template->assign_vars(array(
		'MESSAGE_TITLE' => $lang['Confirm'],

		'MESSAGE_TEXT' => $lang['Comment_delete_confirm'],

		'L_NO' => $lang['No'],
		'L_YES' => $lang['Yes'],

		'S_CONFIRM_ACTION' => append_sid("album_comment_delete.$phpEx?comment_id=$comment_id"),
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
		)
	);

	//
	// Generate the page
	//
	$template->pparse('body');

	include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
}
else
{
	/* ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
              Do the deleting
	   ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~ */

	album_discussion_request('change', array(
		$userdata, (int) $album_user_id, (int) $cat_id, $pic_id, $comment_id));

	// --------------------------------
	// Complete... now send a message to user
	// --------------------------------

	$message = $lang['Deleted'];

	$template->assign_vars(array(
		'META' => '<meta http-equiv="refresh" content="3;url=' . append_sid(album_append_uid("album_cat.$phpEx?cat_id=$cat_id")) . '">')
	);

	$message .= "<br /><br />" . sprintf($lang['Click_return_category'], "<a href=\"" . append_sid(album_append_uid("album_cat.$phpEx?cat_id=$cat_id")) . "\">", "</a>");

	$message .= "<br /><br />" . sprintf($lang['Click_return_album_index'], "<a href=\"" . append_sid(album_append_uid("album.$phpEx")) . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);
}


// +-------------------------------------------------------------+
// |  Powered by Photo Album 2.x.x (c) 2002-2003 Smartor         |
// |  with Volodymyr (CLowN) Skoryk's Service Pack 1 © 2003-2004 |
// +-------------------------------------------------------------+

?>
