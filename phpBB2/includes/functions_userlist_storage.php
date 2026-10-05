<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_login_storage.php';
require_once dirname(__FILE__) . '/functions_acl_storage.php';

class PhpbbUserlistException extends RuntimeException {}
function phpbb_userlist_error($key)
{
    global $lang;
    throw new PhpbbUserlistException(isset($lang[$key]) ? $lang[$key] : $key);
}
function phpbb_userlist_id($value)
{
    if (!(is_int($value) || is_string($value)) || !preg_match('/^[0-9]+$/D', (string)$value)) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
    $value = ltrim((string)$value, '0');
    if ($value === '' || strlen($value) > 8 || (int)$value > 16777215) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
    return (int)$value;
}

// Same independent transaction/mutex as login, with action-specific additional
// tables. A failed batch cannot expire sessions or apply only its first targets.
class PhpbbUserlistDatabase extends PhpbbLoginDatabase
{
    function __construct($database, $action)
    {
        $tables = array(JR_ADMIN_TABLE);
        if ($action === 'group') { $tables = array_merge($tables, array(GROUPS_TABLE, USER_GROUP_TABLE, AUTH_ACCESS_TABLE, FORUMS_TABLE)); }
        parent::__construct($database, $tables);
        try { $this->actor(); }
        catch (Exception $e) { $this->release(); throw $e; }
        catch (Error $e) { $this->release(); throw $e; }
    }
    function sql_query($sql, $transaction = false)
    {
        // Every authority/policy/eligibility read sees current committed rows,
        // never an older RR snapshot, and pins them through complete COMMIT.
        if (is_string($sql) && preg_match('/^\\s*SELECT\\b/i', $sql) && stripos($sql, 'information_schema.') === false
            && !preg_match('/(?:FOR UPDATE|LOCK IN SHARE MODE)\\s*$/i', $sql)) {
            $sql = rtrim($sql, "; \t\r\n") . ' LOCK IN SHARE MODE';
        }
        return parent::sql_query($sql, $transaction);
    }
    function actor()
    {
        global $userdata, $phpEx;
        $id = phpbb_acl_id(isset($userdata['user_id']) ? $userdata['user_id'] : null);
        $sid = isset($userdata['session_id']) && is_string($userdata['session_id']) ? $this->sql_escape($userdata['session_id']) : '';
        if (!$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')"
            . ' AND session_user_id=' . $id . ' AND session_logged_in=1 AND session_admin=1 LOCK IN SHARE MODE')) { phpbb_acl_error('Not_Authorised'); }
        return phpbb_acp_actor($this, 'admin_users_list.' . $phpEx);
    }
    function commit() { $this->actor(); parent::commit(); }
}

function phpbb_userlist_apply($database, $action, $selection, $group = null)
{
    global $userdata;
    if (!is_string($action) || !in_array($action, array('activate','deactivate','ban','unban','group'), true)
        || !is_array($selection) || !$selection || count($selection) > 1000) { phpbb_userlist_error('Admin_userlist_invalid_selection'); }
    $ids = array();
    foreach ($selection as $value) { $id = phpbb_userlist_id($value); $ids[$id] = $id; }
    ksort($ids, SORT_NUMERIC);
    $group_id = $action === 'group' ? phpbb_userlist_id($group) : 0;
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($userdata['session_id']) || !is_string($userdata['session_id'])
        || !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { phpbb_userlist_error('Session_invalid'); }
    $db = null;
    try {
        $db = new PhpbbUserlistDatabase($database, $action); $actor = $db->actor();
        $limit = 0;
        if ($action === 'ban') {
            $rows = $db->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='max_user_bancard'");
            if (count($rows) !== 1 || !preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', (string)$rows[0]['config_value'])
                || (int)$rows[0]['config_value'] > 32767) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
            $limit = (int)$rows[0]['config_value'];
        }
        if ($action === 'group') {
            if (!$db->rows('SELECT group_id FROM ' . GROUPS_TABLE . ' WHERE group_id=' . $group_id . ' AND group_single_user=0 FOR UPDATE')) { phpbb_userlist_error('Admin_userlist_invalid_group'); }
        }
        $targets = $db->rows('SELECT user_id,user_level,user_active,user_warnings FROM ' . USERS_TABLE . ' WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY user_id FOR UPDATE');
        if ($action === 'group') {
            // Hold all selected membership ranges and the current role inputs.
            // Normal/orphan ACLs cannot create a moderator; valid memberships in
            // other groups still preserve the role when this group is ordinary.
            $db->rows('SELECT user_id,group_id,user_pending FROM ' . USER_GROUP_TABLE . ' WHERE user_id IN (' . implode(',', $ids) . ') ORDER BY user_id,group_id FOR UPDATE');
            $db->rows('SELECT group_id FROM ' . GROUPS_TABLE . ' ORDER BY group_id');
            $db->rows('SELECT group_id,forum_id,auth_mod FROM ' . AUTH_ACCESS_TABLE . ' ORDER BY group_id,forum_id');
            $db->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' ORDER BY forum_id');
        }
        $changed = 0;
        foreach ($targets as $target) {
            $id = (int)$target['user_id'];
            if ($id <= 0 || $id === (int)$actor['user_id'] || (int)$target['user_level'] === ADMIN) { continue; }
            $db->actor(); $count = 0;
            // This invalidation is part of the same transaction, including
            // repeated no-op forms. Protected/absent targets are never touched.
            $db->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $id);
            if ($action === 'activate' || $action === 'deactivate') {
                $active = $action === 'activate' ? 1 : 0;
                $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_active=' . $active . ' WHERE user_id=' . $id . ' AND (user_active IS NULL OR user_active<>' . $active . ')');
                $count += (int)$db->sql_affectedrows();
            } elseif ($action === 'ban') {
                if (!$db->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id . ' FOR UPDATE')) {
                    $db->sql_query('INSERT INTO ' . BANLIST_TABLE . " (ban_userid,ban_ip,ban_email) VALUES ($id,'','')");
                    if ((int)$db->sql_affectedrows() !== 1) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
                    $count++;
                }
                $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_warnings=' . $limit . ' WHERE user_id=' . $id . ' AND (user_warnings IS NULL OR user_warnings<>' . $limit . ')');
                $count += (int)$db->sql_affectedrows();
            } elseif ($action === 'unban') {
                $bans = $db->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id . ' FOR UPDATE');
                if ($bans) {
                    // Remove only the user component of historical mixed rules.
                    // Independent IP/email rules and warnings of unbanned users
                    // are not blanket-reset by an unrelated bulk form.
                    $db->sql_query('DELETE FROM ' . BANLIST_TABLE . " WHERE ban_userid=$id AND ban_ip='' AND (ban_email IS NULL OR ban_email='')");
                    $count += (int)$db->sql_affectedrows();
                    $db->sql_query('UPDATE ' . BANLIST_TABLE . ' SET ban_userid=0 WHERE ban_userid=' . $id);
                    $count += (int)$db->sql_affectedrows();
                    $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_warnings=0 WHERE user_id=' . $id . ' AND (user_warnings IS NULL OR user_warnings<>0)');
                }
            } else {
                $membership = $db->rows('SELECT user_pending FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $id . ' AND group_id=' . $group_id . ' FOR UPDATE');
                if (count($membership) > 1) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
                if (!$membership) {
                    $db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . ' (group_id,user_id,user_pending) VALUES (' . $group_id . ',' . $id . ',0)');
                    if ((int)$db->sql_affectedrows() !== 1) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
                    $count++;
                } else {
                    $db->sql_query('UPDATE ' . USER_GROUP_TABLE . ' SET user_pending=0 WHERE user_id=' . $id . ' AND group_id=' . $group_id . ' AND user_pending<>0');
                    $count += (int)$db->sql_affectedrows();
                }
                $role = $db->rows('SELECT 1 AS allowed WHERE ' . phpbb_acl_mod_guard($id)) ? MOD : USER;
                $db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_level=' . $role . ' WHERE user_id=' . $id . ' AND (user_level IS NULL OR user_level IN (' . USER . ',' . MOD . ')) AND (user_level IS NULL OR user_level<>' . $role . ')');
                $count += (int)$db->sql_affectedrows();
            }
            if ($count) { $changed++; }
        }
        $db->commit();
        return array('changed'=>$changed, 'unchanged'=>count($ids)-$changed);
    } catch (PhpbbAclException $e) { throw new PhpbbUserlistException($e->getMessage()); }
    catch (PhpbbLoginException $e) { phpbb_userlist_error('Admin_userlist_storage_failed'); }
    finally { if ($db) { $db->release(); } }
}
