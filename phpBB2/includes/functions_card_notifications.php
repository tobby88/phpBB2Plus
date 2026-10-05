<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

// The caller has already confirmed commit and released its database owner.
// Transport/template failure cannot undo or misreport durable moderation.
function phpbb_card_notify($outcome)
{
    global $phpbb_root_path, $phpEx, $board_config;
    if ($outcome['template']==='') { return true; }
    if (!filter_var($outcome['target']['user_email'],FILTER_VALIDATE_EMAIL)) { return false; }
    require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
    try {
        $emailer=new emailer($board_config['smtp_delivery'],true);
        $actor=$outcome['actor'];
        $from=!empty($actor['user_viewemail']) && filter_var($actor['user_email'],FILTER_VALIDATE_EMAIL) ? $actor['user_email'] : $board_config['board_email'];
        $emailer->from($from); $emailer->replyto($from);
        $emailer->email_address($outcome['target']['user_email']);
        $language=$outcome['target']['user_lang'];
        if (!is_string($language) || !preg_match('/^[a-z0-9_-]+$/iD',$language)) { $language=''; }
        $emailer->use_template($outcome['template'],$language);
        $url=$outcome['post_id']>0 ? 'viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $outcome['post_id'] . '#' . $outcome['post_id'] : 'profile.' . $phpEx . '?mode=viewprofile&' . POST_USERS_URL . '=' . $outcome['target_id'];
        $emailer->assign_vars(array('SITENAME'=>$board_config['sitename'],
            'WARNINGS'=>$outcome['target']['user_warnings'],'TOTAL_WARN'=>$outcome['limit'],
            'POST_URL'=>phpbb_board_url($url),'WARNER'=>html_entity_decode($actor['username'],ENT_QUOTES,'UTF-8'),
            'WARNED_POSTER'=>html_entity_decode($outcome['target']['username'],ENT_QUOTES,'UTF-8'),
            'BLOCK_TIME'=>$outcome['minutes'] ? phpbb_card_duration($outcome['minutes']) : '',
            'EMAIL_SIG'=>str_replace('<br />',"\n","-- \n" . $board_config['board_email_sig'])));
        return (bool)$emailer->send();
    } catch (Exception $e) { return false; }
    catch (Error $e) { return false; }
}
