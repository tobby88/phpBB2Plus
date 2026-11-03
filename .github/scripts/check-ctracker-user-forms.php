<?php
define('IN_PHPBB', true);
$app = dirname(dirname(__DIR__)) . '/phpBB2/';
require $app . 'includes/php_compat.php';
require $app . 'includes/functions.php';
require $app . 'ctracker/classes/class_user_actions.php';
date_default_timezone_set('UTC');
$checks = 0;
function uf_check($ok, $label) { if (!$ok) { throw new RuntimeException($label); } $GLOBALS['checks']++; }
function uf_fragment($source, $start, $end) {
    $a = strpos($source, $start); $b = ($a === false) ? false : strpos($source, $end, $a);
    if ($a === false || $b === false) { throw new RuntimeException('Actual user form fragment unavailable'); }
    return substr($source, $a, $b - $a);
}
class UserFormTemplate {
    public $blocks = array();
    function assign_block_vars($name, $values) { $this->blocks[$name][] = $values; }
}
class UserHistoryRows {
    public $ips; private $position = 0;
    function __construct($ips) { $this->ips = $ips; }
    function sql_fetchrow($unused) {
        if ($this->position >= count($this->ips)) { return false; }
        return array('ct_login_time' => 1700000000, 'ct_login_ip' => $this->ips[$this->position++]);
    }
}
$header = file_get_contents($app . 'includes/page_header.php');
$ip_form = uf_fragment($header, "if ( \$ctracker_settings['login_ip_check'] == 1 &&", '/* CrackerTracker Global Message Function */');
$global_form = uf_fragment($header, "if ( \$userdata['ct_global_msg_read'] == 1 &&", "((\$ctracker_settings['login_history'] == 1");
$phpbb_root_path = $app; $phpEx = 'php';
$images = array('ctracker_note' => 'fixture.gif');
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
foreach (array('english', 'german') as $locale) {
    $lang = array(); require $app . 'language/lang_' . $locale . '/lang_main.php';
    require $app . 'language/lang_' . $locale . '/lang_cback_ctracker.php';
    uf_check(isset($lang['Ctracker_notice_changed']) && $lang['Ctracker_notice_changed'] !== '', 'Both languages explain a changed notice');
    $userdata = array('user_id'=>42, 'session_id'=>str_repeat('a', 32), 'session_logged_in'=>true, 'ct_enable_ip_warn'=>1,
        'ct_last_ip'=>'192.0.2.1', 'ct_last_used_ip'=>'203.0.113.2', 'ct_global_msg_read'=>1, 'user_dateformat'=>'Y-m-d H:i');
    $ctracker_settings = array('login_ip_check'=>1, 'global_message'=>"Grüße 😀 & <em>literal</em>", 'global_message_type'=>'1');
    $template = new UserFormTemplate(); eval($ip_form); eval($global_form);
    $forms = $template->blocks['ctracker_message.switch_mark_action'];
    uf_check(count($forms) === 2, 'Both real header branches render their form');
    foreach ($forms as $form) {
        $action = $form['S_MARK_VALUE'];
        uf_check($form['S_MARK_CONTEXT'] === ctracker_user_notice_context($action, $userdata, $ctracker_settings), 'Form context describes the actual displayed notice');
        uf_check($form['S_MARK_TOKEN'] === ctracker_user_notice_token($action, $form['S_MARK_CONTEXT'], $userdata), 'Actual form token covers its displayed context');
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array('marknow'=>$action, 'ct_context'=>$form['S_MARK_CONTEXT'], 'ct_token'=>$form['S_MARK_TOKEN']);
        uf_check(ctracker_user_request($action)['context'] === $form['S_MARK_CONTEXT'], 'Actual rendered form passes cheap validation without any database');
        foreach (array('session', 'user', 'context', 'action') as $change) {
            $other = $userdata; $context = $form['S_MARK_CONTEXT']; $kind = $action;
            if ($change === 'session') { $other['session_id'] = str_repeat('b', 32); }
            elseif ($change === 'user') { $other['user_id'] = 43; }
            elseif ($change === 'context') { $context = str_repeat('0', 64); }
            else { $kind = ($action === 'ipfeature') ? 'globmsg' : 'ipfeature'; }
            uf_check(ctracker_user_notice_token($kind, $context, $other) !== $form['S_MARK_TOKEN'], 'Different identity/action/content cannot reuse a token');
        }
    }
    uf_check(strpos($template->blocks['ctracker_message'][1]['L_MESSAGE_TEXT'], '<em>') === false
        && strpos($template->blocks['ctracker_message'][1]['L_MESSAGE_TEXT'], 'Grüße 😀 &amp; &lt;em&gt;literal&lt;/em&gt;') !== false, 'Actual announcement text remains escaped Unicode');
    $userdata['session_logged_in'] = false; $template = new UserFormTemplate(); eval($ip_form); eval($global_form);
    uf_check(!$template->blocks, 'Guests see no member acknowledgement forms');
}
// Exact bytes distinguish notice pairs even when their concatenations match.
uf_check(ctracker_user_notice_context('ipfeature', array('ct_last_ip'=>'ab', 'ct_last_used_ip'=>'c'), array())
    !== ctracker_user_notice_context('ipfeature', array('ct_last_ip'=>'a', 'ct_last_used_ip'=>'bc'), array()), 'Context framing is unambiguous');
$source = file_get_contents($app . 'ct_login_history.php');
$body = 'if (true) {' . uf_fragment($source, "\t\$count = 0;", '// Output settings for Login Checker');
$ips = array('192.0.2.1', '2001:db8::1', '::ffff:192.0.2.1', '<em data-fixture="history">text</em>', '&lt;em&gt;', "broken\xc3", null);
foreach (array('Y-m-d H:i', 'Y-m-d "<em>"') as $format) {
    $userdata = array('user_dateformat'=>$format); $template = new UserFormTemplate(); $db = new UserHistoryRows($ips); $result = true;
    eval($body);
    foreach ($template->blocks['login_output'] as $i => $row) {
        uf_check($row['VALUE_3'] === htmlspecialchars((string)$ips[$i], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'Historical address text is escaped once, with invalid UTF8 replaced');
        uf_check($row['VALUE_2'] === htmlspecialchars(date($format, 1700000000), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'Historical formatted date is text, including custom format markup');
    }
}
$tpl = file_get_contents($app . 'templates/fisubsilversh/overall_header.tpl');
uf_check(strpos($tpl, 'name="ct_context" value="{ctracker_message.switch_mark_action.S_MARK_CONTEXT}"') !== false, 'Real template submits notice context');
uf_check(strpos($tpl, 'name="ct_token" value="{ctracker_message.switch_mark_action.S_MARK_TOKEN}"') !== false, 'Real template submits the context-bound token');
restore_error_handler();
echo 'CrackerTracker user forms: ', $checks, " actual header/history/helper assertions PASS; EN/DE, contextual POST tokens and historical HTML/UTF8.\n";
