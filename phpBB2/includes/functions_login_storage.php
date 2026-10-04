<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__DIR__) . '/attach_mod/includes/functions_mutation.php';

class PhpbbLoginException extends RuntimeException {}
function phpbb_login_error() { throw new PhpbbLoginException('Login publication unavailable'); }

// Manual password authentication owns its session/key publication independently
// of a caller transaction. Never deliver an unconfirmed random login capability.
class PhpbbLoginDatabase
{
    var $lock;
    var $connection = null;
    var $transactional = false;
    function __construct($database)
    {
        $this->lock = new attach_mutation_lock($database);
        if (!$this->lock->acquired) { phpbb_login_error(); }
        $this->connection = $this->lock->connection;
        if ($this->connection === $database || (isset($database->db_connect_id, $this->connection->db_connect_id)
            && (is_object($database->db_connect_id) || is_resource($database->db_connect_id)) && $database->db_connect_id === $this->connection->db_connect_id)) {
            // A broken adapter factory must not commit or close its caller.
            $name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
            $this->lock->connection = null; $this->lock->acquired = false; $this->connection = null;
            $r = $database->sql_query("SELECT RELEASE_LOCK('$name')"); if ($r) { $database->sql_freeresult($r); }
            phpbb_login_error();
        }
        register_shutdown_function(array($this, 'release'));
        try {
            $this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
            $this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->control('START TRANSACTION'); $this->transactional = true;
            foreach (array(USERS_TABLE, SESSIONS_TABLE, SESSIONS_KEYS_TABLE, BANLIST_TABLE, CONFIG_TABLE) as $table) {
                $r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r);
                $name = $this->sql_escape($table);
                $rows = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'"
                    . " AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
                if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { phpbb_login_error(); }
            }
        } catch (Exception $e) { $this->release(); throw $e; }
        catch (Error $e) { $this->release(); throw $e; }
    }
    function __call($method, $args)
    {
        if ($this->connection === null) { phpbb_login_error(); }
        return call_user_func_array(array($this->connection, $method), $args);
    }
    private function control($sql)
    {
        if ($this->connection === null) { phpbb_login_error(); }
        $r = $this->connection->sql_query($sql); if (!$r) { phpbb_login_error(); } return $r;
    }
    function sql_query($sql, $transaction = false)
    {
        if (!$this->transactional || !is_string($sql) || $transaction !== false || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) { phpbb_login_error(); }
        return $this->control($sql);
    }
    function rows($sql)
    {
        $r = $this->sql_query($sql); try { return $this->sql_fetchrowset($r); }
        finally { $this->sql_freeresult($r); }
    }
    function commit()
    {
        if (!$this->transactional) { phpbb_login_error(); }
        $this->control('COMMIT'); $this->transactional = false;
    }
    function release()
    {
        if ($this->connection === null) { return; }
        if ($this->transactional) { try { $this->connection->sql_query('ROLLBACK'); } catch (Exception $e) {} catch (Error $e) {} }
        $this->transactional = false; $this->connection = null; $this->lock->release();
    }
}

function phpbb_login_session($database, $expected, $user_ip, $page_id, $autologin, $admin, $replacement = null)
{
    global $db, $userdata, $board_config, $HTTP_COOKIE_VARS, $SID;
    if (!is_array($expected) || !isset($expected['user_id']) || !(is_int($expected['user_id']) || is_string($expected['user_id'])) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$expected['user_id']) || (int)$expected['user_id'] > 8388607
        || !is_string($user_ip) || preg_match('/^[a-f0-9]{8}$/iD', $user_ip) !== 1
        || ($replacement !== null && (!is_string($replacement) || $replacement === '' || strlen($replacement) > 255))
        || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { phpbb_login_error(); }
    $original = $db; $cookies = $HTTP_COOKIE_VARS; $old_sid = $SID;
    $owner = new PhpbbLoginDatabase($database);
    try {
        $id = (int)$expected['user_id'];
        // Lock current policy before trusting the request's cached settings.
        $policy = array(); foreach ($owner->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' LOCK IN SHARE MODE') as $setting) { $policy[$setting['config_name']] = $setting['config_value']; }
        foreach (array('board_disable','password_hashing','allow_autologin','cookie_name','cookie_path','cookie_domain','cookie_secure') as $key) {
            if (!array_key_exists($key, $policy) || !array_key_exists($key, $board_config) || (string)$policy[$key] !== (string)$board_config[$key]) { phpbb_login_error(); }
        }
        $rows = $owner->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($rows) !== 1) { phpbb_login_error(); } $current = $rows[0];
        foreach (array('user_id','username','user_password','user_active','user_level','user_blocktime') as $key) {
            if (!array_key_exists($key, $expected) || !is_scalar($expected[$key]) || !hash_equals((string)$expected[$key], (string)$current[$key])) { phpbb_login_error(); }
        }
        if ((int)$current['user_active'] !== 1 || (int)$current['user_blocktime'] >= time() || (!empty($policy['board_disable']) && (int)$current['user_level'] !== ADMIN)) { phpbb_login_error(); }
        $sid = $owner->sql_escape($userdata['session_id']);
        $caller = $owner->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=" . (int)$userdata['user_id'] . ' AND session_logged_in=' . (empty($userdata['session_logged_in']) ? 0 : 1) . ' LOCK IN SHARE MODE');
        if (count($caller) !== 1) { phpbb_login_error(); }
        // Pin revocations, including empty key/session ranges and new bans.
        $owner->rows('SELECT session_id FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $id . ' FOR UPDATE');
        $owner->rows('SELECT key_id FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        $owner->rows('SELECT ban_ip,ban_userid,ban_email FROM ' . BANLIST_TABLE . ' FOR UPDATE');
        if ($replacement !== null) {
            if (empty($policy['password_hashing'])) { phpbb_login_error(); }
            $owner->sql_query('UPDATE ' . USERS_TABLE . " SET user_password='" . $owner->sql_escape($replacement) . "' WHERE user_id=$id");
        }
        $owner->sql_query('UPDATE ' . USERS_TABLE . ' SET user_badlogin=0 WHERE user_id=' . $id);
        // A stale persistent cookie must not override a freshly verified manual
        // password. Retire this device's old key; other device keys are intact.
        $cookie_name = $board_config['cookie_name'] . '_data';
        $old_data = isset($HTTP_COOKIE_VARS[$cookie_name]) && is_string($HTTP_COOKIE_VARS[$cookie_name]) ? phpbb_safe_unserialize_array(stripslashes($HTTP_COOKIE_VARS[$cookie_name])) : array();
        if (isset($old_data['autologinid']) && is_string($old_data['autologinid']) && preg_match('/^[a-f0-9]{32}$/iD', $old_data['autologinid'])) {
            $owner->sql_query('DELETE FROM ' . SESSIONS_KEYS_TABLE . " WHERE user_id=$id AND key_id='" . md5($old_data['autologinid']) . "'");
        }
        unset($HTTP_COOKIE_VARS[$cookie_name]); $db = $owner;
        $publication = session_begin($id, $user_ip, $page_id, false, (int)(bool)$autologin, (int)(bool)$admin, true);
        if (!is_array($publication) || !isset($publication['user']) || (int)$publication['user']['user_id'] !== $id || empty($publication['user']['session_logged_in'])) { phpbb_login_error(); }
        $owner->commit();
    } finally { $db = $original; $HTTP_COOKIE_VARS = $cookies; $SID = $old_sid; $owner->release(); }
    foreach ($publication['cookies'] as $args) { if (call_user_func_array('phpbb_setcookie', $args) === false) { phpbb_login_error(); } }
    $SID = $publication['sid']; return $publication['user'];
}

// A persistent device key is a credential, too. Validate and rotate it while
// holding the current account, policy, session/key ranges and bans. A revoked
// or inactive device/account falls back to a committed anonymous session.
function phpbb_autologin_session($database, $user_id, $user_ip, $page_id)
{
    global $db, $board_config, $HTTP_COOKIE_VARS, $SID;
    if (!(is_int($user_id) || is_string($user_id)) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$user_id) || (int)$user_id > 8388607
        || !is_string($user_ip) || preg_match('/^[a-f0-9]{8}$/iD', $user_ip) !== 1) { phpbb_login_error(); }
    $original = $db; $cookies = $HTTP_COOKIE_VARS; $old_sid = $SID;
    $owner = new PhpbbLoginDatabase($database);
    try {
        $id = (int)$user_id;
        $policy = array(); foreach ($owner->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE . ' LOCK IN SHARE MODE') as $setting) { $policy[$setting['config_name']] = $setting['config_value']; }
        foreach (array('board_disable','allow_autologin','cookie_name','cookie_path','cookie_domain','cookie_secure') as $key) {
            if (!array_key_exists($key, $policy) || !array_key_exists($key, $board_config) || (string)$policy[$key] !== (string)$board_config[$key]) { phpbb_login_error(); }
        }
        $rows = $owner->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        $current = count($rows) === 1 ? $rows[0] : null;
        $owner->rows('SELECT session_id FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $id . ' FOR UPDATE');
        $keys = $owner->rows('SELECT key_id FROM ' . SESSIONS_KEYS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        $owner->rows('SELECT ban_ip,ban_userid,ban_email FROM ' . BANLIST_TABLE . ' FOR UPDATE');
        $cookie_name = $board_config['cookie_name'] . '_data';
        $data = isset($HTTP_COOKIE_VARS[$cookie_name]) && is_string($HTTP_COOKIE_VARS[$cookie_name]) ? phpbb_safe_unserialize_array(stripslashes($HTTP_COOKIE_VARS[$cookie_name])) : array();
        $key_valid = false;
        if (isset($data['autologinid']) && is_string($data['autologinid']) && preg_match('/^[a-f0-9]{32}$/iD', $data['autologinid'])) {
            $hash = md5($data['autologinid']); foreach ($keys as $key) { if (hash_equals((string)$key['key_id'], $hash)) { $key_valid = true; } }
        }
        $authorized = $current !== null && (int)$current['user_active'] === 1 && (int)$current['user_blocktime'] < time()
            && (!empty($policy['allow_autologin'])) && (empty($policy['board_disable']) || (int)$current['user_level'] === ADMIN) && $key_valid;
        if (!$authorized) { unset($HTTP_COOKIE_VARS[$cookie_name]); }
        $db = $owner;
        $publication = session_begin($authorized ? $id : ANONYMOUS, $user_ip, $page_id, 1, $authorized ? 1 : 0, 0, true);
        if (!is_array($publication) || !isset($publication['user']) || (int)$publication['user']['user_id'] !== ($authorized ? $id : ANONYMOUS)
            || (bool)$publication['user']['session_logged_in'] !== (bool)$authorized || !empty($publication['user']['session_admin'])) { phpbb_login_error(); }
        $owner->commit();
    } finally { $db = $original; $HTTP_COOKIE_VARS = $cookies; $SID = $old_sid; $owner->release(); }
    foreach ($publication['cookies'] as $args) { if (call_user_func_array('phpbb_setcookie', $args) === false) { phpbb_login_error(); } }
    $SID = $publication['sid']; return $publication['user'];
}
