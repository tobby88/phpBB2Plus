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
require_once($phpbb_root_path . 'includes/functions_profile_definition_form.'.$phpEx);
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
$pfid = ($pfid_value === 'x') ? 'x' : (preg_match('/^[1-9][0-9]{0,7}$/D', $pfid_value) ? (int)$pfid_value : 0);
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
  return (isset($_POST[$name]) && is_scalar($_POST[$name])) ? (string) phpbb_request_raw_value($_POST[$name]) : $default;
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

$definition_draft = null;
$definition_error = '';
if ($mode === 'update')
{
  try
  {
    $values = phpbb_profile_definition_form_values($_POST);
    $writer = new PhpbbProfileDefinitionWriter($db, $pfid === 'x' ? 'add' : 'edit', $_POST);
    if ($pfid === 'x')
      $writer->create(profile_field_post_value('definition_operation'), $values);
    else
      $writer->edit($pfid, profile_field_post_value('definition_revision'), $values);
  }
  catch (PhpbbAclException $failure)
  {
    $definition_error = $failure->getMessage();
  }
  catch (Exception $failure) { $definition_error = $lang['Profile_definition_failed']; }
  catch (Throwable $failure) { $definition_error = $lang['Profile_definition_failed']; }
  finally { if (isset($writer)) { $writer->release(); } }
  if ($definition_error !== '')
  {
    $definition_draft = $_POST;
    $mode = $pfid === 'x' ? 'add' : 'edit';
  }
}
$template->assign_vars(array('ERROR_BOX'=>''));
if ($definition_error !== '')
{
  $template->set_filenames(array('definition_error'=>'error_body.tpl'));
  $template->assign_vars(array('ERROR_MESSAGE'=>phpbb_admin_html($definition_error)));
  $template->assign_var_from_handle('ERROR_BOX', 'definition_error');
}


if($mode == 'add')
{
  $definition_operation = $definition_draft === null ? bin2hex(phpbb_random_bytes(32)) : profile_field_post_value('definition_operation');
  $template->set_filenames(array('body' => 'admin/add_profile_field.tpl'));
  
  $template->assign_vars(array(
    'L_ADD_FIELD_TITLE' => $lang['add_field_title'],
    'L_ADD_FIELD_EXPLAIN' => $lang['add_field_explain'],
    
    'S_ADD_FIELD_ACTION' => append_sid($filename),
    'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="update" /><input type="hidden" name="pfid" value="x" /><input type="hidden" name="definition_operation" value="' . phpbb_admin_html($definition_operation) . '" />' . $session_field
    ));
  $template->assign_vars(phpbb_profile_definition_form_vars(null, $definition_draft));
}
elseif($mode == 'update')
{
  $template->set_filenames(array('body' => 'admin/admin_message_body.tpl'));
  $template->assign_vars(array(
    'MESSAGE_TITLE' => $pfid === 'x' ? $lang['profile_field_created'] : $lang['profile_field_updated'],
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
    {
      if ($definition_draft === null) { message_die(GENERAL_ERROR, 'Profile field not found.'); }
      $profile_rows = phpbb_profile_definition_form_defaults();
    }

    $definition_revision = $definition_draft === null ? phpbb_profile_definition_revision($profile_rows) : profile_field_post_value('definition_revision');
    $template->assign_vars(array(
      'L_ADD_FIELD_TITLE' => $lang['edit_field_title'],
      'L_ADD_FIELD_EXPLAIN' => $lang['edit_field_explain'],
      
      'S_ADD_FIELD_ACTION' => append_sid($filename),
      'S_HIDDEN_FIELDS' => '<input type="hidden" name="mode" value="update" /><input type="hidden" name="pfid" value="' . (int) $pfid . '" /><input type="hidden" name="definition_revision" value="' . phpbb_admin_html($definition_revision) . '" />' . $session_field
      ));
    $template->assign_vars(phpbb_profile_definition_form_vars($profile_rows, $definition_draft));
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
	'MESSAGE_TEXT' => sprintf($lang['double_check_delete'], phpbb_profile_display_text($field_name['field_name'])),
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
  'L_AVAILABLE_VALUES_EXPLAIN' => $lang['available_values_explain'] . ' ' . $lang['Profile_definition_options'],
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
