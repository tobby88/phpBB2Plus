<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_mail_delivery.php';

function phpbb_mail_target_id($value)
{
    if (!(is_int($value) || is_string($value)) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string)$value) || (int)$value > 8388607) { phpbb_mail_fail('No_user_specified'); }
    return (int)$value;
}
function phpbb_profile_mail_permission($recipient, $viewer, $settings)
{
    if (!$recipient || (int)$recipient['user_active'] !== 1) { return 'User_not_exist'; }
    if (empty($settings['board_email_form'])) { return 'User_prevent_email'; }
    $admin = (int)$viewer['user_level'] === ADMIN;
    if (empty($recipient['user_viewemail']) && !$admin) { return 'User_prevent_email'; }
    if (!empty($recipient['user_absence']) && !$admin &&
        !((int)$viewer['user_level'] === MOD && !empty($settings['mod_able_sent_absent']))) { return 'Mail_absent'; }
    return '';
}
function phpbb_mail_suggested_subject($value)
{
    global $lang;
    $value = phpbb_mail_text($value, 1024, true, false);
    if ($value === '') { $value = $lang['Mail_recommendation_subject']; }
    preg_match_all('/./us', $value, $characters);
    return implode('', array_slice($characters[0], 0, 200));
}
function phpbb_mail_board_link($value)
{
    $board = rtrim(phpbb_board_url(), '/');
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return $board; }
    if ($value !== '' && !preg_match('#^https?://#i', $value)) { $value = $board . '/' . ltrim($value, '/'); }
    $parts = @parse_url($value); $origin = @parse_url($board);
    if (!$parts || !$origin || empty($parts['host']) || empty($parts['scheme']) || isset($parts['user']) || isset($parts['pass']) ||
        strcasecmp($parts['host'], $origin['host']) !== 0 || strcasecmp($parts['scheme'], $origin['scheme']) !== 0) { return $board; }
    $link_port = isset($parts['port']) ? (int)$parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
    $board_port = isset($origin['port']) ? (int)$origin['port'] : (strtolower($origin['scheme']) === 'https' ? 443 : 80);
    return $link_port === $board_port ? $value : $board;
}
function phpbb_board_mail_payload($kind, $post)
{
    if (!is_array($post)) { phpbb_mail_fail('Mail_invalid'); }
    if ($kind === 'profile') {
        $data = array('subject'=>phpbb_mail_text(phpbb_mail_input($post, 'email_subject'), 200, true),
            'message'=>phpbb_mail_text(phpbb_mail_input($post, 'email_message'), 10000));
        if (isset($post['cc_email']) && !in_array($post['cc_email'], array('0','1',0,1,false,true), true)) { phpbb_mail_fail('Mail_invalid'); }
        $data['copy'] = isset($post['cc_email']) && in_array($post['cc_email'], array('1',1,true), true);
        return $data;
    }
    if ($kind !== 'friend') { phpbb_mail_fail('Mail_invalid'); }
    $address = phpbb_mail_address(trim(phpbb_mail_input($post, 'friendemail')));
    $name = isset($post['friendname']) ? phpbb_mail_text(phpbb_mail_input($post, 'friendname'), 100, true, false) : '';
    if ($name === '') { $name = substr($address, 0, strpos($address, '@')); }
    return array('fname'=>$name,'femail'=>$address,'subject'=>phpbb_mail_suggested_subject(phpbb_mail_input($post, 'topic')),
        'message'=>phpbb_mail_text(phpbb_mail_input($post, 'message'), 10000),'template'=>'tellafriend_email');
}

class PhpbbBoardMailStore extends PhpbbMailStore
{
    protected $kind;
    protected $target;
    function __construct($database, $actor, $kind, $target)
    {
        $this->kind = $kind; $this->target = $target;
        parent::__construct($database, $actor);
    }
    protected function storage_tables()
    {
        return array(USERS_TABLE,SESSIONS_TABLE,CONFIG_TABLE,CTRACKER_CONFIG,CTRACKER_RATE_LIMITS,BANLIST_TABLE);
    }
    protected function authorize_message($data, $user)
    {
        if (!$this->actor['logged']) { phpbb_mail_fail('Not_Authorised'); }
        $data['actor_headers'] = true;
        if ($this->kind === 'profile') {
            $rows = $this->rows('SELECT username,user_email,user_viewemail,user_active,user_lang,user_absence FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->target . ' FOR UPDATE');
            $settings = array('board_email_form'=>0,'mod_able_sent_absent'=>0);
            foreach ($this->rows("SELECT config_name,config_value FROM " . CONFIG_TABLE . " WHERE config_name IN ('board_email_form','mod_able_sent_absent') FOR UPDATE") as $row) { $settings[$row['config_name']] = $row['config_value']; }
            $recipient = count($rows) === 1 ? $rows[0] : null;
            $denied = phpbb_profile_mail_permission($recipient, $user, $settings);
            if ($denied !== '') { phpbb_mail_fail($denied); }
            $data['fname'] = phpbb_mail_text(html_entity_decode($recipient['username'], ENT_QUOTES, 'UTF-8'), 100, true);
            $data['femail'] = phpbb_mail_address($recipient['user_email']);
            $data['language'] = $recipient['user_lang'];
        } else {
            $language = $this->rows('SELECT user_lang FROM ' . USERS_TABLE . ' WHERE user_id=' . $this->actor['id'] . ' FOR UPDATE');
            if (count($language) !== 1) { phpbb_mail_fail(); }
            $data['language'] = $language[0]['user_lang'];
        }
        return $data;
    }
}
function phpbb_send_board_mail($database, $kind, $target, $post, $factory)
{
    $owner = null;
    try {
        $actor = phpbb_mail_actor(false, $post);
        if ($kind === 'profile') { $target = phpbb_mail_target_id($target); }
        $data = phpbb_board_mail_payload($kind, $post);
        $owner = new PhpbbBoardMailStore($database, $actor, $kind, $target);
        $owner->reserve($data, isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
        return $owner->deliver($factory);
    } catch (PhpbbMailFailure $error) { throw $error; }
    catch (Exception $error) { phpbb_mail_fail(); }
    catch (Error $error) { phpbb_mail_fail(); }
    finally { if ($owner) { $owner->release(); } }
}
class PhpbbBoardMailController
{
    function create_mailer($smtp)
    {
        require_once dirname(__FILE__) . '/emailer.php';
        return new emailer($smtp, true);
    }
    function send($database, $kind, $target, $post) { return phpbb_send_board_mail($database, $kind, $target, $post, array($this, 'create_mailer')); }
}
function phpbb_mail_error($error)
{
    global $lang;
    $key = $error->getMessage();
    return isset($lang[$key]) ? $lang[$key] : $lang['Mail_failed'];
}
function phpbb_mail_form_error($key)
{
    return in_array($key, array('Mail_invalid','Mail_too_long','Email_invalid','Mail_busy','Mail_wait','Mail_failed'), true);
}
