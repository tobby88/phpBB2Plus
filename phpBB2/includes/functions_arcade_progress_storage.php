<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_submission.php';

// Flash progress is opaque UTF-8 protocol data, not HTML or a score. Preserve
// whitespace, entities, quotes, zero and empty saves exactly; never truncate.
function phpbb_arcade_progress_request($get, $post, $key, $default = null)
{
    $source = is_array($get) && array_key_exists($key, $get) ? $get : $post;
    if ((!is_array($source) || !array_key_exists($key, $source)) && is_string($default)) { return $default; }
    if (!is_array($source) || !array_key_exists($key, $source) || !is_string($source[$key])) {
        throw new PhpbbOwnedStorageFailure('arcade_submission_failed');
    }
    return phpbb_request_raw_value($source[$key]);
}

require_once dirname(__FILE__).'/functions_arcade_progress_schema.php';

function phpbb_arcade_progress_validate($mode, $hash, $game_id, $data, $actor)
{
    if (!in_array($mode, array('loadGame','saveGame'), true) || !is_string($hash)
        || ($hash !== '' && !preg_match('/^[a-f0-9]{32}$/D', $hash)) || !is_string($game_id)
        || !preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', $game_id) || (float)$game_id > 8388607
        || ($mode === 'saveGame' && (!is_string($data) || strlen($data)>65535 || preg_match('//u',$data)!==1))
        || !is_array($actor) || !isset($actor['user_id'],$actor['session_id']) || !(is_int($actor['user_id'])||is_string($actor['user_id']))
        || !preg_match('/^[1-9][0-9]{0,7}$/D',(string)$actor['user_id']) || (float)$actor['user_id']>16777215
        || !is_string($actor['session_id']) || $actor['session_id']==='' || strlen($actor['session_id'])>32) {
        throw new PhpbbOwnedStorageFailure('arcade_submission_failed');
    }
}

class PhpbbArcadeProgressWriter extends PhpbbOwnedStore
{
    public $commit_attempted = false;
    protected function storage_tables()
    {
        return array(USERS_TABLE, SESSIONS_TABLE, iNA, iNA_GAMES, iNA_SESSIONS, iNA_SCORES,
            iNA_GAME_SAVES, GROUPS_TABLE, USER_GROUP_TABLE, iNA_CAT, iNA_TOUR, iNA_TOUR_DATA, iNA_TOUR_PLAY, iNA_LOG);
    }
    protected function pin_tables()
    {
        parent::pin_tables();
        if (!phpbb_arcade_progress_schema_matches($this->rows('SHOW FULL COLUMNS FROM '.iNA_GAME_SAVES), $this->rows('SHOW INDEX FROM '.iNA_GAME_SAVES))) { $this->storage_fail('Owned_storage_upgrade'); }
        $table = $this->escape(iNA_GAME_SAVES);
        if ($this->rows("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='$table'")
            || $this->rows("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND REFERENCED_TABLE_NAME IS NOT NULL")
            || $this->rows("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='$table'")) { $this->storage_fail('Owned_storage_upgrade'); }
    }
    function transfer($mode, $hash, $game_id, $data)
    {
        global $userdata;
        phpbb_arcade_progress_validate($mode,$hash,$game_id,$data,$userdata);
        $user = (int)$userdata['user_id']; $sid = $this->escape($userdata['session_id']);
        $sessions = $this->rows('SELECT session_user_id,session_logged_in FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') LOCK IN SHARE MODE");
        $users = $this->rows('SELECT user_id,user_active,user_level,user_posts,user_rank,arcade_banned FROM '.USERS_TABLE.' WHERE user_id='.$user.' LOCK IN SHARE MODE');
        if (count($sessions)!==1 || (int)$sessions[0]['session_user_id']!==$user || (int)$sessions[0]['session_logged_in']!==1 || count($users)!==1 || empty($users[0]['user_active'])) { $this->storage_fail('arcade_submission_failed'); }
        $now = time(); $where = 'user_id='.$user.' AND page IN ('.PAGE_PLAYING_GAMES.','.PAGE_ARCADE_TOUR.') AND start_time>='.($now-86400).' AND start_time<='.($now+60);
        if ($hash !== '') { $token = $this->escape($hash); $where .= " AND arcade_hash='$token' AND HEX(arcade_hash)=HEX('$token')"; }
        // An explicit gid disambiguates games using the historical no-token
        // protocol. A mismatched gid can never redirect another game's save.
        $caps = $this->rows('SELECT * FROM '.iNA_SESSIONS.' WHERE '.$where.' ORDER BY start_time DESC LOCK IN SHARE MODE');
        if (!$caps) { $this->storage_fail('arcade_submission_failed'); }
        if ($hash==='' && $game_id==='0' && count($caps)>1 && (string)$caps[0]['start_time']===(string)$caps[1]['start_time']) { $this->storage_fail('arcade_submission_failed'); }
        $cap = null; $game = null;
        foreach ($caps as $candidate) {
            if (!isset($candidate['game_name']) || !is_string($candidate['game_name']) || $candidate['game_name']==='' || strlen($candidate['game_name'])>50 || preg_match('//u',$candidate['game_name'])!==1) { $this->storage_fail('arcade_submission_failed'); }
            $name = $this->escape($candidate['game_name']);
            $games = $this->rows('SELECT * FROM '.iNA_GAMES." WHERE game_name='$name' AND HEX(game_name)=HEX('$name') LOCK IN SHARE MODE");
            if (count($games)!==1) { $this->storage_fail('arcade_submission_failed'); }
            phpbb_arcade_game_revision($games[0]);
            if ($game_id !== '0' && (string)$games[0]['game_id'] !== $game_id) { continue; }
            if ($cap !== null) {
                if ($hash!=='' || (string)$cap['start_time']===(string)$candidate['start_time']) { $this->storage_fail('arcade_submission_failed'); }
                break;
            }
            $cap = $candidate; $game = $games[0];
            if ($hash === '' && $game_id === '0') { break; }
        }
        if (!$cap || !$game || !isset($cap['arcade_hash']) || !is_string($cap['arcade_hash']) || !preg_match('/^[a-f0-9]{32}$/D', $cap['arcade_hash']) || empty($game['game_avail'])) { $this->storage_fail('arcade_submission_failed'); }
        $settings = array(); foreach ($this->rows('SELECT config_name,config_value FROM '.iNA.' ORDER BY config_name LOCK IN SHARE MODE') as $row) { $settings[$row['config_name']] = $row['config_value']; }
        if (!empty($settings['games_mod_ban_users']) && (int)$users[0]['user_level']!==ADMIN && !empty($users[0]['arcade_banned'])) { $this->storage_fail('arcade_submission_failed'); }
        phpbb_arcade_current_access($this, $settings, $users[0], $game);
        if ((int)$cap['page']===PAGE_ARCADE_TOUR) {
            $tour = (int)$cap['tour_id']; $name = $this->escape($game['game_name']);
            $events = $this->rows('SELECT tour_active FROM '.iNA_TOUR.' WHERE tour_id='.$tour.' LOCK IN SHARE MODE');
            $members = $this->rows('SELECT user_id FROM '.iNA_TOUR_PLAY.' WHERE tour_id='.$tour.' AND user_id='.$user.' LOCK IN SHARE MODE');
            $entries = $this->rows('SELECT game_name FROM '.iNA_TOUR_DATA." WHERE tour_id=$tour AND game_name='$name' AND HEX(game_name)=HEX('$name') LOCK IN SHARE MODE");
            if ($tour<1 || count($events)!==1 || (int)$events[0]['tour_active']!==2 || count($members)!==1 || count($entries)!==1) { $this->storage_fail('arcade_submission_failed'); }
        }
        $id = (int)$game['game_id'];
        if ($id < 1 || $id > 8388607) { $this->storage_fail('arcade_submission_failed'); }
        if ($mode === 'saveGame') {
            $value = $this->escape($data);
            $this->query('INSERT INTO '.iNA_GAME_SAVES." (user_id,game_id,game_data,saved_at) VALUES($user,$id,'$value',$now) ON DUPLICATE KEY UPDATE game_data=VALUES(game_data),saved_at=VALUES(saved_at)");
            $check = $this->rows('SELECT game_data FROM '.iNA_GAME_SAVES." WHERE user_id=$user AND game_id=$id FOR UPDATE");
            if (count($check)!==1 || $check[0]['game_data']!==$data) { $this->storage_fail('arcade_submission_failed'); }
        } else {
            $saved = $this->rows('SELECT game_data FROM '.iNA_GAME_SAVES." WHERE user_id=$user AND game_id=$id LOCK IN SHARE MODE");
            if ($saved) { $data = $saved[0]['game_data']; }
            else {
                // Read historical progress without rewriting the score table.
                // Prefer the best score, newest date, then stable data bytes
                // if old installations contain duplicate leaderboard rows.
                $name = $this->escape($game['game_name']);
                $legacy = $this->rows('SELECT gameData FROM '.iNA_SCORES." WHERE player_id=$user AND game_name='$name' AND HEX(game_name)=HEX('$name') AND gameData IS NOT NULL ORDER BY score ".(!empty($game['reverse_list'])?'ASC':'DESC').',date DESC,BINARY gameData ASC LIMIT 1 LOCK IN SHARE MODE');
                $data = $legacy ? $legacy[0]['gameData'] : '';
            }
            if (!is_string($data) || strlen($data)>65535 || preg_match('//u', $data)!==1) { $this->storage_fail('arcade_submission_failed'); }
        }
        if (!empty($settings['games_use_log'])) {
            $message = $this->escape('pnFlashGames action='.$mode.' game_id='.$id);
            $this->query('INSERT INTO '.iNA_LOG." (user_id,name,value,date) VALUES($user,'GAME','$message',$now)");
        }
        $this->commit_attempted = true; $this->commit();
        return $data;
    }
}

function phpbb_arcade_progress_transfer($db, $mode, $hash, $game_id, $data = null)
{
    global $userdata;
    phpbb_arcade_progress_validate($mode,$hash,$game_id,$data,$userdata);
    $store = null;
    try { $store = new PhpbbArcadeProgressWriter($db, 0); return $store->transfer($mode, $hash, $game_id, $data); }
    finally { if ($store) { $store->release(); } }
}
