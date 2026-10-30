<?php
/***************************************************************************
 *                             usercp_email.php 
 *                            -------------------
 *   begin                : Saturday, Feb 13, 2001
 *   copyright            : (C) 2001 The phpBB Group
 *   email                : support@phpbb.com
 *
 *   $Id: usercp_email.php,v 1.7.2.13 2003/06/06 18:02:15 acydburn Exp $
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
 *
 ***************************************************************************/

if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_mail.php';
$target = isset($_GET[POST_USERS_URL]) ? $_GET[POST_USERS_URL] : (isset($_POST[POST_USERS_URL]) ? $_POST[POST_USERS_URL] : null);
try { $user_id = phpbb_mail_target_id($target); }
catch (PhpbbMailFailure $error) { message_die(GENERAL_MESSAGE, phpbb_mail_error($error)); return; }
if (!isset($userdata['session_logged_in']) || !in_array($userdata['session_logged_in'], array(true,1,'1'), true)) {
    redirect(append_sid('login.' . $phpEx . '?redirect=profile.' . $phpEx . '&mode=email&' . POST_USERS_URL . '=' . $user_id, true)); return;
}
$error_msg = ''; $submitted = isset($_POST['submit']);
if ($submitted) {
    try {
        $mail_service = new PhpbbBoardMailController();
        $mail_service->send($db, 'profile', $user_id, $_POST);
        message_die(GENERAL_MESSAGE, $lang['Email_sent'] . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid('index.' . $phpEx) . '">', '</a>')); return;
    } catch (PhpbbMailFailure $error) {
        if (!phpbb_mail_form_error($error->getMessage())) { message_die(GENERAL_MESSAGE, phpbb_mail_error($error)); return; }
        $error_msg = phpbb_mail_error($error);
    }
}
$result = $db->sql_query('SELECT username,user_viewemail,user_active,user_absence FROM ' . USERS_TABLE . ' WHERE user_id=' . $user_id);
if (!$result) { message_die(GENERAL_ERROR, 'Could not select mail recipient'); return; }
$recipient = $db->sql_fetchrow($result); $db->sql_freeresult($result);
$denied = phpbb_profile_mail_permission($recipient, $userdata, $board_config);
if ($denied !== '') { message_die(GENERAL_MESSAGE, $lang[$denied]); return; }
$draft = array('email_subject'=>'','email_message'=>'');
if ($submitted) {
    foreach ($draft as $field=>$value) { $draft[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? phpbb_request_raw_value($_POST[$field]) : ''; }
}
$page_title = $lang['Send_email'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);
$template->set_filenames(array('body'=>'profile_send_email.tpl'));
make_jumpbox('viewforum.' . $phpEx);
if ($error_msg !== '') {
    $template->set_filenames(array('reg_header'=>'error_body.tpl'));
    $template->assign_vars(array('ERROR_MESSAGE'=>htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    $template->assign_var_from_handle('ERROR_BOX', 'reg_header');
}
$template->assign_vars(array(
    'USERNAME'=>htmlspecialchars(html_entity_decode($recipient['username'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'EMAIL_SUBJECT'=>htmlspecialchars($draft['email_subject'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'EMAIL_MESSAGE'=>htmlspecialchars($draft['email_message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'CC_EMAIL_CHECKED'=>(!$submitted || (isset($_POST['cc_email']) && in_array($_POST['cc_email'], array('1',1,true), true))) ? 'checked="checked"' : '',
    'S_HIDDEN_FIELDS'=>'<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
    'S_POST_ACTION'=>append_sid('profile.' . $phpEx . '?mode=email&amp;' . POST_USERS_URL . '=' . $user_id),
    'L_SEND_EMAIL_MSG'=>$lang['Send_email_msg'],'L_RECIPIENT'=>$lang['Recipient'],'L_SUBJECT'=>$lang['Subject'],
    'L_MESSAGE_BODY'=>$lang['Message_body'],'L_MESSAGE_BODY_DESC'=>$lang['Email_message_desc'],
    'L_EMPTY_SUBJECT_EMAIL'=>$lang['Empty_subject_email'],'L_EMPTY_MESSAGE_EMAIL'=>$lang['Empty_message_email'],
    'L_OPTIONS'=>$lang['Options'],'L_CC_EMAIL'=>$lang['CC_email'],'L_SPELLCHECK'=>$lang['Spellcheck'],'L_SEND_EMAIL'=>$lang['Send_email']));
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
