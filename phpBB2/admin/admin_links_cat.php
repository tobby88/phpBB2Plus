<?php
/***************************************************************************
 *                            admin_links_cat.php
 *                            -------------------
 *  MOD add-on page. Contains GPL code copyright of phpBB group.
 *  Author: OOHOO < webdev@phpbb-tw.net >
 *  Author: Stefan2k1 and ddonker from www.portedmods.com
 *  Demo: http://phpbb-tw.net/
 *  Version: 1.0.X - 2002/03/22 - for phpBB RC serial, and was named Related_Links_MOD
 *  Version: 1.1.0 - 2002/04/25 - Re-packed for phpBB 2.0.0, and renamed to Links_MOD
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
	$module['Links']['Category'] = $file;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = '../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'language/lang_' . $board_config['default_lang'] . '/lang_admin_link.' . $phpEx);


require_once($phpbb_root_path . 'includes/functions_link_categories.' . $phpEx);
$post_mode = (isset($_POST['mode']) && is_string($_POST['mode'])) ? $_POST['mode'] : '';
$action = (isset($_GET['action']) && is_string($_GET['action'])) ? $_GET['action'] : '';
if (!in_array($post_mode, array('', 'new', 'edit', 'delete'), true) || !in_array($action, array('', 'edit', 'delete'), true))
{
	message_die(GENERAL_MESSAGE, $lang['Not_Authorised']);
}
if (isset($_POST['cat_action']) || $post_mode === 'edit' || $post_mode === 'delete' || ($post_mode === 'new' && array_key_exists('cat_title', $_POST)))
{
	phpbb_admin_require_post_session();
	try
	{
		$result = phpbb_link_categories_save($db, $_POST, $userdata);
		$messages = array('new'=>'New_category_created','edit'=>'Category_updated','delete'=>'Category_deleted','move'=>'Category_changed_order');
		$message = $lang[$messages[$result['action']]];
	}
	catch (PhpbbLinkException $error)
	{
		$key = $error->getMessage();
		$message = isset($lang[$key]) ? $lang[$key] : $lang['Link_storage_failed'];
	}
	catch (Exception $error) { $message = $lang['Link_storage_failed']; }
	catch (Error $error) { $message = $lang['Link_storage_failed']; }
	$message .= '<br /><br />' . sprintf($lang['Click_return_link_category'], '<a href="' . append_sid("admin_links_cat.$phpEx") . '">', '</a>');
	message_die(GENERAL_MESSAGE, $message);
}
if ($post_mode !== '') { phpbb_admin_require_post_session(); }

if( $post_mode === '' )
{
	if( $action === '' )
	{
		$template->set_filenames(array(
			'body' => 'admin/admin_link_cat_body.tpl')
		);

		$template->assign_vars(array(
			'L_LINK_CAT_TITLE' => $lang['Link_Categories_Title'],
			'L_LINK_CAT_EXPLAIN' => $lang['Link_Categories_Explain'],
			'S_LINK_ACTION' => append_sid("admin_links_cat.$phpEx"),
			'S_SESSION_FIELD' => phpbb_admin_session_field(),
			'L_MOVE_UP' => $lang['Move_up'],
			'L_MOVE_DOWN' => $lang['Move_down'],
			'L_EDIT' => $lang['Edit'],
			'L_DELETE' => $lang['Delete'],
			'S_MODE' => 'new',
			'L_CREATE_CATEGORY' => $lang['Create_category'])
		);

		$sql = "SELECT *
				FROM ". LINK_CATEGORIES_TABLE ."
				ORDER BY cat_order ASC, cat_id ASC";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not query Link Categories information', '', __LINE__, __FILE__, $sql);
		}
		$catrow = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$catrow[] = $row;
		}

		for( $i = 0; $i < count($catrow); $i++ )
		{
			$template->assign_block_vars('catrow', array(
				'COLOR' => ($i % 2) ? 'row1' : 'row2',
				'TITLE' => htmlspecialchars($catrow[$i]['cat_title'], ENT_QUOTES, 'UTF-8'),
				'MOVE_UP_ACTION' => 'move_up:' . (int) $catrow[$i]['cat_id'],
				'MOVE_DOWN_ACTION' => 'move_down:' . (int) $catrow[$i]['cat_id'],
				'MOVE_UP_DISABLED' => ($i === 0) ? ' disabled="disabled"' : '',
				'MOVE_DOWN_DISABLED' => ($i === count($catrow) - 1) ? ' disabled="disabled"' : '',
				'S_EDIT_ACTION' => append_sid("admin_links_cat.$phpEx?action=edit&amp;cat_id=" . $catrow[$i]['cat_id']),
				'S_DELETE_ACTION' => append_sid("admin_links_cat.$phpEx?action=delete&amp;cat_id=" . $catrow[$i]['cat_id'])
				)
			);
		}

		$template->pparse('body');

		include('./page_footer_admin.'.$phpEx);
	}
	else
	{
		if( $action == 'edit' )
		{
			try { $cat_id = phpbb_link_admin_id(isset($_GET['cat_id']) ? $_GET['cat_id'] : null); }
			catch (PhpbbLinkException $error) { message_die(GENERAL_MESSAGE, $lang['Link_category_not_exist']); }

			$sql = "SELECT *
					FROM ". LINK_CATEGORIES_TABLE ."
					WHERE cat_id = '$cat_id'";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Link Categories information', '', __LINE__, __FILE__, $sql);
			}
			if( $db->sql_numrows($result) == 0 )
			{
				message_die(GENERAL_ERROR, 'The requested category is not existed');
			}
			$catrow = $db->sql_fetchrow($result);

			$template->set_filenames(array(
				'body' => 'admin/admin_link_cat_new_body.tpl')
			);

			$template->assign_vars(array(
				'L_LINK_CAT_TITLE' => $lang['Link_Categories_Title'],
				'L_LINK_CAT_EXPLAIN' => $lang['Link_Categories_Explain'],
				'S_LINK_ACTION' => append_sid("admin_links_cat.$phpEx"),
				'S_HIDDEN_FIELDS' => '<input type="hidden" name="cat_id" value="' . $cat_id . '" />' . phpbb_admin_session_field(),
				'L_CAT_TITLE' => $lang['Category_Title'],

				'L_DISABLED' => $lang['Disabled'],

				'S_CAT_TITLE' => htmlspecialchars($catrow['cat_title'], ENT_QUOTES, 'UTF-8'),


				'S_MODE' => 'edit',


				'L_PANEL_TITLE' => $lang['Edit_Category'])
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else if( $action == 'delete' )
		{
			try { $cat_id = phpbb_link_admin_id(isset($_GET['cat_id']) ? $_GET['cat_id'] : null); }
			catch (PhpbbLinkException $error) { message_die(GENERAL_MESSAGE, $lang['Link_category_not_exist']); }

			$sql = "SELECT cat_id, cat_title, cat_order
					FROM ". LINK_CATEGORIES_TABLE ."
					ORDER BY cat_order ASC, cat_id ASC";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Link Categories information', '', __LINE__, __FILE__, $sql);
			}

			$catrow = array();
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

			$select_to = '<select name="target"><option value="0">'. htmlspecialchars($lang['Link_delete_all'], ENT_QUOTES, 'UTF-8') .'</option>';
			for ($i = 0; $i < count($catrow); $i++)
			{
				$select_to .= '<option value="'. (int) $catrow[$i]['cat_id'] .'">'. htmlspecialchars($catrow[$i]['cat_title'], ENT_QUOTES, 'UTF-8') .'</option>';
			}
			$select_to .= '</select>';

			$template->set_filenames(array(
				'body' => 'admin/admin_link_cat_delete_body.tpl')
			);

			$template->assign_vars(array(
				'S_LINK_ACTION' => append_sid("admin_links_cat.$phpEx"),
				'S_HIDDEN_FIELDS' => '<input type="hidden" name="cat_id" value="' . $cat_id . '" />' . phpbb_admin_session_field(),
				'L_CAT_DELETE' => $lang['Delete_Category'],
				'L_CAT_DELETE_EXPLAIN' => $lang['Delete_Category_Explain'],
				'L_CAT_TITLE' => $lang['Category_Title'],
				'S_CAT_TITLE' => htmlspecialchars($thiscat['cat_title'], ENT_QUOTES, 'UTF-8'),
				'L_MOVE_CONTENTS' => $lang['Move_contents'],
				'L_MOVE_DELETE' => $lang['Move_and_Delete'],
				'S_SELECT_TO' => $select_to)
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else
		{
			message_die(GENERAL_ERROR, 'Invalid category action.');
		}
	}
}
else
{
	$template->set_filenames(array('body'=>'admin/admin_link_cat_new_body.tpl'));
	$template->assign_vars(array(
		'L_LINK_CAT_TITLE'=>$lang['Link_Categories_Title'], 'L_LINK_CAT_EXPLAIN'=>$lang['Link_Categories_Explain'],
		'S_LINK_ACTION'=>append_sid("admin_links_cat.$phpEx"), 'S_HIDDEN_FIELDS'=>phpbb_admin_session_field(),
		'L_CAT_TITLE'=>$lang['Category_Title'], 'S_CAT_TITLE'=>'', 'S_MODE'=>'new', 'L_PANEL_TITLE'=>$lang['Create_category']
	));
	$template->pparse('body');
	include('./page_footer_admin.'.$phpEx);
}
?>