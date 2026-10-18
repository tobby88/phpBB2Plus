<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_card_storage.php';

// Reporting is not account moderation: AUTH_ALL may admit a real guest session.
class PhpbbReportScope extends PhpbbCardScope
{
    var $logged_in = false;
    function actor()
    {
        $rows = $this->rows('SELECT user_id,user_active,user_level,user_blocktime,username,user_email,user_viewemail FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor_id);
        if (count($rows) !== 1 || ($this->logged_in && ((int)$rows[0]['user_active'] !== 1 || (int)$rows[0]['user_blocktime'] >= time()))) { phpbb_card_error('Not_Authorised'); }
        $sid = $this->sql_escape($this->sid);
        if (count($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=" . $this->actor_id . ' AND session_logged_in=' . ($this->logged_in ? 1 : 0))) !== 1) { phpbb_card_error('Not_Authorised'); }
        return $rows[0];
    }
    function permission($level, $key, $access, $admin)
    {
        if (!$this->logged_in) { return (string)$level === (string)AUTH_ALL; }
        return parent::permission($level, $key, $access, $admin);
    }
    function forum_access($id, $actor)
    {
        $member_id=(int)$actor['user_id']; $member_logged=$member_id>0;
        $forums = $this->rows('SELECT forum_id,forum_status,auth_view,auth_read,auth_post,auth_reply,auth_bluecard FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $id);
        if (count($forums) !== 1) { return null; }
        $groups = array();
        if ($member_logged) {
            foreach ($this->rows('SELECT ug.group_id FROM ' . USER_GROUP_TABLE . ' ug JOIN ' . GROUPS_TABLE . ' g ON g.group_id=ug.group_id WHERE ug.user_id=' . $member_id . ' AND ug.user_pending=0') as $row) { $groups[(int)$row['group_id']] = true; }
        }
        $access = array();
        foreach ($this->rows('SELECT group_id,auth_view,auth_read,auth_post,auth_reply,auth_bluecard,auth_mod FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id=' . $id) as $row) {
            if ((int)$row['group_id'] > 0 && isset($groups[(int)$row['group_id']])) { $access[] = $row; }
        }
        $admin = $member_logged && (int)$actor['user_level'] === ADMIN;
        $result = array('id' => $id, 'moderator' => $member_logged && ($admin || $this->granted($access, 'auth_mod')), 'status' => (int)$forums[0]['forum_status']);
        foreach (array('auth_view','auth_read','auth_post','auth_reply','auth_bluecard') as $key) {
            $result[$key] = $member_logged ? parent::permission($forums[0][$key], $key, $access, $admin) : (string)$forums[0][$key]===(string)AUTH_ALL;
        }
        return $result;
    }
}

function phpbb_report_post($database, $request, $user_ip)
{
    global $userdata;
    $mode = phpbb_card_action($request);
    if (!in_array($mode, array('report','report_reset'), true) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($request['sid'],$userdata['session_id']) || !is_string($request['sid']) || !is_string($userdata['session_id'])
        || $request['sid'] === '' || !hash_equals($userdata['session_id'],$request['sid'])
        || !is_string($user_ip) || !preg_match('/^[a-f0-9]{8}$/iD',$user_ip)) { phpbb_card_error('Not_Authorised'); }
    $logged = !empty($userdata['session_logged_in']);
    if (!$logged && (!isset($userdata['user_id']) || (string)$userdata['user_id'] !== (string)ANONYMOUS)) { phpbb_card_error('Not_Authorised'); }
    $actor_id = $logged ? phpbb_card_id(isset($userdata['user_id']) ? $userdata['user_id'] : null,8388607) : ANONYMOUS;
    $post_id = phpbb_card_id(isset($request['post_id']) ? $request['post_id'] : null,16777215);
    $owner = new PhpbbReportScope($database,array(POSTS_TABLE,POSTS_TEXT_TABLE,TOPICS_TABLE,FORUMS_TABLE,GROUPS_TABLE,USER_GROUP_TABLE,AUTH_ACCESS_TABLE));
    try {
        $owner->actor_id=$actor_id; $owner->logged_in=$logged; $owner->sid=$request['sid']; $actor=$owner->actor();
        $policy=array(); foreach ($owner->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE) as $row) { $policy[$row['config_name']]=$row['config_value']; }
        if (!array_key_exists('board_disable',$policy)) { phpbb_card_error(); }
        if (!empty($policy['board_disable'])) { phpbb_card_error('Board_disable'); }
        $rules=$owner->rows('SELECT ban_userid,ban_ip,ban_ip_mask,ban_email FROM ' . BANLIST_TABLE . ' ORDER BY ban_id');
        foreach ($rules as $rule) {
            if (!phpbb_ip_ban_valid($rule['ban_ip'],$rule['ban_ip_mask'])) { phpbb_card_error('Ban_ip_storage_upgrade'); }
            if ((int)$rule['ban_userid']===$actor_id || phpbb_ip_ban_matches($rule['ban_ip'],$rule['ban_ip_mask'],$user_ip)
                || ($logged && phpbb_email_ban_matches($rule['ban_email'],$actor['user_email']))) { phpbb_card_error('Not_Authorised'); }
        }
        $posts=$owner->rows('SELECT p.post_id,p.topic_id,p.forum_id,p.post_bluecard,f.forum_name,pt.post_subject FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id AND t.topic_moved_id=0 JOIN ' . FORUMS_TABLE . ' f ON f.forum_id=p.forum_id JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id=p.post_id WHERE p.post_id=' . $post_id . ' FOR UPDATE');
        if (count($posts)!==1) { phpbb_card_error('No_such_post'); } $post=$posts[0];
        $access=$owner->forum_access((int)$post['forum_id'],$actor);
        if (!$access || !$access['auth_view'] || !$access['auth_read'] || ($mode==='report_reset' ? !$access['moderator'] : !$access['auth_bluecard'])) { phpbb_card_error('Not_Authorised'); }
        $raw=$post['post_bluecard'];
        if ($raw!==null && !preg_match('/^-?(?:0|[1-9][0-9]{0,19})$/D',(string)$raw)) { phpbb_card_error(); }
        // Preserve larger counters in compatible custom-widened columns,
        // including unsigned bigint strings on 32-bit PHP. Never reduce an
        // existing count merely because the bundled tinyint limit is lower.
        $old=$raw===null || (float)$raw<0 ? 0 : $raw;
        $count=$mode==='report_reset' ? 0 : ((float)$old>=127 ? $old : (int)$old+1);
        $recipients=array(); $notify=false; $destination=0; $reply=0; $subject=$post['post_subject'];
        if ($mode==='report') {
            foreach (array('bluecard_limit','bluecard_limit_2','report_forum') as $key) {
                if (!isset($policy[$key]) || !preg_match('/^[0-9]{1,10}$/D',(string)$policy[$key]) || (float)$policy[$key]>2147483647) { phpbb_card_error(); }
            }
            $first=$owner->rows('SELECT pt.post_subject FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id JOIN ' . POSTS_TEXT_TABLE . ' pt ON pt.post_id=p.post_id WHERE t.topic_id=' . (int)$post['topic_id']);
            if (count($first)!==1) { phpbb_card_error('No_such_post'); } $subject=$first[0]['post_subject'];
            $start=max(1,(int)$policy['bluecard_limit_2']); $interval=max(1,(int)$policy['bluecard_limit']);
            $notify=$count>$old && $count>=$start && ($count-$start)%$interval===0;
            if ($notify) {
                $recipients=$owner->rows('SELECT DISTINCT u.user_id,u.username,u.user_email,u.user_lang FROM ' . AUTH_ACCESS_TABLE . ' aa JOIN ' . GROUPS_TABLE . ' g ON g.group_id=aa.group_id AND g.group_id>0 JOIN ' . USER_GROUP_TABLE . " ug ON ug.group_id=g.group_id AND ug.user_pending=0 JOIN " . USERS_TABLE . " u ON u.user_id=ug.user_id AND u.user_id>0 AND u.user_active=1 AND u.user_email<>'' WHERE aa.forum_id=" . (int)$post['forum_id'] . ' AND aa.auth_mod=1 ORDER BY u.user_id');
            }
            if ((int)$policy['report_forum']>0) {
                $destination_access=$owner->forum_access((int)$policy['report_forum'],$actor);
                if ($destination_access && $destination_access['auth_view'] && $destination_access['auth_read'] && ($destination_access['status']===0 || $destination_access['moderator'])) {
                    $quoted=$owner->sql_escape('('.$post_id.')'.$subject);
                    $existing=$owner->rows('SELECT t.topic_id,t.topic_status FROM ' . TOPICS_TABLE . ' t JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id AND p.topic_id=t.topic_id AND p.forum_id=t.forum_id JOIN ' . POSTS_TEXT_TABLE . " pt ON pt.post_id=p.post_id WHERE t.forum_id=" . (int)$policy['report_forum'] . " AND t.topic_moved_id=0 AND pt.post_subject='$quoted' ORDER BY t.topic_id LIMIT 1");
                    if ($old>0 && $existing && $destination_access['auth_reply'] && ((int)$existing[0]['topic_status']===0 || $destination_access['moderator'])) { $reply=(int)$existing[0]['topic_id']; $destination=(int)$policy['report_forum']; }
                    elseif ($destination_access['auth_post']) { $destination=(int)$policy['report_forum']; }
                }
            }
            // A moderator grant alone cannot disclose an admin-only forum.
            // Capture only current readable, unblocked, non-user/email-banned
            // recipients, including when the reporting session is anonymous.
            $eligible=array();
            foreach ($recipients as $recipient) {
                $current=$owner->rows('SELECT user_id,user_level,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$recipient['user_id']);
                if (count($current)!==1 || (int)$current[0]['user_blocktime']>=time()) { continue; }
                $rights=$owner->forum_access((int)$post['forum_id'],$current[0]);
                if (!$rights || !$rights['auth_view'] || !$rights['auth_read'] || !$rights['moderator']) { continue; }
                $allowed=true;
                foreach ($rules as $rule) {
                    if ((int)$rule['ban_userid']===(int)$recipient['user_id'] || phpbb_email_ban_matches($rule['ban_email'],$recipient['user_email'])) { $allowed=false; break; }
                }
                if ($allowed) { $eligible[]=$recipient; }
            }
            $recipients=$eligible;
        }
        $owner->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_bluecard=' . $count . ' WHERE post_id=' . $post_id);
        $owner->actor(); $owner->commit();
        return array('mode'=>$mode,'post_id'=>$post_id,'count'=>$count,'actor'=>$actor,'subject'=>$subject,'forum_name'=>$post['forum_name'],'recipients'=>$recipients,'notify'=>$notify,'destination'=>$destination,'reply'=>$reply);
    } finally { $owner->release(); }
}
