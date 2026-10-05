<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_login_storage.php';
require_once dirname(__FILE__) . '/functions_ban.php';

class PhpbbCardException extends RuntimeException {}
function phpbb_card_error($key = 'Card_storage_failed') { throw new PhpbbCardException($key); }
function phpbb_card_duration($minutes)
{
    global $lang;
    return sprintf($lang['Card_block_minutes'],(int)$minutes);
}
function phpbb_card_id($value, $maximum)
{
    if (!(is_int($value) || is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)
        || strlen((string)$value) > strlen((string)$maximum) || (float)$value > $maximum) { phpbb_card_error('Not_Authorised'); }
    return (int)$value;
}
function phpbb_card_action($request)
{
    if (!is_array($request)) { phpbb_card_error('Not_Authorised'); }
    $actions = array();
    foreach (array('report','report_reset','ban','unban','warn','block') as $action) {
        if (isset($request[$action . '_x'])) {
            if (!is_string($request[$action . '_x']) || !preg_match('/^[0-9]{1,6}$/D', $request[$action . '_x'])) { phpbb_card_error('Not_Authorised'); }
            $actions[] = $action;
        }
    }
    if (count($actions) !== 1) { phpbb_card_error('Not_Authorised'); }
    return $actions[0];
}

// A private owning connection: caller snapshots, transactions and cached roles
// cannot publish a warning without its associated ban/session invalidation.
class PhpbbCardScope extends PhpbbLoginDatabase
{
    var $actor_id;
    var $sid;
    function sql_query($sql, $transaction = false)
    {
        if (is_string($sql) && preg_match('/^\s*SELECT\b/i', $sql) && stripos($sql, 'information_schema.') === false
            && !preg_match('/(?:FOR UPDATE|LOCK IN SHARE MODE)\s*$/i', $sql)) { $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE'; }
        return parent::sql_query($sql, $transaction);
    }
    function actor()
    {
        $rows = $this->rows('SELECT user_id,user_active,user_level,user_blocktime,username,user_email,user_viewemail FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor_id);
        if (count($rows) !== 1 || (int)$rows[0]['user_active'] !== 1 || (int)$rows[0]['user_blocktime'] >= time()) { phpbb_card_error('Not_Authorised'); }
        $sid = $this->sql_escape($this->sid);
        if (count($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_logged_in=1 AND session_user_id=" . $this->actor_id)) !== 1) { phpbb_card_error('Not_Authorised'); }
        return $rows[0];
    }
    function permission($level, $key, $access, $admin)
    {
        switch ((string)$level) {
            case (string)AUTH_ALL: case (string)AUTH_REG: return true;
            case (string)AUTH_ACL: return $admin || $this->granted($access, $key);
            case (string)AUTH_MOD: return $admin || $this->granted($access, 'auth_mod');
            case (string)AUTH_ADMIN: return $admin;
        }
        return false;
    }
    function granted($access, $key)
    {
        foreach ($access as $row) { if ((int)$row[$key] === 1 || (int)$row['auth_mod'] === 1) { return true; } }
        return false;
    }
    function authorize($actor, $post, $mode)
    {
        $admin = (int)$actor['user_level'] === ADMIN;
        // The original mod explicitly offers administrators user-mode cards
        // outside a forum. Do not manufacture forum-wide moderator authority.
        if (!$post) { if (!$admin) { phpbb_card_error('Not_Authorised'); } return; }
        $forum = $this->rows('SELECT forum_id,auth_view,auth_read,auth_ban,auth_greencard FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . (int)$post['forum_id']);
        if (count($forum) !== 1) { phpbb_card_error('Not_Authorised'); }
        $members = $this->rows('SELECT group_id,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $this->actor_id);
        $groups = array();
        foreach ($members as $member) {
            // Ignore malformed/orphan legacy references, never grant through
            // them. They must not prevent a valid root administrator acting.
            if (!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$member['group_id']) || (int)$member['group_id']>8388607) { continue; }
            $id = phpbb_card_id($member['group_id'], 8388607);
            $real = $this->rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $id);
            if ($real && $member['user_pending'] !== null && (string)$member['user_pending'] === '0') { $groups[$id] = true; }
        }
        $access = array();
        foreach ($this->rows('SELECT group_id,auth_view,auth_read,auth_ban,auth_greencard,auth_mod FROM ' . AUTH_ACCESS_TABLE . ' WHERE forum_id=' . (int)$post['forum_id']) as $row) {
            if (isset($groups[(int)$row['group_id']])) { $access[] = $row; }
        }
        foreach (array('auth_view','auth_read',$mode === 'unban' ? 'auth_greencard' : 'auth_ban') as $key) {
            if (!$this->permission($forum[0][$key],$key,$access,$admin)) { phpbb_card_error('Not_Authorised'); }
        }
    }
}

function phpbb_card_moderate($database, $request, $user_ip)
{
    global $userdata;
    $mode = phpbb_card_action($request);
    if (!in_array($mode,array('ban','unban','warn','block'),true) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || !isset($request['sid'],$userdata['session_id']) || !is_string($request['sid']) || !is_string($userdata['session_id'])
        || $request['sid'] === '' || !hash_equals($userdata['session_id'],$request['sid']) || empty($userdata['session_logged_in'])
        || !is_string($user_ip) || !preg_match('/^[a-f0-9]{8}$/iD',$user_ip)) { phpbb_card_error('Not_Authorised'); }
    $actor_id = phpbb_card_id(isset($userdata['user_id']) ? $userdata['user_id'] : null,8388607);
    $post_id = isset($request['post_id']) && !in_array($request['post_id'],array('0','-1'),true) ? phpbb_card_id($request['post_id'],16777215) : 0;
    $target_id = isset($request[POST_USERS_URL]) ? phpbb_card_id($request[POST_USERS_URL],8388607) : 0;
    if (!$post_id && !$target_id) { phpbb_card_error('Not_Authorised'); }
    $owner = new PhpbbCardScope($database,array(POSTS_TABLE,TOPICS_TABLE,FORUMS_TABLE,GROUPS_TABLE,USER_GROUP_TABLE,AUTH_ACCESS_TABLE));
    try {
        $owner->actor_id=$actor_id; $owner->sid=$request['sid']; $actor=$owner->actor();
        $policy=array(); foreach ($owner->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE) as $setting) { $policy[$setting['config_name']]=$setting['config_value']; }
        // common.php stops every non-ACP/non-login page when the board is
        // disabled. A cached bootstrap must not outlive that current decision.
        if (!array_key_exists('board_disable',$policy)) { phpbb_card_error(); }
        if (!empty($policy['board_disable'])) { phpbb_card_error('Board_disable'); }
        if (!isset($policy['max_user_bancard']) || !preg_match('/^[0-9]{1,5}$/D',(string)$policy['max_user_bancard']) || (int)$policy['max_user_bancard']>32767) { phpbb_card_error(); }
        $limit=max(1,(int)$policy['max_user_bancard']); $minutes=0;
        if ($mode==='block') {
            if (!isset($policy['block_time']) || !preg_match('/^[0-9]{1,8}$/D',(string)$policy['block_time']) || (int)$policy['block_time']>floor((2147483647-time())/60)) { phpbb_card_error(); }
            $minutes=max(1,(int)$policy['block_time']);
        }
        $post=null;
        if ($post_id) {
            $rows=$owner->rows('SELECT p.post_id,p.poster_id,p.forum_id FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE
                . ' t ON t.topic_id=p.topic_id AND t.forum_id=p.forum_id AND t.topic_moved_id=0 WHERE p.post_id=' . $post_id);
            if (count($rows)!==1) { phpbb_card_error('No_such_post'); } $post=$rows[0];
            $actual=phpbb_card_id($post['poster_id'],8388607);
            if ($target_id && $target_id!==$actual) { phpbb_card_error('Not_Authorised'); } $target_id=$actual;
        }
        if ($target_id===$actor_id) { phpbb_card_error('Not_Authorised'); }
        $owner->authorize($actor,$post,$mode);
        $rows=$owner->rows('SELECT user_id,user_level,user_warnings,username,user_email,user_lang FROM ' . USERS_TABLE . ' WHERE user_id=' . $target_id . ' FOR UPDATE');
        if (count($rows)!==1) { phpbb_card_error('No_such_user'); } $target=$rows[0];
        if ((int)$target['user_level']===ADMIN) { phpbb_card_error($mode==='block'?'Block_no_admin':'Ban_no_admin'); }
        $rules=$owner->rows('SELECT ban_id,ban_userid,ban_ip,ban_email,ban_ip_mask FROM ' . BANLIST_TABLE . ' ORDER BY ban_id FOR UPDATE');
        $banned=false;
        foreach ($rules as $rule) {
            if (!phpbb_ip_ban_valid($rule['ban_ip'],$rule['ban_ip_mask'])) { phpbb_card_error('Ban_ip_storage_upgrade'); }
            if ((int)$rule['ban_userid']===$actor_id || phpbb_ip_ban_matches($rule['ban_ip'],$rule['ban_ip_mask'],$user_ip) || phpbb_email_ban_matches($rule['ban_email'],$actor['user_email'])) { phpbb_card_error('Not_Authorised'); }
            if ((int)$rule['ban_userid']===$target_id) { $banned=true; }
        }
        $warnings=max(0,(int)$target['user_warnings']); $template=''; $message='';
        if ($mode==='unban') {
            $owner->sql_query('DELETE FROM ' . BANLIST_TABLE . " WHERE ban_userid=$target_id AND COALESCE(ban_ip,'')='' AND COALESCE(ban_email,'')=''");
            $owner->sql_query('UPDATE ' . BANLIST_TABLE . " SET ban_userid=0 WHERE ban_userid=$target_id");
            $warnings=0; $template='ban_reactivated'; $message='Ban_update_green';
        } elseif ($mode==='block') {
            $owner->sql_query('UPDATE ' . USERS_TABLE . " SET user_block_by='" . $owner->sql_escape($user_ip) . "',user_blocktime=" . (time()+$minutes*60) . ' WHERE user_id=' . $target_id);
            $template='card_block'; $message='Block_update';
        } else {
            if ($mode==='warn' && !$banned) { $warnings=min(32767,$warnings+1); }
            if ($mode==='ban' || $banned || $warnings>=$limit) {
                if (!$banned) { $owner->sql_query('INSERT INTO ' . BANLIST_TABLE . " (ban_userid,ban_ip,ban_email,ban_ip_mask) VALUES ($target_id,'','','')"); }
                $warnings=max($warnings,$limit); $message=$banned?'user_already_banned':'Ban_update_red'; $template=$banned?'':'ban_block';
            } else { $message='Ban_update_yellow'; $template='ban_warning'; }
        }
        if ($mode!=='block') { $owner->sql_query('UPDATE ' . USERS_TABLE . ' SET user_warnings=' . $warnings . ' WHERE user_id=' . $target_id); }
        if ($mode==='block' || ($mode!=='unban' && $message!=='Ban_update_yellow')) {
            $owner->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $target_id);
            $owner->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id=' . $target_id);
        }
        $owner->actor(); $owner->commit();
        $target['user_warnings']=$warnings;
        return array('message'=>$message,'template'=>$template,'target'=>$target,'actor'=>$actor,'post_id'=>$post_id,'target_id'=>$target_id,'limit'=>$limit,'minutes'=>$minutes);
    } finally { $owner->release(); }
}
