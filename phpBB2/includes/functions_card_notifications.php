<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_card_storage.php';

// Account notices intentionally remain deliverable to a banned/inactive
// account. They are not moderator reports, and must not borrow report ACLs.
function phpbb_card_notification_ready($database, $outcome)
{
    $id=phpbb_card_id($outcome['target_id'],8388607);
    if (!in_array($outcome['template'],array('ban_warning','ban_block','ban_reactivated','card_block'),true)) { phpbb_card_error(); }
    $owner=new PhpbbCardScope($database);
    try {
        $rows=$owner->rows('SELECT user_id,username,user_email,user_lang,user_warnings,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $id);
        if (count($rows)!==1) { return false; }
        $target=$rows[0];$target['user_warnings']=max(0,(int)$target['user_warnings']);
        $banned=count($owner->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id))>0;
        if ($outcome['template']==='ban_block' && !$banned) { return false; }
        if ($outcome['template']==='ban_reactivated' && ($banned || $target['user_warnings']>0)) { return false; }
        if ($outcome['template']==='ban_warning' && ($banned || $target['user_warnings']===0 || $target['user_warnings']<(int)$outcome['target']['user_warnings'])) { return false; }
        if ($outcome['template']==='card_block' && (int)$target['user_blocktime']<=time()) { return false; }
        return $target;
    } finally { $owner->release(); }
}

// The caller has already confirmed commit and released its database owner.
// Transport/template failure cannot undo or misreport durable moderation.
function phpbb_card_notify($outcome)
{
    global $phpbb_root_path, $phpEx, $board_config, $db;
    if ($outcome['template']==='') { return true; }
    try {
        $target=phpbb_card_notification_ready($db,$outcome);
        if ($target===false) { return true; }
        if (!filter_var($target['user_email'],FILTER_VALIDATE_EMAIL)) { return false; }
        require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
        $emailer=new emailer($board_config['smtp_delivery'],true);
        $actor=$outcome['actor'];
        $from=!empty($actor['user_viewemail']) && filter_var($actor['user_email'],FILTER_VALIDATE_EMAIL) ? $actor['user_email'] : $board_config['board_email'];
        $emailer->from($from); $emailer->replyto($from);
        $emailer->email_address($target['user_email']);
        $language=$target['user_lang'];
        if (!is_string($language) || !preg_match('/^[a-z0-9_-]+$/iD',$language)) { $language=''; }
        $emailer->use_template($outcome['template'],$language);
        $url=$outcome['post_id']>0 ? 'viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $outcome['post_id'] . '#' . $outcome['post_id'] : 'profile.' . $phpEx . '?mode=viewprofile&' . POST_USERS_URL . '=' . $outcome['target_id'];
        $emailer->assign_vars(array('SITENAME'=>$board_config['sitename'],
            'WARNINGS'=>$target['user_warnings'],'TOTAL_WARN'=>$outcome['limit'],
            'POST_URL'=>phpbb_board_url($url),'WARNER'=>html_entity_decode($actor['username'],ENT_QUOTES,'UTF-8'),
            'WARNED_POSTER'=>html_entity_decode($target['username'],ENT_QUOTES,'UTF-8'),
            'BLOCK_TIME'=>$outcome['minutes'] ? phpbb_card_duration($outcome['minutes']) : '',
            'BLOCK_MINUTES'=>(int)$outcome['minutes'],
            'EMAIL_SIG'=>str_replace('<br />',"\n","-- \n" . $board_config['board_email_sig'])));
        return (bool)$emailer->send();
    } catch (Exception $e) { return false; }
    catch (Error $e) { return false; }
}
