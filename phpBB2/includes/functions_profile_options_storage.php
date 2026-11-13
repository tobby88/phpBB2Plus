<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';

// The descriptors come from includes/mods_settings, never from request data.
class PhpbbProfileOptionsStore extends PhpbbOwnedStore
{
    var $commit_attempted = false;

    function __construct($database, $actor, $sid, $target)
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
            || !is_array($actor) || !isset($actor['user_id'], $actor['session_id'], $actor['session_logged_in'])
            || !in_array($actor['session_logged_in'], array(true, 1, '1'), true)
            || !$this->valid_id($actor['user_id']) || !$this->valid_id($target)
            || !is_string($sid) || $sid === '' || strlen($sid) > 32 || strpos($sid, "\0") !== false
            || !is_string($actor['session_id']) || !hash_equals($actor['session_id'], $sid)) {
            $this->storage_fail('Session_invalid');
        }
        parent::__construct($database, array('id'=>(int)$actor['user_id'], 'sid'=>$sid, 'target'=>(int)$target));
    }

    private function valid_id($value)
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]{0,7}$/D', (string)$value) === 1 && (float)$value <= 8388607;
    }
    protected function storage_tables() { return array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE); }
    protected function storage_fail($key = 'Profile_options_save_failed')
    {
        if ($key === 'Owned_storage_failed') { $key = 'Profile_options_save_failed'; }
        if ($key === 'Owned_storage_upgrade') { $key = 'Profile_options_storage_upgrade'; }
        throw new PhpbbOwnedStorageFailure($key);
    }
    private function current_actor()
    {
        $settings = $this->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
        if (count($settings) !== 1 || preg_match('/^[1-9][0-9]{0,9}$/D', (string)$settings[0]['config_value']) !== 1 || (float)$settings[0]['config_value'] > 2147483647) { $this->storage_fail(); }
        $sid = $this->escape($this->actor['sid']); $id = $this->actor['id'];
        $session = $this->rows('SELECT session_time FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 FOR UPDATE");
        if (count($session) !== 1 || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$session[0]['session_time']) !== 1 || (float)$session[0]['session_time'] < time() - (int)$settings[0]['config_value']) { $this->storage_fail('Session_invalid'); }
        $user = $this->rows('SELECT user_active,user_blocktime,user_level FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($user) !== 1 || (string)$user[0]['user_active'] !== '1' || !in_array((string)$user[0]['user_level'], array((string)USER,(string)MOD,(string)ADMIN), true)
            || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$user[0]['user_blocktime']) !== 1 || (float)$user[0]['user_blocktime'] >= time()
            || ($id !== $this->actor['target'] && (string)$user[0]['user_level'] !== (string)ADMIN)) { $this->storage_fail('Not_Authorised'); }
        return (int)$user[0]['user_level'];
    }
    private function policy($key)
    {
        $name = $this->escape($key . '_over');
        $rows = $this->rows('SELECT config_value FROM ' . CONFIG_TABLE . " WHERE config_name='$name' AND HEX(config_name)=HEX('$name') LOCK IN SHARE MODE");
        // Installer and the full updater persist every bundled override flag.
        if (count($rows) !== 1 || !in_array((string)$rows[0]['config_value'], array('0','1'), true)) { $this->storage_fail('Profile_options_storage_upgrade'); }
        if ((string)$rows[0]['config_value'] !== '0') { $this->storage_fail('Profile_options_overridden'); }
    }
    private function value($column, $field, $raw)
    {
        if (!(is_string($raw) || is_int($raw)) || strpos((string)$raw, "\0") !== false || preg_match('//u', (string)$raw) !== 1) { $this->storage_fail('Profile_options_invalid'); }
        $value = (string)$raw; $type = $field['type'];
        if ($type === 'LIST_RADIO' || $type === 'LIST_DROP') {
            if (!isset($field['values']) || !is_array($field['values'])) { $this->storage_fail(); }
            $allowed = array(); foreach ($field['values'] as $choice) { if (!is_int($choice) && !is_string($choice)) { $this->storage_fail(); } $allowed[] = (string)$choice; }
            if (!in_array($value, $allowed, true)) { $this->storage_fail('Profile_options_invalid'); }
        } elseif (in_array($type, array('TINYINT','SMALLINT','MEDIUMINT','INT'), true)) {
            if (preg_match('/^-?(?:0|[1-9][0-9]{0,9})$/D', $value) !== 1) { $this->storage_fail('Profile_options_invalid'); }
        } elseif (in_array($type, array('VARCHAR','TEXT','DATEFMT','HTMLVARCHAR','HTMLTEXT'), true)) {
            $value = trim($value);
        } elseif (!empty($field['chk_func']) && is_string($field['chk_func']) && function_exists($field['chk_func'])) {
            $value = call_user_func($field['chk_func'], $field['user'], $raw);
            if (!is_string($value) && !is_int($value)) { $this->storage_fail('Profile_options_invalid'); }
            $value = (string)$value;
        } else { $this->storage_fail('Profile_options_storage_upgrade'); }

        $table = $this->escape(USERS_TABLE); $name = $this->escape($column);
        $meta = $this->rows("SELECT COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$table') AND HEX(COLUMN_NAME)=HEX('$name')");
        if (count($meta) !== 1) { $this->storage_fail('Profile_options_storage_upgrade'); }
        if (preg_match('/^(tinyint|smallint|mediumint|int)(?:\([0-9]+\))?( unsigned)?$/i', $meta[0]['COLUMN_TYPE'], $m)) {
            $bits = array('tinyint'=>8,'smallint'=>16,'mediumint'=>24,'int'=>32); $unsigned = !empty($m[2]); $limit = pow(2, $bits[strtolower($m[1])] - ($unsigned ? 0 : 1));
            if (preg_match('/^-?(?:0|[1-9][0-9]{0,9})$/D', $value) !== 1 || (float)$value < ($unsigned ? 0 : -$limit) || (float)$value > $limit - 1) { $this->storage_fail('Profile_options_invalid'); }
            if ($value === '-0') { $value = '0'; }
        } else {
            if ($meta[0]['CHARACTER_MAXIMUM_LENGTH'] === null) { $this->storage_fail('Profile_options_storage_upgrade'); }
            if (strpos($value, "\0") !== false || preg_match('//u', $value) !== 1 || preg_match_all('/./us', $value, $characters) > (float)$meta[0]['CHARACTER_MAXIMUM_LENGTH']) { $this->storage_fail('Profile_options_invalid'); }
        }
        return $value;
    }
    function save($fields, $request)
    {
        if (!is_array($fields) || !is_array($request)) { $this->storage_fail('Profile_options_invalid'); }
        $level = $this->current_actor(); $id = $this->actor['target'];
        $target = $this->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($target) !== 1) { $this->storage_fail('No_such_user'); }
        $changes = $policies = array(); $needs_admin = false;
        foreach ($fields as $key=>$field) {
            if (!is_array($field) || empty($field['user'])) { continue; }
            $column = $field['user'];
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $key) !== 1 || !is_string($column) || preg_match('/^user_[A-Za-z0-9_]{1,59}$/D', $column) !== 1 || !isset($field['auth'],$field['type']) || !is_string($field['type'])) { $this->storage_fail(); }
            if (!array_key_exists($column, $request)) { continue; }
            if ($level !== ADMIN && !empty($field['auth'])) { $this->storage_fail('Not_Authorised'); }
            if (!empty($field['auth'])) { $needs_admin = true; }
            if (!array_key_exists($column, $target[0]) || isset($changes[$column])) { $this->storage_fail('Profile_options_storage_upgrade'); }
            $this->policy($key); $policies[] = $key;
            $changes[$column] = $this->value($column, $field, $request[$column]);
        }
        if ($changes) {
            $set = array(); foreach ($changes as $column=>$value) { $set[] = '`' . $column . "`='" . $this->escape($value) . "'"; }
            $this->query('UPDATE ' . USERS_TABLE . ' SET ' . implode(',', $set) . ' WHERE user_id=' . $id);
            $after = $this->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $id);
            if (count($after) !== 1) { $this->storage_fail(); }
            foreach ($changes as $column=>$value) { if (!array_key_exists($column,$after[0]) || (string)$after[0][$column] !== $value) { $this->storage_fail(); } }
        }
        $level = $this->current_actor();
        if ($needs_admin && $level !== ADMIN) { $this->storage_fail('Not_Authorised'); }
        foreach ($policies as $key) { $this->policy($key); }
        $this->commit_attempted = true; $this->commit();
        return $changes;
    }
}

function phpbb_profile_options_save($database, $actor, $sid, $target, $fields, $request)
{
    $store = null;
    try {
        $store = new PhpbbProfileOptionsStore($database, $actor, $sid, $target);
        return $store->save($fields, $request);
    } catch (PhpbbOwnedStorageFailure $error) {
        if ($store && $store->commit_attempted) { throw new PhpbbOwnedStorageFailure('Profile_options_save_unconfirmed'); }
        throw $error;
    } catch (Exception $error) { throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted ? 'Profile_options_save_unconfirmed' : 'Profile_options_save_failed'); }
    catch (Error $error) { throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted ? 'Profile_options_save_unconfirmed' : 'Profile_options_save_failed'); }
    finally { if ($store) { $store->release(); } }
}
