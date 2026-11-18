<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }

class PhpbbConnectionOwnershipFailure extends RuntimeException {}

function phpbb_connection_ownership_state($database)
{
    foreach (array('sql_query', 'sql_fetchrowset', 'sql_freeresult') as $method) {
        if (!is_object($database) || !is_callable(array($database, $method))) { throw new PhpbbConnectionOwnershipFailure('Invalid database adapter'); }
    }
    $result = $database->sql_query('SELECT CONNECTION_ID() AS owned_connection_id,DATABASE() AS owned_schema,@@SESSION.autocommit AS owned_autocommit,@@SESSION.character_set_connection AS owned_charset');
    if (!$result) { throw new PhpbbConnectionOwnershipFailure('Connection identity unavailable'); }
    try { $rows = $database->sql_fetchrowset($result); }
    finally { $database->sql_freeresult($result); }
    if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]['owned_connection_id'], $rows[0]['owned_schema'], $rows[0]['owned_autocommit'], $rows[0]['owned_charset'])
        || !(is_int($rows[0]['owned_connection_id']) || is_string($rows[0]['owned_connection_id']))
        || !preg_match('/^[1-9][0-9]*$/D', (string) $rows[0]['owned_connection_id'])
        || !is_string($rows[0]['owned_schema']) || $rows[0]['owned_schema'] === ''
        || !(is_int($rows[0]['owned_autocommit']) || is_string($rows[0]['owned_autocommit']))
        || !in_array((string) $rows[0]['owned_autocommit'], array('0', '1'), true)
        || !is_string($rows[0]['owned_charset'])) { throw new PhpbbConnectionOwnershipFailure('Invalid connection identity'); }
    return $rows[0];
}

// Do not adopt or close an unproven factory result. A different object/socket
// can still be somebody else's pending transaction. SET TRANSACTION refuses
// active work on both MySQL and MariaDB without committing or rolling it back.
function phpbb_acquire_idle_connection($database, $lock_name = null)
{
    if ($lock_name !== null && (!is_string($lock_name) || !preg_match('/^[A-Za-z0-9:_-]{1,64}$/D', $lock_name))) {
        throw new PhpbbConnectionOwnershipFailure('Invalid ownership lock');
    }
    if (!is_object($database) || !is_callable(array($database, 'sql_dedicated_connection'))) {
        throw new PhpbbConnectionOwnershipFailure('Dedicated connection unavailable');
    }
    $source = phpbb_connection_ownership_state($database);
    $candidate = $database->sql_dedicated_connection();
    if (!is_object($candidate) || $candidate === $database
        || (isset($candidate->db_connect_id, $database->db_connect_id) && (is_object($database->db_connect_id) || is_resource($database->db_connect_id))
            && $candidate->db_connect_id === $database->db_connect_id)) { throw new PhpbbConnectionOwnershipFailure('Borrowed caller connection'); }
    $target = phpbb_connection_ownership_state($candidate);
    if ((string) $target['owned_connection_id'] === (string) $source['owned_connection_id']
        || $target['owned_schema'] !== $source['owned_schema'] || $target['owned_charset'] !== 'utf8mb4'
        || (string) $target['owned_autocommit'] !== '1') { throw new PhpbbConnectionOwnershipFailure('Foreign connection context'); }
    // GET_LOCK is reentrant. An idle session can still own another operation's
    // lease; adopting and closing it would silently release that existing lease.
    if ($lock_name !== null) {
        $result = $candidate->sql_query("SELECT IS_USED_LOCK('" . $lock_name . "') AS owned_lock_id");
        if (!$result) { throw new PhpbbConnectionOwnershipFailure('Connection lease unavailable'); }
        try { $rows = $candidate->sql_fetchrowset($result); }
        finally { $candidate->sql_freeresult($result); }
        if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0]) || !array_key_exists('owned_lock_id', $rows[0])) {
            throw new PhpbbConnectionOwnershipFailure('Invalid connection lease');
        }
        $holder = $rows[0]['owned_lock_id'];
        if ($holder !== null && (!(is_int($holder) || is_string($holder)) || !preg_match('/^[1-9][0-9]*$/D', (string) $holder)
            || (string) $holder === (string) $target['owned_connection_id'])) { throw new PhpbbConnectionOwnershipFailure('Inherited connection lease'); }
    }
    if ($candidate->sql_query('SET TRANSACTION READ WRITE') !== true) { throw new PhpbbConnectionOwnershipFailure('Active or unavailable connection'); }
    return $candidate;
}
