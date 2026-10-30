<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_mutation_storage.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_validate.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_ban.php';

class PaFileDbMailFailure extends PaFileDbMutationFailure {}
function pafiledb_mail_fail($key = 'PA_mail_failed') { throw new PaFileDbMailFailure($key); }

// The bootstrap may already have escaped legacy controllers' request values.
// Decode that explicit boundary once; stored account identities are not requests.
function pafiledb_mail_input($post, $name)
{
    if (!isset($post[$name]) || !is_string($post[$name])) { pafiledb_mail_fail('PA_mail_invalid'); }
    return phpbb_request_raw_value($post[$name]);
}
function pafiledb_mail_text($value, $limit, $header = false, $required = true)
{
    if (!is_string($value) || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) { pafiledb_mail_fail('PA_mail_invalid'); }
    if ($header) { $value = preg_replace('/[\x01-\x1f\x7f]+/', ' ', $value); }
    $value = trim($value);
    if ($required && $value === '') { pafiledb_mail_fail('PA_mail_invalid'); }
    if (strlen($value) > $limit * 4 || preg_match_all('/./us', $value, $characters) > $limit) { pafiledb_mail_fail('PA_mail_too_long'); }
    return $value;
}
function pafiledb_mail_address($value)
{
    if (!is_string($value) || strlen($value) > 254 || preg_match('/[\x00-\x20\x7f-\xff]/', $value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) { pafiledb_mail_fail('Email_invalid'); }
    return $value;
}
function pafiledb_mail_payload($post, $logged)
{
    if (!is_array($post)) { pafiledb_mail_fail('PA_mail_invalid'); }
    $data = array();
    foreach (array('fname'=>100, 'subject'=>200, 'message'=>10000) as $name=>$limit) {
        $data[$name] = pafiledb_mail_text(pafiledb_mail_input($post, $name), $limit, $name !== 'message', $name !== 'fname');
    }
    $data['femail'] = pafiledb_mail_address(trim(pafiledb_mail_input($post, 'femail')));
    if (!$logged) {
        $data['sname'] = pafiledb_mail_text(pafiledb_mail_input($post, 'sname'), 100, true);
        $data['semail'] = pafiledb_mail_address(trim(pafiledb_mail_input($post, 'semail')));
    }
    return $data;
}

class PaFileDbMailStore extends PaFileDbMutationStore
{
    protected $mail_lock;
    protected $claim;
    protected $settings;
    protected $sender;

    protected function storage_tables()
    {
        return array(PA_FILES_TABLE, PA_CATEGORY_TABLE, PA_AUTH_ACCESS_TABLE, USERS_TABLE,
            SESSIONS_TABLE, USER_GROUP_TABLE, GROUPS_TABLE, CONFIG_TABLE,
            CTRACKER_CONFIG, CTRACKER_RATE_LIMITS, BANLIST_TABLE, DISALLOW_TABLE, WORDS_TABLE);
    }
    protected function settings()
    {
        $settings = array();
        foreach ($this->rows("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('flood_interval','smtp_delivery','smtp_host','smtp_username','smtp_password','board_email','server_name','sitename','default_lang','sendmail_fix') FOR UPDATE") as $row) {
            $settings[$row['config_name']] = $row['config_value'];
        }
        foreach (array('flood_interval','smtp_delivery','smtp_host','smtp_username','smtp_password','board_email','server_name','sitename','default_lang','sendmail_fix') as $name) {
            if (!isset($settings[$name]) || strpos($settings[$name], "\0") !== false || preg_match('//u', $settings[$name]) !== 1) { pafiledb_mail_fail(); }
        }
        if (!preg_match('/^[0-9]{1,6}$/D', $settings['flood_interval']) || !in_array($settings['smtp_delivery'], array('0','1'), true)) { pafiledb_mail_fail(); }
        pafiledb_mail_address($settings['board_email']);
        return $settings;
    }
    function reserve($data, $address)
    {
        global $db;
        $packed = is_string($address) ? @inet_pton($address) : false;
        if ($packed === false) { pafiledb_mail_fail(); }
        $address = inet_ntop($packed);
        $context = $this->current_context();
        if (!$this->authorized($context['category']['auth_email'], 'auth_email', $context['access'], $context['user'])) { pafiledb_mail_fail('Not_Authorised'); }
        $users = $this->rows('SELECT username,user_email,user_emailtime,user_blocktime FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' FOR UPDATE');
        if (count($users) !== 1 || ($this->actor['logged'] && (int)$users[0]['user_blocktime'] >= time())) { pafiledb_mail_fail('Not_Authorised'); }
        $user = $users[0];
        if ($this->actor['logged']) {
            $data['sname'] = pafiledb_mail_text(html_entity_decode($user['username'], ENT_QUOTES, 'UTF-8'), 100, true);
            $data['semail'] = pafiledb_mail_address($user['user_email']);
        } else {
            $caller = $db; $db = $this->connection;
            try { $validation = validate_username($data['sname'], false, 0, false, true); }
            finally { $db = $caller; }
            if (!empty($validation['error'])) { pafiledb_mail_fail('PA_mail_sender_invalid'); }
        }
        // Recheck current bans too; no forwarded/proxy header supplies identity.
        foreach ($this->rows('SELECT ban_userid,ban_email,ban_ip,ban_ip_mask FROM ' . BANLIST_TABLE . ' FOR UPDATE') as $ban) {
            if (!phpbb_ip_ban_valid($ban['ban_ip'], $ban['ban_ip_mask']) ||
                ((int)$ban['ban_userid'] !== 0 && (int)$ban['ban_userid'] === $this->actor['id']) ||
                phpbb_email_ban_matches($ban['ban_email'], $data['semail']) ||
                (strlen($packed) === 4 && phpbb_ip_ban_matches($ban['ban_ip'], $ban['ban_ip_mask'], bin2hex($packed)))) { pafiledb_mail_fail('Not_Authorised'); }
        }
        $this->settings = $this->settings();
        $ct = array();
        foreach ($this->rows("SELECT ct_config_name,ct_config_value FROM " . CTRACKER_CONFIG . " WHERE ct_config_name IN ('massmail_protection','massmail_time') FOR UPDATE") as $row) { $ct[$row['ct_config_name']] = $row['ct_config_value']; }
        if (!isset($ct['massmail_protection'], $ct['massmail_time']) || !in_array($ct['massmail_protection'], array('0','1'), true) || !preg_match('/^[0-9]{1,6}$/D', $ct['massmail_time'])) { pafiledb_mail_fail(); }
        $identity = $this->actor['logged'] ? 'user:' . $this->actor['id'] : $address;
        $bucket = $this->actor['logged'] ? 'mail-user' : 'mail-guest';
        $hash = hash('sha256', $bucket . "\0" . $identity);
        $schema = $this->identity($this->connection);
        $this->mail_lock = 'pa-mail:' . md5($schema['schema_name'] . "\0" . $hash);
        $lease = $this->rows("SELECT GET_LOCK('" . $this->mail_lock . "',0) held");
        if (count($lease) !== 1 || (string)$lease[0]['held'] !== '1') { pafiledb_mail_fail('PA_mail_busy'); }
        $rows = $this->rows("SELECT window_start,request_count,updated_at FROM " . CTRACKER_RATE_LIMITS . " WHERE bucket_hash='" . $hash . "' FOR UPDATE");
        $now = time(); $flood = max(1, (int)$this->settings['flood_interval']);
        $cooldown = $ct['massmail_protection'] === '1' ? max(1, min(180, (int)$ct['massmail_time'])) * 60 : 0;
        $old = $rows ? $rows[0] : array('window_start'=>0,'request_count'=>0,'updated_at'=>0);
        if ($now - (int)$old['window_start'] < $flood || ($cooldown && $now - (int)$old['updated_at'] < $cooldown) ||
            (int)$old['updated_at'] > $now || ($this->actor['logged'] && $now - (int)$user['user_emailtime'] < $flood)) { pafiledb_mail_fail('PA_mail_wait'); }
        if ((float)$old['request_count'] >= 4294967294 || $now > 2147483047) { pafiledb_mail_fail(); }
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
        if (count($released) !== 1 || (string)$released[0]['released'] !== '1') { pafiledb_mail_fail(); }
        $this->held = false;
        return $data;
    }
    protected function delivery_query($sql)
    {
        $state = $this->direct_rows($this->connection, "SELECT CONNECTION_ID() id,IS_USED_LOCK('" . $this->mail_lock . "') owner");
        if (count($state) !== 1 || (string)$state[0]['id'] !== $this->identity || (string)$state[0]['owner'] !== $this->identity) { pafiledb_mail_fail(); }
        $result = $this->connection->sql_query($sql);
        if (!$result) { pafiledb_mail_fail(); }
        return $result;
    }
    protected function settle($accepted)
    {
        $claim = $this->claim; $now = time();
        $this->delivery_query('START TRANSACTION'); $this->transaction = true;
        $this->delivery_query('UPDATE ' . CTRACKER_RATE_LIMITS . ' SET updated_at=' . ($accepted ? $now : $claim['old_success']) . ',window_start=' . $now .
            " WHERE bucket_hash='" . $claim['hash'] . "' AND request_count=" . $claim['generation'] . ' AND window_start=' . $claim['start'] . ' AND updated_at=' . $claim['pending']);
        if ((int)$this->connection->sql_affectedrows() !== 1) { pafiledb_mail_fail(); }
        if ($this->actor['logged']) {
            $this->delivery_query('UPDATE ' . USERS_TABLE . ' SET user_emailtime=' . max($now, $claim['old_email']) . ' WHERE user_id=' . $this->actor['id'] . ' AND user_emailtime=' . $claim['pending']);
        }
        $this->delivery_query('COMMIT'); $this->transaction = false;
    }
    function deliver($factory)
    {
        global $board_config;
        $previous = $board_config; $accepted = false;
        try {
            $board_config = array_merge($board_config, $this->settings);
            $mailer = call_user_func($factory, $this->settings['smtp_delivery']);
            $data = $this->sender;
            $mailer->use_template('profile_send_email', $this->settings['default_lang']);
            $mailer->email_address($data['femail']); $mailer->from($this->settings['board_email']); $mailer->replyto($data['semail']);
            $mailer->set_subject($data['subject']);
            $server = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $this->settings['server_name']);
            $mailer->extra_headers('X-AntiAbuse: Board servername - ' . $server . "\nX-AntiAbuse: Username - " . $data['sname'] . "\n");
            $mailer->assign_vars(array('SITENAME'=>$this->settings['sitename'],'BOARD_EMAIL'=>$this->settings['board_email'],
                'FROM_USERNAME'=>$data['sname'],'TO_USERNAME'=>$data['fname'],'MESSAGE'=>$data['message']));
            $accepted = $mailer->send() === true;
        } catch (Exception $error) { $accepted = false; }
        catch (Error $error) { $accepted = false; }
        finally { $board_config = $previous; }
        try { $this->settle($accepted); }
        catch (Exception $error) { error_log('paFileDB mail delivery reservation could not be finalized'); }
        catch (Error $error) { error_log('paFileDB mail delivery reservation could not be finalized'); }
        // Once transport accepted the message, never invite a duplicate retry
        // just because the acknowledgement timestamp could not be finalized.
        if (!$accepted) { pafiledb_mail_fail(); }
        return true;
    }
}
function pafiledb_send_recommendation($database, $file_id, $post, $factory)
{
    $owner = null;
    try {
        $actor = pafiledb_mutation_actor(); $file_id = pafiledb_mutation_id($file_id);
        $data = pafiledb_mail_payload($post, $actor['logged']);
        $address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $owner = new PaFileDbMailStore($database, $actor, $file_id);
        $owner->reserve($data, $address); $owner->deliver($factory);
        return $file_id;
    } catch (PaFileDbMailFailure $error) { throw $error; }
    catch (PaFileDbMutationFailure $error) {
        $key = $error->getMessage();
        pafiledb_mail_fail($key === 'PA_storage_failed' ? 'PA_mail_failed' : ($key === 'PA_storage_upgrade' ? 'PA_mail_upgrade' : $key));
    } catch (Exception $error) { pafiledb_mail_fail(); }
    catch (Error $error) { pafiledb_mail_fail(); }
    finally { if ($owner) { $owner->release(); } }
}
