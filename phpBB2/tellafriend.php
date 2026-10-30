<?php
define('IN_PHPBB', true);
$phpbb_root_path = './';
include($phpbb_root_path . 'extension.inc');
include($phpbb_root_path . 'common.' . $phpEx);
require_once $phpbb_root_path . 'includes/functions_board_mail.' . $phpEx;
$PHP_SELF = 'tellafriend.' . $phpEx;
$userdata = session_pagestart($user_ip, PAGE_INDEX);
init_userprefs($userdata);
if (!isset($userdata['session_logged_in']) || !in_array($userdata['session_logged_in'], array(true,1,'1'), true)) {
    redirect(append_sid('login.' . $phpEx . '?redirect=' . $PHP_SELF, true)); return;
}
$submitted = isset($_POST['submit']); $error_msg = '';
if ($submitted) {
    try {
        $mail_service = new PhpbbBoardMailController();
        $mail_service->send($db, 'friend', 0, $_POST);
        message_die(GENERAL_MESSAGE, $lang['Email_sent'] . '<br /><br />' . sprintf($lang['Click_return_index'], '<a href="' . append_sid('index.' . $phpEx) . '">', '</a>')); return;
    } catch (PhpbbMailFailure $error) {
        if (!phpbb_mail_form_error($error->getMessage())) { message_die(GENERAL_MESSAGE, phpbb_mail_error($error)); return; }
        $error_msg = phpbb_mail_error($error);
    }
}
$topic = isset($_POST['topic']) ? $_POST['topic'] : (isset($_GET['topic']) ? $_GET['topic'] : '');
$link = isset($_POST['link']) ? $_POST['link'] : (isset($_GET['link']) ? $_GET['link'] : '');
try { $topic_plain = phpbb_mail_suggested_subject(is_string($topic) ? phpbb_request_raw_value($topic) : null); }
catch (PhpbbMailFailure $error) { $topic_plain = $lang['Mail_recommendation_subject']; if ($error_msg === '') { $error_msg = phpbb_mail_error($error); } }
$link_plain = phpbb_mail_board_link(is_string($link) ? phpbb_request_raw_value($link) : '');
$mail_body = str_replace(array('{TOPIC}','{LINK}','{SITENAME}'), array($topic_plain,$link_plain,$board_config['sitename']), $lang['Tell_Friend_Body']);
$values = array('friendname'=>'','friendemail'=>'','message'=>$mail_body);
if ($submitted) { foreach ($values as $name=>$default) { $values[$name] = isset($_POST[$name]) && is_string($_POST[$name]) ? phpbb_request_raw_value($_POST[$name]) : ''; } }
$safe = array(); foreach ($values as $name=>$value) { $safe[strtoupper($name)] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$page_title = $lang['Tell_Friend_Title'];
include($phpbb_root_path . 'includes/page_header.' . $phpEx);
if ($error_msg !== '') {
    $template->set_filenames(array('reg_header'=>'error_body.tpl'));
    $template->assign_vars(array('ERROR_MESSAGE'=>htmlspecialchars($error_msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
    $template->assign_var_from_handle('ERROR_BOX','reg_header');
}
$template->assign_vars(array_merge($safe,array(
    'L_TELL_FRIEND_TITLE'=>$lang['Tell_Friend_Title'],
    'L_TELL_FRIEND_SENDER_USER'=>$lang['Tell_Friend_Sender_User'],'L_TELL_FRIEND_SENDER_EMAIL'=>$lang['Tell_Friend_Sender_Email'],
    'L_TELL_FRIEND_RECIEVER_USER'=>$lang['Tell_Friend_Reciever_User'],'L_TELL_FRIEND_RECIEVER_EMAIL'=>$lang['Tell_Friend_Reciever_Email'],
    'L_TELL_FRIEND_MSG'=>$lang['Tell_Friend_Msg'],'SUBMIT_ACTION'=>append_sid($PHP_SELF,true),
    'S_HIDDEN_FIELDS'=>'<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'],ENT_QUOTES,'UTF-8') . '" />',
    'L_SUBMIT'=>$lang['Send_email'],'SITENAME'=>htmlspecialchars($board_config['sitename'],ENT_QUOTES,'UTF-8'),
    'TOPIC'=>htmlspecialchars($topic_plain,ENT_QUOTES,'UTF-8'),'LINK'=>htmlspecialchars($link_plain,ENT_QUOTES,'UTF-8'),
    'SENDER_NAME'=>htmlspecialchars(html_entity_decode($userdata['username'],ENT_QUOTES,'UTF-8'),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'),
    'SENDER_MAIL'=>htmlspecialchars($userdata['user_email'],ENT_QUOTES,'UTF-8'))));
$template->set_filenames(array('body'=>'tellafriend_body.tpl'));
$template->pparse('body');
include($phpbb_root_path . 'includes/page_tail.' . $phpEx);
