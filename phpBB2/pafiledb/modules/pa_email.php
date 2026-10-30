<?php
/*
  paFileDB 3.0
  ©2001/2002 PHP Arena
  Written by Todd
  todd@phparena.net
  http://www.phparena.net
  Keep all copyright links on the script visible
  Please read the license included with this script for more information.
*/


require_once dirname(dirname(__FILE__)) . '/includes/functions_toplist.php';
require_once dirname(dirname(__FILE__)) . '/includes/functions_mail_storage.php';
class pafiledb_email extends pafiledb_public
{
    // A trusted controller factory lets native fixtures capture the real mailer
    // at its transport boundary. No request value selects a transport callback.
    function create_mailer($smtp)
    {
        global $phpbb_root_path, $phpEx;
        require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
        return new emailer($smtp, true);
    }
    function main($action = false)
    {
        global $pafiledb_template, $lang, $board_config, $phpEx, $pafiledb_config, $db, $userdata;
        $file_id = isset($_REQUEST['file_id']) ? $_REQUEST['file_id'] : null;
        $submitted = isset($_POST['submit']); $error_key = '';
        // The actual send uses current owned storage, never cached page grants.
        if ($submitted) {
            try {
                $file_id = pafiledb_send_recommendation($db, $file_id, $_POST, array($this, 'create_mailer'));
                $message = $lang['Econf'] . '<br /><br />' . sprintf($lang['Click_return'], '<a href="' . append_sid('dload.' . $phpEx . '?action=file&file_id=' . $file_id) . '">', '</a>');
            } catch (PaFileDbMailFailure $error) {
                $error_key = $error->getMessage();
                if (!in_array($error_key, array('PA_mail_invalid','PA_mail_too_long','Email_invalid','PA_mail_sender_invalid','PA_mail_busy','PA_mail_wait','PA_mail_failed'), true)) {
                    message_die(GENERAL_MESSAGE, isset($lang[$error_key]) ? $lang[$error_key] : $lang['PA_mail_failed']); return;
                }
                $this->init();
            }
            if ($error_key === '') { message_die(GENERAL_MESSAGE, $message); return; }
        }
        try { $file_id = pafiledb_mutation_id($file_id); }
        catch (PaFileDbMutationFailure $error) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
        $result = $db->sql_query('SELECT file_catid,file_name FROM ' . PA_FILES_TABLE . ' WHERE file_id=' . $file_id . ' AND file_approved=1');
        if (!$result) { message_die(GENERAL_ERROR, 'Could not select download'); return; }
        $file_data = $db->sql_fetchrow($result); $db->sql_freeresult($result);
        if (!$file_data) { message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return; }
        if (!pafiledb_file_category_accessible($this, $file_data['file_catid']) || empty($this->auth[$file_data['file_catid']]['auth_email'])) {
            if (empty($userdata['session_logged_in'])) { redirect(append_sid('login.' . $phpEx . '?redirect=dload.' . $phpEx . '?action=email&file_id=' . $file_id, true)); return; }
            message_die(GENERAL_MESSAGE, $lang['File_not_exist']); return;
        }
        $this->generate_category_nav($file_data['file_catid']);
        $file_url = get_formated_url() . '/dload.' . $phpEx . '?action=file&file_id=' . $file_id;
        $values = array('fname'=>'','femail'=>'','subject'=>$file_data['file_name'],'message'=>$lang['Defaultmail'] . ' ' . $file_url,'sname'=>'','semail'=>'');
        if ($submitted) {
            foreach ($values as $name=>$default) {
                $values[$name] = isset($_POST[$name]) && is_string($_POST[$name]) ? phpbb_request_raw_value($_POST[$name]) : '';
            }
        }
        if (!empty($userdata['session_logged_in'])) {
            $values['sname'] = html_entity_decode($userdata['username'], ENT_QUOTES, 'UTF-8');
            $values['semail'] = $userdata['user_email'];
        }
        $safe = array();
        foreach ($values as $name=>$value) { $safe[strtoupper($name)] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
        $pafiledb_template->assign_vars(array_merge($safe, array(
            'USER_LOGGED'=>empty($userdata['session_logged_in']),
            'S_EMAIL_ACTION'=>append_sid('dload.' . $phpEx),
            'S_HIDDEN_FIELDS'=>'<input type="hidden" name="sid" value="' . htmlspecialchars($userdata['session_id'], ENT_QUOTES, 'UTF-8') . '" />',
            'L_INDEX'=>sprintf($lang['Forum_Index'], $board_config['sitename']),
            'L_EMAIL'=>$lang['Emailfile'],'L_EMAILINFO'=>$lang['Emailinfo'],'L_YNAME'=>$lang['Yname'],'L_YEMAIL'=>$lang['Yemail'],
            'L_FNAME'=>$lang['Fname'],'L_FEMAIL'=>$lang['Femail'],'L_ETEXT'=>$lang['Etext'],'L_SEMAIL'=>$lang['Semail'],'L_ESUB'=>$lang['Esub'],
            'L_EMPTY_SUBJECT_EMAIL'=>$lang['Empty_subject_email'],'L_EMPTY_MESSAGE_EMAIL'=>$lang['Empty_message_email'],
            'U_INDEX'=>append_sid('index.' . $phpEx),'U_DOWNLOAD_HOME'=>append_sid('dload.' . $phpEx),
            'U_FILE_NAME'=>append_sid('dload.' . $phpEx . '?action=file&file_id=' . $file_id),
            'FILE_NAME'=>pafiledb_html($file_data['file_name']),'DOWNLOAD'=>$pafiledb_config['settings_dbname'],'ID'=>$file_id)));
        if ($error_key !== '') { $pafiledb_template->assign_block_vars('mail_error', array('MESSAGE'=>pafiledb_html($lang[$error_key]))); }
        $this->display($lang['Download'], 'pa_email_body.tpl');
    }
}
