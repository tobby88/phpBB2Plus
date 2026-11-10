<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_game_storage.php';

class PhpbbArcadeLaunchWriter extends PhpbbOwnedStore
{
    public $commit_attempted = false;

    protected function storage_tables()
    {
        return array(SESSIONS_TABLE, USERS_TABLE, iNA_GAMES, iNA_CAT, iNA_SESSIONS, iNA_USER_DATA);
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
        $actor = array('user_rank'=>0, 'user_posts'=>0, 'user_level'=>0);
        if ($user !== ANONYMOUS) {
            $users = $this->rows('SELECT user_active,user_rank,user_posts,user_level FROM '.USERS_TABLE.
                ' WHERE user_id='.$user.' LOCK IN SHARE MODE');
            if (count($users) !== 1 || empty($users[0]['user_active'])) { $this->storage_fail('arcade_launch_failed'); }
            $actor = $users[0];
        }
        // Same game -> category order as resets and catalogue mutations. The
        // selected asset and access fields must still describe this game.
        $games = $this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$id.' FOR UPDATE');
        if (count($games) !== 1 || !hash_equals(phpbb_arcade_game_revision($expected), phpbb_arcade_game_revision($games[0]))) {
            $this->storage_fail('arcade_launch_failed');
        }
        $game = $games[0];
        if (!is_string($game['game_name']) || $game['game_name'] === '') { $this->storage_fail('arcade_launch_failed'); }
        if ((int)$actor['user_posts'] < (int)$arcade->arcade_config['games_posts_required']
            || (isset($arcade->arcade_config['games_rank_required']) && (int)$actor['user_rank'] < (int)$arcade->arcade_config['games_rank_required'])
            || ((int)$actor['user_level'] !== ADMIN && (
                (!empty($arcade->arcade_config['games_offline']))
                || ($user === ANONYMOUS && empty($game['allow_guest']))
                || (int)$actor['user_rank'] < (int)$game['rank_required']))) {
            $this->storage_fail('arcade_launch_failed');
        }
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
        $capabilities = $this->rows('SELECT user_id,game_name,start_time FROM '.iNA_SESSIONS.
            " WHERE arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token') LOCK IN SHARE MODE");
        if (count($capabilities) !== 1 || (int)$capabilities[0]['user_id'] !== $user
            || $capabilities[0]['game_name'] !== $game['game_name']
            || (int)$capabilities[0]['start_time'] < $now - 86400 || (int)$capabilities[0]['start_time'] > $now + 60) {
            $this->storage_fail('arcade_launch_failed');
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
        $this->commit_attempted = true; $this->commit();
        return array('recorded'=>true, 'game_id'=>$id);
    }
}

function phpbb_arcade_record_launch($database, $game, $hash)
{
    global $arcade, $phpbb_root_path;
    $writer = new PhpbbArcadeLaunchWriter($database, null);
    try { return $writer->record($game, $hash); }
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
