<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';

class PhpbbAbsenceStore extends PhpbbOwnedStore
{
    var $commit_attempted = false;

    function __construct($database, $actor, $sid)
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || !is_string($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST'
            || !is_array($actor) || !isset($actor['user_id'], $actor['session_id'], $actor['session_logged_in'])
            || !in_array($actor['session_logged_in'], array(true, 1, '1'), true)
            || !(is_int($actor['user_id']) || is_string($actor['user_id']))
            || preg_match('/^[1-9][0-9]{0,7}$/D', (string) $actor['user_id']) !== 1 || (float) $actor['user_id'] > 8388607
            || !is_string($sid) || $sid === '' || strlen($sid) > 32 || strpos($sid, "\0") !== false
            || !is_string($actor['session_id']) || !hash_equals($actor['session_id'], $sid)) {
            $this->storage_fail('Session_invalid');
        }
        parent::__construct($database, array('id' => (int) $actor['user_id'], 'sid' => $sid));
    }

    protected function storage_tables() { return array(CONFIG_TABLE, SESSIONS_TABLE, USERS_TABLE); }

    protected function storage_fail($key = 'Absence_save_failed')
    {
        if ($key === 'Owned_storage_failed') { $key = 'Absence_save_failed'; }
        if ($key === 'Owned_storage_upgrade') { $key = 'Absence_storage_upgrade'; }
        throw new PhpbbOwnedStorageFailure($key);
    }

    private function current_actor()
    {
        $settings = $this->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
        if (count($settings) !== 1 || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $settings[0]['config_value']) !== 1
            || (float) $settings[0]['config_value'] > 2147483647) { $this->storage_fail(); }
        $sid = $this->escape($this->actor['sid']); $id = $this->actor['id'];
        $session = $this->rows('SELECT session_time FROM ' . SESSIONS_TABLE . " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 FOR UPDATE");
        if (count($session) !== 1 || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $session[0]['session_time']) !== 1
            || (float) $session[0]['session_time'] < time() - (int) $settings[0]['config_value']) { $this->storage_fail('Session_invalid'); }
        $user = $this->rows('SELECT user_active,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($user) !== 1 || (string) $user[0]['user_active'] !== '1'
            || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string) $user[0]['user_blocktime']) !== 1
            || (float) $user[0]['user_blocktime'] >= time()) { $this->storage_fail('Not_Authorised'); }
    }

    function clear_absence()
    {
        $this->current_actor();
        $this->query('UPDATE ' . USERS_TABLE . ' SET user_absence=0 WHERE user_id=' . $this->actor['id']);
        $rows = $this->rows('SELECT user_absence FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor['id']);
        if (count($rows) !== 1 || (string) $rows[0]['user_absence'] !== '0') { $this->storage_fail(); }
        // The first read pins current authority. Recheck after the write to
        // catch expiry or changes made by a local database trigger as well.
        $this->current_actor();
        $this->commit_attempted = true;
        $this->commit();
    }
}

function phpbb_absence_return($database, $actor, $sid)
{
    $store = null;
    try {
        $store = new PhpbbAbsenceStore($database, $actor, $sid);
        $store->clear_absence();
        return true;
    } catch (PhpbbOwnedStorageFailure $error) {
        if ($store && $store->commit_attempted) { throw new PhpbbOwnedStorageFailure('Absence_save_unconfirmed'); }
        throw $error;
    } catch (Exception $error) {
        throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted ? 'Absence_save_unconfirmed' : 'Absence_save_failed');
    } catch (Error $error) {
        throw new PhpbbOwnedStorageFailure($store && $store->commit_attempted ? 'Absence_save_unconfirmed' : 'Absence_save_failed');
    } finally {
        if ($store) { $store->release(); }
    }
}
