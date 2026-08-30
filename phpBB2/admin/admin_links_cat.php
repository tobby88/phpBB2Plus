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


// --------------------------
// This function will sort the order of all categories
//
function reorder_category()
{
	global $db;

	$sql = "SELECT cat_id, cat_order
			FROM ". LINK_CATEGORIES_TABLE ."
			WHERE cat_id <> 0
			ORDER BY cat_order ASC";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, 'Could not get list of Categories', '', __LINE__, __FILE__, $sql);
	}

	$i = 10;

	while( $row = $db->sql_fetchrow($result) )
	{
		$sql = "UPDATE ". LINK_CATEGORIES_TABLE ."
				SET cat_order = $i
				WHERE cat_id = ". $row['cat_id'];
		if( !$db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, 'Could not update order fields', '', __LINE__, __FILE__, $sql);
		}
		$i += 10;
	}
}
// END
// --------------------------

$post_mode = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] : '';
$action = (isset($_GET['action']) && is_scalar($_GET['action'])) ? (string) $_GET['action'] : '';
$post_mode = in_array($post_mode, array('', 'new', 'edit', 'delete'), true) ? $post_mode : '';
$action = in_array($action, array('', 'edit', 'delete'), true) ? $action : '';

if (isset($_POST['cat_action']) && is_scalar($_POST['cat_action']) &&
	preg_match('/^move_(up|down):([0-9]+)$/D', (string) $_POST['cat_action'], $move_action))
{
	phpbb_admin_require_post_session();
	$cat_id = (int) $move_action[2];
	$move = ($move_action[1] === 'up') ? -15 : 15;
	$sql = "SELECT cat_id, cat_order FROM " . LINK_CATEGORIES_TABLE . " WHERE cat_id = $cat_id";
	if ($cat_id <= 0 || !($result = $db->sql_query($sql)) || !($current_category = $db->sql_fetchrow($result)))
	{
		message_die(GENERAL_ERROR, 'The requested category does not exist.');
	}
	$comparison = ($move_action[1] === 'up') ? '<' : '>';
	$sort = ($move_action[1] === 'up') ? 'DESC' : 'ASC';
	$sql = "SELECT cat_id FROM " . LINK_CATEGORIES_TABLE . "
		WHERE cat_order $comparison " . (int) $current_category['cat_order'] . "
		ORDER BY cat_order $sort LIMIT 1";
	if (!($result = $db->sql_query($sql)) || !$db->sql_fetchrow($result))
	{
		$message = $lang['Category_changed_order'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message);
	}

	$sql = "UPDATE " . LINK_CATEGORIES_TABLE . "
		SET cat_order = cat_order + $move
		WHERE cat_id = $cat_id";
	if (!$db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, 'Could not change category order');
	}
	reorder_category();

	$message = $lang['Category_changed_order'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message);
}

if ($post_mode !== '')
{
	phpbb_admin_require_post_session();
}

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
				ORDER BY cat_order ASC";
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
			$cat_id = (isset($_GET['cat_id']) && is_scalar($_GET['cat_id'])) ? (int) $_GET['cat_id'] : 0;

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
			$cat_id = (isset($_GET['cat_id']) && is_scalar($_GET['cat_id'])) ? (int) $_GET['cat_id'] : 0;

			$sql = "SELECT cat_id, cat_title, cat_order
					FROM ". LINK_CATEGORIES_TABLE ."
					ORDER BY cat_order ASC";
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
	if( $post_mode == 'new' )
	{
		if( !isset($_POST['cat_title']) )
		{
			$template->set_filenames(array(
				'body' => 'admin/admin_link_cat_new_body.tpl')
			);

			$template->assign_vars(array(
				'L_LINK_CAT_TITLE' => $lang['Link_Categories_Title'],
				'L_LINK_CAT_EXPLAIN' => $lang['Link_Categories_Explain'],
				'S_LINK_ACTION' => append_sid("admin_links_cat.$phpEx"),
				'S_HIDDEN_FIELDS' => phpbb_admin_session_field(),
				'L_CAT_TITLE' => $lang['Category_Title'],


				'L_DISABLED' => $lang['Disabled'],

				'S_MODE' => 'new',


				'L_PANEL_TITLE' => $lang['Create_category'])
			);

			$template->pparse('body');

			include('./page_footer_admin.'.$phpEx);
		}
		else
		{
			// Get posting variables
			$cat_title = is_scalar($_POST['cat_title']) ? trim((string) $_POST['cat_title']) : '';
			if (!preg_match('/^.{1,100}$/usD', $cat_title))
			{
				message_die(GENERAL_MESSAGE, $lang['Link_category_title_required']);
			}
			$cat_title_sql = $db->sql_escape($cat_title);


			// Get the last ordered category
			$sql = "SELECT cat_order FROM ". LINK_CATEGORIES_TABLE ."
					ORDER BY cat_order DESC
					LIMIT 1";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Link Categories information', '', __LINE__, __FILE__, $sql);
			}
			$row = $db->sql_fetchrow($result);
			$last_order = $row ? (int) $row['cat_order'] : 0;
			$cat_order = $last_order + 10;

			// Here we insert a new row into the db
			$sql = "INSERT INTO ". LINK_CATEGORIES_TABLE ." (cat_title, cat_order)
					VALUES ('$cat_title_sql', " . (int) $cat_order . ")";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not create new Link Category', '', __LINE__, __FILE__, $sql);
			}

			// Return a message...
			$message = $lang['New_category_created'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
		}
	}
	else if( $post_mode == 'edit' )
	{
		// Get posting variables
		$cat_id = isset($_POST['cat_id']) && is_scalar($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
		$cat_title = isset($_POST['cat_title']) && is_scalar($_POST['cat_title']) ? trim((string) $_POST['cat_title']) : '';
		if ($cat_id <= 0 || !preg_match('/^.{1,100}$/usD', $cat_title))
		{
			message_die(GENERAL_MESSAGE, $lang['Link_category_title_required']);
		}
		$cat_title_sql = $db->sql_escape($cat_title);


		// Now we update this row
		$sql = "UPDATE ". LINK_CATEGORIES_TABLE ."
				SET cat_title = '$cat_title_sql'
				WHERE cat_id = $cat_id";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not update this Link Category', '', __LINE__, __FILE__, $sql);
		}

		// Return a message...
		$message = $lang['Category_updated'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

		message_die(GENERAL_MESSAGE, $message);
	}
	else if( $post_mode == 'delete' )
	{
		$cat_id = isset($_POST['cat_id']) && is_scalar($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
		if (!isset($_POST['target']) || !is_scalar($_POST['target']))
		{
			message_die(GENERAL_ERROR, 'Invalid category selection.');
		}
		$target = (int) $_POST['target'];
		if ($cat_id <= 0 || $target === $cat_id)
		{
			message_die(GENERAL_ERROR, 'Invalid category selection.');
		}
		$sql = "SELECT cat_id FROM " . LINK_CATEGORIES_TABLE . " WHERE cat_id = $cat_id";
		if (!($result = $db->sql_query($sql)) || !$db->sql_fetchrow($result))
		{
			message_die(GENERAL_ERROR, 'The requested category does not exist.');
		}
		if ($target > 0)
		{
			$sql = "SELECT cat_id FROM " . LINK_CATEGORIES_TABLE . " WHERE cat_id = $target";
			if (!($result = $db->sql_query($sql)) || !$db->sql_fetchrow($result))
			{
				message_die(GENERAL_ERROR, 'The target category does not exist.');
			}
		}

		if( $target == 0 ) // Delete All
		{
			// Get file information of all pics in this category
			$sql = "SELECT *
					FROM ". LINKS_TABLE ."
					WHERE link_category = '$cat_id'";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not query Link information', '', __LINE__, __FILE__, $sql);
			}
			$catrow = array();
			while( $row = $db ->sql_fetchrow($result) )
			{
				$catrow[] = $row;
			}

			if( count($catrow) != 0 ) // if this category is not empty
			{

				// Delete pic entries in db
				$sql = "DELETE FROM ". LINKS_TABLE ."
						WHERE link_category = '$cat_id'";
				if(!$result = $db->sql_query($sql))
				{
					message_die(GENERAL_ERROR, 'Could not delete link entries in the DB', '', __LINE__, __FILE__, $sql);
				}
			}

			// This category is now emptied, we can remove it!
			$sql = "DELETE FROM ". LINK_CATEGORIES_TABLE ."
					WHERE cat_id = '$cat_id'";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not delete this Category', '', __LINE__, __FILE__, $sql);
			}

			// Re-order the rest of categories
			reorder_category();

			// Return a message...
			$message = $lang['Category_deleted'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
		}
		else // Move content...
		{
			$sql = "UPDATE ". LINKS_TABLE ."
					SET link_category = $target
					WHERE link_category = $cat_id";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not update this Category content', '', __LINE__, __FILE__, $sql);
			}

			// This category is now emptied, we can remove it!
			$sql = "DELETE FROM ". LINK_CATEGORIES_TABLE ."
					WHERE cat_id = '$cat_id'";
			if(!$result = $db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, 'Could not delete this Category', '', __LINE__, __FILE__, $sql);
			}

			// Re-order the rest of categories
			reorder_category();

			// Return a message...
			$message = $lang['Category_deleted'] . "<br /><br />" . sprintf($lang['Click_return_link_category'], "<a href=\"" . append_sid("admin_links_cat.$phpEx") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

			message_die(GENERAL_MESSAGE, $message);
		}
	}
	else
	{
		message_die(GENERAL_ERROR, 'Invalid category mode.');
	}
}

/* Powered by Photo Link v2.x.x (c) 2002-2003 Smartor */

?>
