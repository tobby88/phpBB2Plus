<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_game_storage.php';

function phpbb_arcade_reset_revision($games, $category, $board)
{
    if (!is_array($games) || !is_int($category) || $category < 0 || $category > 8388607 || !in_array($board, array('current','all_time'), true)) { throw new PhpbbOwnedStorageFailure('arcade_reset_changed'); }
    $pins = array();
    foreach ($games as $game) {
        if (!is_array($game) || !isset($game['game_id']) || !(is_string($game['game_id']) || is_int($game['game_id'])) || !preg_match('/^-?[0-9]{1,7}$/D', (string)$game['game_id']) || !array_key_exists('cat_id', $game) || ($game['cat_id'] !== null && !is_scalar($game['cat_id']))) { throw new PhpbbOwnedStorageFailure('arcade_reset_changed'); }
        $id = (int)$game['game_id'];
        if ($id < -8388608 || $id > 8388607 || isset($pins[$id]) || ($category && (int)$game['cat_id'] !== $category)) { throw new PhpbbOwnedStorageFailure('arcade_reset_changed'); }
        $pins[$id] = phpbb_arcade_game_revision($game);
    }
    ksort($pins, SORT_NUMERIC); $json = json_encode(array($category, $board, $pins));
    if ($json === false) { throw new PhpbbOwnedStorageFailure('arcade_reset_changed'); }
    return hash('sha256', $json);
}

function phpbb_arcade_reset_snapshot($db, $category, $board)
{
    global $phpEx;
    phpbb_arcade_reset_revision(array(), $category, $board);
    phpbb_acp_actor($db, 'admin_arcade_games.'.$phpEx.'?mode=edit_games');
    if ($category) {
        $result = $db->sql_query('SELECT cat_id FROM '.iNA_CAT.' WHERE cat_id='.$category." AND cat_type<>'l'");
        if (!$result) { throw new PhpbbOwnedStorageFailure('no_cat_data'); }
        try { $found = $db->sql_fetchrow($result); } finally { $db->sql_freeresult($result); }
        if (!$found) { throw new PhpbbOwnedStorageFailure('no_cat_data'); }
    }
    $result = $db->sql_query('SELECT * FROM '.iNA_GAMES.($category ? ' WHERE cat_id='.$category : '').' ORDER BY game_id');
    if (!$result) { throw new PhpbbOwnedStorageFailure('no_game_data'); }
    try { $games = $db->sql_fetchrowset($result); } finally { $db->sql_freeresult($result); }
    return phpbb_arcade_reset_revision($games, $category, $board);
}

class PhpbbArcadeActionWriter extends PhpbbArcadeGameWriter
{
    protected function actor()
    {
        global $phpEx;
        return phpbb_acp_actor($this, 'admin_arcade_games.'.$phpEx.'?mode=edit_games');
    }
    protected function lock_actor()
    {
        global $userdata;
        $actor = $this->actor(); $sid = $this->escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'],
            'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) { $this->rows($sql.' LOCK IN SHARE MODE'); }
        $this->actor();
    }
    protected function identity_name($game)
    {
        if (!is_string($game['game_name']) || $game['game_name'] === '' || preg_match('//u', $game['game_name']) !== 1) { $this->storage_fail('arcade_game_changed'); }
        $name = $this->escape($game['game_name']);
        if ($this->rows('SELECT game_id FROM '.iNA_GAMES." WHERE game_name='$name' AND game_id<>".(int)$game['game_id'].' ORDER BY game_id FOR UPDATE')) { $this->storage_fail('arcade_game_changed'); }
        return $name;
    }
    protected function affected_players($games, $tables)
    {
        $ids = array();
        foreach ($games as $game) {
            foreach ($tables as $table => $field) {
                if ((int)$game[$field] > 0) { $ids[] = (int)$game[$field]; }
                foreach ($this->ranked_players($table, $game['game_name'], !empty($game['reverse_list'])) as $row) {
                    if ((int)$row['player_id'] > 0) { $ids[] = (int)$row['player_id']; }
                }
            }
        }
        return $ids;
    }
    function remove($revision)
    {
        if ($this->game_id < 1 || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { $this->storage_fail('arcade_game_changed'); }
        $this->lock_actor();
        $rows = $this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$this->game_id.' FOR UPDATE');
        if (count($rows) !== 1 || !hash_equals(phpbb_arcade_game_revision($rows[0]), $revision)) { $this->storage_fail('arcade_game_changed'); }
        $game = $rows[0]; $name = $this->identity_name($game); $category = (int)$game['cat_id'];
        $present = $this->game_category_topology();
        $wanted = array_keys($this->game_play_categories($category, $present));
        $played = $this->game_play_count($game);
        $players = $this->affected_players(array($game), array(iNA_SCORES=>'highscore_id', iNA_AT_SCORES=>'at_highscore_id'));
        // Stable game IDs preserve saves across renames and leaderboard
        // resets; deleting the game itself removes its private checkpoints.
        $this->query('DELETE FROM '.iNA_GAME_SAVES.' WHERE game_id='.$this->game_id);
        foreach (array(array(iNA_SCORES,'game_name'), array(iNA_AT_SCORES,'game_name'), array(iNA_GAMES_COMMENT,'comment_game_name'),
            array(iNA_GAMES_COMMENT,'game_name'), array(iNA_GAMES_RATE,'rate_game_name'), array(iNA_SESSIONS,'game_name'), array(iNA_TOUR_DATA,'game_name')) as $ref) {
            $this->query('DELETE FROM '.$ref[0].' WHERE '.$ref[1]."='$name'");
        }
        $this->query('DELETE FROM '.iNA_FAV.' WHERE fav_game_id='.$this->game_id." OR fav_game_name='$name'");
        foreach (array(array(iNA_TOUR_PLAY,'last_played_game'), array(iNA_USER_DATA,'last_played')) as $ref) { $this->query('UPDATE '.$ref[0].' SET '.$ref[1]."=NULL WHERE ".$ref[1]."='$name'"); }
        $this->query('UPDATE '.iNA_CAT." SET last_game=NULL,last_player=NULL,last_time=NULL WHERE last_game='$name'");
        $this->query('DELETE FROM '.iNA_GAMES.' WHERE game_id='.$this->game_id);
        if ((int)$this->connection->sql_affectedrows() !== 1) { $this->storage_fail('arcade_game_changed'); }
        foreach ($wanted as $id) {
            if (!isset($present[$id])) { continue; }
            // Offline games still contributed their historical plays.
            $set = $this->game_counter_subtract('total_played', $played);
            if (!empty($game['game_avail']) && ($id === -1 || $id === $category)) { $set .= ','.$this->game_counter_subtract('total_games', '1'); }
            $this->query('UPDATE '.iNA_CAT.' SET '.$set.' WHERE cat_id='.$id);
        }
        $this->rebuild_rankings($players);
        $this->commit_attempted = true; $this->commit(); return array('removed'=>true, 'id'=>$this->game_id);
    }
    function reset_scores($category, $board, $revision)
    {
        phpbb_arcade_reset_revision(array(), $category, $board);
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { $this->storage_fail('arcade_reset_changed'); }
        $this->lock_actor();
        if ($category) {
            $categories = $this->rows('SELECT cat_id,cat_type FROM '.iNA_CAT.' WHERE cat_id='.$category.' LOCK IN SHARE MODE');
            if (count($categories) !== 1 || $categories[0]['cat_type'] === 'l') { $this->storage_fail('no_cat_data'); }
        }
        $games = $this->rows('SELECT * FROM '.iNA_GAMES.($category ? ' WHERE cat_id='.$category : '').' ORDER BY game_id FOR UPDATE');
        if (!hash_equals(phpbb_arcade_reset_revision($games, $category, $board), $revision)) { $this->storage_fail('arcade_reset_changed'); }
        $table = $board === 'current' ? iNA_SCORES : iNA_AT_SCORES; $field = $board === 'current' ? 'highscore_id' : 'at_highscore_id';
        if ($category) {
            $names = array(); foreach ($games as $game) { $names[] = "'".$this->identity_name($game)."'"; }
            $players = $this->affected_players($games, array($table=>$field));
            if ($names) { $this->query('DELETE FROM '.$table.' WHERE game_name IN ('.implode(',', $names).')'); }
            $this->query('UPDATE '.iNA_GAMES.' SET '.$field.'=0 WHERE cat_id='.$category);
            $this->rebuild_rankings($players);
        } else {
            // DELETE is transactional; TRUNCATE would commit a caller's work.
            // Include orphan rows, matching the scope of the old global reset.
            $this->query('DELETE FROM '.$table);
            $prefix = $board === 'current' ? '' : 'at_'; $fields = array();
            foreach (array('first','second','third') as $place) { $fields[] = $prefix.$place.'_places=0'; $fields[] = $prefix.$place."_list=''"; }
            $this->query('UPDATE '.iNA_USER_DATA.' SET '.implode(',', $fields));
            $this->query('UPDATE '.iNA_GAMES.' SET '.$field.'=0');
        }
        $this->commit_attempted = true; $this->commit(); return array('reset'=>true, 'games'=>count($games), 'board'=>$board);
    }
}

function phpbb_arcade_action_run($database, $id, $action, $args)
{
    global $arcade, $phpbb_root_path;
    if (!in_array($action, array('remove','reset_scores'), true)) { throw new PhpbbOwnedStorageFailure('arcade_game_changed'); }
    $writer = new PhpbbArcadeActionWriter($database, $id);
    try { return call_user_func_array(array($writer, $action), $args); }
    finally { try { if ($writer->commit_attempted) { foreach (array('games','categories','best_player','best_at_player') as $key) { try { $arcade->clear_cache($key, $phpbb_root_path); } catch (Exception $ignored) {} catch (Error $ignored) {} } } } finally { $writer->release(); } }
}
function phpbb_arcade_game_remove($database, $id, $revision) { return phpbb_arcade_action_run($database, $id, 'remove', array($revision)); }
function phpbb_arcade_scores_reset($database, $category, $board, $revision) { return phpbb_arcade_action_run($database, 0, 'reset_scores', array($category, $board, $revision)); }
