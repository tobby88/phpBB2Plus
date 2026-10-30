<?php
define('IN_PHPBB', true);
$root = dirname(dirname(__DIR__)) . '/phpBB2/';
if (isset($argv[1]) && $argv[1] === 'legacy') { define('PHPBB_LEGACY_REQUEST_ESCAPED', true); }
require $root . 'includes/php_compat.php';
require $root . 'pafiledb/includes/functions_mail_storage.php';
$cases = 0;
function mi_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['cases']++; }
function mi_post($values) { return defined('PHPBB_LEGACY_REQUEST_ESCAPED') ? phpbb_addslashes_recursive($values) : $values; }
function mi_bad($values, $key = null, $logged = true) {
    $caught = null; try { pafiledb_mail_payload(mi_post($values), $logged); } catch (PaFileDbMailFailure $error) { $caught = $error->getMessage(); }
    mi_check($caught !== null && ($key === null || $caught === $key), 'Expected controlled input rejection: ' . $key);
}
$post = array('fname'=>'Friend','femail'=>'friend@example.invalid','subject'=>'Grüße 😀','message'=>"C:\\Pfad\\Grüße O'Reilly &amp;\nZweite Zeile");
$data = pafiledb_mail_payload(mi_post($post), true);
mi_check($data == $post, 'Raw/legacy boundary preserves Unicode, literal entities, quotes and backslashes');
foreach (array('fname'=>100,'subject'=>200,'message'=>10000) as $field=>$limit) {
    $input = $post; $input[$field] = str_repeat('😀', $limit);
    $data = pafiledb_mail_payload(mi_post($input), true);
    mi_check($data[$field] === $input[$field] && preg_match('//u', $data[$field]) === 1, 'Full Unicode character capacity without mbstring');
    $input[$field] .= 'ä'; mi_bad($input, 'PA_mail_too_long');
}
foreach (array('fname','femail','subject','message') as $field) {
    foreach (array(array(), true, 123, "bad\0value", "\xff") as $value) { $input = $post; $input[$field] = $value; mi_bad($input); }
}
foreach (array('subject','message','femail') as $field) {
    foreach (array('', " \t\r\n ") as $value) { $input = $post; $input[$field] = $value; mi_bad($input); }
}
$input = $post; $input['subject'] = "Hello\r\nBcc: hidden@example.invalid";
$data = pafiledb_mail_payload(mi_post($input), true); mi_check(strpbrk($data['subject'], "\r\n") === false, 'Subject cannot add headers');
$input = $post; $input['femail'] = 'friend@example.invalid' . str_repeat(' ', 255) . 'other@example.invalid'; mi_bad($input, 'Email_invalid');
$input['femail'] = "friend@example.invalid\r\nBcc: hidden@example.invalid"; mi_bad($input, 'Email_invalid');
$input = $post; $input['sname'] = array(); $input['semail'] = "\xff";
mi_check(pafiledb_mail_payload(mi_post($input), true) == $post, 'Member sender fields are ignored, not trusted');
mi_bad($post, 'PA_mail_invalid', false);
$guest = array_merge($post, array('sname'=>'Gast Änne','semail'=>'guest@example.invalid'));
mi_check(pafiledb_mail_payload(mi_post($guest), false) == $guest, 'Actual guest sender retained');
$guest['sname'] = str_repeat('ä', 101); mi_bad($guest, 'PA_mail_too_long', false);
echo 'paFileDB mail input: ', $cases, ' cases PASS (', defined('PHPBB_LEGACY_REQUEST_ESCAPED') ? 'legacy' : 'raw', ").\n";
