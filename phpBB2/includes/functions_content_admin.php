<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_board_config.php';

function phpbb_content_admin_definition($kind)
{
    if ($kind === 'words') { return array('table'=>WORDS_TABLE,'id'=>'word_id','fields'=>array('word'=>100,'replacement'=>100),'route'=>'admin_words','prefix'=>'Word'); }
    if ($kind === 'acronyms') { return array('table'=>ACRONYMS_TABLE,'id'=>'acronym_id','fields'=>array('acronym'=>80,'description'=>255),'route'=>'admin_acronyms','prefix'=>'Acronym'); }
    phpbb_acl_error('Content_admin_failed');
}

class PhpbbContentAdminWriter extends PhpbbBoardConfigWriter
{
    var $definition;
    var $identity = '';
    var $finished = false;
    function __construct($database, $definition)
    {
        $this->definition = $definition;
        $source = phpbb_acl_rows(new PhpbbAclDatabase($database, 'Content_admin_failed'), 'SELECT CONNECTION_ID() AS content_connection_id');
        if (count($source) !== 1) { phpbb_acl_error('Content_admin_failed'); }
        parent::__construct($database); $this->failure_key = 'Content_admin_failed';
        try {
            $this->identity = $this->current_identity();
            if ($this->identity === (string)$source[0]['content_connection_id']) { $this->connection = null; phpbb_acl_error('Content_admin_failed'); }
            $name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
            $rows = phpbb_acl_rows($this, "SELECT GET_LOCK('" . $name . "', 10) AS acquired");
            if (count($rows) !== 1 || (int)$rows[0]['acquired'] !== 1) { phpbb_acl_error('Content_admin_failed'); }
        } catch (Exception $error) { $this->release(); throw $error; }
        catch (Error $error) { $this->release(); throw $error; }
    }
    function current_identity()
    {
        if (!$this->connection) { phpbb_acl_error('Content_admin_failed'); }
        $rows = phpbb_acl_rows(new PhpbbAclDatabase($this->connection, 'Content_admin_failed'), 'SELECT CONNECTION_ID() AS content_connection_id');
        if (count($rows) !== 1) { phpbb_acl_error('Content_admin_failed'); }
        return (string)$rows[0]['content_connection_id'];
    }
    function actor() { global $phpEx; return phpbb_acp_actor($this, $this->definition['route'] . '.' . $phpEx); }
    function sql_query($sql, $transaction = false)
    {
        if ($this->finished || $this->current_identity() !== $this->identity) { phpbb_acl_error('Content_admin_failed'); }
        if (is_string($sql) && preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $sql, $command)) {
            $table = $this->definition['table'];
            if (!$this->transactional || !(strpos($sql, 'UPDATE ' . $table . ' SET ') === 0
                || strpos($sql, 'INSERT INTO ' . $table . ' (') === 0 || strpos($sql, 'DELETE FROM ' . $table . ' WHERE ') === 0)) { phpbb_acl_error('Content_admin_failed'); }
            if (strtoupper($command[1]) !== 'UPDATE') {
                $this->actor(); $result = $this->connection->sql_query($sql, $transaction);
                if (!$result) { phpbb_acl_error('Content_admin_failed'); } return $result;
            }
        }
        $result = parent::sql_query($sql, $transaction);
        if ($sql === 'COMMIT' || $sql === 'ROLLBACK') { $this->finished = true; }
        return $result;
    }
}

function phpbb_content_admin_change($database, $kind, $request)
{
    global $userdata, $phpbb_root_path;
    $definition = phpbb_content_admin_definition($kind);
    if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
        || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
    if (!isset($request['mode']) || !in_array($request['mode'], array('save','delete'), true)) { phpbb_acl_error('Content_admin_failed'); }
    $delete = $request['mode'] === 'delete';
    if ($delete && !isset($request['confirm'])) { phpbb_acl_error('Content_admin_failed'); }
    $raw_id = array_key_exists('id', $request) ? $request['id'] : '0';
    if (!(is_string($raw_id) || is_int($raw_id)) || !preg_match('/^[0-9]{1,8}$/D', (string)$raw_id)
        || (float)$raw_id > ($kind === 'acronyms' ? 8388607 : 16777215) || ($delete && (int)$raw_id < 1)) { phpbb_acl_error('Content_admin_failed'); }
    $id = (int)$raw_id; $adding = !$id; $values = array();
    if (!$delete) {
        foreach ($definition['fields'] as $field=>$limit) {
            if (!isset($request[$field]) || !is_string($request[$field])) { phpbb_acl_error('Content_admin_invalid'); }
            $value = trim(phpbb_request_raw_value($request[$field]));
            if ($value === '' || strpos($value, "\0") !== false || preg_match_all('/./us', $value, $characters) === false
                || count($characters[0]) > $limit) { phpbb_acl_error('Content_admin_invalid'); }
            $values[$field] = $value;
        }
    }
    $db = new PhpbbContentAdminWriter($database, $definition); $attempted = false;
    try {
        $db->actor();
        $db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $db->sql_query('START TRANSACTION');
        foreach (array($definition['table'],USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table) {
            $result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result); $name = $db->sql_escape($table);
            $rows = phpbb_acl_rows($db, "SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."')");
            $columns = phpbb_acl_rows($db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic' || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $columns) { phpbb_acl_error('Content_admin_failed'); }
        }
        $table = $definition['table']; $key = $definition['id'];
        if ($id && count(phpbb_acl_rows($db, 'SELECT '.$key.' FROM '.$table.' WHERE '.$key.'='.$id.' FOR UPDATE')) !== 1) { phpbb_acl_error('Content_admin_failed'); }
        $actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."')",
            'SELECT user_id FROM '.USERS_TABLE.' WHERE user_id='.(int)$actor['user_id'], 'SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.(int)$actor['user_id']) as $sql) {
            $result = $db->sql_query($sql.' LOCK IN SHARE MODE'); $db->sql_freeresult($result);
        }
        $actor = $db->actor(); $message = $definition['prefix'] . ($delete ? '_removed' : ($adding ? '_added' : '_updated'));
        if ($kind === 'acronyms' && $adding && phpbb_acl_rows($db, 'SELECT acronym_id FROM '.$table." WHERE acronym='".$db->sql_escape($values['acronym'])."' FOR UPDATE")) {
            $message = 'Content_acronym_exists';
        } else {
         $attempted = true;
         if ($delete) {
            $db->sql_query('DELETE FROM '.$table.' WHERE '.$key.'='.$id.' AND '.$actor['guard']);
            if (phpbb_acl_rows($db, 'SELECT '.$key.' FROM '.$table.' WHERE '.$key.'='.$id)) { phpbb_acl_error('Content_admin_failed'); }
         } else {
            $literals = $assignments = array(); foreach ($values as $field=>$value) { $literal="'".$db->sql_escape($value)."'"; $literals[]=$literal; $assignments[]=$field.'='.$literal; }
            $sql = $id ? 'UPDATE '.$table.' SET '.implode(',',$assignments).' WHERE '.$key.'='.$id.' AND '.$actor['guard']
                : 'INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') SELECT '.implode(',',$literals).' WHERE '.$actor['guard'];
            $db->sql_query($sql); if (!$id) { $id=(int)$db->sql_nextid(); if($id<1){phpbb_acl_error('Content_admin_failed');} }
            $stored = phpbb_acl_rows($db, 'SELECT * FROM '.$table.' WHERE '.$key.'='.$id);
            if (count($stored) !== 1) { phpbb_acl_error('Content_admin_failed'); }
            foreach ($values as $field=>$value) { if ($stored[0][$field] !== $value) { phpbb_acl_error('Content_admin_failed'); } }
         }
        }
        $db->sql_query('COMMIT');
    } finally {
        try { $db->release(); }
        finally {
            if ($kind === 'words' && $attempted) {
                unset($GLOBALS['global_orig_word'], $GLOBALS['global_replacement_word']);
                $cache = $phpbb_root_path . 'cache/words.cache'; clearstatcache(true, $cache);
                if ((file_exists($cache) || is_link($cache)) && !@unlink($cache)) { phpbb_acl_error('Content_admin_failed'); }
            }
        }
    }
    // A single return after cleanup also avoids PHP 5.6's nested-finally
    // multiple-return bug (the later success return could replace duplicate).
    return $message;
}
