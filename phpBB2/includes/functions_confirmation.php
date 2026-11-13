<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';

// Share the bundled writers' coordinator and lock the current guest session
// before inspecting its challenge set. Concurrent page renders cannot add a
// fifth code, and an account creation cannot consume a code during reuse.
class PhpbbConfirmationStore extends PhpbbOwnedStore
{
    private $sid;
    function __construct($database, $actor)
    {
        if (!is_array($actor) || !isset($actor['user_id'], $actor['session_id'], $actor['session_logged_in']) ||
            (!is_int($actor['user_id']) && !is_string($actor['user_id'])) || (string)$actor['user_id'] !== '-1' ||
            !in_array($actor['session_logged_in'], array(false, 0, '0'), true) || !is_string($actor['session_id']) ||
            preg_match('/\A[a-f0-9]{32}\z/i', $actor['session_id']) !== 1) {
            $this->storage_fail('Session_invalid');
        }
        $this->sid = $actor['session_id'];
        parent::__construct($database, $actor);
    }
    protected function storage_tables() { return array(SESSIONS_TABLE, CONFIG_TABLE, CONFIRM_TABLE); }
    protected function pin_tables()
    {
        parent::pin_tables();
        foreach (array(SESSIONS_TABLE=>array('session_id'), CONFIG_TABLE=>array('config_name'),
            CONFIRM_TABLE=>array('session_id', 'confirm_id')) as $table=>$columns) {
            $rows = $this->rows("SELECT COLUMN_NAME,SUB_PART,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".
                $this->escape($table)."') AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
            if (count($rows) !== count($columns)) { $this->storage_fail('Owned_storage_upgrade'); }
            foreach ($rows as $index=>$row) {
                if ($row['COLUMN_NAME'] !== $columns[$index] || $row['SUB_PART'] !== null || (string)$row['NON_UNIQUE'] !== '0') {
                    $this->storage_fail('Owned_storage_upgrade');
                }
            }
        }
    }
    function challenge()
    {
        $sid = $this->escape($this->sid);
        $session = $this->rows('SELECT session_user_id,session_logged_in,session_time FROM '.SESSIONS_TABLE.
            " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') FOR UPDATE");
        $settings = $this->rows('SELECT config_value FROM '.CONFIG_TABLE." WHERE config_name='session_length' AND HEX(config_name)=HEX('session_length') LOCK IN SHARE MODE");
        if (count($session) !== 1 || (string)$session[0]['session_user_id'] !== '-1' || (string)$session[0]['session_logged_in'] !== '0' ||
            count($settings) !== 1 || preg_match('/\A[1-9][0-9]{0,9}\z/', (string)$settings[0]['config_value']) !== 1 ||
            (float)$settings[0]['config_value'] > 2147483647 || !is_numeric($session[0]['session_time'])) {
            $this->storage_fail('Session_invalid');
        }
        $ttl = (int)$settings[0]['config_value'];
        if ((int)$session[0]['session_time'] < time() - $ttl) { $this->storage_fail('Session_invalid'); }
        // Ephemeral codes for absent/expired sessions are no longer usable.
        // Keep active sessions' codes, including tabs opened before this update.
        $cutoff = time() - $ttl;
        $this->query('DELETE c FROM '.CONFIRM_TABLE.' c LEFT JOIN '.SESSIONS_TABLE.
            ' s ON s.session_id=c.session_id WHERE s.session_id IS NULL OR s.session_time<'.(int)$cutoff);
        $predicate = " WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')";
        $codes = $this->rows('SELECT confirm_id,code FROM '.CONFIRM_TABLE.$predicate." AND confirm_id NOT LIKE 'r%' ORDER BY confirm_id LIMIT 4 FOR UPDATE");
        foreach ($codes as $row) {
            if (preg_match('/\A[a-f0-9]{32}\z/i', $row['confirm_id']) !== 1 || preg_match('/\A[A-Z0-9]{6}\z/', $row['code']) !== 1) {
                $this->storage_fail('Owned_storage_upgrade');
            }
        }
        if (count($codes) === 4) {
            // Also preserve an older oversized set: never add to it or erase
            // active tabs merely to enforce a new display bound retroactively.
            $id = $codes[0]['confirm_id'];
        } else {
            $code = phpbb_random_string(6, '23456789ABCDEFGHJKLMNPQRSTUVWXYZ');
            $id = bin2hex(phpbb_random_bytes(16));
            if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1 || preg_match('/\A[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}\z/', $code) !== 1) {
                $this->storage_fail();
            }
            $key = $this->escape($id); $value = $this->escape($code);
            $this->query('INSERT INTO '.CONFIRM_TABLE." (confirm_id,session_id,code) VALUES ('$key','$sid','$value')");
            if ($this->connection->sql_affectedrows() !== 1) { $this->storage_fail(); }
            $readback = $this->rows('SELECT code FROM '.CONFIRM_TABLE.$predicate." AND confirm_id='$key' AND HEX(confirm_id)=HEX('$key')");
            if (count($readback) !== 1 || !hash_equals($code, $readback[0]['code'])) { $this->storage_fail(); }
        }
        if ((int)$session[0]['session_time'] < time() - $ttl) { $this->storage_fail('Session_invalid'); }
        $this->commit();
        return $id; // Publish neither the secret nor an unacknowledged result.
    }
}
function phpbb_confirmation_challenge($database, $actor)
{
    $store = null;
    try { $store = new PhpbbConfirmationStore($database, $actor); return $store->challenge(); }
    catch (PhpbbOwnedStorageFailure $error) { throw $error; }
    catch (Exception $error) { throw new PhpbbOwnedStorageFailure('Owned_storage_failed'); }
    catch (Error $error) { throw new PhpbbOwnedStorageFailure('Owned_storage_failed'); }
    finally { if ($store) { $store->release(); } }
}
