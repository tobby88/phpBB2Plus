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

// Category mutation dispatch: all SQL and deferred files share one owner.
if (($submit && in_array($mode, array('create', 'edit', 'delete'), true)) || in_array($mode, array('up', 'down'), true))
{
	require_once dirname(__DIR__) . '/includes/functions_kb_category_storage.php';
	$category_input = array();
	if ($mode === 'create' || $mode === 'edit') {
		$category_input = array('name'=>isset($_POST['catname']) && is_string($_POST['catname']) ? phpbb_admin_post_string('catname') : null,
			'description'=>isset($_POST['catdesc']) && is_string($_POST['catdesc']) ? phpbb_admin_post_string('catdesc') : null,
			'parent'=>isset($_POST['parent']) ? $_POST['parent'] : null);
	} elseif ($mode === 'delete') { $category_input['destination'] = isset($_POST['move_id']) ? $_POST['move_id'] : null; }
	$category_key = ($mode === 'up' || $mode === 'down') ? 'cat' : 'catid';
	try {
		$outcome = kb_change_category($db, $mode, $mode === 'create' ? 0 : (isset($_POST[$category_key]) ? $_POST[$category_key] : null), $category_input);
	} catch (PhpbbKbPublicationException $error) { message_die(GENERAL_ERROR, $error->getMessage()); }
	if ($mode === 'up' || $mode === 'down') { $mode = ''; }
	else {
		$labels = array('create'=>'Cat_created', 'edit'=>'Cat_edited', 'delete'=>'Cat_deleted');
		$message = $lang[$labels[$mode]] . '<br /><br />' . sprintf($lang['Click_return_cat_manager'], '<a href="' . append_sid("admin_kb_cat.$phpEx") . '">', '</a>') . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid($phpbb_root_path . "admin/index.$phpEx?pane=right") . '">', '</a>');
		if ($outcome['cleanup_pending']) { $message .= '<br /><br />' . $lang['KB_deletion_cleanup_pending']; }
		message_die(GENERAL_MESSAGE, $message);
	}
}
// Category views only: no legacy autocommit mutation copies remain.

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
  break;
  
  default:
 
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
