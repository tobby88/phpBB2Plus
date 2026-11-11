<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_game_storage.php';
require_once dirname(__FILE__) . '/functions_arcade_submission.php';

class PhpbbArcadeLaunchWriter extends PhpbbOwnedStore
{
    public $commit_attempted = false;

    protected function storage_tables()
    {
        return array(SESSIONS_TABLE, USERS_TABLE, iNA_GAMES, iNA_CAT, iNA_SESSIONS, iNA_USER_DATA,
            iNA, GROUPS_TABLE, USER_GROUP_TABLE, iNA_TOUR, iNA_TOUR_DATA, iNA_TOUR_PLAY);
    }

    function record($expected, $hash)
    {
        global $userdata, $arcade;
        if (!is_array($expected) || !isset($expected['game_id'])
            || !(is_int($expected['game_id']) || is_string($expected['game_id']))
            || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$expected['game_id'])
            || (int)$expected['game_id'] > 8388607 || !is_string($hash)
            || !preg_match('/^[a-f0-9]{32}$/D', $hash)
            || !isset($userdata['user_id'], $userdata['session_id'])
            || !(is_int($userdata['user_id']) || is_string($userdata['user_id']))
            || !preg_match('/^(?:-1|[1-9][0-9]{0,7})$/D', (string)$userdata['user_id'])
            || (float)$userdata['user_id'] > 16777215 || !is_string($userdata['session_id'])
            || $userdata['session_id'] === '' || strlen($userdata['session_id']) > 32) {
            $this->storage_fail('arcade_launch_failed');
        }
        $id = (int)$expected['game_id']; $user = (int)$userdata['user_id'];
        $sid = $this->escape($userdata['session_id']);
        $sessions = $this->rows('SELECT session_user_id,session_logged_in FROM '.SESSIONS_TABLE.
            " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') LOCK IN SHARE MODE");
        if (count($sessions) !== 1 || (int)$sessions[0]['session_user_id'] !== $user
            || (int)$sessions[0]['session_logged_in'] !== ($user === ANONYMOUS ? 0 : 1)) {
            $this->storage_fail('arcade_launch_failed');
        }
        $users = $this->rows('SELECT user_id,user_active,user_rank,user_posts,user_level,arcade_banned FROM '.USERS_TABLE.
            ' WHERE user_id='.$user.' FOR UPDATE');
        if (count($users)!==1 || ($user!==ANONYMOUS&&empty($users[0]['user_active']))) { $this->storage_fail('arcade_launch_failed'); }
        $actor=$users[0];
        // Same game -> category order as resets and catalogue mutations. The
        // selected asset and access fields must still describe this game.
        $games = $this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$id.' FOR UPDATE');
        if (count($games) !== 1 || !hash_equals(phpbb_arcade_game_revision($expected), phpbb_arcade_game_revision($games[0]))) {
            $this->storage_fail('arcade_launch_failed');
        }
        $game = $games[0];
        if (!is_string($game['game_name']) || $game['game_name'] === '') { $this->storage_fail('arcade_launch_failed'); }
        $settings=array();foreach($this->rows('SELECT config_name,config_value FROM '.iNA.' ORDER BY config_name LOCK IN SHARE MODE')as$row){$settings[$row['config_name']]=$row['config_value'];}
        phpbb_arcade_current_access($this,$settings,$actor,$game);
        $categories = $this->rows('SELECT cat_id,cat_parent,cat_type FROM '.iNA_CAT.' ORDER BY cat_id FOR UPDATE');
        $by_id = array(); foreach ($categories as $row) { $by_id[(int)$row['cat_id']] = $row; }
        $category = (int)$game['cat_id'] > 0 ? (int)$game['cat_id'] : -1;
        $wanted = array(-1=>true, $category=>true);
        if (isset($by_id[$category]) && (int)$by_id[$category]['cat_parent'] > 0) {
            $wanted[(int)$by_id[$category]['cat_parent']] = true;
        }
        foreach ($wanted as $cat=>$unused) {
            if (!isset($by_id[$cat]) || $by_id[$cat]['cat_type'] === 'l') { $this->storage_fail('arcade_launch_failed'); }
        }
        $name = $this->escape($game['game_name']); $token = $this->escape($hash); $now = time();
        $capabilities = $this->rows('SELECT user_id,game_name,start_time,page,tour_id,session_ip FROM '.iNA_SESSIONS.
            " WHERE arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token') FOR UPDATE");
        if (count($capabilities) !== 1 || (int)$capabilities[0]['user_id'] !== $user
            || $capabilities[0]['game_name'] !== $game['game_name']
            || (int)$capabilities[0]['page']!==PAGE_ARCADE_LAUNCH_PENDING
            || (int)$capabilities[0]['start_time'] < $now - 86400 || (int)$capabilities[0]['start_time'] > $now + 60) {
            $this->storage_fail('arcade_launch_failed');
        }
        $cap=$capabilities[0];$tour=(int)$cap['tour_id'];$charge=null;
        if($user===ANONYMOUS&&(!isset($userdata['session_ip'])||!is_string($userdata['session_ip'])||$cap['session_ip']!==$userdata['session_ip'])){$this->storage_fail('arcade_launch_failed');}
        if(!empty($settings['games_mod_ban_users'])&&(int)$actor['user_level']!==ADMIN&&!empty($actor['arcade_banned'])){$this->storage_fail('arcade_launch_failed');}
        if($tour>0){$this->record_tournament_turn($tour,$game,$user,$now);}
        elseif(!empty($settings['use_rewards_mod'])&&(int)$game['game_charge']>0&&(!empty($settings['use_point_system'])||!empty($settings['use_cash_system'])||!empty($settings['use_allowance_system']))){
            if($user===ANONYMOUS){$this->storage_fail('arcade_launch_failed');}
            $field=phpbb_arcade_wallet_field($this,$settings,!empty($settings['use_point_system']));$amount=(int)$game['game_charge'];
            $this->query('UPDATE '.USERS_TABLE." SET `$field`=`$field`-$amount WHERE user_id=$user AND `$field` >= $amount");
            if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('arcade_launch_funds');}
            $balance=$this->rows('SELECT `'.$field.'` balance FROM '.USERS_TABLE.' WHERE user_id='.$user);
            $charge=array('field'=>$field,'amount'=>$amount,'balance'=>$balance[0]['balance']);
        }
        $this->query('UPDATE '.iNA_GAMES.' SET played=played+1 WHERE game_id='.$id);
        foreach ($wanted as $cat=>$unused) {
            $this->query('UPDATE '.iNA_CAT." SET total_played=total_played+1,last_game='$name',last_player=$user,last_time=$now WHERE cat_id=".$cat);
        }
        // user_id is a legacy nonunique index, not an UPSERT key. Existence,
        // not affectedrows (zero for a same-second repeat), decides insertion.
        $players = $this->rows('SELECT user_id FROM '.iNA_USER_DATA.' WHERE user_id='.$user.' FOR UPDATE');
        if ($players) {
            $this->query('UPDATE '.iNA_USER_DATA." SET last_played='$name',last_played_date=$now WHERE user_id=$user");
        } else {
            $this->query('INSERT INTO '.iNA_USER_DATA." (user_id,last_played,last_played_date) VALUES($user,'$name',$now)");
        }
        $target=$tour>0?PAGE_ARCADE_TOUR:PAGE_PLAYING_GAMES;
        $this->query('UPDATE '.iNA_SESSIONS.' SET page='.$target." WHERE arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token')");
        if((int)$this->connection->sql_affectedrows()!==1){$this->storage_fail('arcade_launch_failed');}
        $scope=$user===ANONYMOUS?" AND session_ip='".$this->escape($cap['session_ip'])."'":'';
        $this->query('DELETE FROM '.iNA_SESSIONS." WHERE user_id=$user$scope AND page IN (".PAGE_PLAYING_GAMES.','.PAGE_ARCADE_TOUR.") AND (arcade_hash IS NULL OR HEX(arcade_hash)<>HEX('$token'))");
        $this->commit_attempted = true; $this->commit();
        return array('recorded'=>true, 'game_id'=>$id, 'charge'=>$charge);
    }

    protected function record_tournament_turn($tour,$game,$user,$now)
    {
        if($user===ANONYMOUS){$this->storage_fail('arcade_launch_failed');}
        $events=$this->rows('SELECT tour_active,tour_player_turns FROM '.iNA_TOUR.' WHERE tour_id='.$tour.' LOCK IN SHARE MODE');
        $games=$this->rows('SELECT game_name FROM '.iNA_TOUR_DATA.' WHERE tour_id='.$tour." AND game_name='".$this->escape($game['game_name'])."' LOCK IN SHARE MODE");
        $members=$this->rows('SELECT gamedata FROM '.iNA_TOUR_PLAY.' WHERE tour_id='.$tour.' AND user_id='.$user.' FOR UPDATE');
        if(count($events)!==1||(int)$events[0]['tour_active']!==2||count($games)!==1||count($members)!==1){$this->storage_fail('arcade_launch_failed');}
        $data=phpbb_safe_unserialize_array($members[0]['gamedata']);$found=0;
        foreach($data as&$entry){if(!is_array($entry)||!isset($entry['game_name'])||$entry['game_name']!==$game['game_name']){continue;}$found++;$played=isset($entry['played'])?(int)$entry['played']:0;if($played<0||$played>=(int)$events[0]['tour_player_turns']){$this->storage_fail('arcade_launch_failed');}$entry['played']=$played+1;}
        unset($entry);
        if($found===0){if((int)$events[0]['tour_player_turns']<1){$this->storage_fail('arcade_launch_failed');}$data[]=array('game_name'=>$game['game_name'],'score'=>0,'played'=>1,'score_recorded'=>false);}
        elseif($found!==1){$this->storage_fail('arcade_launch_failed');}
        $serialized=serialize($data);if(strlen($serialized)>65535){$this->storage_fail('arcade_launch_failed');}
        $this->query('UPDATE '.iNA_TOUR_PLAY." SET gamedata='".$this->escape($serialized)."',last_played_game='".$this->escape($game['game_name'])."',last_played_time=$now WHERE tour_id=$tour AND user_id=$user");
    }
}

function phpbb_arcade_record_launch($database, $game, $hash)
{
    global $arcade, $phpbb_root_path, $userdata;
    $writer = new PhpbbArcadeLaunchWriter($database, null);
    try { $result=$writer->record($game, $hash);if($result['charge']!==null){$userdata[$result['charge']['field']]=$result['charge']['balance'];}return $result; }
    finally {
        try {
            // Also discard stale counters if COMMIT succeeded but its reply
            // was lost. Never replay accounting after an uncertain reply.
            if ($writer->commit_attempted) {
                foreach (array('games','categories') as $key) {
                    try { $arcade->clear_cache($key, $phpbb_root_path); }
                    catch (Exception $ignored) {} catch (Error $ignored) {}
                }
            }
        } finally { $writer->release(); }
    }
}
