<?php
// Shared, synthetic sessions for native Album creator fixtures only.
if (!defined('IN_PHPBB') || PHP_SAPI !== 'cli') { exit(2); }
if (!defined('SESSIONS_TABLE')) { define('SESSIONS_TABLE', 'fixture_sessions'); }
$GLOBALS['board_config'] = array('session_length' => 3600);
function album_native_session_schema($database, $source)
{
    $ddl = file_get_contents($source . 'install/schemas/mysql_schema.sql');
    if (preg_match('/CREATE TABLE phpbb_sessions\s*\([\s\S]*?;/', $ddl, $match) !== 1 ||
        !$database->sql_query(str_replace('phpbb_sessions', SESSIONS_TABLE, $match[0])))
    { throw new RuntimeException('Canonical owned Album session schema'); }
}
function album_native_session_seed($database, $id, $sid)
{
    if (!is_int($id) || $id <= 0 || $id > 8388607 || !is_string($sid) || !preg_match('/^[a-f0-9]{32}$/D', $sid))
    { throw new RuntimeException('Bounded synthetic Album session identity'); }
    $sid = $database->sql_escape($sid);
    if (!$database->sql_query("DELETE FROM " . SESSIONS_TABLE . " WHERE session_id='" . $sid . "'") ||
        !$database->sql_query("INSERT INTO " . SESSIONS_TABLE . "(session_id,session_user_id,session_logged_in,session_time) VALUES('" . $sid . "'," . $id . ",1," . time() . ")"))
    { throw new RuntimeException('Seed actual owned Album session'); }
}
