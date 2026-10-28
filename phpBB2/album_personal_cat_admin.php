<?php

/***************************************************************************
 *                    album_personal_cat_admin.php
 *                           ---------------
 * copyright: © IdleVoid
 * email    : idlevoid@slater.dk
 * file version : 1.0.7
 * release      : 1.2.0
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

define('IN_PHPBB', true);

//
// Let's set the root dir for phpBB
//

$phpbb_root_path = './';
$album_root_path = $phpbb_root_path . 'album_mod/';

include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.'.$phpEx);

$personal_album_language = preg_match('/^[a-z0-9_-]+$/iD', (string) $board_config['default_lang']) &&
	is_dir($phpbb_root_path . 'language/lang_' . $board_config['default_lang'])
	? (string) $board_config['default_lang']
	: 'english';
require($phpbb_root_path . 'language/lang_' . $personal_album_language . '/lang_main_album.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $personal_album_language . '/lang_admin_album.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $personal_album_language . '/lang_admin.' . $phpEx);

function album_personal_permission_value($value, $is_admin, $default)
{
	$allowed = array(ALBUM_GUEST, ALBUM_USER, ALBUM_PRIVATE);
	if ($is_admin)
	{
		$allowed[] = ALBUM_MOD;
		$allowed[] = ALBUM_ADMIN;
	}
	$value = intval($value);
	return in_array($value, $allowed, true) ? $value : intval($default);
}

function album_personal_category_text($value, $length, $encode_html)
{
	if ((!is_scalar($value) && $value !== null) || (int) $length < 1) { return ''; }
	$length = min($encode_html ? 255 : 65535, (int) $length);
	$value = trim(stripslashes((string) $value));
	// Retain the original logical character limits with or without mbstring.
	// The installer/updater reserves full UTF-8 and HTML-expansion capacity.
	$value = substr($value, 0, $length * 4 + 4);
	$value = html_entity_decode(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), ENT_QUOTES, 'UTF-8');
	$characters = preg_split('//u', $value, $length + 2, PREG_SPLIT_NO_EMPTY);
	if ($characters === false) { return ''; }
	$value = implode('', array_slice($characters, 0, $length));
	return $encode_html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true) : $value;
}

//
// Start session management
//
$userdata = session_pagestart($user_ip, PAGE_ALBUM);
init_userprefs($userdata);
//
// End session management
//
$template->assign_var('S_FORM_TOKEN', '<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />');

//
// Get general album information
//
include($album_root_path. 'album_common.'.$phpEx);
$page_title = $lang['Personal_Cat_Admin'];
$mode = phpbb_request_scalar($_POST, 'mode');
$action = phpbb_request_scalar($_GET, 'action');
if (isset($_POST['move_category']))
{
	// The gallery's shared form defaults to creation; only the clicked button
	// selects ordering. Reject an actual second action, not that hidden default.
	if (!in_array($mode, array('', 'new'), true) || $action !== '' || isset($_POST['addcategory']))
	{ message_die(GENERAL_MESSAGE, $lang['Not_Authorised']); }
	$mode = 'order';
}
if (!in_array($mode, array('', 'new', 'edit', 'delete', 'order'), true) || !in_array($action, array('', 'edit', 'delete'), true))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

// ------------------------------------------------------------------------
// Get $album_user_id
// ------------------------------------------------------------------------
if( isset($_POST['user_id']) )
{
	$album_user_id = intval(phpbb_request_scalar($_POST, 'user_id', 0));
}
else if( isset($_GET['user_id']) )
{
	$album_user_id = intval(phpbb_request_scalar($_GET, 'user_id', 0));
}
else
{
	$album_user_id = (int) $userdata['user_id'];
}

if (!$userdata['session_logged_in'])
{
	redirect(append_sid("login.$phpEx?redirect=album_personal_cat_admin.$phpEx"));
}
if ($album_user_id !== (int) $userdata['user_id'] && (int) $userdata['user_level'] !== ADMIN)
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
$submitted_sid = stripslashes(phpbb_request_scalar($_POST, 'sid'));
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
	(($mode !== '' || isset($_POST['submit'])) && ($submitted_sid === '' ||
	!hash_equals((string) $userdata['session_id'], $submitted_sid))))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}

// ------------------------------------------------------------------------
// Get $cat_id
// ------------------------------------------------------------------------
if( isset($_POST['cat_id']) )
{
	$cat_id = intval(phpbb_request_scalar($_POST, 'cat_id', 0));
}
else if( isset($_GET['cat_id']) )
{
	$cat_id = intval(phpbb_request_scalar($_GET, 'cat_id', 0));
}
else
{
	$cat_id = 0;
}

// ------------------------------------------------------------------------
// Check if user exists and get his/her name
// ------------------------------------------------------------------------
$username = album_find_user_name($album_user_id);
if ($username === false)
{
	message_die(GENERAL_MESSAGE, $lang['No_user_id_specified']);
}

// ------------------------------------------------------------------------
// Check the actual personal gallery exists, if not there is nothing to manage
// ------------------------------------------------------------------------

if (album_get_personal_root_id($album_user_id) == ALBUM_ROOT_CATEGORY)
{
	if(!isset($_POST['submit']))
	{
		album_init_personal_gallery($album_user_id);
	}
	else
	{
		if (!album_create_personal_gallery($album_user_id, $album_config['personal_gallery_view'], ALBUM_PRIVATE))
		{
			message_die(GENERAL_ERROR, $lang['Personal_gallery_creation_failed']);
		}
		if ((int) album_get_personal_root_id($album_user_id) <= 0)
		{
			message_die(GENERAL_ERROR, $lang['Personal_gallery_creation_failed']);
		}
		album_read_tree($album_user_id);
	}
}
else
{
	album_read_tree($album_user_id);
}

// ------------------------------------------------------------------------
// Only the owner of the personal gallery AND the admin of the personal
// gallery can manage the categories
// TODO : should the moderator also be allowed ?
// ------------------------------------------------------------------------
$auth_data = album_permissions($album_user_id, $cat_id, ALBUM_AUTH_MANAGE_PERSONAL_CATEGORIES);
if ( !album_check_permission($auth_data, ALBUM_AUTH_MANAGE_PERSONAL_CATEGORIES) )
{
	if ($album_user_id != $userdata['user_id'] && $userdata['user_level'] != ADMIN)
	{
		if( ($album_user_id <= 0) and (!$userdata['session_logged_in']) )
		{
			redirect(append_sid("login.$phpEx?redirect=album_cat.$phpEx"));
		}

		if( $action === '' )
		{
			redirect(append_sid(album_append_uid("album.$phpEx")));
		}

		$album_user_id = $userdata['user_id'];
	}
	else
	{
		include_once($phpbb_root_path . 'includes/page_header.'.$phpEx);
		$message = $lang['No_Personal_Category_admin'];
		$message .= "<br /><br />" . sprintf($lang['Click_return_album_index'], "<a href=\"" . append_sid(album_append_uid("album.$phpEx")) . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
		exit(); // really not needed
	}
}

include_once($phpbb_root_path . 'includes/page_header.'.$phpEx);

// ------------------------------------------------------------------------
//  A common function to generate the 'sucess' or 'failure' message
// ------------------------------------------------------------------------
function showResultMessage($in_message)
{
	global $lang, $album_user_id, $phpEx, $mode;

	if (album_get_personal_root_id($album_user_id) == ALBUM_ROOT_CATEGORY && $mode === 'delete')
	{
		$message = $in_message . "<br /><br />" . sprintf($lang['Click_return_personal_gallery_index'], "<a href=\"" . append_sid("album_personal_index.$phpEx") . "\">", "</a>");
	}
	else
	{
		$message = $in_message . "<br /><br />" . sprintf($lang['Click_return_album_category'], "<a href=\"" . append_sid(album_append_uid("album_personal_cat_admin.$phpEx")) . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_personal_gallery'], "<a href=\"" . append_sid(album_append_uid("album.$phpEx")) . "\">", "</a>");
	}

	message_die(GENERAL_MESSAGE, $message);
}

function album_personal_category_is_descendant($candidate_id, $ancestor_id, $album_user_id)
{
	global $db;

	$candidate_id = intval($candidate_id);
	$ancestor_id = intval($ancestor_id);
	$seen = array();
	while ($candidate_id > 0 && !isset($seen[$candidate_id]))
	{
		if ($candidate_id === $ancestor_id)
		{
			return true;
		}
		$seen[$candidate_id] = true;

		$sql = "SELECT cat_parent FROM " . ALBUM_CAT_TABLE . "
				WHERE cat_id = " . $candidate_id . "
					AND cat_user_id = " . intval($album_user_id);
		if (!($result = $db->sql_query($sql)) || !($row = $db->sql_fetchrow($result)))
		{
			return false;
		}
		$candidate_id = intval($row['cat_parent']);
	}

	return false;
}


// ------------------------------------------------------------------------
// now start processing the page...
// ------------------------------------------------------------------------
if( $mode === '' )
{
	if( $action === '' )
	{
		$template->set_filenames(array(
			'body' => 'admin/album_personal_cat_body.tpl')
		);

		/* 'global' template vars */
		$template->assign_vars(array(
			'S_ALBUM_ACTION' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx")),

			'L_CREATE_CATEGORY' => $lang['Create_category'],
			'L_ALBUM_INDEX'	=> $lang['Album_Categories_Title'],
			'L_ALBUM_CAT_TITLE' => $lang['Album_Categories_Title'],
			'L_ALBUM_CAT_EXPLAIN' => $lang['Album_Categories_Explain'],
        	'L_PERSONAL_ALBUM' => sprintf($lang['Personal_Gallery_Of_User'], $username),
            'L_PERSONAL_CAT_ADMIN' => $lang['Personal_Cat_Admin'],

			'ALBUM_NAVIGATION_ARROW' => ALBUM_NAV_ARROW,

			'U_PERSONAL_ALBUM' => append_sid(album_append_uid("album.$phpEx")),
			'U_PERSONAL_CAT_ADMIN' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id"))
			)
		);

		// get the values of level selected
		if (!empty($cat_id))
		{
			$parent = $cat_id;
		}

		if (!isset($album_cat_tree['keys'][$parent]))
		{
			$parent = ALBUM_ROOT_CATEGORY; //album_get_personal_root_id($album_user_id);
		}

		// display the tree
		album_display_admin_index($parent);

		// ------------------------------------------------------------------------------------
		// Check if we have reached the maximum number of sub categories in personal gallery
  		// if we have, then disable creation button
  		// ------------------------------------------------------------------------------------
		$sql = "SELECT COUNT(*) AS count FROM ". ALBUM_CAT_TABLE ." WHERE cat_user_id = $album_user_id AND cat_parent <> 0";

		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not query number of sub category for user' ,'' , __LINE__, __FILE__, $sql);
		}

		if( $db->sql_numrows($result) >= 0 )
		{
   			$row = $db->sql_fetchrow($result);

   			if ($row['count'] >= $album_config['personal_sub_category_limit'] && $album_config['personal_sub_category_limit'] >= 0)
   			{
   				$template->assign_vars(array('DISABLE_CREATION' => 'disabled'));

   			}
		}
		// ------------------------------------------
		$template->pparse('body');

		include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
	}
	else
	{
		if( $action == 'edit' )
		{
			//$is_personal_root_cat = ($cat_id == album_get_personal_root_id($album_user_id)) ? true : false;
			if (($cat_id == album_get_personal_root_id($album_user_id)) || -1 == album_get_personal_root_id($album_user_id))
			{
				$is_personal_root_cat = true;
			}
			else
			{
				$is_personal_root_cat = false;
			}

			if ($cat_id != 0)
			{
				$sql = "SELECT cat.*, cat2.cat_title AS cat_parent_title, cat2.cat_id AS cat_parent_id
						FROM ". ALBUM_CAT_TABLE ." AS cat LEFT OUTER JOIN ". ALBUM_CAT_TABLE ." AS cat2
						ON cat2.cat_id = cat.cat_parent WHERE cat.cat_id = '$cat_id' AND cat.cat_user_id = " . $album_user_id;

				if(!$result = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Could not query Album Categories information', '', __LINE__, __FILE__, $sql);
				}

				if( $db->sql_numrows($result) == 0 )
				{
					message_die(GENERAL_ERROR, 'The requested category is not existed');
				}

				$catrow = $db->sql_fetchrow($result);
			}
			else
			{
				$catrow = $album_data['data'][0];
			}

			$parent_cat_id = ($is_personal_root_cat) ? $cat_id : $catrow['cat_parent_id'];
			$s_album_cat_list = album_get_tree_option($parent_cat_id, ALBUM_AUTH_VIEW, ALBUM_SELECTBOX_INCLUDE_ALL|ALBUM_SELECTBOX_INCLUDE_ROOT);//, true, false, true);

			if ($cat_id == 0)
			{
				$cat_id = ALBUM_ROOT_CATEGORY;
			}

			$template->set_filenames(array(
				'body' => 'admin/album_personal_cat_new_body.tpl')
			);

			$template->assign_vars(array(
				'S_ALBUM_ACTION' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id")),
				'L_CAT_TITLE' => $lang['Category_Title'],
				'L_CAT_DESC' => $lang['Category_Desc'],
				'L_CAT_PARENT_TITLE' => $lang['Parent_Category'],
				'L_CAT_PERMISSIONS' => $lang['Category_Permissions'],
				'L_VIEW_LEVEL' => $lang['View_level'],
				'L_UPLOAD_LEVEL' => $lang['Upload_level'],
				'L_RATE_LEVEL' => $lang['Rate_level'],
				'L_COMMENT_LEVEL' => $lang['Comment_level'],
				'L_EDIT_LEVEL' => $lang['Edit_level'],

				'ALBUM_NAVIGATION_ARROW' => ALBUM_NAV_ARROW,
				'U_PERSONAL_ALBUM' => append_sid(album_append_uid("album.$phpEx")),
				'L_PERSONAL_ALBUM' => sprintf($lang['Personal_Gallery_Of_User'], $username),
				'U_PERSONAL_CAT_ADMIN' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id")),
				'L_PERSONAL_CAT_ADMIN' => $lang['Personal_Cat_Admin'],

				'L_GUEST' => $lang['Forum_ALL'],
				'L_REG' => $lang['Forum_REG'],
				'L_PRIVATE' => $lang['Forum_PRIVATE'],

				'L_DISABLED' => $lang['Disabled'],

				'READ_ONLY' => ($is_personal_root_cat) ? 'readonly' :'',
				'DISABLED' => ($is_personal_root_cat) ? 'disabled' :'',

				'S_CAT_TITLE' => ($is_personal_root_cat) ? sprintf($lang['Personal_Gallery_Of_User'], htmlspecialchars($username, ENT_QUOTES, 'UTF-8')) : htmlspecialchars(html_entity_decode((string) $catrow['cat_title'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'),
				'S_CAT_DESC' => htmlspecialchars((string) $catrow['cat_desc'], ENT_QUOTES, 'UTF-8'),
				'S_CAT_PARENT_OPTIONS' => $s_album_cat_list,

				'VIEW_GUEST' => ($catrow['cat_view_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'VIEW_REG' => ($catrow['cat_view_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'VIEW_PRIVATE' => ($catrow['cat_view_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'UPLOAD_GUEST' => ($catrow['cat_upload_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'UPLOAD_REG' => ($catrow['cat_upload_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'UPLOAD_PRIVATE' => ($catrow['cat_upload_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'RATE_GUEST' => ($catrow['cat_rate_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'RATE_REG' => ($catrow['cat_rate_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'RATE_PRIVATE' => ($catrow['cat_rate_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'COMMENT_GUEST' => ($catrow['cat_comment_level'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'COMMENT_REG' => ($catrow['cat_comment_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'COMMENT_PRIVATE' => ($catrow['cat_comment_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'EDIT_REG' => ($catrow['cat_edit_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'EDIT_PRIVATE' => ($catrow['cat_edit_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'DELETE_REG' => ($catrow['cat_delete_level'] == ALBUM_USER) ? 'selected="selected"' : '',
				'DELETE_PRIVATE' => ($catrow['cat_delete_level'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'S_MODE' => 'edit',

				'CATEGORY_ID' => $cat_id,
				'ROOT_CATEGORY_ID' => album_get_personal_root_id($album_user_id),
				'ALBUM_PUBLIC_GALLERY' => intval(ALBUM_JUMPBOX_PUBLIC_GALLERY),
				'ALBUM_ROOT_CATEGORY' => intval(ALBUM_ROOT_CATEGORY),
				'ALBUM_USERS_GALLERY' => intval(ALBUM_JUMPBOX_USERS_GALLERY),
				'ALBUM_JUMPBOX_SEPERATOR' => intval(ALBUM_JUMPBOX_SEPERATOR),
				'L_NO_SELF_REFERING' => $lang['No_Self_Refering_Cat'],
				'L_NO_VALID_CAT_SELECTED' => $lang['No_valid_category_selected'],

				'S_GUEST' => ALBUM_GUEST,
				'S_USER' => ALBUM_USER,
				'S_PRIVATE' => ALBUM_PRIVATE,

				'L_PANEL_TITLE' => $lang['Edit_Category'])
			);

			$template->pparse('body');

			include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		else if( $action == 'delete' )
		{
			$sql = "SELECT cat_id, cat_title, cat_order, cat_parent
					FROM ". ALBUM_CAT_TABLE ."
					WHERE cat_user_id = " . (int) $album_user_id . "
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

			$select_to  = '<select name="target">';
			if ($cat_id == album_get_personal_root_id($album_user_id))
			{
				$select_to .= '<option value="0" selected="selected">' . $lang['Delete_all_pics'] . '</option>' ;
			}
			else
			{
				$select_to .= album_get_tree_option($thiscat['cat_parent'], ALBUM_AUTH_VIEW, ALBUM_SELECTBOX_ALL);
			}

			$select_to .= '</select>';

			$template->set_filenames(array(
				'body' => 'admin/album_personal_cat_delete_body.tpl')
			);

			$template->assign_vars(array(
				'S_ALBUM_ACTION' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id")),
				'L_CAT_DELETE' => $lang['Delete_Category'],
				'L_CAT_DELETE_EXPLAIN' => $lang['Delete_Category_Explain'],
				'L_CAT_TITLE' => $lang['Category_Title'],
                'L_MOVE_CONTENTS' => $lang['Move_contents'],
                'L_MOVE_DELETE' => $lang['Move_and_Delete'],
                'L_PERSONAL_ALBUM' => sprintf($lang['Personal_Gallery_Of_User'], $username),
                'L_PERSONAL_CAT_ADMIN' => $lang['Personal_Cat_Admin'],

				'ALBUM_NAVIGATION_ARROW' => ALBUM_NAV_ARROW,

                'U_PERSONAL_ALBUM' => append_sid(album_append_uid("album.$phpEx")),
				'U_PERSONAL_CAT_ADMIN' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id")),

				'S_CAT_TITLE' => htmlspecialchars($thiscat['cat_title'], ENT_QUOTES, 'UTF-8'),
				'S_SELECT_TO' => $select_to)
			);

			$template->pparse('body');

			include($phpbb_root_path . 'includes/page_tail.'.$phpEx); //include('./page_footer_admin.'.$phpEx);
		}
	}
}
else
{
	if ($mode === 'order')
	{
		// The existing outer form supplies the central POST session token.
		$requested_order = phpbb_request_scalar($_POST, 'move_category');
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($requested_order) ||
			preg_match('/^([1-9][0-9]{0,7}):(-15|15)$/D', $requested_order, $order_parts) !== 1)
		{
			message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
		}
		require_once dirname(__FILE__) . '/album_mod/album_category_storage.php';
		if (!album_personal_category_order((int) $album_user_id, (int) $order_parts[1], (int) $order_parts[2]))
		{
			message_die(GENERAL_MESSAGE, $lang['Personal_category_order_failed']);
		}
		showResultMessage($lang['Category_changed_order']);
	}
	else if( $mode == 'new' )
	{
		$cat_title = '';
		$cat_parent = album_get_personal_root_id($album_user_id);
		if ( isset($_POST['addcategory']) && is_array($_POST['addcategory']) )
		{
			foreach ($_POST['addcategory'] as $requested_parent => $unused)
			{
				$cat_parent = intval($requested_parent);
				break;
			}
			$names = (isset($_POST['name']) && is_array($_POST['name'])) ? $_POST['name'] : array();
			$cat_title = (isset($names[$cat_parent]) && is_scalar($names[$cat_parent]))
				? stripslashes((string) $names[$cat_parent])
				: '';
			$cat_id = -1;
		}

		if( !isset($_POST['cat_title']) )
		{
			$s_album_cat_list = album_get_tree_option($cat_parent, ALBUM_AUTH_VIEW);

			if (empty($s_album_cat_list) || $cat_parent == 0)
			{
			    $s_album_cat_list = '<option value="-1" selected="selected">'. sprintf($lang['Personal_Gallery_Of_User'], $username). '</option>';
			}
			$template->set_filenames(array(
				'body' => 'admin/album_personal_cat_new_body.tpl')
			);

			$template->assign_vars(array(
				'S_ALBUM_ACTION' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx")),

				'L_CAT_TITLE' => $lang['Category_Title'],
				'L_CAT_DESC' => $lang['Category_Desc'],
				'L_CAT_PARENT_TITLE' => $lang['Parent_Category'],
				'L_CAT_PERMISSIONS' => $lang['Category_Permissions'],

				'ALBUM_NAVIGATION_ARROW' => ALBUM_NAV_ARROW,
				'U_PERSONAL_ALBUM' => append_sid(album_append_uid("album.$phpEx")),
				'L_PERSONAL_ALBUM' => sprintf($lang['Personal_Gallery_Of_User'], $username),
				'U_PERSONAL_CAT_ADMIN' => append_sid(album_append_uid("album_personal_cat_admin.$phpEx?cat_id=$cat_id")),
				'L_PERSONAL_CAT_ADMIN' => $lang['Personal_Cat_Admin'],

				'L_VIEW_LEVEL' => $lang['View_level'],
				'L_UPLOAD_LEVEL' => $lang['Upload_level'],
				'L_RATE_LEVEL' => $lang['Rate_level'],
				'L_COMMENT_LEVEL' => $lang['Comment_level'],

				'L_GUEST' => $lang['Forum_ALL'],
				'L_REG' => $lang['Forum_REG'],
				'L_PRIVATE' => $lang['Forum_PRIVATE'],

				'VIEW_GUEST' => ($album_config['personal_gallery_view'] == ALBUM_GUEST) ? 'selected="selected"' : '',
				'VIEW_REG' => ($album_config['personal_gallery_view'] == ALBUM_USER) ? 'selected="selected"' : '',
				'VIEW_PRIVATE' => ($album_config['personal_gallery_view'] == ALBUM_PRIVATE) ? 'selected="selected"' : '',

				'UPLOAD_PRIVATE' => 'selected="selected"',
				'RATE_REG' => 'selected="selected"',
				'COMMENT_REG' => 'selected="selected"',

				'S_CAT_TITLE' => htmlspecialchars($cat_title, ENT_QUOTES, 'UTF-8'),
				'S_CAT_PARENT_OPTIONS' => $s_album_cat_list,

				'CATEGORY_ID'=> 0,
				'ROOT_CATEGORY_ID' => ALBUM_ROOT_CATEGORY,
				'ALBUM_PUBLIC_GALLERY' => intval(ALBUM_JUMPBOX_PUBLIC_GALLERY),
				'ALBUM_ROOT_CATEGORY' => intval(ALBUM_ROOT_CATEGORY),
				'ALBUM_USERS_GALLERY' => intval(ALBUM_JUMPBOX_USERS_GALLERY),
				'ALBUM_JUMPBOX_SEPERATOR' => intval(ALBUM_JUMPBOX_SEPERATOR),
				'L_NO_VALID_CAT_SELECTED' => $lang['No_valid_category_selected'],

				'S_MODE' => 'new',

				'S_GUEST' => ALBUM_GUEST,
				'S_USER' => ALBUM_USER,
				'S_PRIVATE' => ALBUM_PRIVATE,
				'S_MOD' => ALBUM_MOD,
				'S_ADMIN' => ALBUM_ADMIN,

				'L_PANEL_TITLE' => $lang['Create_category'])
			);

			$template->pparse('body');

			include($phpbb_root_path . 'includes/page_tail.'.$phpEx);
		}
		else
		{
			// Get posting variables. Recheck current authority inside the writer.
			$cat_title = album_personal_category_text(phpbb_request_scalar($_POST, 'cat_title'), 255, true);
			$cat_desc = album_personal_category_text(phpbb_request_scalar($_POST, 'cat_desc'), 65535, false);
			if ($cat_title === '')
			{
				message_die(GENERAL_MESSAGE, $lang['No_valid_category_selected']);
			}
			$requested_parent = intval(phpbb_request_scalar($_POST, 'cat_parent_id', ALBUM_ROOT_CATEGORY));
			$requested_levels = array(
				'view' => phpbb_request_scalar($_POST, 'cat_view_level', ALBUM_PRIVATE),
				'upload' => phpbb_request_scalar($_POST, 'cat_upload_level', ALBUM_PRIVATE),
				'rate' => phpbb_request_scalar($_POST, 'cat_rate_level', ALBUM_USER),
				'comment' => phpbb_request_scalar($_POST, 'cat_comment_level', ALBUM_USER)
			);
			require_once dirname(__FILE__) . '/album_mod/album_category_storage.php';
			if (!album_personal_category_create((int) $album_user_id, $requested_parent, $cat_title, $cat_desc, $requested_levels))
			{
				message_die(GENERAL_MESSAGE, $lang['Personal_category_creation_failed']);
			}

			// Return a message...
			showResultMessage($lang['New_category_created']);
		}
	}
	else if( $mode == 'edit' )
	{
		// Get posting variables. Lock and recheck the current editing grant.
		$cat_title = album_personal_category_text(phpbb_request_scalar($_POST, 'cat_title'), 255, true);
		$cat_desc = album_personal_category_text(phpbb_request_scalar($_POST, 'cat_desc'), 65535, false);
		if ($cat_title === '')
		{
			message_die(GENERAL_MESSAGE, $lang['No_valid_category_selected']);
		}
		$requested_parent = intval(phpbb_request_scalar($_POST, 'cat_parent_id', ALBUM_ROOT_CATEGORY));
		$requested_levels = array(
			'view' => phpbb_request_scalar($_POST, 'cat_view_level', ALBUM_PRIVATE),
			'upload' => phpbb_request_scalar($_POST, 'cat_upload_level', ALBUM_PRIVATE),
			'rate' => phpbb_request_scalar($_POST, 'cat_rate_level', ALBUM_USER),
			'comment' => phpbb_request_scalar($_POST, 'cat_comment_level', ALBUM_USER)
		);
		require_once dirname(__FILE__) . '/album_mod/album_category_storage.php';
		if (!album_personal_category_edit((int) $album_user_id, (int) $cat_id, $requested_parent, $cat_title, $cat_desc, $requested_levels))
		{
			message_die(GENERAL_MESSAGE, $lang['Personal_category_update_failed']);
		}

		// Return a message...
		showResultMessage($lang['Category_updated']);
	}
	else if( $mode == 'delete' )
	{
		require_once dirname(__FILE__) . '/album_mod/album_category_remove.php';
		$remove_result = album_category_remove_request((int)$album_user_id, (int)$cat_id, intval(phpbb_request_scalar($_POST, 'target', 0)));
		$album_data = array();
		album_read_tree($album_user_id);
		$message = '';
		if ($remove_result['children'] > 0)
		{
			$parent_title = $remove_result['parent_title'] !== '' ? album_html_text($remove_result['parent_title']) : album_html_text($lang['Album']);
			$message = sprintf($lang['Child_Category_Moved'], $parent_title) . '<br />';
		}
		showResultMessage($message . $lang['Category_deleted']);
	}
}

?>
