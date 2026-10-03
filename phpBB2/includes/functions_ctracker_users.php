<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_content_admin.php';

// Share the identity-checked dedicated account owner, including the common
// account/attachment mutation lock, without changing the caller's transaction.
class PhpbbCtrackerUserWriter extends PhpbbContentAdminWriter
{
    function __construct($database) { parent::__construct($database, array('table'=>USERS_TABLE)); }
    function actor() { global $phpEx; return phpbb_acp_actor($this, 'admin_cracker_tracker.' . $phpEx . '?modu=8'); }
}

function phpbb_ctracker_user_flag_change($database, $action, $request)
{
    global $userdata;
    if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($request['sid'])
        || !is_string($request['sid']) || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
    if (!in_array($action, array('mark','unmark'), true)) { phpbb_acl_error('Acl_selection_changed'); }
    $id = phpbb_acl_id(isset($request['userid']) ? $request['userid'] : null);
    // The canonical users key is a signed MEDIUMINT; no anonymous/zero IDs.
    if ($id > 8388607) { phpbb_acl_error('Acl_selection_changed'); }
    $mark = $action === 'mark';
    if ($mark && (!isset($request['username']) || !is_string($request['username'])
        || $request['username'] === '' || strpos($request['username'], "\0") !== false
        || preg_match('//u', $request['username']) !== 1)) { phpbb_acl_error('Acl_selection_changed'); }
    $db = new PhpbbCtrackerUserWriter($database);
    try {
        $db->actor();
        $db->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
        $db->sql_query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->sql_query('START TRANSACTION');
        foreach (array(USERS_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE) as $table) {
            $result = $db->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $db->sql_freeresult($result);
            $name = $db->sql_escape($table);
            $rows = phpbb_acl_rows($db, "SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name')");
            $columns = phpbb_acl_rows($db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
            if (count($rows) !== 1 || $rows[0]['ENGINE'] !== 'InnoDB' || strtolower($rows[0]['ROW_FORMAT']) !== 'dynamic'
                || $rows[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $columns) { phpbb_acl_error('Content_admin_failed'); }
        }
        $target = phpbb_acl_rows($db, 'SELECT user_id,username,user_level,ct_miserable_user FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($target) !== 1 || ($mark && $target[0]['username'] !== $request['username'])) { phpbb_acl_error('Acl_selection_changed'); }
        if ($mark && in_array((int)$target[0]['user_level'], array(ADMIN, MOD), true)) { phpbb_acl_error('ctracker_mu_error_admin'); }
        $actor = $db->actor(); $sid = $db->sql_escape($userdata['session_id']);
        foreach (array('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')",
            'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . (int)$actor['user_id'],
            'SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . (int)$actor['user_id']) as $sql) {
            $result = $db->sql_query($sql . ' LOCK IN SHARE MODE'); $db->sql_freeresult($result);
        }
        $actor = $db->actor(); $value = $mark ? 1 : 0;
        $target_guard = $mark ? ' AND user_level NOT IN (' . ADMIN . ',' . MOD . ") AND HEX(username)=HEX('" . $db->sql_escape($request['username']) . "')" : '';
        $db->sql_query('UPDATE ' . USERS_TABLE . ' SET ct_miserable_user=' . $value . ' WHERE user_id=' . $id . $target_guard . ' AND ' . $actor['guard']);
        $stored = phpbb_acl_rows($db, 'SELECT ct_miserable_user FROM ' . USERS_TABLE . ' WHERE user_id=' . $id);
        if (count($stored) !== 1 || $stored[0]['ct_miserable_user'] !== (string)$value) { phpbb_acl_error('Content_admin_failed'); }
        $db->sql_query('COMMIT');
    } finally { $db->release(); }
    return true;
}
