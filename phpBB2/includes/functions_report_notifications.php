<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// Called only after the report owner has confirmed commit and released locks.
function phpbb_report_notify($outcome)
{
    global $phpbb_root_path,$phpEx,$board_config;
    if (!$outcome['notify'] || !$outcome['recipients']) { return true; }
    require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
    $ok=true;
    foreach ($outcome['recipients'] as $recipient) {
        if (!filter_var($recipient['user_email'],FILTER_VALIDATE_EMAIL)) { $ok=false; continue; }
        try {
            $emailer=new emailer($board_config['smtp_delivery'],true);
            $actor=$outcome['actor'];
            $from=!empty($actor['user_viewemail']) && filter_var($actor['user_email'],FILTER_VALIDATE_EMAIL) ? $actor['user_email'] : $board_config['board_email'];
            $emailer->from($from); $emailer->replyto($from); $emailer->email_address($recipient['user_email']);
            $language=$recipient['user_lang']; if (!is_string($language) || !preg_match('/^[a-z0-9_-]+$/iD',$language)) { $language=''; }
            $emailer->use_template('repport_post',$language);
            $emailer->assign_vars(array('POST_URL'=>phpbb_board_url('viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $outcome['post_id'] . '#' . $outcome['post_id']),
                'POST_SUBJECT'=>$outcome['subject'],'FORUM_NAME'=>$outcome['forum_name'],'USER'=>html_entity_decode($actor['username'],ENT_QUOTES,'UTF-8'),
                'NUMBER_OF_REPPORTS'=>$outcome['count'],'SITENAME'=>$board_config['sitename'],'BOARD_EMAIL'=>$board_config['board_email']));
            if (!$emailer->send()) { $ok=false; }
        } catch (Exception $e) { $ok=false; }
        catch (Error $e) { $ok=false; }
    }
    return $ok;
}
