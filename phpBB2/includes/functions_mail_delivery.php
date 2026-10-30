<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_owned_storage.php';
require_once dirname(__FILE__) . '/functions_validate.php';
require_once dirname(__FILE__) . '/functions_ban.php';

class PhpbbMailFailure extends RuntimeException {}
function phpbb_mail_fail($key = 'Mail_failed') { throw new PhpbbMailFailure($key); }

// The bootstrap may already have escaped legacy controllers' request values.
// Decode that explicit boundary once; stored account identities are not requests.
function phpbb_mail_input($post, $name)
{
    if (!isset($post[$name]) || !is_string($post[$name])) { phpbb_mail_fail('Mail_invalid'); }
    return phpbb_request_raw_value($post[$name]);
}
function phpbb_mail_text($value, $limit, $header = false, $required = true)
{
    if (!is_string($value) || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) { phpbb_mail_fail('Mail_invalid'); }
    if ($header) { $value = preg_replace('/[\x01-\x1f\x7f]+/', ' ', $value); }
    $value = trim($value);
    if ($required && $value === '') { phpbb_mail_fail('Mail_invalid'); }
    if (strlen($value) > $limit * 4 || preg_match_all('/./us', $value, $characters) > $limit) { phpbb_mail_fail('Mail_too_long'); }
    return $value;
}
function phpbb_mail_address($value)
{
    if (!is_string($value) || strlen($value) > 254 || preg_match('/[\x00-\x20\x7f-\xff]/', $value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) { phpbb_mail_fail('Email_invalid'); }
    return $value;
}
function phpbb_mail_payload($post, $logged)
{
    if (!is_array($post)) { phpbb_mail_fail('Mail_invalid'); }
    $data = array();
    foreach (array('fname'=>100, 'subject'=>200, 'message'=>10000) as $name=>$limit) {
        $data[$name] = phpbb_mail_text(phpbb_mail_input($post, $name), $limit, $name !== 'message', $name !== 'fname');
    }
    $data['femail'] = phpbb_mail_address(trim(phpbb_mail_input($post, 'femail')));
    if (!$logged) {
        $data['sname'] = phpbb_mail_text(phpbb_mail_input($post, 'sname'), 100, true);
        $data['semail'] = phpbb_mail_address(trim(phpbb_mail_input($post, 'semail')));
    }
    return $data;
}

abstract class PhpbbMailStore extends PhpbbOwnedStore
{
    protected $mail_lock;
    protected $claim;
    protected $settings;
    protected $sender;
    protected $sender_address;

    abstract protected function authorize_message($data, $user);
    protected function storage_fail($key = 'Owned_storage_failed')
    {
        phpbb_mail_fail($key === 'Owned_storage_upgrade' ? 'Mail_upgrade' : 'Mail_failed');
    }
    protected function current_mail_user()
    {
        $ttl = $this->rows("SELECT config_value FROM " . CONFIG_TABLE . " WHERE config_name='session_length' FOR UPDATE");
        if (count($ttl) !== 1 || !preg_match('/^[1-9][0-9]{0,9}$/D', $ttl[0]['config_value']) || (float)$ttl[0]['config_value'] > 2147483647) { phpbb_mail_fail(); }
        $sid = $this->escape($this->actor['sid']); $id = $this->actor['id'];
        $sessions = $this->rows("SELECT session_user_id,session_logged_in,session_time FROM " . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') FOR UPDATE");
        if (count($sessions) !== 1 || (int)$sessions[0]['session_user_id'] !== $id || (int)$sessions[0]['session_logged_in'] !== ($this->actor['logged'] ? 1 : 0) ||
            (int)$sessions[0]['session_time'] < time() - (int)$ttl[0]['config_value']) { phpbb_mail_fail('Session_invalid'); }
        $users = $this->rows('SELECT user_id,user_active,user_level,username,user_email,user_emailtime,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' FOR UPDATE');
        if (count($users) !== 1 || ($this->actor['logged'] && ((int)$users[0]['user_active'] !== 1 || !in_array((int)$users[0]['user_level'], array(USER,MOD,ADMIN), true) || (int)$users[0]['user_blocktime'] >= time()))) { phpbb_mail_fail('Not_Authorised'); }
        return $users[0];
    }
    protected function settings()
    {
        $settings = array();
        foreach ($this->rows("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('flood_interval','smtp_delivery','smtp_host','smtp_username','smtp_password','board_email','server_name','sitename','default_lang','sendmail_fix') FOR UPDATE") as $row) {
            $settings[$row['config_name']] = $row['config_value'];
        }
        foreach (array('flood_interval','smtp_delivery','smtp_host','smtp_username','smtp_password','board_email','server_name','sitename','default_lang','sendmail_fix') as $name) {
            if (!isset($settings[$name]) || strpos($settings[$name], "\0") !== false || preg_match('//u', $settings[$name]) !== 1) { phpbb_mail_fail(); }
        }
        if (!preg_match('/^[0-9]{1,6}$/D', $settings['flood_interval']) || !in_array($settings['smtp_delivery'], array('0','1'), true)) { phpbb_mail_fail(); }
        phpbb_mail_address($settings['board_email']);
        return $settings;
    }
    function reserve($data, $address)
    {
        global $db;
        $packed = is_string($address) ? @inet_pton($address) : false;
        if ($packed === false) { phpbb_mail_fail(); }
        $address = inet_ntop($packed);
        $this->sender_address = $address;
        $user = $this->current_mail_user();
        $data = $this->authorize_message($data, $user);
        if ($this->actor['logged']) {
            $data['sname'] = phpbb_mail_text(html_entity_decode($user['username'], ENT_QUOTES, 'UTF-8'), 100, true);
            $data['semail'] = phpbb_mail_address($user['user_email']);
        } else {
            $caller = $db; $db = $this->connection;
            try { $validation = validate_username($data['sname'], false, 0, false, true); }
            finally { $db = $caller; }
            if (!empty($validation['error'])) { phpbb_mail_fail('Mail_sender_invalid'); }
        }
        // Recheck current bans too; no forwarded/proxy header supplies identity.
        foreach ($this->rows('SELECT ban_userid,ban_email,ban_ip,ban_ip_mask FROM ' . BANLIST_TABLE . ' FOR UPDATE') as $ban) {
            if (!phpbb_ip_ban_valid($ban['ban_ip'], $ban['ban_ip_mask']) ||
                ((int)$ban['ban_userid'] !== 0 && (int)$ban['ban_userid'] === $this->actor['id']) ||
                phpbb_email_ban_matches($ban['ban_email'], $data['semail']) ||
                (strlen($packed) === 4 && phpbb_ip_ban_matches($ban['ban_ip'], $ban['ban_ip_mask'], bin2hex($packed)))) { phpbb_mail_fail('Not_Authorised'); }
        }
        $this->settings = $this->settings();
        $ct = array();
        foreach ($this->rows("SELECT ct_config_name,ct_config_value FROM " . CTRACKER_CONFIG . " WHERE ct_config_name IN ('massmail_protection','massmail_time') FOR UPDATE") as $row) { $ct[$row['ct_config_name']] = $row['ct_config_value']; }
        if (!isset($ct['massmail_protection'], $ct['massmail_time']) || !in_array($ct['massmail_protection'], array('0','1'), true) || !preg_match('/^[0-9]{1,6}$/D', $ct['massmail_time'])) { phpbb_mail_fail(); }
        $identity = $this->actor['logged'] ? 'user:' . $this->actor['id'] : $address;
        $bucket = $this->actor['logged'] ? 'mail-user' : 'mail-guest';
        $hash = hash('sha256', $bucket . "\0" . $identity);
        $schema = $this->identity($this->connection);
        $this->mail_lock = 'pa-mail:' . md5($schema['schema_name'] . "\0" . $hash);
        $lease = $this->rows("SELECT GET_LOCK('" . $this->mail_lock . "',0) held");
        if (count($lease) !== 1 || (string)$lease[0]['held'] !== '1') { phpbb_mail_fail('Mail_busy'); }
        $rows = $this->rows("SELECT window_start,request_count,updated_at FROM " . CTRACKER_RATE_LIMITS . " WHERE bucket_hash='" . $hash . "' FOR UPDATE");
        $now = time(); $flood = max(1, (int)$this->settings['flood_interval']);
        $cooldown = $ct['massmail_protection'] === '1' ? max(1, min(180, (int)$ct['massmail_time'])) * 60 : 0;
        $old = $rows ? $rows[0] : array('window_start'=>0,'request_count'=>0,'updated_at'=>0);
        if ($now - (int)$old['window_start'] < $flood || ($cooldown && $now - (int)$old['updated_at'] < $cooldown) ||
            (int)$old['updated_at'] > $now || ($this->actor['logged'] && $now - (int)$user['user_emailtime'] < $flood)) { phpbb_mail_fail('Mail_wait'); }
        if ((float)$old['request_count'] >= 4294967294 || $now > 2147483047) { phpbb_mail_fail(); }
        $generation = (int)$old['request_count'] + 1; $pending = $now + 600;
        if ($rows) {
            $this->query('UPDATE ' . CTRACKER_RATE_LIMITS . ' SET window_start=' . $now . ',request_count=' . $generation . ',updated_at=' . $pending . " WHERE bucket_hash='" . $hash . "'");
        } else {
            $this->query('INSERT INTO ' . CTRACKER_RATE_LIMITS . " (bucket_hash,window_start,request_count,updated_at) VALUES('" . $hash . "'," . $now . ',' . $generation . ',' . $pending . ')');
        }
        if ($this->actor['logged']) { $this->query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . $pending . ' WHERE user_id=' . $this->actor['id']); }
        $this->claim = array('hash'=>$hash,'generation'=>$generation,'start'=>$now,'pending'=>$pending,'old_success'=>(int)$old['updated_at'],'old_email'=>(int)$user['user_emailtime']);
        $this->sender = $data;
        $this->commit();
        // Keep only the per-sender lease during network I/O. No row transaction
        // or forum-wide attachment lock may be held across SMTP/mail().
        $released = $this->direct_rows($this->connection, "SELECT RELEASE_LOCK('" . $this->lock . "') released");
        if (count($released) !== 1 || (string)$released[0]['released'] !== '1') { phpbb_mail_fail(); }
        $this->held = false;
        return $data;
    }
    protected function delivery_query($sql)
    {
        $state = $this->direct_rows($this->connection, "SELECT CONNECTION_ID() id,IS_USED_LOCK('" . $this->mail_lock . "') owner");
        if (count($state) !== 1 || (string)$state[0]['id'] !== $this->identity || (string)$state[0]['owner'] !== $this->identity) { phpbb_mail_fail(); }
        $result = $this->connection->sql_query($sql);
        if (!$result) { phpbb_mail_fail(); }
        return $result;
    }
    protected function settle($accepted)
    {
        $claim = $this->claim; $now = time();
        $this->delivery_query('START TRANSACTION'); $this->transaction = true;
        $this->delivery_query('UPDATE ' . CTRACKER_RATE_LIMITS . ' SET updated_at=' . ($accepted ? $now : $claim['old_success']) . ',window_start=' . $now .
            " WHERE bucket_hash='" . $claim['hash'] . "' AND request_count=" . $claim['generation'] . ' AND window_start=' . $claim['start'] . ' AND updated_at=' . $claim['pending']);
        if ((int)$this->connection->sql_affectedrows() !== 1) { phpbb_mail_fail(); }
        if ($this->actor['logged']) {
            $this->delivery_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . max($now, $claim['old_email']) . ' WHERE user_id=' . $this->actor['id'] . ' AND user_emailtime=' . $claim['pending']);
        }
        $this->delivery_query('COMMIT'); $this->transaction = false;
    }
    protected function send_message($factory, $recipient)
    {
        $mailer = call_user_func($factory, $this->settings['smtp_delivery']);
        $data = $this->sender;
        $mailer->use_template(isset($data['template']) ? $data['template'] : 'profile_send_email', isset($data['language']) ? $data['language'] : $this->settings['default_lang']);
        $mailer->email_address($recipient); $mailer->from($this->settings['board_email']); $mailer->replyto($data['semail']);
        $mailer->set_subject($data['subject']);
        $server = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $this->settings['server_name']);
        $headers = 'X-AntiAbuse: Board servername - ' . $server . "\nX-AntiAbuse: Username - " . $data['sname'] . "\n";
        if (!empty($data['actor_headers'])) { $headers .= 'X-AntiAbuse: User_id - ' . $this->actor['id'] . "\nX-AntiAbuse: User IP - " . $this->sender_address . "\n"; }
        $mailer->extra_headers($headers);
        $mailer->assign_vars(array('SITENAME'=>$this->settings['sitename'],'BOARD_EMAIL'=>$this->settings['board_email'],
            'FROM_USERNAME'=>$data['sname'],'TO_USERNAME'=>$data['fname'],'MESSAGE'=>$data['message']));
        return $mailer->send() === true;
    }
    function deliver($factory)
    {
        global $board_config;
        $previous = $board_config; $accepted = false; $copy_sent = null;
        try {
            $board_config = array_merge($board_config, $this->settings);
            $data = $this->sender;
            $accepted = $this->send_message($factory, $data['femail']);
            if ($accepted && !empty($data['copy'])) {
                // A BCC copy would reveal the profile recipient's address in
                // its To header. Keep separate envelopes and the same lease.
                $copy_sent = $data['femail'] === $data['semail'] || $this->send_message($factory, $data['semail']);
            }
        } catch (Exception $error) { if ($accepted) { $copy_sent = false; } }
        catch (Error $error) { if ($accepted) { $copy_sent = false; } }
        finally { $board_config = $previous; }
        try { $this->settle($accepted); }
        catch (Exception $error) { error_log('phpBB mail delivery reservation could not be finalized'); }
        catch (Error $error) { error_log('phpBB mail delivery reservation could not be finalized'); }
        // Once transport accepted the message, never invite a duplicate retry
        // just because the acknowledgement timestamp could not be finalized.
        if (!$accepted) { phpbb_mail_fail(); }
        return array('sent'=>true, 'copy_sent'=>$copy_sent);
    }
}
function phpbb_mail_actor($allow_guest = false, $post = null)
{
    global $userdata;
    if ($post === null) { $post = $_POST; }
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($userdata['session_id']) || !is_string($userdata['session_id']) ||
        $userdata['session_id'] === '' || strlen($userdata['session_id']) > 32 || !is_array($post) || !isset($post['sid']) || !is_string($post['sid']) || !hash_equals($userdata['session_id'], $post['sid'])) { phpbb_mail_fail('Session_invalid'); }
    $logged = isset($userdata['session_logged_in']) && in_array($userdata['session_logged_in'], array(true,1,'1'), true);
    $id = isset($userdata['user_id']) ? $userdata['user_id'] : null;
    if (!(is_int($id) || is_string($id)) || ($logged ? (!preg_match('/^[1-9][0-9]{0,6}$/D', (string)$id) || (int)$id > 8388607) : (!$allow_guest || (string)$id !== (string)ANONYMOUS))) { phpbb_mail_fail('Not_Authorised'); }
    return array('id'=>(int)$id,'sid'=>$userdata['session_id'],'logged'=>$logged);
}
