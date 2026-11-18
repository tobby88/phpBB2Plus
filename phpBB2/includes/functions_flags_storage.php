<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_config.php';

function phpbb_flag_image_name($value)
{
    if (!is_string($value)) { return ''; }
    $value = basename(str_replace('\\', '/', trim($value)));
    return preg_match('/^[A-Za-z0-9._-]+\.(?:gif|png|jpe?g)$/iD', $value) ? $value : '';
}

function phpbb_flag_id($value, $adding = false)
{
    if (!(is_string($value) || is_int($value)) || !preg_match('/^[0-9]{1,10}$/D', (string)$value)
        || (float)$value > 2147483647 || (!$adding && (int)$value < 1)) { phpbb_acl_error('Must_select_flag'); }
    return (int)$value;
}

class PhpbbFlagsWriter extends PhpbbBoardConfigWriter
{
    var $identity = '';
    var $schema = '';
    var $finished = false;
    function __construct($database)
    {
        $source = phpbb_acl_rows(new PhpbbAclDatabase($database, 'Flags_failed'), 'SELECT CONNECTION_ID() AS flag_connection,DATABASE() AS flag_schema');
        if (count($source) !== 1 || !is_string($source[0]['flag_schema']) || $source[0]['flag_schema'] === ''
            || !isset($database->dbname) || $source[0]['flag_schema'] !== $database->dbname) { phpbb_acl_error('Flags_failed'); }
        $this->schema = $source[0]['flag_schema'];
        parent::__construct($database); $this->failure_key = 'Flags_failed';
        try {
            $this->identity = $this->current_identity();
            if ($this->identity === (string)$source[0]['flag_connection']) { $this->connection = null; phpbb_acl_error('Flags_failed'); }
            // Share ownership with the profile writers which use these images.
            $name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
            $rows = phpbb_acl_rows($this, "SELECT GET_LOCK('" . $name . "',10) AS acquired");
            if (count($rows) !== 1 || (string)$rows[0]['acquired'] !== '1') { phpbb_acl_error('Flags_failed'); }
        } catch (Exception $error) { $this->release(); throw $error; }
        catch (Error $error) { $this->release(); throw $error; }
    }
    function current_identity()
    {
        if (!$this->connection) { phpbb_acl_error('Flags_failed'); }
        $rows = phpbb_acl_rows(new PhpbbAclDatabase($this->connection, 'Flags_failed'), 'SELECT CONNECTION_ID() AS flag_connection,DATABASE() AS flag_schema');
        if (count($rows) !== 1 || $rows[0]['flag_schema'] !== $this->schema) { phpbb_acl_error('Flags_failed'); }
        return (string)$rows[0]['flag_connection'];
    }
    function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_flags.' . $phpEx); }
    function sql_query($sql, $transaction = false)
    {
        if ($this->finished || !is_string($sql) || $this->current_identity() !== $this->identity) { phpbb_acl_error('Flags_failed'); }
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql, $command)) {
            if (!$this->transactional || !(strpos($sql, 'UPDATE ' . FLAG_TABLE . ' SET ') === 0
                || strpos($sql, 'INSERT INTO ' . FLAG_TABLE . ' (') === 0 || strpos($sql, 'DELETE FROM ' . FLAG_TABLE . ' WHERE ') === 0
                || strpos($sql, 'UPDATE ' . USERS_TABLE . ' SET user_from_flag=') === 0)) { phpbb_acl_error('Flags_failed'); }
            if (strtoupper($command[1]) !== 'UPDATE') {
                $this->actor(); $result = $this->connection->sql_query($sql, $transaction);
                if (!$result) { phpbb_acl_error('Flags_failed'); } return $result;
            }
        }
        $result = parent::sql_query($sql, $transaction);
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') { $this->finished = true; }
        return $result;
    }
}

function phpbb_flags_change($database, $request)
{
    global $userdata;
    if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
        || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
    if (!isset($request['mode']) || !in_array($request['mode'], array('save','do_delete'), true)) { phpbb_acl_error('Flags_invalid'); }
    $delete = $request['mode'] === 'do_delete';
    if ($delete && empty($request['confirm'])) { phpbb_acl_error('Flags_invalid'); }
    $id = phpbb_flag_id(array_key_exists('id', $request) ? $request['id'] : '0', !$delete);
    $name = $image = '';
    if (!$delete) {
        if (!isset($request['title'], $request['flag_image']) || !is_string($request['title']) || !is_string($request['flag_image'])) { phpbb_acl_error('Flags_invalid'); }
        $name = trim(phpbb_request_raw_value($request['title'])); $image = trim(phpbb_request_raw_value($request['flag_image']));
        if ($name === '' || strpos($name, "\0") !== false || preg_match_all('/./us', $name, $characters) === false || count($characters[0]) > 25
            || strlen($image) > 25 || ($image !== '' && phpbb_flag_image_name($image) !== $image)) { phpbb_acl_error('Flags_invalid'); }
    }
    $db = new PhpbbFlagsWriter($database);
    try {
        $db->actor();
        $db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->sql_query('START TRANSACTION');
        foreach (array(FLAG_TABLE, USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table) {
            $result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result); $literal = "'" . $db->sql_escape($table) . "'";
            $meta = phpbb_acl_rows($db, 'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(' . $literal . ')');
            $columns = phpbb_acl_rows($db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX(" . $literal . ") AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if (count($meta) !== 1 || $meta[0]['ENGINE'] !== 'InnoDB' || strtolower($meta[0]['ROW_FORMAT']) !== 'dynamic' || $meta[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $columns) { phpbb_acl_error('Flags_failed'); }
        }
        $old = array();
        if ($id) { $rows = phpbb_acl_rows($db, 'SELECT flag_id,flag_name,flag_image FROM ' . FLAG_TABLE . ' WHERE flag_id=' . $id . ' FOR UPDATE'); if (count($rows) !== 1) { phpbb_acl_error('Must_select_flag'); } $old = $rows[0]; }
        $actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "')",
            'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'], 'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql) {
            $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result);
        }
        $actor = $db->actor(); $message = $delete ? 'Flag_removed' : ($id ? 'Flag_updated' : 'Flag_added');
        if ($delete) {
            $db->sql_query('DELETE FROM ' . FLAG_TABLE . ' WHERE flag_id=' . $id . ' AND ' . $actor['guard']);
            if (phpbb_acl_rows($db, 'SELECT flag_id FROM ' . FLAG_TABLE . ' WHERE flag_id=' . $id)) { phpbb_acl_error('Flags_failed'); }
        } else {
            $name_sql = "'" . $db->sql_escape($name) . "'"; $image_sql = "'" . $db->sql_escape($image) . "'";
            $db->sql_query($id ? 'UPDATE ' . FLAG_TABLE . ' SET flag_name=' . $name_sql . ',flag_image=' . $image_sql . ' WHERE flag_id=' . $id . ' AND ' . $actor['guard']
                : 'INSERT INTO ' . FLAG_TABLE . ' (flag_name,flag_image) SELECT ' . $name_sql . ',' . $image_sql . ' WHERE ' . $actor['guard']);
            if (!$id) { $id = (int)$db->sql_nextid(); if ($id < 1) { phpbb_acl_error('Flags_failed'); } }
            $stored = phpbb_acl_rows($db, 'SELECT flag_name,flag_image FROM ' . FLAG_TABLE . ' WHERE flag_id=' . $id);
            if (count($stored) !== 1 || $stored[0]['flag_name'] !== $name || $stored[0]['flag_image'] !== $image) { phpbb_acl_error('Flags_failed'); }
        }
        // Profiles store filenames, not flag IDs. Do not clear a filename which
        // is still offered by another flag; preserve differently cased names.
        if ($old && is_string($old['flag_image']) && $old['flag_image'] !== '' && ($delete || $old['flag_image'] !== $image)) {
            $old_sql = "'" . $db->sql_escape($old['flag_image']) . "'";
            if (!phpbb_acl_rows($db, 'SELECT flag_id FROM ' . FLAG_TABLE . ' WHERE flag_image=' . $old_sql . ' AND HEX(flag_image)=HEX(' . $old_sql . ') LOCK IN SHARE MODE')) {
                $new_sql = "'" . $db->sql_escape($delete || $image === '' ? 'blank.gif' : $image) . "'";
                $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_from_flag=' . $new_sql . ' WHERE user_from_flag=' . $old_sql . ' AND HEX(user_from_flag)=HEX(' . $old_sql . ') AND ' . $actor['guard']);
                if ($new_sql !== $old_sql && phpbb_acl_rows($db, 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_from_flag=' . $old_sql . ' AND HEX(user_from_flag)=HEX(' . $old_sql . ')')) { phpbb_acl_error('Flags_failed'); }
            }
        }
        $db->sql_query('COMMIT');
    } finally { $db->release(); }
    return $message;
}
