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
require_once dirname(__DIR__).'/includes/functions_arcade_category_storage.php';
$mode=$arcade->pass_var('mode','',true);$mode=is_string($mode)?$mode:'';
$cat_id=max(0,(int)$arcade->pass_var('cat_id',0,true));
$write_requested=isset($HTTP_POST_VARS['cat_action'])||isset($HTTP_POST_VARS['save_cat'])||isset($HTTP_POST_VARS['resync_cats'])||in_array($mode,array('cat_up','cat_down','del_cat','resync_cats'),true);
if($write_requested)
{
    phpbb_admin_require_post_session();
    try { $saved=phpbb_arcade_category_change($db,$HTTP_POST_VARS); }
    catch(PhpbbAclException$error){message_die(GENERAL_MESSAGE,$error->getMessage());}
    catch(PhpbbOwnedStorageFailure$error){$key=$error->getMessage();message_die(GENERAL_MESSAGE,isset($lang[$key])?$lang[$key]:$lang['no_cat_update']);}
    $mode='categories';
    if(!in_array($saved['operation'],array('cat_up','cat_down'),true)){
        $key=$saved['operation']==='save'?'admin_cat_saved':($saved['operation']==='del_cat'?'admin_cat_deleted':'admin_cats_resynced');
        message_die(GENERAL_MESSAGE,$lang[$key].'<br /><br />'.sprintf($lang['admin_return_cats'],'<a href="'.phpbb_admin_html(append_sid($file.'?mode=categories')).'">','</a>'));
    }
}
elseif(isset($HTTP_POST_VARS['add_cat'])){$mode='add_cat';$cat_id=0;}
if(!in_array($mode,array('','categories','add_cat','edit_cat'),true)){$mode='categories';}
if($mode==='edit_cat'&&(!$cat_id||$cat_id>8388607)){message_die(GENERAL_MESSAGE,$lang['no_cat_data']);}
// The ACP reads fresh metadata, never a category cache from another request.
$result=$db->sql_query('SELECT * FROM '.iNA_CAT.' ORDER BY cat_order,cat_id');
if(!$result){message_die(GENERAL_ERROR,$lang['no_cat_data']);}
$category_rows=$db->sql_fetchrowset($result);$db->sql_freeresult($result);
$tree_revision=phpbb_arcade_category_tree_revision($category_rows);
try{$category_layout=phpbb_arcade_category_layout($category_rows,true);}
catch(PhpbbOwnedStorageFailure$error){$key=$error->getMessage();message_die(GENERAL_MESSAGE,isset($lang[$key])?$lang[$key]:$lang['no_cat_data']);}
$cat_rows=array();foreach($category_layout['order']as$id){$cat_rows[]=$category_layout['rows'][$id];}
$category_by_id=array();foreach($category_rows as$row){$category_by_id[(int)$row['cat_id']]=$row;}
if($category_layout['repaired']){$template->assign_block_vars('repair_needed',array('MESSAGE'=>$lang['arcade_category_repair']));}
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

  $cat_parent = '<select name="cat_parent"><option value="' . 0 . '">' . $lang['None'] . '</option>';
	for($i = 1;$i < (count($cat_rows)); $i++)
	{
    if($cat_rows[$i]['cat_type'] == 'l' || $cat_rows[$i]['cat_type'] == 's' || (int)$cat_rows[$i]['cat_id'] === $cat_id)
    {
      continue;
    }	 
		$selected = ( $cat_info['cat_parent'] == $cat_rows[$i]['cat_id'] ) ? ' selected="selected"' : '';
		$cat_parent .= '<option value="' . (int) $cat_rows[$i]['cat_id'] . '" ' . $selected . '>' . phpbb_admin_html($cat_rows[$i]['cat_name']) . '</option>';
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
	$group_present=(int)$cat_info['group_required']===0;
	while ($groups_info = $db->sql_fetchrow($result))
	{
		if((int)$groups_info['group_id']===(int)$cat_info['group_required']){$group_present=true;}
		$selected = ( $groups_info['group_id'] == $cat_info['group_required'] ) ? ' selected="selected"' : '';
		$cat_group .= '<option value="' . (int) $groups_info['group_id'] . '"' . $selected . '>' . htmlspecialchars($groups_info['group_name'], ENT_QUOTES, 'UTF-8') . '</option>';
	}	
	if(!$group_present){$cat_group.='<option value="'.(int)$cat_info['group_required'].'" selected="selected">'.phpbb_admin_html(sprintf($lang['arcade_category_missing_group'],(int)$cat_info['group_required'])).'</option>';}
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
		"NAME" => phpbb_admin_html($cat_info['cat_name']),
		"TYPE" => $cat_type,
		"PARENT" => $cat_parent,
		"GROUP" => $cat_group,
		"DESC" => phpbb_admin_html($cat_info['cat_desc']),
		"ICON" => phpbb_admin_html($cat_info['cat_icon']),
		"MODERATOR" => phpbb_stored_text($moderator),
		"SPECIAL" => $cat_info['special_play'],
		"DISPLAY_ICON" => $icon_path,
		
		'S_HIDDEN_FIELDS' => '<input type="hidden" name="save_cat" value="1" /><input type="hidden" name="cat_order" value="' . (int) $cat_info['cat_order'] . '" /><input type="hidden" name="cat_id" value="' . (int) $cat_id . '" />' . '<input type="hidden" name="cat_revision" value="' . ($cat_id ? phpbb_arcade_category_revision($cat_info) : '') . '" /><input type="hidden" name="tree_revision" value="' . $tree_revision . '" />' . phpbb_admin_session_field() ));
}
//
if( $mode == "categories" || $mode == "cat_down" || $mode == "cat_up" || $mode == "" )
{
	$template->set_filenames(array( "body" => "admin/arcade_cats_body.tpl") );
	$template->assign_vars(array("VERSION" => $version));


 	$cat_count = count($cat_rows);

	for($i = 1; $i < $cat_count; $i++)
  {
    $icon = ''; 
		if($cat_rows[$i]['cat_icon'])
		{
			$icon = "<img src=\"" . htmlspecialchars($phpbb_root_path . $cat_rows[$i]['cat_icon'], ENT_QUOTES, 'UTF-8') . "\" width=\"" . (int) $arcade->arcade_config['games_cat_image_width'] . "\" height=\"" . (int) $arcade->arcade_config['games_cat_image_height'] . "\" border=\"0\" alt=\"\" />";
		}
        $row_id=(int)$cat_rows[$i]['cat_id'];$parent=(int)$cat_rows[$i]['cat_parent'];
        $siblings=$cat_rows[$i]['cat_type']==='s'?$category_layout['children'][$parent]:$category_layout['roots'];
        $at=array_search($row_id,$siblings,true);$image_up=$image_down='';
        if(!$category_layout['repaired']&&$at!==false&&$at>0){$image_up='<img src="'.phpbb_admin_html($phpbb_root_path.'images/arrow_up.gif').'" border="0" alt="'.phpbb_admin_html($lang['admin_up']).'" />';}
        if(!$category_layout['repaired']&&$at!==false&&$at<count($siblings)-1){$image_down='<img src="'.phpbb_admin_html($phpbb_root_path.'images/arrow_down.gif').'" border="0" alt="'.phpbb_admin_html($lang['admin_down_full']).'" />';}
        $row_revision=phpbb_arcade_category_revision($category_by_id[$row_id]);
		
    $template->assign_block_vars("cats", array(
		    'ROW_CLASS' => ( !($i % 2) ) ? 'row1' : 'row2', 
     		'CAT_ID' => $cat_rows[$i]['cat_id'], 
       	'ICON' => $icon,
				'NAME' => phpbb_admin_html($cat_rows[$i]['cat_name']),
				'DESC' => phpbb_admin_html($cat_rows[$i]['cat_desc']),

       	'IMAGE_UP' => $image_up,
   			'IMAGE_DOWN' => $image_down,
		       	
       	'EDIT_GAMES' => ( $cat_rows[$i]['cat_type'] == 'l' ) ? '' : '[<a href="' . append_sid("admin_arcade_games.$phpEx?mode=edit_games&amp;cat_id=" . $cat_rows[$i]['cat_id']) . '">'.$lang['admin_edit_games'].'</a>]', 
		       	
				'CAT_UP_ACTION' => 'cat_up:' . (int) $cat_rows[$i]['cat_id'] . ':' . $row_revision,
				'CAT_DOWN_ACTION' => 'cat_down:' . (int) $cat_rows[$i]['cat_id'] . ':' . $row_revision,
				'CAT_DELETE_ACTION' => 'del_cat:' . (int) $cat_rows[$i]['cat_id'] . ':' . $row_revision,
				'CAT_UP_DISABLED' => ($image_up === '') ? ' disabled="disabled"' : '',
				'CAT_DOWN_DISABLED' => ($image_down === '') ? ' disabled="disabled"' : '',
				'U_CAT_EDIT' => append_sid("$file?mode=edit_cat&amp;cat_id=" . $cat_rows[$i]['cat_id']), 
				));
	} 

	$template->assign_vars(array(
 		'S_CONFIG_ACTION' => append_sid("$file"),
		'S_SESSION_FIELD' => '<input type="hidden" name="tree_revision" value="'.$tree_revision.'" />'.phpbb_admin_session_field(),
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
