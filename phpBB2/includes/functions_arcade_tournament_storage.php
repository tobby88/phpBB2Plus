<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_arcade_submission.php';

function phpbb_arcade_tournament_join_ids($ids)
{
    if (!is_array($ids) || count($ids)>100) { throw new PhpbbOwnedStorageFailure('tour_no_join'); }
    $out = array();
    foreach ($ids as $id) {
        if (!(is_int($id) || is_string($id)) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$id) || (int)$id>8388607) {
            throw new PhpbbOwnedStorageFailure('tour_no_join');
        }
        $out[(int)$id] = (int)$id;
    }
    return array_values($out);
}

function phpbb_arcade_tournament_join_request()
{
    global $userdata, $HTTP_POST_VARS;
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST'
        || !is_array($userdata) || empty($userdata['session_logged_in']) || !isset($userdata['user_id'], $userdata['session_id'])
        || !(is_int($userdata['user_id']) || is_string($userdata['user_id']))
        || !preg_match('/^[1-9][0-9]{0,7}$/D', (string)$userdata['user_id']) || (float)$userdata['user_id']>16777215
        || !is_string($userdata['session_id']) || $userdata['session_id']==='' || strlen($userdata['session_id'])>32
        || !isset($HTTP_POST_VARS['sid']) || !is_string($HTTP_POST_VARS['sid'])
        || !hash_equals($userdata['session_id'], phpbb_request_raw_value($HTTP_POST_VARS['sid']))
        || !phpbb_request_source_is_same_origin()) {
        throw new PhpbbOwnedStorageFailure('Session_invalid');
    }
}

class PhpbbArcadeTournamentJoinWriter extends PhpbbOwnedStore
{
    public $commit_attempted = false;
    protected function storage_tables()
    {
        return array(USERS_TABLE, SESSIONS_TABLE, iNA, GROUPS_TABLE, USER_GROUP_TABLE, iNA_CAT, iNA_TOUR, iNA_TOUR_PLAY, iNA_LOG);
    }
    function join($ids)
    {
        global $userdata;
        $user = (int)$userdata['user_id']; $sid = $this->escape($userdata['session_id']);
        $sessions = $this->rows('SELECT session_user_id,session_logged_in FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') LOCK IN SHARE MODE");
        $actors = $this->rows('SELECT user_id,user_active,user_level,user_posts,user_rank,arcade_banned FROM '.USERS_TABLE.' WHERE user_id='.$user.' LOCK IN SHARE MODE');
        if (count($sessions)!==1 || (int)$sessions[0]['session_user_id']!==$user || (int)$sessions[0]['session_logged_in']!==1
            || count($actors)!==1 || empty($actors[0]['user_active'])) { $this->storage_fail('Not_Authorised'); }
        $settings = array();
        foreach ($this->rows('SELECT config_name,config_value FROM '.iNA.' ORDER BY config_name LOCK IN SHARE MODE') as $row) { $settings[$row['config_name']] = $row['config_value']; }
        if (!empty($settings['games_mod_ban_users']) && (int)$actors[0]['user_level']!==ADMIN && !empty($actors[0]['arcade_banned'])) { $this->storage_fail('Not_Authorised'); }
        // Joining has no game-specific requirements, but must obey the same
        // current global and all-games-category access as playing a game.
        phpbb_arcade_current_access($this, $settings, $actors[0], array('allow_guest'=>0, 'rank_required'=>0,
            'level_required'=>0, 'group_required'=>0, 'cat_id'=>-1));
        $ordered = $ids; sort($ordered, SORT_NUMERIC); $tours = array();
        foreach ($this->rows('SELECT tour_id,tour_name,tour_active,tour_max_players FROM '.iNA_TOUR.' WHERE tour_id IN ('.implode(',', $ordered).') ORDER BY tour_id FOR UPDATE') as $row) {
            $tours[(int)$row['tour_id']] = $row;
        }
        $results = array();
        foreach ($ids as $id) {
            if (!isset($tours[$id]) || (int)$tours[$id]['tour_active']!==2) { continue; }
            $tour = $tours[$id];
            $players = $this->rows('SELECT user_id FROM '.iNA_TOUR_PLAY.' WHERE tour_id='.$id.' ORDER BY user_id FOR UPDATE');
            $member = false; foreach ($players as $player) { if ((int)$player['user_id']===$user) { $member = true; } }
            $status = 'tour_member';
            if (!$member) {
                $status = 'tour_full';
                if (count($players)<(int)$tour['tour_max_players']) {
                    // No IGNORE: a failed insert must never become a successful
                    // enrolment message. The event row serializes its last seat.
                    $this->query('INSERT INTO '.iNA_TOUR_PLAY.' (tour_id,user_id) VALUES('.$id.','.$user.')');
                    if ((int)$this->connection->sql_affectedrows()!==1) { $this->storage_fail('no_tour_play_data'); }
                    if (!empty($settings['games_use_log'])) {
                        $this->query('INSERT INTO '.iNA_LOG." (user_id,name,value,date) VALUES($user,'GAME','Tournament join id=$id',".time().')');
                    }
                    $status = 'tour_joined';
                }
            }
            $results[] = array('status'=>$status, 'name'=>(string)$tour['tour_name']);
        }
        $this->commit_attempted = true; $this->commit();
        return $results;
    }
}

function phpbb_arcade_tournament_join($database, $ids)
{
    global $arcade, $phpbb_root_path;
    $ids = phpbb_arcade_tournament_join_ids($ids); phpbb_arcade_tournament_join_request();
    if (!$ids) { return array(); }
    $writer = null;
    try { $writer = new PhpbbArcadeTournamentJoinWriter($database, 0); return $writer->join($ids); }
    finally {
        if ($writer) {
            try {
                if ($writer->commit_attempted) {
                    try { $arcade->clear_cache('tournaments', $phpbb_root_path); } catch (Exception $ignored) {} catch (Error $ignored) {}
                }
            } finally { $writer->release(); }
        }
    }
}
