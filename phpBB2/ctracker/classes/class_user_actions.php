<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(dirname(__DIR__)) . '/includes/functions_owned_storage.php';

class CtrackerUserActionFailure extends RuntimeException {}

// Notice tokens describe the displayed content, not just the button. Length
// prefixes preserve exact historical bytes without ambiguous concatenation.
function ctracker_user_notice_context($action, $user, $settings)
{
    if (!is_array($user) || !is_array($settings)) { throw new CtrackerUserActionFailure('Session_invalid'); }
    if ($action === 'ipfeature') {
        $values = array(isset($user['ct_last_ip']) ? $user['ct_last_ip'] : '', isset($user['ct_last_used_ip']) ? $user['ct_last_used_ip'] : '');
    } elseif ($action === 'globmsg') {
        $values = array(isset($settings['global_message']) ? $settings['global_message'] : '', isset($settings['global_message_type']) ? (string) (int) $settings['global_message_type'] : '0');
    } else { throw new CtrackerUserActionFailure('Session_invalid'); }
    $bytes = $action . "\0";
    foreach ($values as $value) {
        if (!is_string($value)) { throw new CtrackerUserActionFailure('Session_invalid'); }
        $bytes .= pack('N', strlen($value)) . $value;
    }
    return hash('sha256', $bytes);
}

function ctracker_user_notice_token($action, $context, $user)
{
    if (!in_array($action, array('ipfeature', 'globmsg'), true) || !is_string($context) || !preg_match('/^[a-f0-9]{64}$/D', $context)) {
        throw new CtrackerUserActionFailure('Session_invalid');
    }
    return phpbb_session_action_token('ctracker-message', $action . '_' . $context, (int) $user['user_id'], $user['session_id']);
}

function ctracker_user_request($action)
{
    global $userdata;
    if (!in_array($action, array('preference', 'ipfeature', 'globmsg'), true) || !is_array($userdata)
        || !isset($userdata['user_id'], $userdata['session_id'], $userdata['session_logged_in'])
        || !(is_int($userdata['user_id']) || is_string($userdata['user_id']))
        || !preg_match('/^[1-9][0-9]{0,6}$/D', (string) $userdata['user_id']) || (int) $userdata['user_id'] > 8388607
        || !is_string($userdata['session_id']) || $userdata['session_id'] === '' || strlen($userdata['session_id']) > 32
        || !in_array($userdata['session_logged_in'], array(true, 1, '1'), true)
        || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new CtrackerUserActionFailure('Session_invalid');
    }
    $request = array('id' => (int) $userdata['user_id'], 'sid' => $userdata['session_id']);
    if ($action === 'preference') {
        if (!isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($request['sid'], $_POST['sid'])) {
            throw new CtrackerUserActionFailure('Session_invalid');
        }
        $value = array_key_exists('ct_enable_ip_warn', $_POST) ? $_POST['ct_enable_ip_warn'] : '0';
        if (!in_array($value, array(0, 1, '0', '1'), true)) { throw new CtrackerUserActionFailure('Not_Authorised'); }
        $request['value'] = (int) $value;
    } else {
        if (!isset($_POST['marknow'], $_POST['ct_context'], $_POST['ct_token']) || $_POST['marknow'] !== $action
            || !is_string($_POST['ct_context']) || !is_string($_POST['ct_token'])) {
            throw new CtrackerUserActionFailure('Session_invalid');
        }
        $expected = ctracker_user_notice_token($action, $_POST['ct_context'], $userdata);
        if (!hash_equals($expected, $_POST['ct_token'])) { throw new CtrackerUserActionFailure('Session_invalid'); }
        $request['context'] = $_POST['ct_context'];
    }
    return $request;
}

// Ordinary self-service actions have no ACP/JR requirement. The owner exposes
// only these three fixed mutations; it never commits the caller's connection.
class CtrackerUserActions extends PhpbbOwnedStore
{
    private $session_time;
    private $session_length;
    protected function storage_tables() { return array(USERS_TABLE, SESSIONS_TABLE, CONFIG_TABLE, CTRACKER_CONFIG); }
    function rows($sql) { if (!is_string($sql) || !preg_match('/^SELECT\b/i', $sql)) { $this->storage_fail(); } return parent::rows($sql); }
    private function current_user()
    {
        $sid = $this->escape($this->actor['sid']);
        $sessions = $this->rows('SELECT session_user_id,session_logged_in,session_time FROM ' . SESSIONS_TABLE
            . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') LOCK IN SHARE MODE");
        if (count($sessions) !== 1 || (string) $sessions[0]['session_user_id'] !== (string) $this->actor['id']
            || (string) $sessions[0]['session_logged_in'] !== '1' || !preg_match('/^[0-9]+$/D', (string) $sessions[0]['session_time'])) {
            throw new CtrackerUserActionFailure('Session_invalid');
        }
        $users = $this->rows('SELECT user_id,user_active,user_level,user_blocktime,ct_enable_ip_warn,ct_last_ip,ct_last_used_ip,ct_global_msg_read FROM '
            . USERS_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' FOR UPDATE');
        if (count($users) !== 1 || (string) $users[0]['user_active'] !== '1'
            || !in_array((int) $users[0]['user_level'], array(USER, MOD, ADMIN), true)
            || !preg_match('/^-?[0-9]+$/D', (string) $users[0]['user_blocktime']) || (float) $users[0]['user_blocktime'] >= time()) {
            throw new CtrackerUserActionFailure('Not_Authorised');
        }
        $policy = $this->rows('SELECT config_name,config_value FROM ' . CONFIG_TABLE
            . " WHERE config_name IN ('session_length','board_disable') LOCK IN SHARE MODE");
        $settings = array(); foreach ($policy as $row) { $settings[$row['config_name']] = $row['config_value']; }
        if (count($policy) !== 2 || !isset($settings['session_length'], $settings['board_disable'])
            || !preg_match('/^[1-9][0-9]*$/D', (string) $settings['session_length']) || (float) $settings['session_length'] > 2147483647
            || !in_array((string) $settings['board_disable'], array('0', '1'), true)) { $this->storage_fail(); }
        if ((string) $settings['board_disable'] === '1' && (int) $users[0]['user_level'] !== ADMIN) {
            throw new CtrackerUserActionFailure('Not_Authorised');
        }
        $this->session_time = (float) $sessions[0]['session_time'];
        $this->session_length = (int) $settings['session_length'];
        $this->check_expiry();
        return $users[0];
    }
    private function check_expiry()
    {
        if ($this->session_time < time() - $this->session_length) { throw new CtrackerUserActionFailure('Session_invalid'); }
    }
    private function settings($action)
    {
        $keys = ($action === 'globmsg') ? array('global_message', 'global_message_type') : array('login_ip_check');
        $rows = $this->rows('SELECT ct_config_name,ct_config_value FROM ' . CTRACKER_CONFIG
            . " WHERE ct_config_name IN ('" . implode("','", $keys) . "') LOCK IN SHARE MODE");
        $values = array(); foreach ($rows as $row) { $values[$row['ct_config_name']] = $row['ct_config_value']; }
        if (count($rows) !== count($keys)) { $this->storage_fail(); }
        foreach ($keys as $key) { if (!isset($values[$key]) || !is_string($values[$key])) { $this->storage_fail(); } }
        if ($action !== 'globmsg' && $values['login_ip_check'] !== '1') { throw new CtrackerUserActionFailure('Not_Authorised'); }
        if ($action === 'globmsg' && !in_array($values['global_message_type'], array('0', '1'), true)) { $this->storage_fail(); }
        return $values;
    }
    function apply($action)
    {
        $request = ctracker_user_request($action);
        if ($request !== $this->actor) { throw new CtrackerUserActionFailure('Session_invalid'); }
        $user = $this->current_user(); $settings = $this->settings($action);
        if ($action === 'preference') {
            $changes = array('ct_enable_ip_warn' => $request['value']);
            $assignment = 'ct_enable_ip_warn=' . $request['value'];
        } elseif ($action === 'ipfeature') {
            if ((string) $user['ct_enable_ip_warn'] !== '1') { throw new CtrackerUserActionFailure('Not_Authorised'); }
            $changes = array('ct_last_ip' => $user['ct_last_used_ip']);
            $assignment = ($user['ct_last_ip'] === $user['ct_last_used_ip']) ? null : 'ct_last_ip=ct_last_used_ip';
        } else {
            $changes = array('ct_global_msg_read' => 0);
            $assignment = ((string) $user['ct_global_msg_read'] === '1') ? 'ct_global_msg_read=0' : null;
            if ($assignment !== null && $settings['global_message'] === '') { throw new CtrackerUserActionFailure('Ctracker_notice_changed'); }
        }
        if ($action !== 'preference' && $assignment !== null && !hash_equals(ctracker_user_notice_context($action, $user, $settings), $request['context'])) {
            throw new CtrackerUserActionFailure('Ctracker_notice_changed');
        }
        if ($assignment !== null) {
            $this->query('UPDATE ' . USERS_TABLE . ' SET ' . $assignment . ' WHERE user_id=' . $this->actor['id']);
            $saved = $this->rows('SELECT ct_enable_ip_warn,ct_last_ip,ct_global_msg_read FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor['id']);
            if (count($saved) !== 1) { $this->storage_fail(); }
            foreach ($changes as $key => $value) {
                if ((string) $saved[0][$key] !== (string) $value || ($value === null && $saved[0][$key] !== null)) { $this->storage_fail(); }
            }
        }
        $this->check_expiry();
        // Repeat cheap POST/identity validation before committing, without
        // trusting changes made to request globals during rendering/hooks.
        if (ctracker_user_request($action) !== $this->actor) { throw new CtrackerUserActionFailure('Session_invalid'); }
        $this->commit();
        return $changes;
    }
}

function ctracker_user_apply($database, $action)
{
    $actor = ctracker_user_request($action);
    $owner = new CtrackerUserActions($database, $actor);
    try { return $owner->apply($action); }
    finally { $owner->release(); }
}
