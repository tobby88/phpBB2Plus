<?php
/***************************************************************************
 *
 *                             admin_arcade_cats.php
 *                            -----------------------
 *   begin                : Monday, Jan 2nd, 2007
 *   copyright            : (c)2003-2007 www.phpbb-arcade.com
 *   email                : defenders_realm@yahoo.com
 *
 *   $Id : admin_arcade_cats.php, v 2.1.8 2007/01/02 dEfEndEr Exp $
 *   Support @ http://www.phpbb-arcade.com
 *
 ***************************************************************************
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************
 *
 *   This is a MOD for phpbb v2.0.6+. The phpbb group has all rights to the 
 *   phpbb source. They can be contacted at :
 *   
 *      I-Net : www.phpbb.com
 *      E-Mail: support@phpbb.com
 *
 ***************************************************************************/

define('IN_PHPBB', 1);
define('ARCADE_ADMIN', 1);
//
//  Set the filename
//
$file = basename(__FILE__);
//
//  Make sure the ACP doesn't go and run something.
//
if( !empty($setmodules) )
{
	$module['Arcade']['Categories'] = $file;
	return;
}
//
//  Set the base directory
//
$phpbb_root_path = './../';
//
//  Load phpBB required files
//
require($phpbb_root_path . 'extension.inc');
require('pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_arcade.'.$phpEx);
//
//  Setup the Arcade for use.
//
$version = $arcade->version($phpbb_root_path);
//
//  Check to see what Mode to run in.
//
if( isset($HTTP_POST_VARS['add_cat']) )
{
	$mode = "add_cat";
}
else if( isset($HTTP_POST_VARS['resync_cats']) )
{
	$mode = "resync_cats";
}
else
{
	$mode = $arcade->pass_var('mode', '');
}
//
//  Set cat_id
//
$cat_id = $arcade->pass_var('cat_id', 0);
//
//  Move Category
//
if( $mode == "cat_down" || $mode == "cat_up" )
{
//
//  Get cat_order
//
	$sql = "SELECT cat_type, cat_order FROM " . iNA_CAT . "
		WHERE cat_id = " . $cat_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
	}
	$cat = $db->sql_fetchrow($result);

  $cat_order = $cat['cat_order'];
 	if($cat['cat_type'] == 's')
 	{
	  $adder = 1;
  }
  else
  {
    $adder = 10;
  }
	if( $mode == "cat_down" )
	{
	  $new_order = ($cat_order+$adder);
	}
	else
	{
		$new_order = ($cat_order-$adder);
	}
//  Update the OLD category order first.
//
	$sql = "UPDATE " . iNA_CAT . "
		SET cat_order = " . $cat_order . "
			WHERE cat_order = $new_order";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
	}
//
//  Now put the new one into it's place.
//
	$sql = "UPDATE " . iNA_CAT . "
		SET cat_order = " . $new_order . "
			WHERE cat_id = '$cat_id'";
	if(!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $sql);
	}
  $arcade->clear_cache('categories', './../');
}
//
//  Edit Categories
//
if( $mode == "edit_cat" || $mode == "add_cat" )
{
  if($cat_id > 0)
  {
  	$sql = "SELECT * FROM " . iNA_CAT . "
  		WHERE cat_id = $cat_id";
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
  	}
  	$cat_info = $db->sql_fetchrow($result);
  	if($cat_info['cat_icon'])
  	{
  		$icon_path = '<img src="' . $phpbb_root_path . $cat_info['cat_icon'] . '">';
  	}
  	else
  	{
  		$icon_path = '';
  	}
  }
	$template->set_filenames(array( "body" => "admin/arcade_cats_edit_body.tpl") );
	if($cat_info['mod_id'] > 0)
	{
		$sql = "SELECT username FROM " . USERS_TABLE . "
			WHERE user_id = '". $cat_info['mod_id'] . "'";
		if(!$result = $db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_user_data'], "", __LINE__, __FILE__, $sql);
		}
		$mod_info = $db->sql_fetchrow($result);
		$moderator = $mod_info['username'];
	}
	else
	{
		$moderator = '';
	}
//
//  Category Type
//	
	$cat_type = '<select name="cat_type">';
	$selected = ( $cat_info['cat_type'] == 's' ) ? ' selected="selected"' : '';
	$cat_type .= '<option value="p">' . $lang['admin_cat_main'] . '</option><option value="s" '.$selected . '>'. $lang['admin_cat_sub'] . '</option>';
	$selected = ( $cat_info['cat_type'] == 'l' ) ? ' selected="selected"' : '';
	$cat_type .= '<option value="l" '.$selected . '>'. $lang['admin_cat_link'] . '</option>';
	$cat_type .= '</select>';
//
//  Parent Category
//	
  $cat_rows = $arcade->read_cat('./../');
  $cat_parent = '<select name="cat_parent"><option value="' . 0 . '">' . $lang['None'] . '</option>';
	for($i = 1;$i < (count($cat_rows)); $i++)
	{
    if($cat_rows[$i]['cat_type'] == 'l' || $cat_rows[$i]['cat_type'] == 's')
    {
      continue;
    }	 
		$selected = ( $cat_info['cat_parent'] == $cat_rows[$i]['cat_id'] ) ? ' selected="selected"' : '';
		$cat_parent .= '<option value="' . $cat_rows[$i]['cat_id'] . '" ' . $selected . '>' . $cat_rows[$i]['cat_name'] . '</option>';
  }
  $cat_parent .= '</select>';
//
// Category Group Required
//
  $cat_group =  '<select name="group_required"><option value="' . 0 . '">' . $lang['All'] . '</option>';
  $sql = "SELECT group_id, group_name FROM " . GROUPS_TABLE . "
    WHERE group_single_user <> " . TRUE . "
    ORDER BY group_name";
	if( !$result = $db->sql_query($sql) )
	{
		message_die(CRITICAL_ERROR, $lang['no_config_data'], '', __LINE__, __FILE__, $sql);
	}
	while ($groups_info = $db->sql_fetchrow($result))
	{
		$selected = ( $groups_info['group_id'] == $cat_info['group_required'] ) ? ' selected="selected"' : '';
		$cat_group .= '<option value="' . $groups_info['group_id'] . '"' . $selected . '>' . $groups_info['group_name'] . '</option>';
	}	
	$cat_group .= '</select>';
	
	$template->assign_vars(array(
		"S_GAME_ACTION" => append_sid("$file"),
		"VERSION" => $version,

 		"L_MENU_INFO" => $lang['admin_cat_header'],
		"L_MENU_HEADER" => $lang['admin_cat_menu'],
		"L_DESC" => $lang['admin_description'],
		"L_SUBMIT" => $lang['Submit'],
		"L_RESET" => $lang['Reset'],
		"L_ICON" => $lang['admin_cat_icon'],
		"L_ICON_INFO" => $lang['admin_cat_icon_info'],
		"L_NAME" => $lang['admin_cat_name'],
		"L_NAME_INFO" => $lang['admin_cat_name_info'],
		"L_TYPE" => $lang['admin_cat_type'],
		"L_TYPE_INFO" => $lang['admin_cat_type_info'],
		"L_PARENT" => $lang['admin_cat_parent'],
		"L_PARENT_INFO" => $lang['admin_cat_parent_info'],
  	"L_GROUP" => $lang['admin_cat_group'],
		"L_GROUP_INFO" => $lang['admin_cat_group_info'],
		"L_MODERATOR" => $lang['Moderator'],
		"L_MODERATOR_INFO" => $lang['admin_game_moderator_info'],
		"L_SPECIAL" => $lang['admin_game_special'],
		"L_SPECIAL_INFO" => $lang['admin_game_special_info'],
		
		"DASH" => $lang['game_dash'],
		"NAME" => $cat_info['cat_name'],
		"TYPE" => $cat_type,
		"PARENT" => $cat_parent,
		"GROUP" => $cat_group,
		"DESC" => $cat_info['cat_desc'],
		"ICON" => $cat_info['cat_icon'],
		"MODERATOR" => $moderator,
		"SPECIAL" => $cat_info['special_play'],
		"DISPLAY_ICON" => $icon_path,
		
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="save_cat"><input type="hidden" name="cat_order" value="'.$cat_info['cat_order'].'"><input type="hidden" name="cat_id" value="' . $cat_id . '">' ));
}
//
//  Delete a Category.
//
if( $mode == "del_cat")
{
	if( $cat_id )
	{
		$sql = "DELETE FROM " . iNA_CAT . "
			WHERE cat_id = $cat_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_cat_delete'], "", __LINE__, __FILE__, $sql);
		}
		$sql = "UPDATE " . iNA_GAMES . "
		  SET cat_id = -1
			WHERE cat_id = $cat_id";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_update'], "", __LINE__, __FILE__, $sql);
		}
    $arcade->clear_cache('categories', $phpbb_root_path);
		$message = $lang['admin_cat_deleted'];
		$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
	}
	else
	{
		$message = $lang['admin_cat_not_deleted'];
		$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
		message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
	}
}
//
//  Save category information
//
if( isset($HTTP_POST_VARS['save_cat']) || isset($HTTP_GET_VARS['save_cat']) )
{
	$icon    = $arcade->pass_var('icon', '');
	$name    = $arcade->pass_var('name', '');
	$type    = $arcade->pass_var('cat_type', '');
	$desc    = trim($HTTP_POST_VARS['desc']);
	$order   = $arcade->pass_var('cat_order', 0);
	
	if($type == 's')
	{
    $message = $lang['admin_cat_no_parent'];
  	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

    $parent = ( isset($HTTP_POST_VARS['cat_parent']) ) ? intval($HTTP_POST_VARS['cat_parent']) : "";
    if($parent == 0)
    {
      $message = $lang['admin_cat_no_parent'];
    	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
      message_die(GENERAL_MESSAGE, $message);
    }
    else if($parent == $cat_id)
    {
      message_die(GENERAL_MESSAGE, $message);
    }
    else
    {
      $sql = "SELECT cat_type, cat_order FROM " . iNA_CAT . "
        WHERE cat_id = ". $parent;
      if(!$result = $db->sql_query($sql))
      {
    		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
      }
      $parent_id = $db->sql_fetchrow($result);
      if($parent_id['cat_type'] == 's')
      {
        message_die(GENERAL_MESSAGE, $message);
      }
      if($order == 0 || $order == ($cat_id*10))
      {
        $order = $parent_id['cat_order']+1;
      }
    }
  }
  else if($type == 'l')
  {
    if (strlen($desc) < 5)
    {
      $message = $lang['game_link_error'];
    	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
      message_die(GENERAL_ERROR, $message);
    }
  }
  $group = ( isset($HTTP_POST_VARS['group_required']) ) ? intval($HTTP_POST_VARS['group_required']) : "";
	$moderator = ( isset($HTTP_POST_VARS['moderator']) ) ? trim($HTTP_POST_VARS['moderator']) : "";
	$special_play = ( isset($HTTP_POST_VARS['special']) ) ? intval($HTTP_POST_VARS['special']) : 0;
	if($moderator)
	{
		$sql = "SELECT user_id FROM " . USERS_TABLE . "
			WHERE username = '" . $moderator . "'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
		}
		$mod_info = $db->sql_fetchrow($result);
		$mod_id = $mod_info['user_id'];
	}
	if($cat_id > 0)
	{
  	if($order == 0)
  	{
      $order = ($cat_id * 10);
    }
		$sql = "UPDATE " . iNA_CAT . "
			SET cat_name = '$name', cat_order = '$order', cat_type = '$type', cat_parent = '$parent', group_required = '$group', mod_id = '$mod_id', special_play = '" . $special_play . "', cat_icon = '" . $icon . "', cat_desc = '$desc'
				WHERE cat_id = $cat_id";
	}
	else
	{
		if(empty($name) && empty($desc))
		{
			message_die(GENERAL_MESSAGE, $lang['no_cat_data_enter'], '', __LINE__, __FILE__, $sql);
		}
    $cat_id = ($arcade->last_cat_id() + 1 );
    if($type != 's')
    {
      $order = ($cat_id*10);
    }
		$sql = "INSERT INTO " . iNA_CAT . " (`cat_id`, `cat_order`, `cat_name`, `cat_type`, `cat_parent`, `cat_icon`, `mod_id`, `special_play`, `cat_desc`)
			VALUES ('$cat_id', '$order', '$name', '$type', '$parent', '$icon', '$mod_id', '$special_play', '$desc')";
	}
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_cat_update'], "", __LINE__, __FILE__, $sql);
	}
//
//  Force update of cache file
//
  $arcade->clear_cache('categories');

	$message = $lang['admin_cat_saved'];
	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
}
//
//  Re-Sync Category Info
//
if( $mode == 'resync_cats')
{
  $sql = "REPAIR TABLE " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
  if(!$result = $db->sql_query($sql))
  {
  	message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
  }
  $catrows = $arcade->read_cat('./../');

  $total_played = (get_games_total('SUM(played)')); 
  $total_games  = (get_games_total('COUNT(*)')); 
  $sql = "UPDATE " . iNA_CAT . " 
       SET total_played = " . $total_played . ",  total_games = " . $total_games . " 
          WHERE cat_id = -1"; 
  if (!$db->sql_query($sql)) 
  { 
    message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql); 
  } 
  $sql = "UPDATE " . iNA_GAMES . " 
       SET cat_id = -1
          WHERE cat_id IS NULL
          OR cat_id = 0"; 
  if (!$db->sql_query($sql)) 
  { 
    message_die(GENERAL_ERROR, $lang['no_game_update'], __LINE__, __FILE__, $sql); 
  } 

  for($i = 1; $i < count($catrows); $i++)
  {
    if($catrows[$i]['cat_type'] == 'l')
    {
      continue;
    }
    $extra = "cat_id = '" . $catrows[$i]['cat_id'] . "'"; 
    $total_played = intval(get_games_total('SUM(played)',$extra)); 
    $total_games  = intval(get_games_total('COUNT(*)',$extra)); 
    $sql = "UPDATE " . iNA_CAT . " 
       SET total_played = " . $total_played . ",  total_games = " . $total_games . " 
          WHERE cat_id = " . $catrows[$i]['cat_id']; 
    if (!$db->sql_query($sql)) 
    { 
      message_die(GENERAL_ERROR, $lang['no_cat_update'], __LINE__, __FILE__, $sql); 
    } 
  }
  $sql = "OPTIMIZE TABLE  " . iNA_GAMES . ", " . iNA_CAT . ", " . iNA_FAV . ", " . iNA_SESSIONS . ", " . iNA_SCORES . ", " . iNA_AT_SCORES;
 	if(!$result = $db->sql_query($sql))
 	{
 		message_die(GENERAL_ERROR, $lang['no_game_repair'], "", __LINE__, __FILE__, $sql);
 	}

	$message = $lang['admin_cats_resynced'];
	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
	message_die(GENERAL_MESSAGE, $message, '', __LINE__, __FILE__, $sql);
}
//
//  Default Categories Screen
//
if( $mode == "categories" || $mode == "cat_down" || $mode == "cat_up" || $mode == "" )
{
	$template->set_filenames(array( "body" => "admin/arcade_cats_body.tpl") );
	$template->assign_vars(array("VERSION" => $arcade->version()));	

  $cat_rows = $arcade->read_cat('./../');
 	$cat_count = count($cat_rows);

	for($i = 1; $i < $cat_count; $i++)
  {
    $icon = ''; 
		if($cat_rows[$i]['cat_icon'])
		{
			$icon = "<img src=\"" . $phpbb_root_path . $cat_rows[$i]['cat_icon'] . "\" width=\"" . $arcade->arcade_config['games_cat_image_width'] . "\" height=\"" . $arcade->arcade_config['games_cat_image_height'] . "\" border=\"0\">";
		}
		if (($i == 1) || ($cat_rows[$i]['cat_type'] == 's') && ($cat_rows[$i]['cat_order'] == $last_order+1))
		{
			$image_up = '';
		}
		else
		{
			$image_up = '<img src="' . $phpbb_root_path . "images/arrow_up.gif" . '" border="0" alt="' . $lang['admin_up'] . '"/>';
		}
		if ($i == ($cat_count-1))
		{
			$image_down = '';
		}
		else
		{
			$image_down = '<img src="' . $phpbb_root_path . "images/arrow_down.gif" . '" border="0" alt="' . $lang['admin_down_full'] . '"/>';
		}
		
		$last_order = $cat_rows[$i]['cat_order'];
		
    $template->assign_block_vars("cats", array(
		    'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2', 
     		'CAT_ID' => $cat_rows[$i]['cat_id'], 
       	'ICON' => $icon,
       	'NAME' => $cat_rows[$i]['cat_name'],                                  
       	'DESC' => $cat_rows[$i]['cat_desc'],

       	'IMAGE_UP' => $image_up,
   			'IMAGE_DOWN' => $image_down,
		       	
       	'EDIT_GAMES' => ( $cat_rows[$i]['cat_type'] == 'l' ) ? '' : '[<a href="' . append_sid("admin_arcade_games.$phpEx?mode=edit_games&amp;cat_id=" . $cat_rows[$i]['cat_id']) . '">'.$lang['admin_edit_games'].'</a>]', 
		       	
				'U_CAT_UP' => append_sid("$file?mode=cat_up&amp;cat_id=" . $cat_rows[$i]['cat_id']), 
				'U_CAT_DOWN' => append_sid("$file?mode=cat_down&amp;cat_id=" . $cat_rows[$i]['cat_id']), 
				'U_CAT_EDIT' => append_sid("$file?mode=edit_cat&amp;cat_id=" . $cat_rows[$i]['cat_id']), 
				'U_CAT_DELETE' => append_sid("$file?mode=del_cat&amp;cat_id=" . $cat_rows[$i]['cat_id']) )); 
	} 

	$template->assign_vars(array(
 		'S_CONFIG_ACTION' => append_sid("$file"),

   	'IMAGE_DEL' => '<img src="./../' . $images['icon_delpost'] . '" alt="' . $lang['Delete'] . '" title="' . $lang['Delete'] . '" border="0" />',
   	'IMAGE_EDIT' => '<img src="./../' . $images['icon_edit'] . '" alt="' . $lang['Edit'] . '" title="' . $lang['Edit'] . '" border="0" />',

 		'L_INA_HEADER' => $lang['admin_cat_header'],
		'L_CONFIG_MENU' => $lang['admin_cat_menu'],
		'L_CAT_ID' => "#",
		'L_CATS' => $lang['admin_description'],
		'L_ACTION' => $lang['admin_action'],
		'L_MOVE' => $lang['admin_move'],
		'L_EDIT' => $lang['Edit'],
		'L_UP' => $lang['admin_up'],
		'L_DOWN' => $lang['admin_down_full'],
		'L_DEL' => $lang['admin_delete_full'],
		'L_ADD' => $lang['Add_new'],
    'L_RESYNC' => $lang['admin_resync'] ));
}
//
// Generate the page
//
$template->pparse('body');
include('page_footer_admin.' . $phpEx);

?>
