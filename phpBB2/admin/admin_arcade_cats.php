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

if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
if (!defined('ARCADE_ADMIN')) { define('ARCADE_ADMIN', 1); }
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
$mode = is_scalar($mode) ? (string) $mode : '';
if (!in_array($mode, array('', 'categories', 'add_cat', 'edit_cat', 'cat_up', 'cat_down', 'del_cat', 'resync_cats'), true))
{
	$mode = '';
}

if (isset($HTTP_POST_VARS['cat_action']) && is_scalar($HTTP_POST_VARS['cat_action']) &&
	preg_match('/^(cat_up|cat_down|del_cat):([0-9]+)$/D', (string) $HTTP_POST_VARS['cat_action'], $cat_action))
{
	$mode = $cat_action[1];
	$cat_id = (int) $cat_action[2];
}
//
//  Set cat_id
//
if (!isset($cat_id))
{
	$cat_id = max(0, (int) $arcade->pass_var('cat_id', 0));
}

if (in_array($mode, array('cat_up', 'cat_down', 'del_cat', 'resync_cats'), true) || isset($HTTP_POST_VARS['save_cat']))
{
	phpbb_admin_require_post_session();
}
//
//  Move Category
//
if( $mode == "cat_down" || $mode == "cat_up" )
{
//
//  Get cat_order
//
	$sql = "SELECT cat_type, cat_order, cat_parent FROM " . iNA_CAT . "
		WHERE cat_id = " . $cat_id;
	if( !$result = $db->sql_query($sql) )
	{
		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
	}
	$cat = $db->sql_fetchrow($result);
	if (!$cat)
	{
		message_die(GENERAL_MESSAGE, $lang['no_cat_data']);
	}

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
	$target_scope = ($cat['cat_type'] === 's') ?
		"cat_type = 's' AND cat_parent = " . (int) $cat['cat_parent'] :
		"cat_type <> 's' AND cat_id > 0";
	$sql = "SELECT cat_id FROM " . iNA_CAT . " WHERE cat_order = $new_order AND $target_scope LIMIT 1";
	if (!$result = $db->sql_query($sql))
	{
		message_die(GENERAL_ERROR, $lang['no_cat_data'], '', __LINE__, __FILE__, $sql);
	}
	$target_cat = $db->sql_fetchrow($result);
	if (!$target_cat)
	{
		message_die(GENERAL_MESSAGE, $lang['no_cat_data']);
	}
	$sql = "UPDATE " . iNA_CAT . "
		SET cat_order = " . (int) $cat_order . "
			WHERE cat_id = " . (int) $target_cat['cat_id'];
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
	$cat_info = array(
		'cat_name' => '', 'cat_type' => 'p', 'cat_parent' => 0,
		'group_required' => 0, 'mod_id' => 0, 'special_play' => 0,
		'cat_icon' => '', 'cat_desc' => '', 'cat_order' => 0
	);
	$icon_path = '';
  if($cat_id > 0)
  {
  	$sql = "SELECT * FROM " . iNA_CAT . "
  		WHERE cat_id = $cat_id";
  	if(!$result = $db->sql_query($sql))
  	{
  		message_die(GENERAL_ERROR, $lang['no_cat_data'], "", __LINE__, __FILE__, $sql);
  	}
  	$cat_info = $db->sql_fetchrow($result);
	if (!$cat_info)
	{
		message_die(GENERAL_MESSAGE, $lang['no_cat_data']);
	}
  	if($cat_info['cat_icon'])
  	{
		$icon_path = '<img src="' . htmlspecialchars($phpbb_root_path . $cat_info['cat_icon'], ENT_QUOTES, 'UTF-8') . '" alt="" />';
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
		$moderator = $mod_info ? (string) $mod_info['username'] : '';
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
		$cat_parent .= '<option value="' . (int) $cat_rows[$i]['cat_id'] . '" ' . $selected . '>' . htmlspecialchars($cat_rows[$i]['cat_name'], ENT_QUOTES, 'UTF-8') . '</option>';
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
		$cat_group .= '<option value="' . (int) $groups_info['group_id'] . '"' . $selected . '>' . htmlspecialchars($groups_info['group_name'], ENT_QUOTES, 'UTF-8') . '</option>';
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
		"NAME" => htmlspecialchars($cat_info['cat_name'], ENT_QUOTES, 'UTF-8'),
		"TYPE" => $cat_type,
		"PARENT" => $cat_parent,
		"GROUP" => $cat_group,
		"DESC" => htmlspecialchars($cat_info['cat_desc'], ENT_QUOTES, 'UTF-8'),
		"ICON" => htmlspecialchars($cat_info['cat_icon'], ENT_QUOTES, 'UTF-8'),
		"MODERATOR" => htmlspecialchars($moderator, ENT_QUOTES, 'UTF-8'),
		"SPECIAL" => $cat_info['special_play'],
		"DISPLAY_ICON" => $icon_path,
		
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="save_cat" value="1" /><input type="hidden" name="cat_order" value="' . (int) $cat_info['cat_order'] . '" /><input type="hidden" name="cat_id" value="' . (int) $cat_id . '" />' . phpbb_admin_session_field() ));
}
//
//  Delete a Category.
//
if( $mode == "del_cat")
{
	if( $cat_id )
	{
		$sql = "SELECT cat_id FROM " . iNA_CAT . " WHERE cat_id = $cat_id";
		$result = $db->sql_query($sql);
		if (!$result || !$db->sql_fetchrow($result))
		{
			message_die(GENERAL_MESSAGE, $lang['admin_cat_not_deleted']);
		}
		$sql = "UPDATE " . iNA_CAT . " SET cat_type = 'p', cat_parent = 0, cat_order = cat_id * 10 WHERE cat_parent = $cat_id";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $sql);
		}
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
		$total_played = max(0, (int) get_games_total('SUM(played)'));
		$total_games = max(0, (int) get_games_total('COUNT(*)'));
		$sql = "UPDATE " . iNA_CAT . " SET total_played = $total_played, total_games = $total_games WHERE cat_id = -1";
		if (!$db->sql_query($sql))
		{
			message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $sql);
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
if( isset($HTTP_POST_VARS['save_cat']) )
{
	$icon_input = isset($HTTP_POST_VARS['icon']) && is_scalar($HTTP_POST_VARS['icon']) ? substr(trim(stripslashes((string) $HTTP_POST_VARS['icon'])), 0, 255) : '';
	$icon = ($icon_input === '') ? '' : phpbb_arcade_local_asset($icon_input);
	$name = isset($HTTP_POST_VARS['name']) && is_scalar($HTTP_POST_VARS['name']) ? substr(trim(stripslashes((string) $HTTP_POST_VARS['name'])), 0, 100) : '';
	$type = isset($HTTP_POST_VARS['cat_type']) && is_scalar($HTTP_POST_VARS['cat_type']) ? (string) $HTTP_POST_VARS['cat_type'] : 'p';
	$desc = isset($HTTP_POST_VARS['desc']) && is_scalar($HTTP_POST_VARS['desc']) ? substr(trim(stripslashes((string) $HTTP_POST_VARS['desc'])), 0, 65535) : '';
	$order = (isset($HTTP_POST_VARS['cat_order']) && is_scalar($HTTP_POST_VARS['cat_order'])) ? (int) $HTTP_POST_VARS['cat_order'] : 0;
	$parent = (isset($HTTP_POST_VARS['cat_parent']) && is_scalar($HTTP_POST_VARS['cat_parent'])) ? (int) $HTTP_POST_VARS['cat_parent'] : 0;
	$mod_id = 0;

	if (!in_array($type, array('p', 's', 'l'), true))
	{
		$type = 'p';
	}
	if ($name === '')
	{
		message_die(GENERAL_MESSAGE, $lang['no_cat_data_enter']);
	}
	if ($icon_input !== '' && ($icon === '' || !preg_match('#^[a-zA-Z0-9_./-]+\.(?:gif|jpe?g|png|webp)$#D', $icon)))
	{
		message_die(GENERAL_MESSAGE, $lang['no_cat_data_enter']);
	}
	
	if($type == 's')
	{
    $message = $lang['admin_cat_no_parent'];
  	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");

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
	  if(!$parent_id || $parent_id['cat_type'] != 'p')
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
	$link_parts = @parse_url($desc);
    if (!$link_parts || empty($link_parts['scheme']) || empty($link_parts['host']) ||
		isset($link_parts['user']) || isset($link_parts['pass']) ||
		!in_array(strtolower($link_parts['scheme']), array('http', 'https'), true) || strpos($desc, '\\') !== false ||
		preg_match('/[\x00-\x20\x7f<>"\'`]/', $desc))
    {
      $message = $lang['game_link_error'];
    	$message .= sprintf($lang['admin_return_cats'], "<a href=\"" . append_sid("$file?mode=categories") . "\">", "</a>") . "<br /><br />" . sprintf($lang['Click_return_admin_index'], "<a href=\"" . append_sid("index.$phpEx?pane=right") . "\">", "</a>");
      message_die(GENERAL_ERROR, $message);
    }
  }
	if ($type !== 's')
	{
		$parent = 0;
	}
	$group = (isset($HTTP_POST_VARS['group_required']) && is_scalar($HTTP_POST_VARS['group_required'])) ? (int) $HTTP_POST_VARS['group_required'] : 0;
	if ($group > 0)
	{
		$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_id = $group AND group_single_user <> " . TRUE;
		$result = $db->sql_query($sql);
		if (!$result || !$db->sql_fetchrow($result))
		{
			$group = 0;
		}
	}
	$moderator = isset($HTTP_POST_VARS['moderator']) && is_scalar($HTTP_POST_VARS['moderator']) ? substr(trim(stripslashes((string) $HTTP_POST_VARS['moderator'])), 0, 25) : '';
	$special_play = max(0, min(65535, (isset($HTTP_POST_VARS['special']) && is_scalar($HTTP_POST_VARS['special'])) ? intval($HTTP_POST_VARS['special']) : 0));
	if($moderator)
	{
		$sql = "SELECT user_id FROM " . USERS_TABLE . "
			WHERE username = '" . $db->sql_escape($moderator) . "'";
		if( !$result = $db->sql_query($sql) )
		{
			message_die(GENERAL_ERROR, $lang['no_game_save'], "", __LINE__, __FILE__, $sql);
		}
		$mod_info = $db->sql_fetchrow($result);
		$mod_id = $mod_info ? (int) $mod_info['user_id'] : 0;
	}
	$name_sql = $db->sql_escape($name);
	$desc_sql = $db->sql_escape($desc);
	$icon_sql = $db->sql_escape($icon);
	if($cat_id > 0)
	{
		$sql = "SELECT cat_id FROM " . iNA_CAT . " WHERE cat_id = $cat_id";
		$result = $db->sql_query($sql);
		if (!$result || !$db->sql_fetchrow($result))
		{
			message_die(GENERAL_MESSAGE, $lang['no_cat_data']);
		}
		if ($type !== 'p')
		{
			$sql = "UPDATE " . iNA_CAT . " SET cat_type = 'p', cat_parent = 0, cat_order = cat_id * 10 WHERE cat_parent = $cat_id";
			if (!$db->sql_query($sql))
			{
				message_die(GENERAL_ERROR, $lang['no_cat_update'], '', __LINE__, __FILE__, $sql);
			}
		}
  	if($order == 0)
  	{
      $order = ($cat_id * 10);
    }
		$sql = "UPDATE " . iNA_CAT . "
			SET cat_name = '$name_sql', cat_order = " . (int) $order . ", cat_type = '$type', cat_parent = " . (int) $parent . ", group_required = " . (int) $group . ", mod_id = " . (int) $mod_id . ", special_play = " . (int) $special_play . ", cat_icon = '$icon_sql', cat_desc = '$desc_sql'
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
		$sql = "INSERT INTO " . iNA_CAT . " (`cat_id`, `cat_order`, `cat_name`, `cat_type`, `cat_parent`, `cat_icon`, `group_required`, `mod_id`, `special_play`, `cat_desc`)
			VALUES (" . (int) $cat_id . ", " . (int) $order . ", '$name_sql', '$type', " . (int) $parent . ", '$icon_sql', " . (int) $group . ", " . (int) $mod_id . ", " . (int) $special_play . ", '$desc_sql')";
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
			$icon = "<img src=\"" . htmlspecialchars($phpbb_root_path . $cat_rows[$i]['cat_icon'], ENT_QUOTES, 'UTF-8') . "\" width=\"" . (int) $arcade->arcade_config['games_cat_image_width'] . "\" height=\"" . (int) $arcade->arcade_config['games_cat_image_height'] . "\" border=\"0\" alt=\"\" />";
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
				'NAME' => htmlspecialchars($cat_rows[$i]['cat_name'], ENT_QUOTES, 'UTF-8'),
				'DESC' => htmlspecialchars($cat_rows[$i]['cat_desc'], ENT_QUOTES, 'UTF-8'),

       	'IMAGE_UP' => $image_up,
   			'IMAGE_DOWN' => $image_down,
		       	
       	'EDIT_GAMES' => ( $cat_rows[$i]['cat_type'] == 'l' ) ? '' : '[<a href="' . append_sid("admin_arcade_games.$phpEx?mode=edit_games&amp;cat_id=" . $cat_rows[$i]['cat_id']) . '">'.$lang['admin_edit_games'].'</a>]', 
		       	
				'CAT_UP_ACTION' => 'cat_up:' . (int) $cat_rows[$i]['cat_id'],
				'CAT_DOWN_ACTION' => 'cat_down:' . (int) $cat_rows[$i]['cat_id'],
				'CAT_DELETE_ACTION' => 'del_cat:' . (int) $cat_rows[$i]['cat_id'],
				'CAT_UP_DISABLED' => ($image_up === '') ? ' disabled="disabled"' : '',
				'CAT_DOWN_DISABLED' => ($image_down === '') ? ' disabled="disabled"' : '',
				'U_CAT_EDIT' => append_sid("$file?mode=edit_cat&amp;cat_id=" . $cat_rows[$i]['cat_id']), 
				));
	} 

	$template->assign_vars(array(
 		'S_CONFIG_ACTION' => append_sid("$file"),
		'S_SESSION_FIELD' => phpbb_admin_session_field(),
		'L_CONFIRM_DELETE' => htmlspecialchars(addslashes($lang['admin_cat_delete_confirm']), ENT_QUOTES, 'UTF-8'),

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
