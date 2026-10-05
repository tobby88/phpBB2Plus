<?php
define('IN_PHPBB', true); define('GENERAL_ERROR', 1); define('GENERAL_MESSAGE', 2);
define('BANLIST_TABLE', 'fixture_bans'); define('USERS_TABLE', 'fixture_users');
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions_validate.php';
class EmailBanValidationExit extends RuntimeException {}
function message_die($level, $message) { throw new EmailBanValidationExit($message); }
function email_validation_check($ok, $label) { $GLOBALS['checks']++; if (!$ok) { throw new RuntimeException($label); } }
class EmailBanValidationDatabase {
    var $bans = array(); var $users = array(); var $rows = array(); var $fail = ''; var $queries = array(); var $open = 0;
    function sql_escape($value) { return str_replace("'", "''", $value); }
    function sql_query($sql) {
        $this->queries[] = $sql;
        if ($this->fail !== '' && strpos($sql, $this->fail) !== false) { return false; }
        if (strpos($sql, 'FROM fixture_bans') !== false) { $this->rows = $this->bans; }
        elseif (strpos($sql, 'FROM fixture_users') !== false) { $this->rows = $this->users; }
        else { throw new RuntimeException('Unexpected validation SQL'); }
        $this->open++; return true;
    }
    function sql_fetchrow($result) { return $this->rows ? array_shift($this->rows) : false; }
    function sql_freeresult($result) { email_validation_check($result && $this->open === 1, 'Only free real open result'); $this->open--; }
}
$lang = array('Email_invalid'=>'invalid','Email_banned'=>'banned','Email_taken'=>'taken');
$board_config = array('sfs_enable'=>0); $checks = 0;
// Only the protected account lookup is substituted for parser tests below.
// Pattern parsing, founder protection and the shared matcher are actual code.
function get_userdata($id, $force_str = false) { return array('user_email'=>$GLOBALS['protected_fixture_email']); }
class EmailBanProtectedTracker { function first_admin_user_id() { return 7; } }
set_error_handler(function($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
$cases = array(
    array('member@example.invalid', '*@example.invalid', 'banned'),
    array('member@example.invalid', '@example.invalid', 'banned'),
    array('member@other.invalid', '*@example.invalid', ''),
    array('member@example.invalid', 'MEMBER@EXAMPLE.INVALID', 'banned'),
    array('member@example.invalid', null, ''), array('member@example.invalid', '', ''),
    array('axb@example.invalid', 'a.b@example.invalid', ''),
    array('a.b@example.invalid', 'a.b@example.invalid', 'banned'),
    array('a+b@example.invalid', 'a+b@example.invalid', 'banned'),
    array('ab@example.invalid', 'a+b@example.invalid', ''),
    array("o'reilly@example.invalid", "o'reilly@example.invalid", 'banned'),
    array('a_b@example.invalid', 'axb@example.invalid', ''),
    array('a_b@example.invalid', 'a_b@example.invalid', 'banned'),
    array('member@example.invalid', 'a[b]@example.invalid', '')
);
foreach ($cases as $case) {
    $db = new EmailBanValidationDatabase();
    $db->bans = array(array('ban_email'=>null), array('ban_email'=>'unrelated@example.invalid'), array('ban_email'=>$case[1]));
    $result = validate_email($case[0]);
    email_validation_check($result['error_msg'] === $case[2] && $db->open === 0, 'Actual validator uses literal/STAR ban semantics');
    email_validation_check(count($db->queries) === ($case[2] === 'banned' ? 1 : 2), 'Ban stops before duplicate-account lookup');
}
foreach (array('', null, array(), str_repeat('a', 250) . '@x.invalid') as $email) {
    $db = new EmailBanValidationDatabase(); $result = validate_email($email);
    email_validation_check($result['error_msg'] === 'invalid' && $db->queries === array(), 'Invalid/beyond-column input refused before SQL');
}
$db = new EmailBanValidationDatabase(); $db->users = array(array('user_email'=>'member@example.invalid'));
$result = validate_email('member@example.invalid');
email_validation_check($result['error_msg'] === 'taken' && $db->open === 0, 'Duplicate-account result released');
$db = new EmailBanValidationDatabase(); validate_email('member@example.invalid', false, 42);
email_validation_check(strpos($db->queries[1], 'AND user_id <> 42') !== false, 'Owned profile identity exclusion remains explicit');
foreach (array('fixture_bans','fixture_users') as $fail) {
    $db = new EmailBanValidationDatabase(); $db->fail = $fail; $denied = false;
    try { validate_email('member@example.invalid'); } catch (EmailBanValidationExit $e) { $denied = true; }
    email_validation_check($denied && $db->open === 0, 'Failed database reader never silently permits address');
}
$phpbb_root_path = dirname(dirname(__DIR__)) . '/phpBB2/';
$source = file_get_contents($phpbb_root_path . 'admin/admin_user_ban.php');
$start = strpos($source, "\t\$email_list = array();");
$end = strpos($source, "\n\t\t\$ban_scope->save", $start);
email_validation_check($start !== false && $end > $start, 'Complete actual email-ban parser/protection block');
$body = substr($source, $start, $end-$start);
$body = str_replace("admin_ban_post_string('ban_email')", "trim(stripslashes(\$_POST['ban_email']))", $body);
$ctracker_config = new EmailBanProtectedTracker(); $lang['ctracker_gmb_1stadmin'] = 'protected';
foreach (array(
    array('*@example.invalid','founder@example.invalid',true),
    array('f*er@example.invalid','FOUNDER@example.invalid',true),
    array('f+er@example.invalid','f+er@example.invalid',true),
    array('f+er@example.invalid','fer@example.invalid',false),
    array('f.er@example.invalid','fxer@example.invalid',false),
    array("o'reilly@example.invalid", "o'reilly@example.invalid",true)
) as $case) {
    $_POST = array('ban_email'=>addslashes($case[0])); $protected_fixture_email = $case[1]; $denied = false;
    try { eval($body); } catch (EmailBanValidationExit $e) { $denied = $e->getMessage() === 'protected'; }
    email_validation_check($denied === $case[2] && ($denied || $email_list === array($case[0])), 'Actual parser protects only a matching founder address');
}
restore_error_handler();
echo $checks . " actual email-ban validation checks passed\n";
