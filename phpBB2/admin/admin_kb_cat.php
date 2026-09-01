<?php
/***************************************************************************
 *                             admin_kb_cat.php
 *                            -------------------
 *   begin                : Monday, Mar 31, 2003
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: admin_kb_cat.php,v 1.4 2004/05/02 08:25:02 jonohlsson Exp $
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
	$file = basename(__FILE__);
	$module['KB_title']['Cat_man'] = $file;
	return;
}

// function get_list($id, $select, $selected = false)
function get_list_kb($id, $select, $selected = false)
{
 	global $db;
	$id = (int) $id;
	$select = (int) $select;
	$selected = (int) $selected;

    $idfield = 'category_id';
	$namefield = 'category_name';

	$sql = "SELECT *
		FROM " . KB_CATEGORIES_TABLE;
	
	if( $select == 0 )
	{
		$sql .= " WHERE $idfield <> $id";
	}
	
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, "Couldn't get list of Categories", "", __LINE__, __FILE__, $sql);
	}

	$catlist = "";

	while( $row = $db->sql_fetchrow($result) )
	{
		if ( $selected == $row[$idfield] )
		{
		    $status = 'selected';
		}
		else
		{
		    $status = '';
		}
		$category_id = (int) $row[$idfield];
		$catlist .= '<option value="' . $category_id . '" ' . $status . '>' . phpbb_stored_text($row[$namefield]) . "</option>\n";
	}

	return($catlist);
}

function kb_admin_category_order_form($category_id, $mode, $label)
{
	global $phpbb_root_path, $phpEx;
	$category_id = (int) $category_id;
	$mode = ($mode === 'down') ? 'down' : 'up';
	return '<form method="post" action="' . append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx") . '" style="display:inline">' .
		'<input type="hidden" name="mode" value="' . $mode . '">' .
		'<input type="hidden" name="cat" value="' . $category_id . '">' . phpbb_admin_session_field() .
		'<button type="submit" class="gen" style="border:0;background:transparent;padding:0;text-decoration:underline;cursor:pointer">' . $label . '</button></form>';
}

function kb_admin_category_text($value)
{
	$value = trim((string) $value);
	return function_exists('mb_substr') ? mb_substr($value, 0, 255, 'UTF-8') : substr($value, 0, 255);
}

function kb_admin_category_parent_valid($category_id, $parent_id)
{
	global $db;
	$category_id = (int) $category_id;
	$parent_id = (int) $parent_id;
	$visited = array();
	if ($category_id > 0)
	{
		$sql = "SELECT category_id FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = $category_id";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, 'Could not validate category', '', __LINE__, __FILE__, $sql);
		}
		if (!$db->sql_fetchrow($result))
		{
			return false;
		}
	}

	while ($parent_id)
	{
		if ($parent_id === $category_id || isset($visited[$parent_id]))
		{
			return false;
		}
		$visited[$parent_id] = true;
		$sql = "SELECT parent FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = $parent_id";
		if (!($result = $db->sql_query($sql)))
		{
			message_die(GENERAL_ERROR, "Could not validate category parent", '', __LINE__, __FILE__, $sql);
		}
		$row = $db->sql_fetchrow($result);
		if (!$row)
		{
			return false;
		}
		$parent_id = (int) $row['parent'];
	}

	return true;
}

function kb_admin_rebuild_category_counts()
{
	global $db;
	$parents = array();
	$counts = array();
	$sql = "SELECT category_id, parent FROM " . KB_CATEGORIES_TABLE;
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain Knowledge Base categories', '', __LINE__, __FILE__, $sql);
	}
	while ($row = $db->sql_fetchrow($result))
	{
		$category_id = (int) $row['category_id'];
		$parents[$category_id] = (int) $row['parent'];
		$counts[$category_id] = 0;
	}
	$db->sql_freeresult($result);

	$sql = "SELECT article_category_id, COUNT(article_id) AS total
		FROM " . KB_ARTICLES_TABLE . "
		WHERE approved = 1
		GROUP BY article_category_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not count Knowledge Base articles', '', __LINE__, __FILE__, $sql);
	}
	while ($row = $db->sql_fetchrow($result))
	{
		$current_id = (int) $row['article_category_id'];
		$article_count = max(0, (int) $row['total']);
		$visited = array();
		while ($current_id > 0 && isset($counts[$current_id]) && !isset($visited[$current_id]) && count($visited) < 100)
		{
			$visited[$current_id] = true;
			$counts[$current_id] += $article_count;
			$current_id = isset($parents[$current_id]) ? $parents[$current_id] : 0;
		}
	}
	$db->sql_freeresult($result);

	foreach ($counts as $category_id => $article_count)
	{
		$sql = "UPDATE " . KB_CATEGORIES_TABLE . " SET number_articles = " . (int) $article_count . " WHERE category_id = " . (int) $category_id;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not rebuild Knowledge Base category counters', '', __LINE__, __FILE__, $sql);
		}
	}
}

function kb_admin_delete_discussions_enabled()
{
	global $db;
	$sql = "SELECT config_value FROM " . KB_CONFIG_TABLE . " WHERE config_name = 'del_topic'";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain Knowledge Base deletion settings', '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	return is_array($row) && !empty($row['config_value']);
}

function kb_admin_move_category($category_id, $direction)
{
	global $db;
	$category_id = (int) $category_id;
	$direction = ($direction === 'down') ? 'down' : 'up';
	$sql = "SELECT parent FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = $category_id";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain category order', '', __LINE__, __FILE__, $sql);
	}
	$row = $db->sql_fetchrow($result);
	if (!is_array($row))
	{
		message_die(GENERAL_MESSAGE, 'Category does not exist');
	}
	$parent_id = (int) $row['parent'];

	$sql = "SELECT category_id FROM " . KB_CATEGORIES_TABLE . " WHERE parent = $parent_id ORDER BY cat_order ASC, category_id ASC";
	if (!($result = $db->sql_query($sql)))
	{
		message_die(GENERAL_ERROR, 'Could not obtain category order', '', __LINE__, __FILE__, $sql);
	}
	$category_ids = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$category_ids[] = (int) $row['category_id'];
	}
	$current_index = array_search($category_id, $category_ids, true);
	$target_index = ($direction === 'down') ? $current_index + 1 : $current_index - 1;
	if ($current_index !== false && isset($category_ids[$target_index]))
	{
		$temp_id = $category_ids[$target_index];
		$category_ids[$target_index] = $category_ids[$current_index];
		$category_ids[$current_index] = $temp_id;
	}

	foreach ($category_ids as $index => $ordered_category_id)
	{
		$order = ($index + 1) * 10;
		$sql = "UPDATE " . KB_CATEGORIES_TABLE . " SET cat_order = $order WHERE category_id = " . (int) $ordered_category_id;
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, 'Could not update category order', '', __LINE__, __FILE__, $sql);
		}
	}
}

//
// get_kb_cat_subs($parent)
// gets sub categories for a category
//
function get_kb_cat_subs($parent, $indent)
{
    global $db, $template, $phpbb_root_path, $phpbb_root_path, $phpEx, $images, $row_color, $row_class, $theme, $i, $lang;
	static $visited = array();
	$parent = (int) $parent;
	if (isset($visited[$parent]))
	{
		return;
	}
	$visited[$parent] = true;
	
	//$i = $i + 1;
	
	$sql = "SELECT *  
       		FROM " . KB_CATEGORIES_TABLE . " 
			WHERE parent = " . $parent . " 
			ORDER BY cat_order";
	
	 if ( !($result = $db->sql_query($sql)) )
	 {
		message_die(GENERAL_ERROR, "Could not obtain sub-category data", '', __LINE__, __FILE__, $sql);
	 }

	 while ( $category2 = $db->sql_fetchrow($result) )
	 {		
		$category_details2 = phpbb_stored_text($category2['category_details']);
		$category_articles2 = (int) $category2['number_articles'];
		
		$category_id2 = (int) $category2['category_id'];
		$category_name2 = phpbb_stored_text($category2['category_name']);
		$temp_url = append_sid($phpbb_root_path . "kb.$phpEx?mode=cat&amp;cat=$category_id2");
	   	$category2 = '<a href="' . $temp_url . '" class="gen">' . $category_name2 . '</a>';
		
		$temp_url = append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=edit&amp;cat=$category_id2");
	   	$edit2 = '<a href="' . $temp_url . '"><img src="'.$phpbb_root_path . $images['icon_edit'] . '" border="0" alt="' . $lang['Edit'] . '"></a>';
		
		$temp_url = append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=delete&amp;cat=$category_id2");
	   	$delete2 = '<a href="' . $temp_url . '" class="gen"><img src="'.$phpbb_root_path . $images['icon_delpost'] . '" border="0" alt="' . $lang['Delete'] . '"></a>';
		
		$up2 = kb_admin_category_order_form($category_id2, 'up', $lang['Move_up']);
		$down2 = kb_admin_category_order_form($category_id2, 'down', $lang['Move_down']);
		
		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
		
		$template->assign_block_vars('catrow.subrow', array(
			'CATEGORY' => $category2,
			'CAT_DESCRIPTION' => $category_details2,
			'CAT_ARTICLES' => $category_articles2,
			
			'INDENT' => $indent,
			
			'U_EDIT' => $edit2,
			'U_DELETE' => $delete2,
			'U_UP' => $up2,
			'U_DOWN' => $down2,
			
			'ROW_COLOR' => '#' . $row_color,
			'ROW_CLASS' => $row_class)
		);
		$i++;
		$sql = "SELECT category_id  
       		FROM " . KB_CATEGORIES_TABLE . " 
			WHERE parent = " . $category_id2 . " 
			ORDER BY cat_order";
		if ( !($result2 = $db->sql_query($sql)) )
	 	{
		    message_die(GENERAL_ERROR, "Could not obtain sub-category data", '', __LINE__, __FILE__, $sql);
	 	}

	 	$kb_cat = $db->sql_fetchrow($result2);
	 	
		if ( $kb_cat && !empty($kb_cat['category_id']) )
		{
			$temp = $indent . '-> ';
			get_kb_cat_subs($category_id2, $temp);
		}		    
	}	
	return;
}

//
// Load default header
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
require($phpbb_root_path . 'includes/kb_constants.' . $phpEx);
include($phpbb_root_path . 'includes/functions_admin.'.$phpEx);
include_once($phpbb_root_path . 'includes/functions_kb.' . $phpEx);

$mode_value = isset($_POST['mode']) ? $_POST['mode'] : (isset($_GET['mode']) ? $_GET['mode'] : '');
$mode = is_scalar($mode_value) ? (string) $mode_value : '';
$submit = !empty($_POST['submit']);
if (!in_array($mode, array('', 'create', 'edit', 'delete', 'up', 'down'), true))
{
	$mode = '';
}
if (($submit && in_array($mode, array('create', 'edit', 'delete'), true)) || in_array($mode, array('up', 'down'), true))
{
	phpbb_admin_require_post_session();
}

switch( $mode )
{

  case ('create'):
	  
  if ( !$submit )
  {
		$new_cat_name = phpbb_admin_html(phpbb_admin_post_string('new_cat_name'));
  
	   //
 	   // Generate page
  	   //
  	   $template->set_filenames(array(
			'body' => 'admin/kb_cat_edit_body.tpl')
       );
	   
	   $template->assign_block_vars('switch_cat', array());
	   
  	   $template->assign_vars(array( 
	        'L_EDIT_TITLE' => $lang['Create_cat'],
			'L_EDIT_DESCRIPTION' => $lang['Create_description'],
			'L_CATEGORY' => $lang['Category'],
			'L_DESCRIPTION' => $lang['Article_description'],
			'L_NUMBER_ARTICLES' => $lang['Articles'],
			'L_CAT_SETTINGS' => $lang['Cat_settings'],
			'L_CREATE' => $lang['Create'],
			'L_PARENT' => $lang['Parent'],
			'L_NONE' => $lang['None'],
			
			'PARENT_LIST' => get_list_kb(0, 0, 0),
			
			'S_ACTION' => append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=create"),
			'CAT_NAME' => $new_cat_name,
			'DESC' => '',
			'NUMBER_ARTICLES' => '0',
			'S_HIDDEN' => phpbb_admin_session_field())
		);
  }
  else
  {	   
	   $cat_name = kb_admin_category_text(phpbb_admin_post_string('catname'));
	   
	   if ( !$cat_name )
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
	   
	   $cat_desc = kb_admin_category_text(phpbb_admin_post_string('catdesc'));
	   $parent = (isset($_POST['parent']) && is_scalar($_POST['parent'])) ? (int) $_POST['parent'] : 0;
	   if (!kb_admin_category_parent_valid(0, $parent))
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
	   $cat_name_sql = $db->sql_escape($cat_name);
	   $cat_desc_sql = $db->sql_escape($cat_desc);
	   
	   $sql = "SELECT MAX(cat_order) AS cat_order
			FROM " .  KB_CATEGORIES_TABLE . " WHERE parent = $parent";
	   if ( !($result = $db->sql_query($sql)) )
	   {
		  message_die(GENERAL_ERROR, 'Could not obtain next type id', '', __LINE__, __FILE__, $sql);
	   }

	   if ( !($id = $db->sql_fetchrow($result)) )
	   {
		    message_die(GENERAL_ERROR, 'Could not obtain next type id', '', __LINE__, __FILE__, $sql);
	    }
		$cat_order = (int) $id['cat_order'] + 10;
	   
	   $sql = "INSERT INTO " . KB_CATEGORIES_TABLE . " ( category_name, category_details, number_articles, parent, cat_order)" . 
		   " VALUES ( '$cat_name_sql', '$cat_desc_sql', 0, $parent, $cat_order)";
			   
	   if ( !($results = $db->sql_query($sql)) )
	   {
	       message_die(GENERAL_ERROR, "Could not create category", '', __LINE__, __FILE__, $sql);
	   }
	   kb_admin_rebuild_category_counts();

	   $message = $lang['Cat_created'] . '<br /><br />' . sprintf($lang['Click_return_cat_manager'], '<a href="' . append_sid("admin_kb_cat.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	message_die(GENERAL_MESSAGE, $message);	
  }
  break;

  case ('edit'):
  
  if ( !$submit )
  {
		$cat_id = (isset($_GET['cat']) && is_scalar($_GET['cat'])) ? (int) $_GET['cat'] : 0;
	   if (!$cat_id)
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
	   
	   $sql = "SELECT * FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = " . $cat_id;
		 
	   if ( !($results = $db->sql_query($sql)) )
	   {
   	  	  message_die(GENERAL_ERROR, "Could not obtain category information", '', __LINE__, __FILE__, $sql);
	   }
	   if ( $kb_cat = $db->sql_fetchrow($results) )
	   {
	  	  $cat_name = $kb_cat['category_name'];
		  $cat_desc = $kb_cat['category_details'];
		  $number_articles = $kb_cat['number_articles'];
		  $parent = $kb_cat['parent'];
	   }
	   else
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
  
	   //
 	   // Generate page
  	   //
  	   $template->set_filenames(array(
			'body' => 'admin/kb_cat_edit_body.tpl')
       );

	   $template->assign_block_vars('switch_cat', array());
	   $template->assign_block_vars('switch_cat.switch_edit_category', array());
	   
  	   $template->assign_vars(array( 
	        'L_EDIT_TITLE' => $lang['Edit_cat'],
			'L_EDIT_DESCRIPTION' => $lang['Edit_description'],
			'L_CATEGORY' => $lang['Category'],
			'L_DESCRIPTION' => $lang['Article_description'],
			'L_NUMBER_ARTICLES' => $lang['Articles'],
			'L_CAT_SETTINGS' => $lang['Cat_settings'],
			'L_CREATE' => $lang['Edit'],
			
			'L_PARENT' => $lang['Parent'],
			'L_NONE' => $lang['None'],
			
			'PARENT_LIST' => get_list_kb($cat_id, 0, $parent),
			
			'S_ACTION' => append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=edit"),
			'CAT_NAME' => phpbb_stored_text($cat_name),
			'CAT_DESCRIPTION' => phpbb_stored_text($cat_desc),
			'NUMBER_ARTICLES' => (int) $number_articles,
			
			'S_HIDDEN' => '<input type="hidden" name="catid" value="' . $cat_id . '">' . phpbb_admin_session_field())
		);
  }
  else
  {
		$cat_id = (isset($_POST['catid']) && is_scalar($_POST['catid'])) ? (int) $_POST['catid'] : 0;
	   $cat_name = kb_admin_category_text(phpbb_admin_post_string('catname'));
	   $cat_desc = kb_admin_category_text(phpbb_admin_post_string('catdesc'));
	   $parent = (isset($_POST['parent']) && is_scalar($_POST['parent'])) ? (int) $_POST['parent'] : 0;
	   
	   if ( !$cat_id || !$cat_name || !kb_admin_category_parent_valid($cat_id, $parent) )
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }

	   $cat_name_sql = $db->sql_escape($cat_name);
	   $cat_desc_sql = $db->sql_escape($cat_desc);
	   $sql = "UPDATE " . KB_CATEGORIES_TABLE .
		" SET category_name = '" . $cat_name_sql .
			"', category_details = '" . $cat_desc_sql .
			"', parent = " . $parent .
			" WHERE category_id = " . $cat_id;
		   
	   if ( !($results = $db->sql_query($sql)) )
	   {
	       message_die(GENERAL_ERROR, "Could not update category", '', __LINE__, __FILE__, $sql);
	   }
	   kb_admin_rebuild_category_counts();

	   $message = $lang['Cat_edited'] . '<br /><br />' . sprintf($lang['Click_return_cat_manager'], '<a href="' . append_sid("admin_kb_cat.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	   message_die(GENERAL_MESSAGE, $message);	
  }
  break;
  
  case ('delete'):

  if ( !$submit )
  {
		$cat_id = (isset($_GET['cat']) && is_scalar($_GET['cat'])) ? (int) $_GET['cat'] : 0;
	   if (!$cat_id)
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
  
  	   $sql = "SELECT *  
       		FROM " . KB_CATEGORIES_TABLE . 
			" WHERE category_id = '" . $cat_id . "'";
	
	   if ( !($cat_result = $db->sql_query($sql)) )
	   {
	   	  message_die(GENERAL_ERROR, "Could not obtain category information", '', __LINE__, __FILE__, $sql);
	   }

	   if ( $category = $db->sql_fetchrow($cat_result) )
	   {
	   	  $cat_name = $category['category_name'];
	   }
	   else
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
  
  	   //
 	   // Generate page
  	   //
  	   $template->set_filenames(array(
			'body' => 'admin/kb_cat_del_body.tpl')
       );

  	   $template->assign_vars(array(
	       'L_DELETE_TITLE' => $lang['Cat_delete_title'],
		   'L_DELETE_DESCRIPTION' => $lang['Cat_delete_desc'],
		   'L_CAT_DELETE' => $lang['Cat_delete_title'],
		   'L_DELETE_ARTICLES' => $lang['Delete_all_articles'],
		   
		   'L_CAT_NAME' => $lang['Article_category'],
		   'L_MOVE_CONTENTS' => $lang['Move_contents'],
		   'L_DELETE' => $lang['Move_and_Delete'],
		   
		   'S_HIDDEN_FIELDS' => '<input type="hidden" name="catid" value="' . $cat_id .'">' . phpbb_admin_session_field(),
		   'S_SELECT_TO' => get_list_kb($cat_id, 0),
		   'S_ACTION' => append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=delete"),
		   
		   'CAT_NAME' => phpbb_stored_text($cat_name))
	);  
  }
  else
  {
		$new_category = (isset($_POST['move_id']) && is_scalar($_POST['move_id'])) ? (int) $_POST['move_id'] : 0;
	   $old_category = (isset($_POST['catid']) && is_scalar($_POST['catid'])) ? (int) $_POST['catid'] : 0;
	   if (!$old_category || $new_category === $old_category)
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }

	   $sql = "SELECT parent FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = $old_category";
	   if (!($oldcat_result = $db->sql_query($sql)))
	   {
		  message_die(GENERAL_ERROR, "Could not get category data", '', __LINE__, __FILE__, $sql);
	   }
	   $old_cat = $db->sql_fetchrow($oldcat_result);
	   if (!$old_cat)
	   {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	   }
	   $old_parent = (int) $old_cat['parent'];
  
	   if ( $new_category != 0 )
	   {  
		  $sql = "SELECT category_id FROM " . KB_CATEGORIES_TABLE . " WHERE category_id = $new_category";
		  if ( !($cat_result = $db->sql_query($sql)) )
		  {
			 message_die(GENERAL_ERROR, "Could not get category data", '', __LINE__, __FILE__, $sql);
		  }
		  $new_cat = $db->sql_fetchrow($cat_result);
		  if (!$new_cat)
		  {
			 message_die(GENERAL_MESSAGE, $lang['Empty_category']);
		  }

		  $sql = "UPDATE " . KB_ARTICLES_TABLE .
		   " SET article_category_id = $new_category
			   WHERE article_category_id = $old_category";
			
	      if ( !($move_result = $db->sql_query($sql)) )
	      {
	   	     message_die(GENERAL_ERROR, "Could not move articles", '', __LINE__, __FILE__, $sql);
	      }
	   
	   }
	   else
	   {
		   if (kb_admin_delete_discussions_enabled())
		   {
			   $sql = "SELECT topic_id FROM " . KB_ARTICLES_TABLE . " WHERE article_category_id = $old_category AND topic_id > 0";
			   if (!($topic_result = $db->sql_query($sql)))
			   {
				   message_die(GENERAL_ERROR, 'Could not obtain Knowledge Base discussion topics', '', __LINE__, __FILE__, $sql);
			   }
			   while ($topic_row = $db->sql_fetchrow($topic_result))
			   {
				   kb_delete_discussion_topic((int) $topic_row['topic_id']);
			   }
		   }

	       $sql = "DELETE FROM " . KB_MATCH_TABLE . "
			      WHERE article_id IN (SELECT article_id FROM " . KB_ARTICLES_TABLE . " WHERE article_category_id = $old_category)";
		   if (!$db->sql_query($sql))
		   {
			   message_die(GENERAL_ERROR, "Could not delete article wordmatch data", '', __LINE__, __FILE__, $sql);
		   }

	       $sql = "DELETE FROM " . KB_VOTES_TABLE . "
			      WHERE votes_file IN (SELECT article_id FROM " . KB_ARTICLES_TABLE . " WHERE article_category_id = $old_category)";
		   if (!$db->sql_query($sql))
		   {
			   message_die(GENERAL_ERROR, "Could not delete article vote data", '', __LINE__, __FILE__, $sql);
		   }

	       $sql = "DELETE FROM " . KB_ARTICLES_TABLE . "
			      WHERE article_category_id = " . $old_category;
		   if ( !($delete__articles = $db->sql_query($sql)) )
	   	   {
	   	       message_die(GENERAL_ERROR, "Could not delete articles", '', __LINE__, __FILE__, $sql);
	   	   }
	   }

	   // Keep child categories reachable instead of orphaning them.
	   $sql = "UPDATE " . KB_CATEGORIES_TABLE . " SET parent = $old_parent WHERE parent = $old_category";
	   if (!$db->sql_query($sql))
	   {
		  message_die(GENERAL_ERROR, "Could not reparent sub-categories", '', __LINE__, __FILE__, $sql);
	   }

	   $sql = "DELETE FROM " . KB_CATEGORIES_TABLE .
	   		  " WHERE category_id = $old_category";
			 
	   if ( !($delete_result = $db->sql_query($sql)) )
	   {
	   	  message_die(GENERAL_ERROR, "Could not delete category", '', __LINE__, __FILE__, $sql);
	   }
	   $sql = "DELETE FROM " . KB_SEARCH_TABLE;
	   if (!$db->sql_query($sql))
	   {
		  message_die(GENERAL_ERROR, 'Could not invalidate Knowledge Base search results', '', __LINE__, __FILE__, $sql);
	   }
	   kb_admin_rebuild_category_counts();
	   	
	   $message = $lang['Cat_deleted'] . '<br /><br />' . sprintf($lang['Click_return_cat_manager'], '<a href="' . append_sid("admin_kb_cat.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');

	   message_die(GENERAL_MESSAGE, $message);
  }
  break;
  
  default:
 
  if ( $mode == "up" )
  {
	  $cat_id = (isset($_POST['cat']) && is_scalar($_POST['cat'])) ? (int) $_POST['cat'] : 0;
	  if (!$cat_id)
	  {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	  }
	  kb_admin_move_category($cat_id, 'up');
  }
  
  if ( $mode == "down" )
  {
	  $cat_id = (isset($_POST['cat']) && is_scalar($_POST['cat'])) ? (int) $_POST['cat'] : 0;
	  if (!$cat_id)
	  {
		  message_die(GENERAL_MESSAGE, $lang['Empty_category']);
	  }
	  kb_admin_move_category($cat_id, 'down');
  }
 
  //
  // Generate page
  //
  $template->set_filenames(array(
		'body' => 'admin/kb_cat_admin_body.tpl')
  );

  $template->assign_vars(array(
      'L_KB_CAT_TITLE' => $lang['Cat_man'],
  	  'L_KB_CAT_DESCRIPTION' => $lang['KB_cat_description'],
  
  	  'L_CREATE_CAT' => $lang['Create_cat'],
	  'L_CREATE' => $lang['Create'],
	  'L_CATEGORY' => isset($lang['Article_category']) ? $lang['Article_category'] : $lang['Category'],
  	  'L_ACTION' => $lang['Art_action'],
	  'L_ARTICLES' => $lang['Articles'],
	  'L_ORDER' => $lang['Update_order'],
	  
	  'S_ACTION' => append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx"),
	  'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="create">' . phpbb_admin_session_field())
   );
  
  //get categories
  $sql = "SELECT *  
       		FROM " . KB_CATEGORIES_TABLE . " 
			WHERE parent = 0 ORDER BY cat_order ASC";
	
	if ( !($cat_result = $db->sql_query($sql)) )
	{
	   message_die(GENERAL_ERROR, "Could not obtain category information", '', __LINE__, __FILE__, $sql);
	}

	$i = 0;
	while ( $category = $db->sql_fetchrow($cat_result) )
	{	
		
		$category_details = phpbb_stored_text($category['category_details']);
		$category_articles = (int) $category['number_articles'];
		
		$category_id = (int) $category['category_id'];
		$category_name = phpbb_stored_text($category['category_name']);
		$temp_url = append_sid($phpbb_root_path . "kb.$phpEx?mode=cat&amp;cat=$category_id");
	   	$category_link = '<a href="' . $temp_url . '" class="gen">' . $category_name . '</a>';
		
		$temp_url = append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=edit&amp;cat=$category_id");
	   	$edit = '<a href="' . $temp_url . '"><img src="'.$phpbb_root_path . $images['icon_edit'] . '" border="0" alt="' . $lang['Edit'] . '"></a>';
		
		$temp_url = append_sid($phpbb_root_path . "admin/admin_kb_cat.$phpEx?mode=delete&amp;cat=$category_id");
	   	$delete = '<a href="' . $temp_url . '" class="gen"><img src="'.$phpbb_root_path . $images['icon_delpost'] . '" border="0" alt="' . $lang['Delete'] . '"></a>';
		
		$up = kb_admin_category_order_form($category_id, 'up', $lang['Move_up']);
		$down = kb_admin_category_order_form($category_id, 'down', $lang['Move_down']);
		
		$row_color = ( !($i % 2) ) ? $theme['td_color1'] : $theme['td_color2'];
		$row_class = ( !($i % 2) ) ? $theme['td_class1'] : $theme['td_class2'];
		
		$template->assign_block_vars('catrow', array(
			'CATEGORY' => $category_link,
			'CAT_DESCRIPTION' => $category_details,
			'CAT_ARTICLES' => $category_articles,
			
			'U_EDIT' => $edit,
			'U_DELETE' => $delete,
			'U_UP' => $up,
			'U_DOWN' => $down,
			
			'ROW_COLOR' => '#' . $row_color,
			'ROW_CLASS' => $row_class)
		);
		
		$i++;
		get_kb_cat_subs($category_id, '-> ');		
	}
	break;
}

$template->pparse('body');

include('./page_footer_admin.'.$phpEx);

?>
