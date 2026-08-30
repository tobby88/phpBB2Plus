<?php
/***************************************************************************
 *                          admin_profile_fields.php
 *                            -------------------
 *   author:                Brian Shields (alias Blankety Blank Man)
 *   email:                 blanketyblankman@gmail.com
 *   description:           Dynamic content file driving the addition/editing/
 *                          deletion of custom profile fields for the Custom
 *                          Profiles MOD.
 *
 *
 ***************************************************************************/

/***************************************************************************
 *                               Version notes
 *                                 ---------
 *   1.0.0: December 31, 2005
 *   ------------------------
 *      - No longer vulnerable to SQL injection
 *      - Removed Javascript implementaion for radio & checkboxes
 *      - Added field description
 *
 *   0.0.1: December 17, 2005
 *   ------------------------
 *      - Vulnerable to SQL injection
 *      - Poorly implemented Javascript for radio and checkbox values
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
if(!empty($setmodules))
{
  $filename = basename(__FILE__);
  $module['Custom_Profile']['Add_new'] = $filename."?mode=add&pfid=x";
  $module['Custom_Profile']['Edit'] = $filename."?mode=edit&pfid=x";
  
  return;
}

//
// Load default header
//
$no_page_header = false;
$phpbb_root_path = './../';
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);
include_once($phpbb_root_path . 'includes/functions_profile_fields.'.$phpEx);
$filename = basename(__FILE__);

$mode_value = (isset($_POST['mode']) && is_scalar($_POST['mode'])) ? (string) $_POST['mode'] :
  ((isset($_GET['mode']) && is_scalar($_GET['mode'])) ? (string) $_GET['mode'] : '');
$pfid_value = (isset($_POST['pfid']) && is_scalar($_POST['pfid'])) ? (string) $_POST['pfid'] :
  ((isset($_GET['pfid']) && is_scalar($_GET['pfid'])) ? (string) $_GET['pfid'] : '');
if($mode_value === '' || $pfid_value === '')
{
  message_die(GENERAL_ERROR,'Required request variables not set','Could not reach admin page; Insufficient data',__LINE__,__FILE__);
}

$mode = $mode_value;
$pfid = ($pfid_value === 'x') ? 'x' : (int) $pfid_value;
if (!in_array($mode, array('add', 'update', 'edit', 'delete', 'confirmdelete'), true) || ($pfid !== 'x' && $pfid < 1))
{
  message_die(GENERAL_ERROR, 'Invalid profile-field request.');
}

if (in_array($mode, array('update', 'confirmdelete'), true))
{
  phpbb_admin_require_post_session();
}

function profile_field_post_value($name, $default = '')
{
  return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? stripslashes((string) $_POST[$name]) : $default;
}

function profile_field_column_identifier($display_name)
{
  $identifier = text_to_column($display_name);
  if (!preg_match('/^[a-z_][a-z0-9_]{0,63}$/D', $identifier))
  {
    return false;
  }

  return $identifier;
}

$session_field = phpbb_admin_session_field();

if($mode == 'add')
{
  $template->set_filenames(array('body' => 'admin/add_profile_field.tpl'));
  
  $template->assign_vars(array(
    'TEXT_FIELD_CHECKED' => ' checked="checked"',
    'NOT_REQUIRED_CHECKED' => ' checked="checked"',
    'ALLOW_VIEW_CHECKED' => ' checked="checked"',
    'VIEW_IN_PROFILE_CHECKED' => ' checked="checked"',
    'ABOUT_CHECKED' => ' checked="checked"',
    'NO_VIEW_IN_MEMBERLIST' => ' checked="checked"',
    'NO_VIEW_IN_TOPIC' => ' checked="checked"',
    'AUTHOR_CHECKED' => ' checked="checked"',
    
    'L_ADD_FIELD_TITLE' => $lang['add_field_title'],
    'L_ADD_FIELD_EXPLAIN' => $lang['add_field_explain'],
    
    'S_ADD_FIELD_ACTION' => append_sid($filename),
    'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="update" /><input type="hidden" name="pfid" value="x" />' . $session_field
    ));
}
elseif($mode == 'update')
{
  $template->set_filenames(array('body' => 'admin/admin_message_body.tpl'));
  
  $name_input = trim(profile_field_post_value('field_name'));
  if($name_input === '' || strlen($name_input) > 255)
    message_die(GENERAL_ERROR,$lang['enter_a_name']);
  $name = htmlspecialchars($name_input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if (strlen($name) > 255)
    message_die(GENERAL_ERROR,$lang['enter_a_name']);
  
  $description_input = profile_field_post_value('field_descrition');
  if (strlen($description_input) > 255)
    message_die(GENERAL_ERROR, 'The profile-field description is too long.');
  $description = htmlspecialchars($description_input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if (strlen($description) > 255)
    message_die(GENERAL_ERROR, 'The profile-field description is too long.');
  
  $type = intval(profile_field_post_value('field_type'));
  if (!in_array($type, array(TEXT_FIELD, TEXTAREA, RADIO, CHECKBOX), true))
    message_die(GENERAL_ERROR, 'Invalid profile-field type.');
  $text_field_default = htmlspecialchars(profile_field_post_value('text_field_default'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $text_field_maxlen = profile_field_post_value('text_field_maxlen') === '' ? TEXT_FIELD_MAXLENGTH : intval(profile_field_post_value('text_field_maxlen'));
  $text_field_maxlen = max(1, min(TEXT_FIELD_MAXLENGTH, $text_field_maxlen));
  $text_area_default = htmlspecialchars(profile_field_post_value('text_area_default'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $text_area_maxlen = profile_field_post_value('text_area_maxlen') === '' ? TEXTAREA_MINLENGTH : intval(profile_field_post_value('text_area_maxlen'));
  $text_area_maxlen = max(TEXTAREA_MINLENGTH, min(TEXTAREA_MAXLENGTH, $text_area_maxlen));
  
  $radio_values = htmlspecialchars(profile_field_post_value('radio_values'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $radio_default_value = htmlspecialchars(profile_field_post_value('radio_default_value'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $radio_values = explode("\n",str_replace("\r",'',$radio_values));
  if(empty($radio_default_value))
    $radio_default_value = $radio_values[0];
  $temp = '';
  foreach($radio_values as $val)
    $temp .= $val . ',';
  $radio_values = substr($temp,0,strlen($temp)-1);
  
  $checkbox_values = htmlspecialchars(profile_field_post_value('checkbox_values'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $check_default_values = htmlspecialchars(profile_field_post_value('check_default_values'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $checkbox_values = explode("\n",str_replace("\r",'',$checkbox_values));
  if(!empty($check_default_values))
  {
    $check_default_values = explode("\n",str_replace("\r",'',$check_default_values));
    $temp = '';
    foreach($check_default_values as $val)
      $temp .= $val . ',';
    $check_default_values = substr($temp,0,strlen($temp)-1);
  }
  $temp = '';
  foreach($checkbox_values as $val)
    $temp .= $val . ',';
  $checkbox_values = substr($temp,0,strlen($temp)-1);

  if (strlen($text_field_default) > $text_field_maxlen || strlen($text_area_default) > $text_area_maxlen ||
    strlen($radio_default_value) > 255 || strlen($radio_values) > 60000 ||
    strlen($check_default_values) > 60000 || strlen($checkbox_values) > 60000)
    message_die(GENERAL_ERROR, 'One or more profile-field values exceed their configured limit.');
  
  $required = intval(profile_field_post_value('required')) === REQUIRED ? REQUIRED : NOT_REQUIRED;
  $user_can_view = intval(profile_field_post_value('user_can_view')) === ALLOW_VIEW ? ALLOW_VIEW : DISALLOW_VIEW;
  $view_in_profile = intval(profile_field_post_value('view_in_profile')) === VIEW_IN_PROFILE ? VIEW_IN_PROFILE : NO_VIEW_IN_PROFILE;
  $profile_location_value = intval(profile_field_post_value('profile_location'));
  $profile_location = in_array($profile_location_value, array(CONTACTS, ABOUT), true) ? $profile_location_value : ABOUT;
  $view_in_memberlist = intval(profile_field_post_value('view_in_memberlist')) === VIEW_IN_MEMBERLIST ? VIEW_IN_MEMBERLIST : NO_VIEW_IN_MEMBERLIST;
  $view_in_topic = intval(profile_field_post_value('view_in_topic')) === VIEW_IN_TOPIC ? VIEW_IN_TOPIC : NO_VIEW_IN_TOPIC;
  $signature_wrap_value = intval(profile_field_post_value('signature_wrap'));
  $signature_wrap = in_array($signature_wrap_value, array(AUTHOR, ABOVE_SIGNATURE, BELOW_SIGNATURE), true) ? $signature_wrap_value : AUTHOR;
  
  if($pfid == 'x')
  {
    $sql = "SELECT field_name FROM " . PROFILE_FIELDS_TABLE . "
      WHERE field_name='" . $db->sql_escape($name) . "'";
    if(!($result = $db->sql_query($sql)))
      message_die(GENERAL_ERROR,'Could not query database for field name information','',__LINE__,__FILE__,$sql);
    $temp = $db->sql_fetchrowset($result);
    if(!empty($temp))
      message_die(GENERAL_ERROR,$lang['field_exists']);
  }
  
  if($pfid == 'x')
    $die_message = 'Could not insert new profile field';
  else
    $die_message = 'Could not update profile information';
  
  if($pfid != 'x')
  {
    $sql = "SELECT field_name FROM " . PROFILE_FIELDS_TABLE . "
      WHERE field_id = $pfid";
    if(!($result = $db->sql_query($sql)))
      message_die(GENERAL_ERROR,'Could not find old name','',__LINE__,__FILE__,$sql);
    $old_name = $db->sql_fetchrow($result);
    $old_name = $old_name ? profile_field_column_identifier($old_name['field_name']) : false;
    if ($old_name === false)
      message_die(GENERAL_ERROR, 'Invalid existing profile-field column.');
  }
  
  $name_display = $name;
  $name = profile_field_column_identifier($name_input);
  if ($name === false)
    message_die(GENERAL_ERROR, 'The profile-field name cannot be represented as a safe database column.');
  $name_display_sql = $db->sql_escape($name_display);
  $description = $db->sql_escape($description);
  $text_field_default = $db->sql_escape($text_field_default);
  $text_area_default = $db->sql_escape($text_area_default);
  $radio_default_value = $db->sql_escape($radio_default_value);
  $radio_values = $db->sql_escape($radio_values);
  $check_default_values = $db->sql_escape($check_default_values);
  $checkbox_values = $db->sql_escape($checkbox_values);

  $sql = "SELECT $name FROM " . USERS_TABLE . " WHERE user_id = ".$userdata['user_id']." LIMIT 1";
  if($db->sql_query($sql))
  {
	  if($pfid == 'x')
		message_die(GENERAL_ERROR, "Field like $name allready exists");
	  else if ( $old_name != $name )
		message_die(GENERAL_ERROR, "Field like $name allready exists");
  }

  if($pfid == 'x')
  {
    $sql = "INSERT INTO " . PROFILE_FIELDS_TABLE . "
      (field_name, field_description, field_type, text_field_default, text_field_maxlen, text_area_default, text_area_maxlen,
      radio_button_default, radio_button_values, checkbox_default, checkbox_values, is_required,
      users_can_view, view_in_profile, profile_location, view_in_memberlist, view_in_topic, topic_location)
      VALUES ('$name_display_sql','$description',$type,'$text_field_default',$text_field_maxlen,'$text_area_default',$text_area_maxlen,
      '$radio_default_value','$radio_values','$check_default_values','$checkbox_values',$required,$user_can_view,
      $view_in_profile,$profile_location,$view_in_memberlist,$view_in_topic,$signature_wrap)";
  }
  else
  {
    $sql = "UPDATE " . PROFILE_FIELDS_TABLE . "
      SET field_name = '$name_display_sql',
        field_description = '$description',
        field_type = $type,
        text_field_default = '$text_field_default',
        text_field_maxlen = $text_field_maxlen,
        text_area_default = '$text_area_default',
        text_area_maxlen = $text_area_maxlen,
        radio_button_default = '$radio_default_value',
        radio_button_values = '$radio_values',
        checkbox_default = '$check_default_values',
        checkbox_values = '$checkbox_values',
        is_required = $required,
        users_can_view = $user_can_view,
        view_in_profile = $view_in_profile,
        profile_location = $profile_location,
        view_in_memberlist = $view_in_memberlist,
        view_in_topic = $view_in_topic,
        topic_location = $signature_wrap
      WHERE field_id = $pfid";
  }
  
  if(!$db->sql_query($sql))
    message_die(GENERAL_ERROR,$die_message,'',__LINE__,__FILE__,$sql);
  
  if($pfid != 'x')
  {
    switch($type)
    {
		case TEXT_FIELD: 
			$col_type = 'VARCHAR('.$text_field_maxlen.') DEFAULT \''.$text_field_default.'\''; 
			break;
		case RADIO:
			$col_type = 'VARCHAR(255) DEFAULT \''.$radio_default_value.'\''; 
			break;
		
		case TEXTAREA:
		case CHECKBOX: 
			$col_type = 'TEXT';
			break;
    }
    $sql = "ALTER TABLE " . USERS_TABLE . "
      CHANGE $old_name $name $col_type";
    if(!$db->sql_query($sql))
      message_die(GENERAL_ERROR,'Could not change column name in '.USERS_TABLE,'',__LINE__,__FILE__,$sql);
  }
  
  $sql = "ALTER TABLE " . USERS_TABLE . "
    ADD $name";
  switch($type)
  {
    case TEXT_FIELD:
      $sql .= " varchar($text_field_maxlen) DEFAULT '$text_field_default'";
      break;
    case RADIO:
      $sql .= " varchar(255) DEFAULT '$radio_default_value'";
      break;
    case TEXTAREA:
    case CHECKBOX:
      $sql .= " text";
      break;
  }
  
  if($pfid == 'x' && !$db->sql_query($sql))
    message_die(GENERAL_ERROR,'Could not expand users table for new profile field.','',__LINE__,__FILE__,$sql);
  
  $sql = "SELECT user_id FROM " . USERS_TABLE;
  if(!($result = $db->sql_query($sql)))
    message_die(GENERAL_ERROR,'Could not retrieve use and profile information','',__LINE__,__FILE__,$sql);
  
  $user_id_array = array();
  while($temp = $db->sql_fetchrow($result))$user_id_array[] = $temp['user_id'];
  
  if($pfid == 'x')
    foreach($user_id_array as $user_id)
    {
      $sql = "UPDATE " . USERS_TABLE . "
        SET $name = %s
        WHERE user_id = $user_id";
      
      switch($type)
      {
        case TEXT_FIELD:
          $val = $text_field_default;
          break;
        case TEXTAREA:
          $val = $text_area_default;
          break;
        case RADIO:
          $val = $radio_default_value;
          break;
        case CHECKBOX:
          $val = $check_default_values;
          break;
      }
      
      $sql = sprintf($sql,"'$val'");
      
      if(!$db->sql_query($sql))
        message_die(GENERAL_ERROR,'Could not update users with default values','',__LINE__,__FILE__,$sql);
    }
  
  $template->assign_vars(array(
    'MESSAGE_TITLE' => $pfid == 'x' ? $lang['profile_field_created'] : $lang['profile_field_updated'],
    'MESSAGE_TEXT' => $lang['field_success']));
}
elseif($mode == 'edit')
{
  if($pfid == 'x')
  {
    $template->set_filenames(array('body' => 'admin/add_profile_field_list.tpl'));
    
    $template->assign_vars(array(
      'L_PROFILE_FIELD_LIST_TITLE' => $lang['profile_field_list'],
      'L_PROFILE_FIELD_LIST_EXPLAIN' => $lang['profile_field_list_explain'],
      'L_ID' => $lang['profile_field_id'],
      'L_NAME' => $lang['profile_field_name'],
      'L_ACTION' => $lang['profile_field_action'],
      'L_EDIT' => $lang['Edit'],
      'L_DELETE' => $lang['Delete']
      ));
    
    $profile_rows = get_fields();
    
    if(count($profile_rows) == 0)
      $template->assign_block_vars('switch_no_fields',array('NO_FIELDS_EXIST' => $lang['no_profile_fields_exist']));
    else
    {
      $template->assign_block_vars('switch_fields',array());
      
      foreach($profile_rows as $col => $val)
      {
        $row = $col % 2 == 0 ? 'row1' : 'row2';
        $id = $val['field_id'];
        $name = $val['field_name'];
        
        $edit_url = append_sid("$filename?mode=edit&pfid=$id");
        $delete_url = append_sid("$filename?mode=delete&pfid=$id");
        
        $template->assign_block_vars('switch_fields.profile_fields',array(
          'ROW_CLASS' => $row,
          'ID' => $id,
          'NAME' => phpbb_admin_html(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')),
          
          'U_PROFILE_FIELD_EDIT' => $edit_url,
          'U_PROFILE_FIELD_DELETE' => $delete_url
          ));
      }
    }
  }
  else
  {
    $template->set_filenames(array('body' => 'admin/add_profile_field.tpl'));
    
    $profile_rows = get_fields('WHERE field_id = ' . $pfid,false);
    
    if (!is_array($profile_rows) || empty($profile_rows))
      message_die(GENERAL_ERROR, 'Profile field not found.');

    $template->assign_vars(array(
      'FIELD_NAME' => phpbb_admin_html(html_entity_decode((string) $profile_rows['field_name'], ENT_QUOTES, 'UTF-8')),
      'FIELD_DESCRIPTION' => phpbb_admin_html(html_entity_decode((string) $profile_rows['field_description'], ENT_QUOTES, 'UTF-8')),
      'TEXT_FIELD_CHECKED' => $profile_rows['field_type'] == TEXT_FIELD ? ' checked="checked"' : '',
      'TEXTAREA_CHECKED' => $profile_rows['field_type'] == TEXTAREA ? ' checked="checked"' : '',
      'RADIO_CHECKED' => $profile_rows['field_type'] == RADIO ? ' checked="checked"' : '',
      'CHECKBOX_CHECKED' => $profile_rows['field_type'] == CHECKBOX ? ' checked="checked"' : '',
      'TEXT_FIELD_DEFAULT' => $profile_rows['text_field_default'],
      'TEXT_FIELD_MAXLENGTH' => $profile_rows['text_field_maxlen'],
      'TEXTAREA_DEFAULT' => $profile_rows['text_area_default'],
      'TEXTAREA_MAXLENGTH' => $profile_rows['text_area_maxlen'],
      'REQUIRED_CHECKED' => $profile_rows['is_required'] == REQUIRED ? ' checked="checked"' : '',
      'NOT_REQUIRED_CHECKED' => $profile_rows['is_required'] == NOT_REQUIRED ? ' checked="checked"' : '',
      'ALLOW_VIEW_CHECKED' => $profile_rows['users_can_view'] == ALLOW_VIEW ? ' checked="checked"' : '',
      'DISALLOW_VIEW_CHECKED' => $profile_rows['users_can_view'] == DISALLOW_VIEW ? ' checked="checked"' : '',
      'VIEW_IN_PROFILE_CHECKED' => $profile_rows['view_in_profile'] == VIEW_IN_PROFILE ? ' checked="checked"' : '',
      'NO_VIEW_IN_PROFILE_CHECKED' => $profile_rows['view_in_profile'] == NO_VIEW_IN_PROFILE ? ' checked="checked"' : '',
      'CONTACTS_CHECKED' => $profile_rows['profile_location'] == CONTACTS ? ' checked="checked"' : '',
      'ABOUT_CHECKED' => $profile_rows['profile_location'] == ABOUT ? ' checked="checked"' : '',
      'VIEW_IN_MEMBERLIST' => $profile_rows['view_in_memberlist'] == VIEW_IN_MEMBERLIST ? ' checked="checked"' : '',
      'NO_VIEW_IN_MEMBERLIST' => $profile_rows['view_in_memberlist'] == NO_VIEW_IN_MEMBERLIST ? ' checked="checked"' : '',
      'VIEW_IN_TOPIC' => $profile_rows['view_in_topic'] == VIEW_IN_TOPIC ? ' checked="checked"' : '',
      'NO_VIEW_IN_TOPIC' => $profile_rows['view_in_topic'] == NO_VIEW_IN_TOPIC ? ' checked="checked"' : '',
      'AUTHOR_CHECKED' => $profile_rows['topic_location'] == AUTHOR ? ' checked="checked"' : '',
      'ABOVE_SIG_CHECKED' => $profile_rows['topic_location'] == ABOVE_SIGNATURE ? ' checked="checked"' : '',
      'BELOW_SIG_CHECKED' => $profile_rows['topic_location'] == BELOW_SIGNATURE ? ' checked="checked"' : '',
      'RADIO_VALUES' => str_replace(',',"\r\n",$profile_rows['radio_button_values']),
      'RADIO_DEFAULT' => $profile_rows['radio_button_default'],
      'CHECKBOX_VALUES' => str_replace(',',"\r\n",$profile_rows['checkbox_values']),
      'CHECKBOX_DEFAULT' => str_replace(',',"\r\n",$profile_rows['checkbox_default']),
      
      'L_ADD_FIELD_TITLE' => $lang['edit_field_title'],
      'L_ADD_FIELD_EXPLAIN' => $lang['edit_field_explain'],
      
      'S_ADD_FIELD_ACTION' => append_sid($filename),
      'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="update" /><input type="hidden" name="pfid" value="' . (int) $pfid . '" />' . $session_field
      ));
  }
}
elseif($mode == 'delete')
{
  $field_name = get_fields('WHERE field_id = '.(int) $pfid,false,'field_name');
  if (!$field_name)
    message_die(GENERAL_ERROR, 'Profile field not found.');

  $template->set_filenames(array('body' => 'admin/confirm_body.tpl'));
  $hidden_fields = '<input type="hidden" name="mode" value="confirmdelete" />' .
    '<input type="hidden" name="pfid" value="' . (int) $pfid . '" />' . phpbb_admin_session_field();
  $template->assign_vars(array(
    'MESSAGE_TITLE' => $lang['Confirm'],
    'MESSAGE_TEXT' => sprintf($lang['double_check_delete'], htmlspecialchars((string) $field_name['field_name'])),
    'L_YES' => $lang['Yes'],
    'L_NO' => $lang['No'],
    'S_CONFIRM_ACTION' => append_sid($filename),
    'S_HIDDEN_FIELDS' => $hidden_fields
    ));
}
elseif($mode == 'confirmdelete')
{
  if (!isset($_POST['confirm']))
    redirect(append_sid("$filename?mode=edit&pfid=x"));

  $field_name = get_fields('WHERE field_id = '.(int) $pfid,false,'field_name');
  $name = $field_name ? profile_field_column_identifier($field_name['field_name']) : false;
  if ($name === false)
    message_die(GENERAL_ERROR, 'Invalid profile-field column.');

  $sql = "DELETE FROM " . PROFILE_FIELDS_TABLE . "
    WHERE field_id = " . (int) $pfid;
  if(!$db->sql_query($sql))
    message_die(GENERAL_ERROR,'Could not delete profile form database','',__LINE__,__FILE__,$sql);
  
  $sql = "ALTER TABLE " . USERS_TABLE . "
    DROP COLUMN $name";
  if(!$db->sql_query($sql))
    message_die(GENERAL_ERROR,'Could not remove column from '.USERS_TABLE,'',__LINE__,__FILE__,$sql);
  
  $template->set_filenames(array('body' => 'admin/admin_message_body.tpl'));
  $template->assign_vars(array(
    'MESSAGE_TITLE' => $lang['field_deleted'],
    'MESSAGE_TEXT' => $lang['click_here_here']
    ));
}

$template->assign_vars(array(
  'L_NEW_FIELD_NAME' => $lang['add_field_name'],
  'L_NEW_FIELD_EXPLAIN' => $lang['add_field_name_explain'],
  'L_NEW_FIELD_DESCRIPTION' => $lang['add_field_description'],
  'L_NEW_FIELD_DESCRIPTION_EXPLAIN' => $lang['add_field_description_explain'],
  'L_NEW_FIELD_TYPE' => $lang['add_field_type'],
  'L_NEW_FIELD_TYPE_EXPLAIN' => $lang['edit_field_type_explain'],
  'L_REQUIRED_FIELD' => $lang['add_field_required'],
  'L_REQUIRED_FIELD_EXPLAIN' => $lang['add_field_required_explain'],
  'L_USER_CAN_VIEW' => $lang['add_field_user_can_view'],
  'L_USER_CAN_VIEW_EXPLAIN' => $lang['add_field_user_can_view_explain'],
  'L_TEXTAREA' => $lang['textarea'],
  'L_TEXTAREA_EXAMPLE' => $lang['textarea_example'],
  'L_TEXT_FIELD' => $lang['text_field'],
  'L_TEXT_FIELD_EXAMPLE' => $lang['text_field_example'],
  'L_RADIO' => $lang['radio'],
  'L_RADIO_EXAMPLE' => $lang['radio_example'],
  'L_CHECKBOX' => $lang['checkbox'],
  'L_CHECKBOX_EXAMPLE' => $lang['checkbox_example'],
  'L_VIEW_IN_PROFILE' => $lang['view_in_profile'],
  'L_VIEW_IN_MEMBERLIST' => $lang['view_in_memberlist'],
  'L_VIEW_IN_TOPIC' => $lang['view_in_topic'],    
  'L_PROFILE_LOCATIONS_EXPLAIN' => $lang['profile_locations_explain'],
  'L_CONTACTS_COLUMN' => $lang['contacts_column'],
  'L_ABOUT_COLUMN' => $lang['about_column'],    
  'L_TOPIC_LOCATIONS_EXPLAIN' => $lang['topic_locations_explain'],
  'L_ABOVE_SIGNATURE' => $lang['above'] . $lang['Signature'],
  'L_BELOW_SIGNATURE' => $lang['below'] . $lang['Signature'],
  'L_AUTHOR_COLUMN' => $lang['author_column'],    
  'L_YES' => $lang['Yes'],
  'L_NO' => $lang['No'],    
  'L_ADMIN_SETTINGS' => $lang['add_field_admin'],
  'L_GENERAL_SETTINGS' => $lang['add_field_general'],
  'L_VIEW_SETTINGS' => $lang['add_field_view'],
  'L_TEXT_FIELD_SETTINGS' => $lang['add_field_text_field'],
  'L_TEXT_AREA_SETTINGS' => $lang['add_field_text_area'],
  'L_RADIO_BUTTON_SETTINGS' => $lang['add_field_radio_button'],
  'L_CHECKBOX_SETTINGS' => $lang['add_field_checkbox'],    
  'L_DEFAULT_VALUE' => $lang['default_value'],
  'L_DEFAULT_VALUE_EXPLAIN' => $lang['default_value_explain'],
  'L_DEFAULT_VALUE_RADIO_EXPLAIN' => $lang['default_value_radio_explain'],
  'L_DEFAULT_VALUE_CHECKBOX_EXPLAIN' => $lang['default_value_checkbox_explain'],
  'L_MAX_LENGTH' => $lang['max_length'],
  'L_MAX_LENGTH_TEXT_FIELD_EXPLAIN' => $lang['max_length_explain'] . sprintf($lang['max_length_value'],TEXT_FIELD_MINLENGTH,TEXT_FIELD_MAXLENGTH),
  'L_MAX_LENGTH_TEXTAREA_EXPLAIN' => $lang['max_length_explain'] . sprintf($lang['max_length_value'],TEXTAREA_MINLENGTH,TEXTAREA_MAXLENGTH),
  'L_AVAILABLE_VALUES' => $lang['available_values'],
  'L_AVAILABE_VALUES_EXPLAIN' => $lang['available_values_explain'],    
  'L_VIEW_DISCLAIMER' => $lang['add_field_view_disclaimer'],
  'L_SUBMIT' => $lang['Submit'],
  'L_RESET' => $lang['Reset'],
  
  'S_TEXT_FIELD' => TEXT_FIELD,
  'S_TEXTAREA' => TEXTAREA,
  'S_RADIO' => RADIO,
  'S_CHECKBOX' => CHECKBOX,
  'S_REQUIRED' => REQUIRED,
  'S_NOT_REQUIRED' => NOT_REQUIRED,
  'S_ALLOW_VIEW' => ALLOW_VIEW,
  'S_DISALLOW_VIEW' => DISALLOW_VIEW,
  'S_VIEW_IN_PROFILE' => VIEW_IN_PROFILE,
  'S_NO_VIEW_IN_PROFILE' => NO_VIEW_IN_PROFILE,
  'S_CONTACTS' => CONTACTS,
  'S_ABOUT' => ABOUT,
  'S_VIEW_IN_MEMBERLIST' => VIEW_IN_MEMBERLIST,
  'S_NO_VIEW_IN_MEMBERLIST' => NO_VIEW_IN_MEMBERLIST,
  'S_VIEW_IN_TOPIC' => VIEW_IN_TOPIC,
  'S_NO_VIEW_IN_TOPIC' => NO_VIEW_IN_TOPIC,
  'S_AUTHOR' => AUTHOR,
  'S_ABOVE_SIGNATURE' => ABOVE_SIGNATURE,
  'S_BELOW_SIGNATURE' => BELOW_SIGNATURE
  ));

$template->pparse('body');

include($phpbb_root_path . 'admin/page_footer_admin.' . $phpEx);
?>
