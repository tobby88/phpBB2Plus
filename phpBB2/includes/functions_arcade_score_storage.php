<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_arcade_action_storage.php';

function phpbb_arcade_score_request($source, $key, $default = null)
{
    if (!is_array($source) || !array_key_exists($key, $source)) { return $default; }
    if (!is_string($source[$key])) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return phpbb_request_raw_value($source[$key]);
}
function phpbb_arcade_score_integer($value, $minimum = -8388608)
{
    if (!(is_int($value) || is_string($value)) || !preg_match('/^(?:0|-?[1-9][0-9]{0,6})$/D', (string)$value)
        || (int)$value < $minimum || (int)$value > 8388607) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return (int)$value;
}
function phpbb_arcade_score_number($value)
{
    if (is_string($value)) { $value = trim($value); }
    if (!(is_string($value) || is_int($value) || is_float($value)) || strlen((string)$value) > 128
        || !preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', trim((string)$value)) || !is_numeric($value)
        || !is_finite((float)$value) || abs((float)$value) > 9999999999.9999) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    $number = sprintf('%.4F', round((float)$value, 4));
    return $number === '-0.0000' ? '0.0000' : $number;
}
function phpbb_arcade_score_table($board)
{
    if ($board !== 'current' && $board !== 'all_time') { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return $board === 'current' ? iNA_SCORES : iNA_AT_SCORES;
}
function phpbb_arcade_score_fields($board)
{
    phpbb_arcade_score_table($board);
    return array('game_name', $board === 'current' ? 'gameData' : 'player_name', 'player', 'player_id', 'score', 'date', 'player_ip', 'time_taken');
}
function phpbb_arcade_score_revision($game, $board, $row)
{
    $values = array();
    foreach (phpbb_arcade_score_fields($board) as $key) {
        if (!is_array($row) || !array_key_exists($key, $row) || ($row[$key] !== null && !is_scalar($row[$key]))) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
        $values[$key] = $row[$key] === null ? null : (string)$row[$key];
    }
    phpbb_arcade_score_integer($values['player_id']); phpbb_arcade_score_number($values['score']);
    $json = json_encode(array(phpbb_arcade_game_revision($game), $board, $values));
    if ($json === false) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return hash('sha256', $json);
}
function phpbb_arcade_score_rows($db, $sql)
{
    $result = $db->sql_query($sql);
    if (!$result) { throw new PhpbbOwnedStorageFailure('no_score_data'); }
    try { $rows = $db->sql_fetchrowset($result); } finally { $db->sql_freeresult($result); }
    if (!is_array($rows)) { throw new PhpbbOwnedStorageFailure('no_score_data'); }
    return $rows;
}
function phpbb_arcade_score_game($db, $id, $name)
{
    $id = phpbb_arcade_score_integer($id, 0);
    if ($id) { $where = 'game_id='.$id; }
    else {
        if (!is_string($name) || $name === '' || strlen($name) > 50 || preg_match('//u', $name) !== 1) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
        $where = "game_name='".$db->sql_escape($name)."'";
    }
    $rows = phpbb_arcade_score_rows($db, 'SELECT * FROM '.iNA_GAMES.' WHERE '.$where.' ORDER BY game_id LIMIT 2');
    if (count($rows) !== 1 || (int)$rows[0]['game_id'] < 1) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    // A database name is already raw. Never decode and resolve it a second time.
    return $rows[0];
}
function phpbb_arcade_score_target($db, $game, $board, $player, $revision)
{
    $player = phpbb_arcade_score_integer($player); $table = phpbb_arcade_score_table($board);
    $rows = phpbb_arcade_score_rows($db, 'SELECT * FROM '.$table." WHERE game_name='".$db->sql_escape($game['game_name'])."' AND player_id=".$player);
    if (count($rows) !== 1 || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)
        || !hash_equals(phpbb_arcade_score_revision($game, $board, $rows[0]), $revision)) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    return $rows[0];
}

class PhpbbArcadeScoreWriter extends PhpbbArcadeActionWriter
{
    function change($board, $player, $revision, $action, $value)
    {
        $table = phpbb_arcade_score_table($board); $player = phpbb_arcade_score_integer($player);
        if ($this->game_id < 1 || !in_array($action, array('update','delete'), true)) { $this->storage_fail('arcade_score_changed'); }
        if ($action === 'update') { $value = phpbb_arcade_score_number($value); }
        $this->lock_actor();
        $games = $this->rows('SELECT * FROM '.iNA_GAMES.' WHERE game_id='.$this->game_id.' FOR UPDATE');
        if (count($games) !== 1) { $this->storage_fail('arcade_score_changed'); }
        $game = $games[0]; $name = $this->identity_name($game);
        $rows = $this->rows('SELECT * FROM '.$table." WHERE game_name='$name' AND player_id=$player FOR UPDATE");
        if (count($rows) !== 1 || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)
            || !hash_equals(phpbb_arcade_score_revision($game, $board, $rows[0]), $revision)) { $this->storage_fail('arcade_score_changed'); }
        $row = $rows[0]; $changed = $action === 'delete' || $value !== phpbb_arcade_score_number($row['score']);
        if ($changed) {
            $field = $board === 'current' ? 'highscore_id' : 'at_highscore_id';
            $players = $this->affected_players(array($game), array($table=>$field));
            // No primary key exists in these preserved tables. Lock and compare
            // the complete selected row; refuse ambiguous legacy duplicates.
            $where = array();
            foreach (phpbb_arcade_score_fields($board) as $key) {
                $literal = $row[$key] === null ? 'NULL' : "'".$this->escape((string)$row[$key])."'";
                $binary = in_array($key, array('game_name','gameData','player_name','player','player_ip'), true) ? 'BINARY ' : '';
                $where[] = $binary.$key.' <=> '.($literal === 'NULL' ? 'NULL' : $binary.$literal);
            }
            $this->query(($action === 'delete' ? 'DELETE FROM '.$table : 'UPDATE '.$table.' SET score='.$value).' WHERE '.implode(' AND ', $where));
            if ((int)$this->connection->sql_affectedrows() !== 1) { $this->storage_fail('arcade_score_changed'); }
            foreach ($this->ranked_players($table, $game['game_name'], !empty($game['reverse_list'])) as $ranked) {
                if ((int)$ranked['player_id'] > 0) { $players[] = (int)$ranked['player_id']; }
            }
            // The scores are already locked above. Shared account locks retain
            // the winner without upgrading the actor lock past a queued revoke.
            $top = $this->rows('SELECT s.player_id FROM '.$table.' s INNER JOIN '.USERS_TABLE." u ON u.user_id=s.player_id WHERE s.game_name='$name' ORDER BY s.score ".(!empty($game['reverse_list'])?'ASC':'DESC').',s.date ASC,s.player_id ASC LIMIT 1 LOCK IN SHARE MODE');
            $this->query('UPDATE '.iNA_GAMES.' SET '.$field.'='.($top?(int)$top[0]['player_id']:0).' WHERE game_id='.$this->game_id);
            $this->rebuild_rankings($players);
        }
        $this->commit_attempted = true; $this->commit();
        return array('changed'=>$changed, 'board'=>$board, 'id'=>$this->game_id, 'player'=>$player);
    }
}
function phpbb_arcade_score_change($database, $id, $board, $player, $revision, $action, $value = null)
{
    global $arcade, $phpbb_root_path;
    $id = phpbb_arcade_score_integer($id, 1); $player = phpbb_arcade_score_integer($player); phpbb_arcade_score_table($board);
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision) || !in_array($action, array('update','delete'), true)) { throw new PhpbbOwnedStorageFailure('arcade_score_changed'); }
    if ($action === 'update') { $value = phpbb_arcade_score_number($value); }
    $writer = new PhpbbArcadeScoreWriter($database, $id);
    try { return $writer->change($board, $player, $revision, $action, $value); }
    finally {
        try { if ($writer->commit_attempted) { foreach (array('games','categories','best_player','best_at_player') as $key) {
            try { $arcade->clear_cache($key, $phpbb_root_path); } catch (Exception $ignored) {} catch (Error $ignored) {}
        } } } finally { $writer->release(); }
    }
}
