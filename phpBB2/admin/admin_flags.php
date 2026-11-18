<?php
/***************************************************************************
 *                              admin_flags.php
 *                            -------------------
 *   begin                : Thursday, February 6, 2003
 *   written by Nuttzy
 *
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
	$module['Users']['Flags'] = $file;
	return;
}

//
// Let's set the root dir for phpBB
//
$phpbb_root_path = "./../";
require($phpbb_root_path . 'extension.inc');
require('./pagestart.' . $phpEx);

require_once dirname(__DIR__) . '/includes/functions_flags_storage.php';

$mode = isset($_POST['mode']) ? $_POST['mode'] : (isset($_GET['mode']) ? $_GET['mode'] : '');
$mode = is_string($mode) && in_array($mode, array('add','edit','save','delete','do_delete'), true) ? $mode : '';
if ($mode === '' && isset($_POST['add'])) { $mode = 'add'; }
if ($mode === '' && isset($_POST['save'])) { $mode = 'save'; }
if (isset($_POST['cancel']) || ($mode === 'do_delete' && empty($_POST['confirm']))) { $mode = ''; }

if ($mode === 'save' || $mode === 'do_delete') {
    phpbb_admin_require_post_session();
    $request = $_POST; $request['mode'] = $mode;
    try { $message = $lang[phpbb_flags_change($db, $request)]; }
    catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }
    $message .= '<br /><br />' . sprintf($lang['Click_return_flagadmin'], '<a href="' . append_sid('admin_flags.' . $phpEx) . '">', '</a>')
        . '<br /><br />' . sprintf($lang['Click_return_admin_index'], '<a href="' . append_sid('index.' . $phpEx . '?pane=right') . '">', '</a>');
    message_die(GENERAL_MESSAGE, $message);
}

if ($mode === 'add' || $mode === 'edit' || $mode === 'delete') {
    $flag_id = 0; $flag_info = array('flag_name'=>'', 'flag_image'=>'');
    if ($mode !== 'add') {
        $selection = isset($_POST['id']) ? $_POST['id'] : (isset($_GET['id']) ? $_GET['id'] : null);
        try { $flag_id = phpbb_flag_id($selection); }
        catch (PhpbbAclException $error) { message_die(GENERAL_MESSAGE, $error->getMessage()); }
        $result = $db->sql_query('SELECT flag_id,flag_name,flag_image FROM ' . FLAG_TABLE . ' WHERE flag_id=' . $flag_id);
        if (!$result) { message_die(GENERAL_ERROR, $lang['Flags_failed']); }
        $flag_info = $db->sql_fetchrow($result); $db->sql_freeresult($result);
        if (!$flag_info) { message_die(GENERAL_MESSAGE, $lang['Must_select_flag']); }
    }
    $hidden = phpbb_admin_session_field() . '<input type="hidden" name="id" value="' . $flag_id . '" />';
    if ($mode === 'delete') {
        $template->set_filenames(array('body'=>'admin/confirm_body.tpl'));
        $template->assign_vars(array(
            'MESSAGE_TITLE'=>$lang['Flag_confirm'], 'MESSAGE_TEXT'=>$lang['Confirm_delete_flag'],
            'L_YES'=>$lang['Yes'], 'L_NO'=>$lang['No'],
            'S_CONFIRM_ACTION'=>append_sid('admin_flags.' . $phpEx),
            'S_HIDDEN_FIELDS'=>$hidden . '<input type="hidden" name="mode" value="do_delete" />'));
    } else {
        $image = phpbb_flag_image_name($flag_info['flag_image']);
        $template->set_filenames(array('body'=>'admin/flags_edit_body.tpl'));
        $template->assign_vars(array(
            'FLAG'=>phpbb_admin_html($flag_info['flag_name']), 'IMAGE'=>phpbb_admin_html($image),
            'IMAGE_DISPLAY'=>$image === '' ? '' : '<img src="../images/flags/' . phpbb_admin_html($image) . '" alt="" />',
            'L_FLAGS_TITLE'=>$lang['Flags_title'], 'L_FLAGS_TEXT'=>$lang['Flags_explain'],
            'L_FLAG_NAME'=>$lang['Flag_name'], 'L_FLAG_IMAGE'=>$lang['Flag_image'], 'L_FLAG_IMAGE_EXPLAIN'=>$lang['Flag_image_explain'],
            'L_SUBMIT'=>$lang['Submit'], 'L_RESET'=>$lang['Reset'],
            'S_FLAG_ACTION'=>append_sid('admin_flags.' . $phpEx),
            'S_HIDDEN_FIELDS'=>$hidden . '<input type="hidden" name="mode" value="save" />'));
    }
} else {
    $template->set_filenames(array('body'=>'admin/flags_list_body.tpl'));
    $result = $db->sql_query('SELECT flag_id,flag_name,flag_image FROM ' . FLAG_TABLE . ' ORDER BY flag_name,flag_id');
    if (!$result) { message_die(GENERAL_ERROR, $lang['Flags_failed']); }
    $flags = $db->sql_fetchrowset($result); $db->sql_freeresult($result);
    $template->assign_vars(array(
        'L_FLAGS_TITLE'=>$lang['Flags_title'], 'L_FLAGS_TEXT'=>$lang['Flags_explain'], 'L_FLAG'=>$lang['Flag_name'], 'L_FLAG_PIC'=>$lang['Flag_pic'],
        'L_EDIT'=>$lang['Edit'], 'L_DELETE'=>$lang['Delete'], 'L_ADD_FLAG'=>$lang['Add_new_flag'], 'L_ACTION'=>$lang['Action'],
        'S_FLAGS_ACTION'=>append_sid('admin_flags.' . $phpEx)));
    foreach ($flags as $i=>$flag) {
        $image = phpbb_flag_image_name($flag['flag_image']); $flag_id = (int)$flag['flag_id'];
        $template->assign_block_vars('flags', array(
            'ROW_COLOR'=>'#' . $theme[$i % 2 ? 'td_color2' : 'td_color1'], 'ROW_CLASS'=>$theme[$i % 2 ? 'td_class2' : 'td_class1'],
            'FLAG'=>phpbb_admin_html($flag['flag_name']), 'IMAGE_DISPLAY'=>$image === '' ? '' : '<img src="../images/flags/' . phpbb_admin_html($image) . '" alt="" />',
            'U_FLAG_EDIT'=>append_sid('admin_flags.' . $phpEx . '?mode=edit&amp;id=' . $flag_id),
            'U_FLAG_DELETE'=>append_sid('admin_flags.' . $phpEx . '?mode=delete&amp;id=' . $flag_id)));
    }
}
$template->pparse('body');
include './page_footer_admin.' . $phpEx;
