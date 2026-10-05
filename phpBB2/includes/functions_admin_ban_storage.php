<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_login_storage.php';
require_once dirname(__FILE__) . '/functions_acl_storage.php';
require_once dirname(__FILE__) . '/functions_ban.php';

// Own the entire ACP request on a separate canonical InnoDB connection. The
// parser's get_userdata/CrackerTracker reads use this owner too, not an old
// caller snapshot. No response is published before acknowledged COMMIT.
class PhpbbAdminBanScope extends PhpbbLoginDatabase
{
    var $ready = false;
    var $actor_id;
    var $warnings;
    function __construct($database, $request)
    {
        global $userdata;
        if (!is_array($request) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
            || !isset($request['sid'], $userdata['session_id']) || !is_string($request['sid']) || !is_string($userdata['session_id'])
            || !hash_equals($userdata['session_id'], $request['sid'])) { phpbb_acl_error('Session_invalid'); }
        foreach (array('username','ban_ip','ban_email') as $field) {
            if (isset($request[$field]) && !is_string($request[$field])) { phpbb_acl_error('Acl_selection_changed'); }
        }
        foreach (array('ban_ip','ban_email') as $field) {
            if (isset($request[$field]) && substr_count($request[$field], ',') >= 100) { phpbb_acl_error('Acl_selection_changed'); }
        }
        parent::__construct($database, array(JR_ADMIN_TABLE));
        try {
            $actor = $this->actor(); $this->actor_id = (int)$actor['user_id'];
            // The live session, role and exact junior grant stay locked until
            // the whole request commits. Current reads never use an RR snapshot.
            $rows = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='max_user_bancard' LOCK IN SHARE MODE");
            if (count($rows) !== 1 || !preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', (string)$rows[0]['config_value'])
                || (int)$rows[0]['config_value'] > 32767) { phpbb_acl_error('Ban_storage_failed'); }
            $this->warnings = (int)$rows[0]['config_value'];
            $this->ready = true;
        } catch (Exception $e) { $this->release(); throw $e; }
        catch (Error $e) { $this->release(); throw $e; }
    }
    function sql_query($sql, $transaction = false)
    {
        if (is_string($sql) && preg_match('/^\s*SELECT\b/i', $sql) && stripos($sql, 'information_schema.') === false
            && !preg_match('/(?:FOR UPDATE|LOCK IN SHARE MODE)\s*$/i', $sql)) {
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
        return phpbb_acp_actor($this, 'admin_user_ban.' . $phpEx);
    }
    function insert_rule($user, $ip, $email)
    {
        // Explicit neutral columns are required by the canonical strict schema.
        $this->sql_query('INSERT INTO ' . BANLIST_TABLE . " (ban_userid,ban_ip,ban_email) VALUES (" . $user
            . ",'" . $this->sql_escape($ip) . "','" . $this->sql_escape($email) . "')");
        if ((int)$this->sql_affectedrows() !== 1) { phpbb_acl_error('Ban_storage_failed'); }
    }
    function save($users, $ips, $emails, $request)
    {
        if (!$this->ready || !$this->transactional || !is_array($users) || count($users) > 1
            || !is_array($ips) || count($ips) > 4096 || !is_array($emails) || count($emails) > 100) { phpbb_acl_error('Acl_selection_changed'); }
        $actor = $this->actor();
        $rules = $this->rows('SELECT ban_id,ban_userid,ban_ip,ban_email FROM ' . BANLIST_TABLE . ' ORDER BY ban_id FOR UPDATE');
        $selected = array();
        foreach (array('unban_user'=>'ban_userid','unban_ip'=>'ban_ip','unban_email'=>'ban_email') as $field=>$column) {
            if (!isset($request[$field])) { continue; }
            if (!is_array($request[$field]) || count($request[$field]) > 10000) { phpbb_acl_error('Acl_selection_changed'); }
            foreach ($request[$field] as $value) {
                // The existing empty-list option is not a selected database row.
                if ($value === '-1') { continue; }
                $id = phpbb_acl_id($value); $found = false;
                foreach ($rules as $rule) {
                    if ((int)$rule['ban_id'] === $id && ($column === 'ban_userid' ? (int)$rule[$column] > 0 : (string)$rule[$column] !== '')) {
                        $selected[$id] = $rule; $found = true; break;
                    }
                }
                if (!$found) { phpbb_acl_error('Acl_selection_changed'); }
            }
        }
        foreach ($users as $value) {
            $id = phpbb_acl_id($value);
            if ($id === $this->actor_id) { phpbb_acl_error('Ban_self_disable'); }
            $target = $this->rows('SELECT user_id,user_level FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
            if (count($target) !== 1) { phpbb_acl_error('Acl_selection_changed'); }
            if ((int)$target[0]['user_level'] === ADMIN && !$actor['root']) { phpbb_acl_error('Not_Authorised'); }
            $first = $this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_level=' . ADMIN . ' AND user_id>0 ORDER BY user_id LIMIT 1');
            if ($first && (int)$first[0]['user_id'] === $id) { phpbb_acl_error('ctracker_gmb_1stadmin'); }
            $present = false; foreach ($rules as $rule) { if ((int)$rule['ban_userid'] === $id) { $present = true; break; } }
            if (!$present) { $this->insert_rule($id, '', ''); }
            $this->sql_query('UPDATE ' . USERS_TABLE . ' SET user_warnings=' . $this->warnings . ' WHERE user_id=' . $id);
            $this->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $id);
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || !preg_match('/^[a-f0-9]{8}$/iD', $ip)) { phpbb_acl_error('Acl_selection_changed'); }
            // Match exactly the four candidate prefixes used by session_begin,
            // under the database collation (including historical upper-case hex).
            $escaped = $this->sql_escape($ip);
            $match = "'$escaped' IN (session_ip,CONCAT(SUBSTRING(session_ip,1,6),'ff'),CONCAT(SUBSTRING(session_ip,1,4),'ffff'),CONCAT(SUBSTRING(session_ip,1,2),'ffffff'))";
            if ($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . ' WHERE session_user_id=' . $this->actor_id . ' AND ' . $match)) { phpbb_acl_error('Ban_self_disable'); }
            if (!$this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . " WHERE ban_ip='$escaped'")) { $this->insert_rule(0, $ip, ''); }
            $this->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE ' . $match);
        }
        if ($emails) {
            // An email change or login must happen before or after invalidation.
            // The bounded matcher is shared with new-session/registration checks.
            $accounts = $this->rows('SELECT user_id,user_email,user_level FROM ' . USERS_TABLE . ' ORDER BY user_id LOCK IN SHARE MODE');
            $first = 0;
            foreach ($accounts as $account) { if ((int)$account['user_id'] > 0 && (int)$account['user_level'] === ADMIN) { $first = (int)$account['user_id']; break; } }
            $expire = array();
            foreach ($emails as $email) {
                if (!is_string($email) || $email === '' || strlen($email) > 255 || preg_match('/[\x00\r\n]/', $email)) { phpbb_acl_error('Acl_selection_changed'); }
                foreach ($accounts as $account) {
                    $id = (int)$account['user_id'];
                    if ($id > 0 && phpbb_email_ban_matches($email, $account['user_email'])) {
                        if ($id === $first) { phpbb_acl_error('ctracker_gmb_1stadmin'); }
                        if ($id === $this->actor_id) { phpbb_acl_error('Ban_self_disable'); }
                        if ((int)$account['user_level'] === ADMIN && !$actor['root']) { phpbb_acl_error('Not_Authorised'); }
                        $expire[$id] = $id;
                    }
                }
                if (!$this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . " WHERE ban_email='" . $this->sql_escape($email) . "'")) { $this->insert_rule(0, '', $email); }
            }
            if ($expire) { $this->sql_query('DELETE FROM ' . SESSIONS_TABLE . ' WHERE session_user_id IN (' . implode(',', $expire) . ')'); }
        }
        if ($selected) {
            $this->sql_query('DELETE FROM ' . BANLIST_TABLE . ' WHERE ban_id IN (' . implode(',', array_keys($selected)) . ')');
            $targets = array(); foreach ($selected as $rule) { if ((int)$rule['ban_userid'] > 0) { $targets[(int)$rule['ban_userid']] = (int)$rule['ban_userid']; } }
            foreach ($targets as $id) {
                // Removing one duplicate user ban must not clear warnings while
                // another user ban still applies. IP/email bans do not own them.
                if (!$this->rows('SELECT ban_id FROM ' . BANLIST_TABLE . ' WHERE ban_userid=' . $id)) {
                    $this->sql_query('UPDATE ' . USERS_TABLE . ' SET user_warnings=0 WHERE user_id=' . $id);
                }
            }
        }
        $this->actor();
    }
    function commit()
    {
        $this->actor(); parent::commit(); $this->ready = false;
    }
}
