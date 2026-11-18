<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';
require_once dirname(__FILE__) . '/functions_connection_ownership.php';

class PhpbbDatabaseBackupException extends RuntimeException {}
function phpbb_database_backup_failed($storage = false)
{
    throw new PhpbbDatabaseBackupException($storage ? 'storage' : 'failed');
}

// Two dedicated connections have different responsibilities: current authority
// uses READ COMMITTED; all exported rows use one read-only RR snapshot. Neither
// is the request's connection, and neither may reconnect/reuse a finished job.
class PhpbbDatabaseBackupConnection extends PhpbbAclDatabase
{
    var $identity = '';
    var $transactional = false;
    var $finished = false;
    var $stream_result = null;
    var $stream_complete = false;
    var $stream_failed = false;
    function __construct($database)
    {
        if (!is_callable(array($database, 'sql_dedicated_connection'))) { phpbb_database_backup_failed(); }
        try {
            $this->connection = phpbb_acquire_idle_connection($database);
            $this->identity = $this->current_identity();
            if ($this->identity === '') { phpbb_database_backup_failed(); }
        } catch (Exception $error) { $this->close(); throw $error; }
        catch (Error $error) { $this->close(); throw $error; }
    }
    function current_identity()
    {
        if (!$this->connection) { phpbb_database_backup_failed(); }
        if ($this->stream_result !== null || $this->stream_failed) { $this->stream_failed = true; phpbb_database_backup_failed(); }
        $result = $this->connection->sql_query('SELECT CONNECTION_ID() AS backup_connection_id');
        if (!$result) { phpbb_database_backup_failed(); }
        $row = $this->connection->sql_fetchrow($result); $this->connection->sql_freeresult($result);
        if (!$row || !isset($row['backup_connection_id'])) { phpbb_database_backup_failed(); }
        return (string)$row['backup_connection_id'];
    }
    function control($sql)
    {
        if ($this->finished || !$this->connection || $this->current_identity() !== $this->identity) { phpbb_database_backup_failed(); }
        $result = $this->connection->sql_query($sql);
        if (!$result) { phpbb_database_backup_failed(); }
        return $result;
    }
    function sql_query($sql, $transaction = false)
    {
        if (!$this->transactional || !is_string($sql) || !preg_match('/^(SELECT|SHOW)\b/', $sql)) { phpbb_database_backup_failed(); }
        return $this->control($sql);
    }
    function sql_query_unbuffered($sql)
    {
        if (!$this->transactional || $this->finished || !is_string($sql) || !preg_match('/^SELECT\\b/', $sql)
            || $this->current_identity() !== $this->identity || !is_callable(array($this->connection, 'sql_query_unbuffered'))) { phpbb_database_backup_failed(); }
        $result = $this->connection->sql_query_unbuffered($sql);
        if (!($result instanceof mysqli_result)) { $this->stream_failed = true; phpbb_database_backup_failed(); }
        $this->stream_result = $result; $this->stream_complete = false;
        return $result;
    }
    function sql_fetchrow($result = 0)
    {
        if ($this->stream_result === null) { return $this->connection->sql_fetchrow($result); }
        if ($result !== 0 && $result !== $this->stream_result) { $this->stream_failed = true; phpbb_database_backup_failed(); }
        try {
            $row = $this->connection->sql_fetchrow($this->stream_result);
            if ($row === false) {
                // Inspect the fetch error before a later identity query or
                // COMMIT can clear it and turn a truncated export into success.
                $error = $this->connection->sql_error();
                if (!is_array($error) || !isset($error['code']) || (int)$error['code'] !== 0) { phpbb_database_backup_failed(); }
                $this->stream_complete = true;
            } elseif (!is_array($row)) { phpbb_database_backup_failed(); }
            return $row;
        } catch (Exception $error) { $this->stream_failed = true; throw $error; }
        catch (Error $error) { $this->stream_failed = true; throw $error; }
    }
    function sql_freeresult($result = 0)
    {
        if ($this->stream_result === null || ($result !== 0 && $result !== $this->stream_result)) { return $this->connection->sql_freeresult($result); }
        $stream = $this->stream_result;
        if (!$this->stream_complete) { $this->stream_failed = true; }
        try {
            $released = $this->connection->sql_freeresult($stream);
            if (!$released) { $this->stream_failed = true; }
            return $released;
        } catch (Exception $error) { $this->stream_failed = true; throw $error; }
        catch (Error $error) { $this->stream_failed = true; throw $error; }
        finally { $this->stream_result = null; }
    }
    function begin($snapshot)
    {
        if ($this->transactional || $this->finished) { phpbb_database_backup_failed(); }
        // Match the SQL mode declared in the generated import stream. Keep the
        // storage fallback guard while making source escaping deterministic.
        $this->control("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->control('SET SESSION TRANSACTION ISOLATION LEVEL ' . ($snapshot ? 'REPEATABLE READ' : 'READ COMMITTED'));
        $this->control($snapshot ? 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' : 'START TRANSACTION');
        $this->transactional = true;
    }
    function commit()
    {
        if (!$this->transactional) { phpbb_database_backup_failed(); }
        $this->control('COMMIT'); $this->transactional = false; $this->finished = true;
    }
    function close()
    {
        if (!$this->connection) { return; }
        // A partly consumed result cannot accept ROLLBACK. Close only this
        // owned connection; never leave its cursor/transaction for shutdown.
        try { if ($this->transactional && $this->stream_result === null) { @$this->connection->sql_query('ROLLBACK'); } }
        catch (Exception $ignored) {} catch (Error $ignored) {}
        try { $this->connection->sql_close(); } catch (Exception $ignored) {} catch (Error $ignored) {}
        $this->connection = null; $this->stream_result = null; $this->transactional = false; $this->finished = true;
    }
}

function phpbb_database_backup_authority($owner)
{
    global $userdata, $phpEx;
    $actor = phpbb_acp_actor($owner, 'admin_db_utilities.' . $phpEx . '?perform=backup');
    $sid = $owner->sql_escape($userdata['session_id']);
    foreach (array(
        'SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
        'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
        'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']
    ) as $sql) { $result = $owner->sql_query($sql . ' LOCK IN SHARE MODE'); $owner->sql_freeresult($result); }
    return phpbb_acp_actor($owner, 'admin_db_utilities.' . $phpEx . '?perform=backup');
}

function phpbb_database_backup_pin($owner, $tables, $canonical = false, $require_innodb = true)
{
    foreach ($tables as $table) {
        if (!is_string($table) || $table === '' || strpos($table, "\0") !== false) { phpbb_database_backup_failed(); }
        $result = $owner->sql_query('SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT 0'); $owner->sql_freeresult($result);
        $name = $owner->sql_escape($table);
        $rows = phpbb_acl_rows($owner, "SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $name . "')");
        if (count($rows) !== 1 || !is_string($rows[0]['ENGINE']) || $rows[0]['ENGINE'] === ''
            || ($require_innodb && $rows[0]['ENGINE'] !== 'InnoDB')) { phpbb_database_backup_failed(true); }
        if ($canonical) {
            $columns = phpbb_acl_rows($owner, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $name . "') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if (strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $columns) { phpbb_database_backup_failed(true); }
        }
    }
}

function phpbb_database_backup_columns($database, $table)
{
    if (!is_string($table) || $table === '' || strpos($table, "\0") !== false) { phpbb_database_backup_failed(); }
    $quoted_table = '`' . str_replace('`', '``', $table) . '`';
    $columns = phpbb_acl_rows($database, 'SHOW FULL COLUMNS FROM ' . $quoted_table);
    if (!$columns) { phpbb_database_backup_failed(); }
    $fields = array(); $quoted = array(); $seen = array(); $project = false;
    foreach ($columns as $column) {
        if (!isset($column['Field'], $column['Extra']) || !is_string($column['Field']) || $column['Field'] === ''
            || strpos($column['Field'], "\0") !== false || !is_string($column['Extra']) || isset($seen[$column['Field']])) { phpbb_database_backup_failed(); }
        $seen[$column['Field']] = true; $extra = $column['Extra'];
        // DEFAULT_GENERATED describes an ordinary expression default, not a
        // computed column. Its actual stored value must still be exported.
        $generated = preg_match('/(?:^|\\s)(?:VIRTUAL|STORED|PERSISTENT)(?:\\s+GENERATED)?(?:\\s|$)/i', $extra) === 1;
        if ($generated) { $project = true; continue; }
        if (preg_match('/(?:^|\\s)GENERATED(?:\\s|$)/i', $extra)) { phpbb_database_backup_failed(); }
        $fields[] = $column['Field']; $quoted[] = '`' . str_replace('`', '``', $column['Field']) . '`';
        if (preg_match('/(?:^|\\s)INVISIBLE(?:\\s|$)/i', $extra)) { $project = true; }
    }
    // A valid all-computed table still needs one empty INSERT per source row.
    return array('fields'=>$fields, 'quoted'=>$quoted, 'projection'=>$project ? ($quoted ? implode(', ', $quoted) : '1') : '*');
}

function phpbb_database_backup_write($stream, $bytes)
{
    if (!is_resource($stream) || !is_string($bytes)) { phpbb_database_backup_failed(); }
    for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $written) {
        $written = @fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) { phpbb_database_backup_failed(); }
    }
}

function phpbb_database_backup_capture($database, $tables, $gzip, $builder, $data = true)
{
    global $userdata;
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sid']) || !is_string($_POST['sid'])
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { phpbb_acl_error('Session_invalid'); }
    if (!is_array($tables) || !$tables || !is_bool($gzip) || !is_bool($data) || !is_callable($builder)) { phpbb_database_backup_failed(); }
    $authority = null; $snapshot = null; $stream = null; $filter = false; $success = false;
    try {
        $authority = new PhpbbDatabaseBackupConnection($database); $authority->begin(false);
        phpbb_database_backup_pin($authority, array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE), true);
        phpbb_database_backup_authority($authority);
        $snapshot = new PhpbbDatabaseBackupConnection($database); $snapshot->begin(true);
        // Metadata locks exclude changes to selected definitions during capture.
        // Schema migrations/new-table DDL still require a maintenance window.
        phpbb_database_backup_pin($snapshot, $tables, false, $data);
        $stream = @tmpfile();
        if ($stream === false) { phpbb_database_backup_failed(); }
        if ($gzip) {
            $filter = @stream_filter_append($stream, 'zlib.deflate', STREAM_FILTER_WRITE, array('level'=>9, 'window'=>31));
            if ($filter === false) { phpbb_database_backup_failed(); }
        }
        call_user_func($builder, $snapshot, $stream);
        if ($filter !== false) { if (!@stream_filter_remove($filter)) { phpbb_database_backup_failed(); } $filter = false; }
        if (!@fflush($stream) || !@rewind($stream)) { phpbb_database_backup_failed(); }
        $snapshot->commit();
        phpbb_database_backup_authority($authority); $authority->commit();
        $success = true; return $stream;
    } finally {
        if ($snapshot !== null) { $snapshot->close(); }
        if ($authority !== null) { $authority->close(); }
        if (!$success && is_resource($stream)) { @fclose($stream); }
    }
}
