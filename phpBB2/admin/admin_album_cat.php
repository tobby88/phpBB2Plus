<?php
/***************************************************************************
 *                            admin_album_cat.php
 *                             -------------------
 *   begin                : Monday, February 03, 2003
 *   copyright            : (C) 2003 Smartor
 *   email                : smartor_xp@hotmail.com
 *
 *   $Id: admin_album_cat.php,v 1.0.3 2003/03/05, 20:19:28 ngoctu Exp $
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
if( !empty($setmodules) )
{
	$filename = basename(__FILE__);
	$module['Photo_Album']['Categories'] = $filename;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = '../';
//--- Album Category Hierarchy : begin
//--- version : <= 1.1.0
$album_root_path = $phpbb_root_path . 'album_mod/';
//--- Album Category Hierarchy : end
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_main_album.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_album.' . $phpEx);

require($album_root_path. 'album_common.'.$phpEx);
require_once $album_root_path . 'album_admin_category_storage.' . $phpEx;

$album_user_id = ALBUM_PUBLIC_GALLERY;
$template->assign_var('S_FORM_TOKEN', phpbb_admin_session_field());

function album_admin_post_scalar($key, $default = '')
{
	return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? stripslashes((string) $_POST[$key]) : (string) $default;
}

function album_admin_permission_level($key, $default, $allow_guest = true)
{
	$value = intval(album_admin_post_scalar($key, $default));
	$allowed = $allow_guest ? array(ALBUM_GUEST, ALBUM_USER, ALBUM_PRIVATE, ALBUM_MOD, ALBUM_ADMIN) : array(ALBUM_USER, ALBUM_PRIVATE, ALBUM_MOD, ALBUM_ADMIN);
	return in_array($value, $allowed, true) ? $value : $default;
}

function album_admin_category_post_values($title,$description,$view,$upload,$rate,$comment,$edit,$delete,$approval)
{
	return array('cat_title'=>$title,'cat_desc'=>$description,'cat_view_level'=>$view,'cat_upload_level'=>$upload,
		'cat_rate_level'=>$rate,'cat_comment_level'=>$comment,'cat_edit_level'=>$edit,'cat_delete_level'=>$delete,'cat_approval'=>$approval);
}

$post_mode = album_admin_post_scalar('mode');
if ($post_mode !== '')
{
	if (!in_array($post_mode, array('new', 'edit', 'delete'), true))
	{
		message_die(GENERAL_ERROR, 'Invalid Album category action.');
	}
	phpbb_admin_require_post_session();
}

function showResultMessage($in_message)
{
	global $lang, $album_user_id, $phpEx;

	$message = $in_message . "<br /><br />" . sprintf($lang['Click_return_album_category'], "<a href=\"" . append_sid("admin_album_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

	message_die(GENERAL_MESSAGE, $message);
}

$get_action = (isset($_GET['action']) && is_scalar($_GET['action'])) ? (string) $_GET['action'] : '';
if (!in_array($get_action, array('', 'edit', 'delete', 'move'), true))
{
	$get_action = '';
}

if( $post_mode === '' )
{
	if( $get_action === '' )
	{
		//--- Album Category Hierarchy : begin
		//--- version <= 1.1.0
		album_read_tree();

		/* if we still get layout issuse then replace the template file with this
		   'admin/album_cat_body_debug.tpl', BUT ONLY FOR DEBUGGIN PURPOSE, and seen me a screen shot of it
		   then go back to this template file 'admin/album_cat_body.tpl'.
		*/
		$template->set_filenames(array(
			'body' => 'admin/album_cat_body.tpl')
		);
		//--- Album Category Hierarchy : end

		$template->assign_vars(array(
			'S_ALBUM_ACTION' => append_sid("admin_album_cat.$phpEx"),
			'L_CREATE_CATEGORY' => $lang['Create_category'],
			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			'L_ALBUM_INDEX'	=> $lang['Album_Categories_Title']
			//--- Album Category Hierarchy : end
			)
		);

		//--- Album Category Hierarchy : begin
		//--- version <= 1.1.0
		// get the values of level selected
		$parent = !empty($cat_id) ? $cat_id : ALBUM_ROOT_CATEGORY;

		if (!isset($album_cat_tree['keys'][$parent]))
			$parent = ALBUM_ROOT_CATEGORY;

		// display the tree
		album_display_admin_index($parent);
		//--- Album Category Hierarchy : end

		$template->pparse('body');

		include('./page_footer_admin.'.$phpEx);
	}
	else
	{
		if( $get_action == 'edit' )
		{
			$cat_id = (isset($_GET['cat_id']) && is_scalar($_GET['cat_id'])) ? intval($_GET['cat_id']) : 0;

			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			$sql = "SELECT cat.*, cat2.cat_title AS cat_parent_title, cat2.cat_id AS cat_parent_id
					FROM ". ALBUM_CAT_TABLE ." AS cat LEFT OUTER JOIN ". ALBUM_CAT_TABLE ." AS cat2
					ON cat2.cat_id = cat.cat_parent WHERE cat.cat_id = '$cat_id'";
			//--- Album Category Hierarchy : end
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Album Categories information', '', __LINE__, __FILE__, $sql);
			}
			
			if( $db->sql_numrows($result) == 0 )
			{
				message_die(GENERAL_ERROR, 'The requested category is not existed');
			}
			$catrow = $db->sql_fetchrow($result);
			
			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			album_read_tree();
			$s_album_cat_list = album_get_tree_option($catrow['cat_parent_id'], ALBUM_AUTH_VIEW, ALBUM_SELECTBOX_INCLUDE_ALL | ALBUM_SELECTBOX_INCLUDE_ROOT);
			//--- Album Category Hierarchy : end

			$template->set_filenames(array(
				'body' => 'admin/album_cat_new_body.tpl')
			);

			$template->assign_block_vars('acp', array(
				'L_ALBUM_CAT_TITLE' => $lang['Album_Categories_Title'],
				'L_ALBUM_CAT_EXPLAIN' => $lang['Album_Categories_Explain']
				)
			);

			$template->assign_vars(array(
				//--- Album Category Hierarchy : begin
				//--- version = 1.1.0
				//-- deleted
				//--- Album Category Hierarchy : end
				'S_ALBUM_ACTION' => append_sid("admin_album_cat.$phpEx?cat_id=$cat_id"),
				'L_CAT_TITLE' => $lang['Category_Title'],
				'L_CAT_DESC' => $lang['Category_Desc'],
				//--- Album Category Hierarchy : begin
				//--- version <= 1.1.0
				'L_CAT_PARENT_TITLE' => $lang['Parent_Category'],
				//--- Album Category Hierarchy : end
				'L_CAT_PERMISSIONS' => $lang['Category_Permissions'],
				'L_VIEW_LEVEL' => $lang['View_level'],
				'L_UPLOAD_LEVEL' => $lang['Upload_level'],
				'L_RATE_LEVEL' => $lang['Rate_level'],
				'L_COMMENT_LEVEL' => $lang['Comment_level'],
				'L_EDIT_LEVEL' => $lang['Edit_level'],
				'L_DELETE_LEVEL' => $lang['Delete_level'],
				'L_PICS_APPROVAL' => $lang['Pics_Approval'],
				'L_GUEST' => $lang['Forum_ALL'], 
				'L_REG' => $lang['Forum_REG'], 
				'L_PRIVATE' => $lang['Forum_PRIVATE'], 
				'L_MOD' => $lang['Forum_MOD'], 
				'L_ADMIN' => $lang['Forum_ADMIN'],

				'L_DISABLED' => $lang['Disabled'],

				'S_CAT_TITLE' => phpbb_admin_html($catrow['cat_title']),
				'S_CAT_DESC' => phpbb_admin_html($catrow['cat_desc']),
				//--- Album Category Hierarchy : begin
				//--- version <= 1.1.0
				'S_CAT_PARENT_OPTIONS' => $s_album_cat_list,
				//--- Album Category Hierarchy : end
				'VIEW_GUEST' => ($catrow['cat_view_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'VIEW_REG' => ($catrow['cat_view_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'VIEW_PRIVATE' => ($catrow['cat_view_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'VIEW_MOD' => ($catrow['cat_view_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'VIEW_ADMIN' => ($catrow['cat_view_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'UPLOAD_GUEST' => ($catrow['cat_upload_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'UPLOAD_REG' => ($catrow['cat_upload_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'UPLOAD_PRIVATE' => ($catrow['cat_upload_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'UPLOAD_MOD' => ($catrow['cat_upload_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'UPLOAD_ADMIN' => ($catrow['cat_upload_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'RATE_GUEST' => ($catrow['cat_rate_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'RATE_REG' => ($catrow['cat_rate_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'RATE_PRIVATE' => ($catrow['cat_rate_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'RATE_MOD' => ($catrow['cat_rate_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'RATE_ADMIN' => ($catrow['cat_rate_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'COMMENT_GUEST' => ($catrow['cat_comment_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'COMMENT_REG' => ($catrow['cat_comment_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'COMMENT_PRIVATE' => ($catrow['cat_comment_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'COMMENT_MOD' => ($catrow['cat_comment_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'COMMENT_ADMIN' => ($catrow['cat_comment_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'EDIT_REG' => ($catrow['cat_edit_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'EDIT_PRIVATE' => ($catrow['cat_edit_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'EDIT_MOD' => ($catrow['cat_edit_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'EDIT_ADMIN' => ($catrow['cat_edit_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'DELETE_REG' => ($catrow['cat_delete_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'DELETE_PRIVATE' => ($catrow['cat_delete_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',
				'DELETE_MOD' => ($catrow['cat_delete_level'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'DELETE_ADMIN' => ($catrow['cat_delete_level'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'APPROVAL_DISABLED' => ($catrow['cat_approval'] == ALBUM_USER) ? 'selected="selected"' : '',
				'APPROVAL_MOD' => ($catrow['cat_approval'] == ALBUM_MOD) ? 'selected="selected"' : '',
				'APPROVAL_ADMIN' => ($catrow['cat_approval'] == ALBUM_ADMIN) ? 'selected="selected"' : '',

				'S_MODE' => 'edit',
				'S_CAT_ID' => $cat_id,

				'S_GUEST' => ALBUM_GUEST,
				'S_USER' => ALBUM_USER,
				'S_PRIVATE' => ALBUM_PRIVATE,
				'S_MOD' => ALBUM_MOD,
				'S_ADMIN' => ALBUM_ADMIN,

				'L_PANEL_TITLE' => $lang['Edit_Category'])
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else if( $get_action == 'delete' )
		{
			$cat_id = (isset($_GET['cat_id']) && is_scalar($_GET['cat_id'])) ? intval($_GET['cat_id']) : 0;

			$sql = "SELECT cat_id, cat_title, cat_order, cat_parent
					FROM ". ALBUM_CAT_TABLE ."
					ORDER BY cat_order ASC";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Album Categories information', '', __LINE__, __FILE__, $sql);
			}

			$cat_found = FALSE;
			while( $row = $db->sql_fetchrow($result) )
			{
				if( $row['cat_id'] == $cat_id )
				{
					$thiscat = $row;
					$cat_found = TRUE;
				}
				else
				{
					$catrow[] = $row;
				}
			}
			if( $cat_found == FALSE )
			{
				message_die(GENERAL_ERROR, 'The requested category is not existed');
			}

			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			album_read_tree();
			$select_to = '<select name="target">';
			$select_to .= album_get_tree_option($thiscat['cat_parent'], ALBUM_AUTH_VIEW, ALBUM_SELECTBOX_ALL);
			$select_to .= '</select>';
			//--- Album Category Hierarchy : end

			$template->set_filenames(array(
				'body' => 'admin/album_cat_delete_body.tpl')
			);

			$template->assign_vars(array(
				'S_ALBUM_ACTION' => append_sid("admin_album_cat.$phpEx?cat_id=$cat_id"),
				'L_CAT_DELETE' => $lang['Delete_Category'],
				'L_CAT_DELETE_EXPLAIN' => $lang['Delete_Category_Explain'],
				'L_CAT_TITLE' => $lang['Category_Title'],
				'S_CAT_TITLE' => phpbb_admin_html($thiscat['cat_title']),
				'S_CAT_ID' => $cat_id,
				'L_MOVE_CONTENTS' => $lang['Move_contents'],
				'L_MOVE_DELETE' => $lang['Move_and_Delete'],
				'S_SELECT_TO' => $select_to)
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else if( $get_action == 'move' )
		{
			$cat_id = (isset($_GET['cat_id']) && is_scalar($_GET['cat_id'])) ? intval($_GET['cat_id']) : 0;
			$move = (isset($_GET['move']) && is_scalar($_GET['move'])) ? intval($_GET['move']) : 0;
			$album_token = (isset($_GET['album_token']) && is_scalar($_GET['album_token'])) ? stripslashes((string) $_GET['album_token']) : '';
			$expected_token = hash_hmac('sha256', 'album-category-order:' . $cat_id . ':' . $move, (string) $userdata['session_id']);
			if (!in_array($move, array(-15, 15), true) || !hash_equals($expected_token, $album_token))
			{
				message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
			}

         		//--- Album Category Hierarchy : begin
				//--- version <= 1.1.0
			if (album_admin_category_change('order',$cat_id,0,array(),$move) === false)
			{
				showResultMessage($lang['Album_category_save_failed']);
			}
         		//--- Album Category Hierarchy : end

			// Return a message...
			showResultMessage($lang['Category_changed_order']);
		}
	}
}
else
{
	if( $post_mode == 'new' )
	{
		//--- Album Category Hierarchy : begin
		//--- version <= 1.1.0
		if (isset($_POST['addcategory']) && is_array($_POST['addcategory']))
		{
			$add_category_ids = array_keys($_POST['addcategory']);
			$cat_id = count($add_category_ids) ? intval($add_category_ids[0]) : 0;
			$cat_title = (isset($_POST['name'][$cat_id]) && is_scalar($_POST['name'][$cat_id])) ? stripslashes((string) $_POST['name'][$cat_id]) : '';
			$cat_parent = $cat_id;
			$cat_id = -1;
		}
		//--- Album Category Hierarchy : end

		if( !isset($_POST['cat_title']) )
		{
			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			album_read_tree();
			$s_album_cat_list = album_get_tree_option($cat_parent, ALBUM_AUTH_VIEW, ALBUM_SELECTBOX_INCLUDE_ALL);
			//--- Album Category Hierarchy : end

			$template->set_filenames(array(
				'body' => 'admin/album_cat_new_body.tpl')
			);

			$template->assign_vars(array(
				'L_ALBUM_CAT_TITLE' => $lang['Album_Categories_Title'],
				'L_ALBUM_CAT_EXPLAIN' => $lang['Album_Categories_Explain'],
				'S_ALBUM_ACTION' => append_sid("admin_album_cat.$phpEx"),

				'L_CAT_TITLE' => $lang['Category_Title'],
				'L_CAT_DESC' => $lang['Category_Desc'],
				//--- Album Category Hierarchy : begin
				//--- version <= 1.1.0
				'L_CAT_PARENT_TITLE' => $lang['Parent_Category'],
				//--- Album Category Hierarchy : end
				'L_CAT_PERMISSIONS' => $lang['Category_Permissions'],

				'L_VIEW_LEVEL' => $lang['View_level'],
				'L_UPLOAD_LEVEL' => $lang['Upload_level'],
				'L_RATE_LEVEL' => $lang['Rate_level'],
				'L_COMMENT_LEVEL' => $lang['Comment_level'],
				'L_EDIT_LEVEL' => $lang['Edit_level'],
				'L_DELETE_LEVEL' => $lang['Delete_level'],
				'L_PICS_APPROVAL' => $lang['Pics_Approval'],
				'L_GUEST' => $lang['Forum_ALL'],
				'L_REG' => $lang['Forum_REG'],
				'L_PRIVATE' => $lang['Forum_PRIVATE'],
				'L_MOD' => $lang['Forum_MOD'],
				'L_ADMIN' => $lang['Forum_ADMIN'],

				'L_DISABLED' => $lang['Disabled'],
				//--- Album Category Hierarchy : begin
				//--- version <= 1.1.0
				'S_CAT_TITLE' => phpbb_admin_html($cat_title),
				'S_CAT_PARENT_OPTIONS' => $s_album_cat_list,
				//--- Album Category Hierarchy : end
				'VIEW_GUEST' => 'selected="selected"',
				'UPLOAD_REG' => 'selected="selected"',
				'RATE_REG' => 'selected="selected"',
				'COMMENT_REG' => 'selected="selected"',
				'EDIT_REG' => 'selected="selected"',
				'DELETE_MOD' => 'selected="selected"',
				'APPROVAL_DISABLED' => 'selected="selected"',

				'S_MODE' => 'new',
				'S_CAT_ID' => 0,

				'S_GUEST' => ALBUM_GUEST,
				'S_USER' => ALBUM_USER,
				'S_PRIVATE' => ALBUM_PRIVATE,
				'S_MOD' => ALBUM_MOD,
				'S_ADMIN' => ALBUM_ADMIN,

				'L_PANEL_TITLE' => $lang['Create_category'])
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else
		{
			// Get posting variables
			$cat_title = trim(album_admin_post_scalar('cat_title'));
			$cat_desc = trim(album_admin_post_scalar('cat_desc'));
			$view_level = album_admin_permission_level('cat_view_level', ALBUM_GUEST);
			$upload_level = album_admin_permission_level('cat_upload_level', ALBUM_USER);
			$rate_level = album_admin_permission_level('cat_rate_level', ALBUM_USER);
			$comment_level = album_admin_permission_level('cat_comment_level', ALBUM_USER);
			$edit_level = album_admin_permission_level('cat_edit_level', ALBUM_USER, false);
			$delete_level = album_admin_permission_level('cat_delete_level', ALBUM_MOD, false);
			$cat_approval = intval(album_admin_post_scalar('cat_approval', ALBUM_USER));
			$cat_approval = in_array($cat_approval, array(ALBUM_USER, ALBUM_MOD, ALBUM_ADMIN), true) ? $cat_approval : ALBUM_USER;
			//--- Album Category Hierarchy : begin
			//--- version <= 1.1.0
			$posted_parent = intval(album_admin_post_scalar('cat_parent_id', ALBUM_ROOT_CATEGORY));
			$cat_parent = ($posted_parent == ALBUM_ROOT_CATEGORY) ? 0 : $posted_parent;
			$values = album_admin_category_post_values($cat_title,$cat_desc,$view_level,$upload_level,$rate_level,$comment_level,$edit_level,$delete_level,$cat_approval);
			if (album_admin_category_change('new',null,$cat_parent,$values) === false)
			{
				showResultMessage($lang['Album_category_save_failed']);
			}

			// Return a message...
			showResultMessage($lang['New_category_created']);
		}
	}
	else if( $post_mode == 'edit' )
	{
		// Get posting variables
		$cat_id = intval(album_admin_post_scalar('cat_id', 0));
		$cat_title = trim(album_admin_post_scalar('cat_title'));
		$cat_desc = trim(album_admin_post_scalar('cat_desc'));
		$view_level = album_admin_permission_level('cat_view_level', ALBUM_GUEST);
		$upload_level = album_admin_permission_level('cat_upload_level', ALBUM_USER);
		$rate_level = album_admin_permission_level('cat_rate_level', ALBUM_USER);
		$comment_level = album_admin_permission_level('cat_comment_level', ALBUM_USER);
		$edit_level = album_admin_permission_level('cat_edit_level', ALBUM_USER, false);
		$delete_level = album_admin_permission_level('cat_delete_level', ALBUM_MOD, false);
		$cat_approval = intval(album_admin_post_scalar('cat_approval', ALBUM_USER));
		$cat_approval = in_array($cat_approval, array(ALBUM_USER, ALBUM_MOD, ALBUM_ADMIN), true) ? $cat_approval : ALBUM_USER;
		//--- Album Category Hierarchy : begin
		//--- version <= 1.1.0
		$posted_parent = intval(album_admin_post_scalar('cat_parent_id', ALBUM_ROOT_CATEGORY));
		$cat_parent = ($posted_parent == ALBUM_ROOT_CATEGORY) ? 0 : $posted_parent;
		$values = album_admin_category_post_values($cat_title,$cat_desc,$view_level,$upload_level,$rate_level,$comment_level,$edit_level,$delete_level,$cat_approval);
		if (album_admin_category_change('edit',$cat_id,$cat_parent,$values) === false)
		{
			showResultMessage($lang['Album_category_save_failed']);
		}

		// Return a message...
		showResultMessage($lang['Category_updated']);
	}
	else if( $post_mode == 'delete' )
	{
		require_once $album_root_path . 'album_category_remove.' . $phpEx;
		$remove_result = album_category_remove_request(0, intval(album_admin_post_scalar('cat_id', 0)), intval(album_admin_post_scalar('target', 0)));
		$album_data = array();
		album_read_tree(ALBUM_PUBLIC_GALLERY);
		$message = '';
		if ($remove_result['children'] > 0)
		{
			$parent_title = $remove_result['parent_title'] !== '' ? album_html_text($remove_result['parent_title']) : album_html_text($lang['Album']);
			$message = sprintf($lang['Child_Category_Moved'], $parent_title) . '<br />';
		}
		showResultMessage($message . $lang['Category_deleted']);
	}
}

/* Powered by Photo Album v2.x.x (c) 2002-2003 Smartor */

?>
