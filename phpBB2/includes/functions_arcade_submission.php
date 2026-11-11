<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_score_storage.php';

function phpbb_arcade_submission_better($score, $previous, $reverse)
{
    return $previous === null || ($reverse ? (float)$score < (float)$previous : (float)$score > (float)$previous);
}

class PhpbbArcadeSubmissionWriter extends PhpbbOwnedStore
{
    use PhpbbArcadeRankings;
    public $commit_attempted = false;
    protected $settings = array();

    protected function storage_tables()
    {
        return array(USERS_TABLE, SESSIONS_TABLE, iNA, iNA_GAMES, iNA_SESSIONS,
            iNA_SCORES, iNA_AT_SCORES, iNA_HIGHSCORES, iNA_USER_DATA,
            iNA_TOUR, iNA_TOUR_DATA, iNA_TOUR_PLAY, iNA_LOG, GROUPS_TABLE, USER_GROUP_TABLE, iNA_CAT);
    }
    protected function enabled($name) { return isset($this->settings[$name]) && (int)$this->settings[$name] !== 0; }
    protected function number($name) { return isset($this->settings[$name]) ? (int)$this->settings[$name] : 0; }
    protected function top($table, $name, $reverse)
    {
        $rows = $this->rows('SELECT s.player_id,s.score FROM '.$table.' s INNER JOIN '.USERS_TABLE.
            " u ON u.user_id=s.player_id WHERE s.game_name='$name' ORDER BY s.score ".($reverse?'ASC':'DESC').
            ',s.date ASC,s.player_id ASC LIMIT 1 LOCK IN SHARE MODE');
        return $rows ? $rows[0] : null;
    }
    protected function board($table, $game, $user, $score, $ip, $username, $elapsed, $now, &$players)
    {
        $name = $this->escape($game['game_name']); $reverse = !empty($game['reverse_list']);
        foreach ($this->ranked_players($table, $game['game_name'], $reverse) as $row) { $players[] = (int)$row['player_id']; }
        $old_top = $this->top($table, $name, $reverse);
        $rows = $this->rows('SELECT score FROM '.$table." WHERE game_name='$name' AND player_id=$user ORDER BY score ".($reverse?'ASC':'DESC').' FOR UPDATE');
        $old = $rows ? $rows[0]['score'] : null;
        $changed = phpbb_arcade_submission_better($score, $old, $reverse);
        if ($changed) {
            $values = 'score='.$score.',date='.$now.',time_taken='.$elapsed.",player_ip='".$this->escape($ip)."'";
            if ($table === iNA_AT_SCORES) { $values .= ",player_name='".$this->escape($username)."'"; }
            if ($rows) { $this->query('UPDATE '.$table.' SET '.$values." WHERE game_name='$name' AND player_id=$user"); }
            else { $this->query('INSERT INTO '.$table.' SET '.$values.",game_name='$name',player_id=$user"); }
        }
        // Prune complete numeric player records, including legacy signed/zero
        // values. The old DELETE-by-player could also remove a retained tie.
        $limit = (int)$game[$table === iNA_SCORES ? 'highscore_limit' : 'at_highscore_limit'];
        if ($limit > 0) {
            $ordered = $this->rows('SELECT * FROM '.$table." WHERE game_name='$name' ORDER BY score ".($reverse?'ASC':'DESC').',date ASC,player_id ASC FOR UPDATE');
            foreach ($ordered as $index=>$row) {
                $player = (int)$row['player_id']; $players[] = $player;
                if ($index < $limit) { continue; }
                $where=array();
                foreach(phpbb_arcade_score_fields($table===iNA_SCORES?'current':'all_time')as$key){
                    $binary=in_array($key,array('game_name','gameData','player_name','player','player_ip'),true)?'BINARY ':'';
                    $where[]=$binary.$key.' <=> '.($row[$key]===null?'NULL':$binary."'".$this->escape((string)$row[$key])."'");
                }
                $this->query('DELETE FROM '.$table.' WHERE '.implode(' AND ',$where).' LIMIT 1');
                if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('arcade_submission_failed');}
            }
        }
        foreach ($this->ranked_players($table, $game['game_name'], $reverse) as $row) { $players[] = (int)$row['player_id']; }
        $top = $this->top($table, $name, $reverse);
        $field = $table === iNA_SCORES ? 'highscore_id' : 'at_highscore_id';
        $this->query('UPDATE '.iNA_GAMES.' SET '.$field.'='.($top ? (int)$top['player_id'] : 0).' WHERE game_id='.(int)$game['game_id']);
        return array('changed'=>$changed, 'existed'=>$rows!==array(), 'old_score'=>$old,
            'first'=>$changed && phpbb_arcade_submission_better($score, $old_top ? $old_top['score'] : null, $reverse),
            'previous_winner'=>$old_top ? (int)$old_top['player_id'] : 0);
    }
    protected function monthly($game, $user, $score, $username, $now)
    {
        if ($user === ANONYMOUS || !$this->enabled('games_show_mhm') || (float)$score < 0 || (float)$score > 99999999.9999) { return null; }
        $name = $this->escape($game['game_name']); $year = (int)date('Y', $now); $month = (int)date('n', $now);
        $rows = $this->rows('SELECT highscore_id,highscore_score FROM '.iNA_HIGHSCORES.
            " WHERE highscore_game='$name' AND highscore_year=$year AND highscore_mon=$month ORDER BY highscore_score ".
            (!empty($game['reverse_list'])?'ASC':'DESC').',highscore_date ASC,highscore_user_id ASC,highscore_id ASC FOR UPDATE');
        if (!phpbb_arcade_submission_better($score, $rows ? $rows[0]['highscore_score'] : null, !empty($game['reverse_list']))) { return false; }
        $values = 'highscore_user_id='.$user.",highscore_player='".$this->escape($username)."',highscore_score=$score,highscore_date=$now";
        if ($rows) { $this->query('UPDATE '.iNA_HIGHSCORES.' SET '.$values.' WHERE highscore_id='.(int)$rows[0]['highscore_id']); }
        else { $this->query('INSERT INTO '.iNA_HIGHSCORES.' SET '.$values.",highscore_game='$name',highscore_year=$year,highscore_mon=$month"); }
        return true;
    }
    protected function tournament($capability, $game, $user, $score, $now)
    {
        if ((int)$capability['page'] !== PAGE_ARCADE_TOUR) { return false; }
        $tour = (int)$capability['tour_id']; $name = $this->escape($game['game_name']);
        if ($tour < 1 || $user === ANONYMOUS || abs((float)$score) > 99999999.9999) { $this->storage_fail('arcade_submission_failed'); }
        $events = $this->rows('SELECT tour_id,tour_active FROM '.iNA_TOUR.' WHERE tour_id='.$tour.' LOCK IN SHARE MODE');
        $games = $this->rows('SELECT top_score,top_player FROM '.iNA_TOUR_DATA." WHERE tour_id=$tour AND game_name='$name' FOR UPDATE");
        $members = $this->rows('SELECT gamedata FROM '.iNA_TOUR_PLAY." WHERE tour_id=$tour AND user_id=$user FOR UPDATE");
        if (count($events)!==1 || (int)$events[0]['tour_active']!==2 || count($games)!==1 || count($members)!==1) { $this->storage_fail('arcade_submission_failed'); }
        $data = phpbb_safe_unserialize_array($members[0]['gamedata']); $found = 0;
        foreach ($data as &$entry) {
            if (!is_array($entry) || !isset($entry['game_name']) || $entry['game_name'] !== $game['game_name']) { continue; }
            $found++;
            // Legacy zero entries were initialized before a player submitted
            // any score; the score protocols do not accept zero submissions.
            $old = isset($entry['score']) && is_numeric($entry['score']) ? (float)$entry['score'] : null;
            if ($old === null || ($old === 0.0 && empty($entry['score_recorded'])) || phpbb_arcade_submission_better($score,$old,!empty($game['reverse_list']))) { $entry['score']=(float)$score; }
            // 'played' is the launch count, not a boolean score marker.
            $entry['score_recorded']=true;
        }
        unset($entry);
        if ($found !== 1) { $this->storage_fail('arcade_submission_failed'); }
        $old = (int)$games[0]['top_player'] > 0 ? $games[0]['top_score'] : null;
        if (phpbb_arcade_submission_better($score,$old,!empty($game['reverse_list']))) {
            $this->query('UPDATE '.iNA_TOUR_DATA." SET top_score=$score,top_player=$user WHERE tour_id=$tour AND game_name='$name'");
        }
        $serialized = serialize($data);
        if (strlen($serialized)>65535) { $this->storage_fail('arcade_submission_failed'); }
        $this->query('UPDATE '.iNA_TOUR_PLAY." SET last_played_game='$name',last_played_time=$now,gamedata='".$this->escape($serialized)."' WHERE tour_id=$tour AND user_id=$user");
        return true;
    }
    protected function reward($game, $user, $score, $bonus, $profile)
    {
        if ($profile !== 'standard' || $user === ANONYMOUS || !$this->enabled('use_rewards_mod')) { return null; }
        $points = $this->enabled('use_point_system');
        if (!$points && !$this->enabled('use_cash_system') && !$this->enabled('use_allowance_system')) { return null; }
        $amount = (int)$game['game_reward'] > 0 ? (int)(float)$score / (int)$game['game_reward'] + $bonus : $bonus;
        if ($amount <= 0) { return null; }
        $field = phpbb_arcade_wallet_field($this,$this->settings,$points);
        $amount = rtrim(rtrim(number_format($amount,8,'.',''),'0'),'.');
        $this->query('UPDATE '.USERS_TABLE." SET `$field`=`$field`+$amount WHERE user_id=$user");
        $balance=$this->rows('SELECT `'.$field.'` balance FROM '.USERS_TABLE.' WHERE user_id='.$user);
        return array('amount'=>(float)$amount,'points'=>$points,'field'=>$field,'balance'=>$balance[0]['balance']);
    }
    function submit($expected, $capability, $score, $profile, $detected)
    {
        global $userdata;
        if (!in_array($profile,array('standard','pnflash'),true) || !is_array($capability) || !isset($capability['arcade_hash']) || !is_string($capability['arcade_hash']) || !preg_match('/^[a-f0-9]{32}$/D',$capability['arcade_hash'])) { $this->storage_fail('arcade_submission_failed'); }
        $score = phpbb_arcade_score_number($score);
        if ((float)$score === 0.0) { $this->storage_fail('arcade_submission_failed'); }
        if (!isset($userdata['user_id'],$userdata['session_id'],$userdata['session_ip']) || !(is_string($userdata['user_id'])||is_int($userdata['user_id'])) || !preg_match('/^(?:-1|[1-9][0-9]{0,7})$/D',(string)$userdata['user_id']) || (float)$userdata['user_id']>16777215 || !is_string($userdata['session_id']) || $userdata['session_id']==='' || strlen($userdata['session_id'])>32 || !is_string($userdata['session_ip']) || !preg_match('/^[a-f0-9]{8}$/iD',$userdata['session_ip'])) { $this->storage_fail('arcade_submission_failed'); }
        $user = (int)$userdata['user_id']; $sid=$this->escape($userdata['session_id']); $token=$this->escape($capability['arcade_hash']);
        $sessions=$this->rows('SELECT session_user_id,session_logged_in FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') LOCK IN SHARE MODE");
        if(count($sessions)!==1||(int)$sessions[0]['session_user_id']!==$user||(int)$sessions[0]['session_logged_in']!==($user===ANONYMOUS?0:1)){ $this->storage_fail('arcade_submission_failed'); }
        // Rewards can update this very account. Take the exclusive lock now,
        // avoiding a lock upgrade behind an already queued account revocation.
        $users=$this->rows('SELECT user_id,username,user_active,user_level,user_posts,user_rank,arcade_banned FROM '.USERS_TABLE.' WHERE user_id='.$user.' FOR UPDATE');
        if(count($users)!==1||($user!==ANONYMOUS&&empty($users[0]['user_active']))||strtoupper($users[0]['username'])==='TEST'){ $this->storage_fail('arcade_submission_failed'); }
        $actor=$users[0];$id=phpbb_arcade_score_integer(isset($expected['game_id'])?$expected['game_id']:null,1);
        $games=$this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$id.' FOR UPDATE');
        if(count($games)!==1||!hash_equals(phpbb_arcade_game_revision($expected),phpbb_arcade_game_revision($games[0]))){ $this->storage_fail('arcade_submission_failed'); }
        $game=$games[0];if(!is_string($game['game_name'])||$game['game_name']===''||strlen($game['game_name'])>50||preg_match('//u',$game['game_name'])!==1){$this->storage_fail('arcade_submission_failed');}$name=$this->escape($game['game_name']);
        if($this->rows('SELECT game_id FROM '.iNA_GAMES." WHERE game_name='$name' AND game_id<>$id FOR UPDATE")){ $this->storage_fail('arcade_submission_failed'); }
        foreach($this->rows('SELECT config_name,config_value FROM '.iNA.' ORDER BY config_name LOCK IN SHARE MODE')as$row){$this->settings[$row['config_name']]=$row['config_value'];}
        if($this->enabled('games_mod_ban_users')&&(int)$actor['user_level']!==ADMIN&&!empty($actor['arcade_banned'])){$this->storage_fail('arcade_submission_failed');}
        phpbb_arcade_current_access($this,$this->settings,$actor,$game);
        if(empty($game['game_avail'])||empty($game['game_show_score'])||($this->enabled('games_offline')&&(int)$actor['user_level']!==ADMIN)||($user===ANONYMOUS&&(empty($game['allow_guest'])||!$this->enabled('games_guest_highscore')))){ $this->storage_fail('arcade_submission_failed'); }
        $caps=$this->rows('SELECT * FROM '.iNA_SESSIONS." WHERE arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token') FOR UPDATE");$now=time();
        if(count($caps)!==1||(int)$caps[0]['user_id']!==$user||$caps[0]['game_name']!==$game['game_name']||!in_array((int)$caps[0]['page'],array(PAGE_PLAYING_GAMES,PAGE_ARCADE_TOUR),true)||(int)$caps[0]['start_time']<$now-86400||(int)$caps[0]['start_time']>$now+60){ $this->storage_fail('arcade_submission_failed'); }
        $cap=$caps[0];foreach(array('game_name','user_id','start_time','page','tour_id')as$key){if(!array_key_exists($key,$capability)||($capability[$key]!==null&&!is_scalar($capability[$key]))||(string)$capability[$key]!== (string)$cap[$key]){ $this->storage_fail('arcade_submission_failed'); }}
        if($user===ANONYMOUS&&$cap['session_ip']!==$userdata['session_ip']){$this->storage_fail('arcade_submission_failed');}
        $ip=decode_ip($userdata['session_ip']);$username=$actor['username'];$elapsed=max(0,$now-(int)$cap['start_time']);$players=array($user);
        $this->query('DELETE FROM '.iNA_SESSIONS." WHERE arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token')");
        if((int)$this->connection->sql_affectedrows()!==1){ $this->storage_fail('arcade_submission_failed'); }
        if($detected!==null){
            if(!(is_int($detected)||is_string($detected))||!preg_match('/^[1-8]$/D',(string)$detected)){ $this->storage_fail('arcade_submission_failed'); }
            if($profile==='standard'||(int)$actor['user_level']===ADMIN){$this->query('UPDATE '.iNA_GAMES.' SET score_type='.(int)$detected.' WHERE game_id='.$id);}
        }
        $tour=$this->tournament($cap,$game,$user,$score,$now);
        $current=$this->board(iNA_SCORES,$game,$user,$score,$ip,$username,$elapsed,$now,$players);
        $alltime=$user!==ANONYMOUS&&$this->enabled('games_at_highscore')?$this->board(iNA_AT_SCORES,$game,$user,$score,$ip,$username,$elapsed,$now,$players):null;
        $month=$this->monthly($game,$user,$score,$username,$now);
        $bonus=($current['first']?(int)$game['game_bonus']:0)+($alltime&&$alltime['first']?(int)$game['at_game_bonus']:0);
        $reward=$this->reward($game,$user,$score,$bonus,$profile);
        $this->rebuild_rankings($players);
        if($current['first']||($alltime&&$alltime['first'])){$this->query('UPDATE '.iNA_USER_DATA.' SET last_won_date='.$now.' WHERE user_id='.$user);}
        $better=$this->rows('SELECT COUNT(*) n FROM '.iNA_SCORES." WHERE game_name='$name' AND score".(!empty($game['reverse_list'])?'<':'>').$score);
        if($this->enabled('games_use_log')){$log=$this->escape('game='.$game['game_name'].' score='.$score.' protocol='.$profile);$this->query('INSERT INTO '.iNA_LOG." (user_id,name,value,date) VALUES($user,'GAME','$log',$now)");}
        $events=array();foreach(array('current'=>$current,'alltime'=>$alltime)as$key=>$board){if($board&&$board['first']&&$board['previous_winner']>0&&$board['previous_winner']!==$user&&$this->enabled($key==='current'?'games_pm_highscore':'games_pm_at_highscore')){$events[]=array('to'=>$board['previous_winner'],'alltime'=>$key==='alltime');}}
        $this->commit_attempted=true;$this->commit();
        return array('game'=>$game,'username'=>$username,'user_id'=>$user,'score'=>(float)$score,'position'=>1+(int)$better[0]['n'],'current'=>$current,'alltime'=>$alltime,'monthly'=>$month,'bonus'=>$bonus,'reward'=>$reward,'tournament'=>$tour,'notifications'=>$events);
    }
}

function phpbb_arcade_submit_score($database,$game,$capability,$score,$profile,$detected=null)
{
    global $arcade,$phpbb_root_path,$userdata;
    $writer=new PhpbbArcadeSubmissionWriter($database,null);
    try{$result=$writer->submit($game,$capability,$score,$profile,$detected);if($result['reward']!==null){$userdata[$result['reward']['field']]=$result['reward']['balance'];}return $result;}
    finally{try{if($writer->commit_attempted){foreach(array('games','categories','best_player','best_at_player')as$key){try{$arcade->clear_cache($key,$phpbb_root_path);}catch(Exception$ignored){}catch(Error$ignored){}}}}finally{$writer->release();}}
}

function phpbb_arcade_wallet_field($store,$settings,$points)
{
    $field=phpbb_arcade_wallet_name($settings,$points);
    // Both identifiers have been allowlisted, and the owner has pinned the
    // users table for the whole transaction. No credential column is allowed.
    $meta=$store->rows("SELECT DATA_TYPE,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".USERS_TABLE."') AND HEX(COLUMN_NAME)=HEX('$field')");
    if(count($meta)!==1||!in_array(strtolower($meta[0]['DATA_TYPE']),array('tinyint','smallint','mediumint','int','bigint','decimal','float','double'),true)||$meta[0]['COLUMN_KEY']==='PRI'||strpos($meta[0]['EXTRA'],'auto_increment')!==false){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    return $field;
}

function phpbb_arcade_wallet_name($settings,$points)
{
    global $board_config;
    $field=$points?'user_points':(!empty($board_config['default_reward_dbfield'])?$board_config['default_reward_dbfield']:(isset($settings['default_cash'])?$settings['default_cash']:''));
    if(!is_string($field)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D',$field)||in_array(strtolower($field),phpbb_arcade_submission_core_user_fields(),true)){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    return $field;
}

function phpbb_arcade_wallet_label($points,$field)
{
    global $board_config,$lang;
    if($points){return !empty($board_config['points_name'])?$board_config['points_name']:$lang['game_points'];}
    require_once dirname(__FILE__).'/rewards_api.php';
    return get_cash_name($field);
}

function phpbb_arcade_wallet_display($settings,$actor)
{
    global $lang;
    $points=!empty($settings['use_point_system']);
    if(empty($settings['use_rewards_mod'])||(int)$actor['user_id']===ANONYMOUS||(!$points&&empty($settings['use_cash_system'])&&empty($settings['use_allowance_system']))){return '';}
    try{$field=phpbb_arcade_wallet_name($settings,$points);}catch(PhpbbOwnedStorageFailure$error){return '';}
    if(!isset($actor[$field])||!is_scalar($actor[$field])||!is_numeric($actor[$field])||!is_finite((float)$actor[$field])){return '';}
    return sprintf($lang['games_total_points'],$actor[$field],phpbb_profile_text(phpbb_arcade_wallet_label($points,$field)));
}

function phpbb_arcade_current_access($store,$settings,$actor,$game)
{
    $root=(int)$actor['user_level']===ADMIN;$user=(int)$actor['user_id'];
    $number=function($key)use($settings){return isset($settings[$key])?(int)$settings[$key]:0;};
    if((int)$actor['user_posts']<$number('games_posts_required')||(int)$actor['user_rank']<$number('games_rank_required')
        ||(!$root&&($number('games_offline')||($user===ANONYMOUS&&empty($game['allow_guest']))||(int)$actor['user_rank']<(int)$game['rank_required']))){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    $required=(int)$game['level_required'];
    if(!phpbb_arcade_level_allows($actor['user_level'],$required)){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    $groups=array(0=>true);
    foreach($store->rows('SELECT ug.group_id FROM '.USER_GROUP_TABLE.' ug INNER JOIN '.GROUPS_TABLE.' g ON g.group_id=ug.group_id WHERE ug.user_id='.$user.' AND ug.user_pending=0 ORDER BY ug.group_id LOCK IN SHARE MODE')as$row){$groups[(int)$row['group_id']]=true;}
    if(!isset($groups[$number('games_group_required')])||(!$root&&!isset($groups[(int)$game['group_required']]))){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    $cat=(int)$game['cat_id']>0?(int)$game['cat_id']:-1;
    $rows=$store->rows('SELECT cat_id,cat_parent,group_required,cat_type FROM '.iNA_CAT.' WHERE cat_id='.$cat.' LOCK IN SHARE MODE');
    if(count($rows)!==1||$rows[0]['cat_type']==='l'){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}
    $parent=(int)$rows[0]['cat_parent'];
    if($parent>0&&$parent!==$cat){$parents=$store->rows('SELECT cat_id,cat_parent,group_required,cat_type FROM '.iNA_CAT.' WHERE cat_id='.$parent.' LOCK IN SHARE MODE');if(count($parents)!==1||$parents[0]['cat_type']==='l'){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}$rows[]=$parents[0];}
    foreach($rows as$row){if(!$root&&!isset($groups[(int)$row['group_required']])){throw new PhpbbOwnedStorageFailure('arcade_submission_failed');}}
}

function phpbb_arcade_submission_notify($result)
{
    global $board_config,$lang;
    foreach($result['notifications']as$event){
        try{
            $key=$event['alltime']?'games_pm_info_lost_at':'games_pm_info_lost';
            $message=sprintf($lang[$key],$board_config['server_name'].$board_config['script_path'],$result['game']['game_id'],$result['game']['game_desc']);
            ina_send_user_pm($event['to'],$lang['games_important_info'],$message,$result['user_id'],'NO',true);
        }catch(Exception$ignored){}catch(Error$ignored){}
    }
}

function phpbb_arcade_submission_feedback($result)
{
    global $lang,$board_config;
    $current=$result['current'];$alltime=$result['alltime'];
    $text=($result['tournament']?$lang['tour_save']:'').sprintf($lang['game_your_score'],games_position($result['position']),$result['score']);
    if($current['first']){$text.=$lang['game_new_high_score'];}
    $status=$current['changed']?($current['existed']?$lang['game_score_updated']:$lang['game_score_saved']):$lang['game_no_high_score'];
    $text.=sprintf($lang['game_score_text'],phpbb_stored_text($result['username']),$status);
    if($alltime!==null){
        if($alltime['first']){$text.=$lang['game_new_at_high_score'];}
        if($alltime['changed']){$text.=($alltime['existed']?$lang['Your'].' ':'').$lang['game_at_highscores'].' '.($alltime['existed']?$lang['game_score_updated']:$lang['game_score_saved']).'<br />';}
    }elseif($result['user_id']===ANONYMOUS){$text.=$lang['at_score_no_guest'];}
    if($result['reward']!==null){$label=phpbb_arcade_wallet_label($result['reward']['points'],$result['reward']['field']);$text.=sprintf($lang['games_updated_points'],$result['reward']['amount'],phpbb_profile_text($label));}
    return array('saved'=>$text,'first'=>$current['first']||($current['existed']&&$current['changed'])||($alltime!==null&&$alltime['first']),
        'comment'=>$current['changed']||($alltime!==null&&$alltime['changed']),
        'monthly'=>$result['monthly']===null?'':($result['monthly']?$lang['highscore_new_mon_score']:$lang['highscore_no_new_mon_score']));
}

// Core account fields are never wallet extensions. Update this list with the
// canonical user schema; optional wallet columns are validated independently.
function phpbb_arcade_submission_core_user_fields()
{
    return array(
        'user_id','user_active','username','user_password','user_session_time',
        'user_session_page','user_session_topic','user_lastvisit','user_regdate','user_reg_ip',
        'user_reg_host','user_level','user_posts','user_timezone','user_style',
        'user_lang','user_dateformat','user_new_privmsg','user_unread_privmsg','user_last_privmsg',
        'user_login_tries','user_last_login_try','user_emailtime','user_viewemail','user_attachsig',
        'user_setbm','user_allowhtml','user_allowbbcode','user_allowsmile','user_allowavatar',
        'user_allow_pm','user_allow_viewonline','user_notify','user_notify_pm','user_popup_pm',
        'user_rank','user_avatar','user_avatar_type','user_email','user_icq',
        'user_website','user_from','user_from_flag','user_sig','user_sig_bbcode_uid',
        'user_aim','user_yim','user_msnm','user_fb','user_ig',
        'user_pt','user_twr','user_skp','user_tg','user_li',
        'user_tt','user_dc','user_signal','user_threema','user_occ',
        'user_interests','user_actkey','user_newpasswd','ct_last_pw_reset','ct_enable_ip_warn',
        'ct_last_used_ip','ct_last_ip','ct_last_pw_change','ct_global_msg_read','ct_miserable_user',
        'user_sub_forum','user_split_cat','user_last_topic_title','user_sub_level_links','user_display_viewonline',
        'user_birthday','user_next_birthday_greeting','user_gender','user_color_group','user_lastlogon',
        'user_totaltime','user_totallogon','user_totalpages','user_calendar_display_open','user_calendar_header_cells',
        'user_calendar_week_start','user_calendar_nb_row','user_calendar_birthday','user_calendar_forum','user_warnings',
        'user_passwd_change','user_badlogin','user_blocktime','user_block_by','user_split_global_announce',
        'user_split_announce','user_split_sticky','user_split_news','user_split_topic_split','user_absence',
        'user_absence_mode','user_absence_text','user_announcement_date_display','user_announcement_display','user_announcement_display_forum',
        'user_announcement_split','user_announcement_forum','user_use_ajax_preview','user_use_ajax_edit','games_block_pm',
        'arcade_banned',
    );
}
