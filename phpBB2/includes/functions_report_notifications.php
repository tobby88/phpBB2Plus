<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_report_storage.php';

// Reopen current storage independently of a caller's earlier snapshot. Release
// the read owner before any mail I/O; an optional notification cannot change
// or roll back the already committed report.
function phpbb_report_notification_ready($database, $outcome, $user_id)
{
    $post_id=phpbb_card_id($outcome['post_id'],16777215);
    $user_id=phpbb_card_id($user_id,8388607);
    if ((!is_int($outcome['count']) && !is_string($outcome['count'])) || !preg_match('/^[1-9][0-9]{0,19}$/D',(string)$outcome['count'])) { phpbb_card_error(); }
    $owner=new PhpbbReportScope($database,array(POSTS_TABLE,POSTS_TEXT_TABLE,TOPICS_TABLE,FORUMS_TABLE,GROUPS_TABLE,USER_GROUP_TABLE,AUTH_ACCESS_TABLE));
    try {
        $posts=$owner->rows('SELECT p.post_bluecard,p.forum_id,f.forum_name,CASE WHEN first_post.post_deleted=0 THEN pt.post_subject ELSE t.topic_title END AS post_subject FROM ' . POSTS_TABLE
            . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id AND t.topic_moved_id=0'
            . ' JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=p.forum_id JOIN ' . POSTS_TABLE . ' first_post'
            . ' ON first_post.post_id=t.topic_first_post_id AND first_post.topic_id=t.topic_id AND first_post.forum_id=t.forum_id'
            . ' JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id=first_post.post_id WHERE p.post_id=' . $post_id . ' AND p.post_deleted=0');
        if (count($posts)!==1 || !preg_match('/^[1-9][0-9]{0,19}$/D',(string)$posts[0]['post_bluecard']) || (float)$posts[0]['post_bluecard']<(float)$outcome['count']) { return false; }
        $post=$posts[0];
        $users=$owner->rows('SELECT user_id,user_level,user_active,user_blocktime,user_email,user_lang FROM ' . USERS_TABLE . ' WHERE user_id=' . $user_id);
        if (count($users)!==1 || (int)$users[0]['user_active']!==1 || (int)$users[0]['user_blocktime']>=time()) { return false; }
        $recipient=$users[0];$rights=$owner->forum_access((int)$post['forum_id'],$recipient);
        if (!$rights || !$rights['auth_view'] || !$rights['auth_read'] || !$rights['moderator']) { return false; }
        foreach ($owner->rows('SELECT ban_userid,ban_email FROM ' . BANLIST_TABLE) as $rule) {
            if ((int)$rule['ban_userid']===$user_id || phpbb_email_ban_matches($rule['ban_email'],$recipient['user_email'])) { return false; }
        }
        return array('recipient'=>$recipient,'post'=>$post);
    } finally { $owner->release(); }
}

// Called only after the report owner has confirmed commit and released locks.
function phpbb_report_notify($outcome)
{
    global $phpbb_root_path,$phpEx,$board_config,$db;
    if (!$outcome['notify'] || !$outcome['recipients']) { return true; }
    require_once $phpbb_root_path . 'includes/emailer.' . $phpEx;
    $ok=true;
    foreach ($outcome['recipients'] as $recipient) {
        try {
            $ready=phpbb_report_notification_ready($db,$outcome,$recipient['user_id']);
            if ($ready===false) { continue; }
            $recipient=$ready['recipient'];$post=$ready['post'];
            if (!filter_var($recipient['user_email'],FILTER_VALIDATE_EMAIL)) { $ok=false; continue; }
            $emailer=new emailer($board_config['smtp_delivery'],true);
            $actor=$outcome['actor'];
            $from=!empty($actor['user_viewemail']) && filter_var($actor['user_email'],FILTER_VALIDATE_EMAIL) ? $actor['user_email'] : $board_config['board_email'];
            $emailer->from($from); $emailer->replyto($from); $emailer->email_address($recipient['user_email']);
            $language=$recipient['user_lang']; if (!is_string($language) || !preg_match('/^[a-z0-9_-]+$/iD',$language)) { $language=''; }
            $emailer->use_template('repport_post',$language);
            $emailer->assign_vars(array('POST_URL'=>phpbb_board_url('viewtopic.' . $phpEx . '?' . POST_POST_URL . '=' . $outcome['post_id'] . '#' . $outcome['post_id']),
                'POST_SUBJECT'=>$post['post_subject'],'FORUM_NAME'=>$post['forum_name'],'USER'=>html_entity_decode($actor['username'],ENT_QUOTES,'UTF-8'),
                'NUMBER_OF_REPPORTS'=>$outcome['count'],'SITENAME'=>$board_config['sitename'],'BOARD_EMAIL'=>$board_config['board_email']));
            if (!$emailer->send()) { $ok=false; }
        } catch (Exception $e) { $ok=false; }
        catch (Error $e) { $ok=false; }
    }
    return $ok;
}
