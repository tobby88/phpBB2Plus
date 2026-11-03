<?php
define('IN_PHPBB', true);
define('CTRACKER_SECURITY_NO_AUTO_RUN', true);
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/engines/ct_security.php';
function ct_error_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "CrackerTracker error-path test failed: $message\n"); exit(1); }
}
$options = array('post_free_text' => ct_security_free_post_fields(), 'scan_post' => true);
$contact_source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/includes/functions.php');
ct_error_assert(preg_match('/^function phpbb_social_profile_allowed_url\(.*?^\}/ms', $contact_source, $contact_match) === 1, 'actual contact URL helper must be present');
eval($contact_match[0]);
$contact_form = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/templates/fisubsilversh/profile_add_body.tpl');
foreach (array('fb'=>'facebook.com','ig'=>'instagram.com','twr'=>'x.com','tg'=>'t.me','li'=>'linkedin.com','tt'=>'tiktok.com','dc'=>'discord.com','signal'=>'signal.me','threema'=>'threema.id') as $field=>$host)
{
	$url = 'https://' . $host . '/example/?online=1';
	ct_error_assert(strpos($contact_form, 'name="' . $field . '"') !== false, 'a current real contact input is covered');
	ct_error_assert(phpbb_social_profile_allowed_url($url, array($host)) === $url, 'actual contact helper accepts the service URL');
	ct_error_assert(ct_security_value_is_attack($url, false, array()), 'URL sample reproduces the generic parameter signature');
	$post = array($field=>$url); $snapshot = serialize($post);
	ct_error_assert(!ct_security_request_is_attack(array(), $post, array(), $options), 'approved contact URL has website content policy: ' . $field);
	ct_error_assert(serialize($post) === $snapshot, 'comparison preserves original contact URL');
	ct_error_assert(ct_security_request_is_attack(array(), array($field=>$url . "\0"), array(), $options), 'contact fields cannot bypass NUL checks');
	$contact_custom = $options; $contact_custom['custom_rules'] = array('online=1');
	ct_error_assert(ct_security_request_is_attack(array(), $post, array(), $contact_custom), 'contact fields cannot bypass configured custom rules');
}
echo "CrackerTracker current contact request policy: 9 approved service URLs, 63 assertions PASS.\n";
foreach (array('password', 'new_password', 'cur_password', 'password_confirm', 'smtp_password', 'ftp_pass') as $field)
{
	ct_error_assert(!ct_security_request_is_attack(array(), array($field => 'Long!Pass;select<svg>Example9'), array(), $options), 'credential field must accept arbitrary non-NUL password characters: ' . $field);
}
$attack = array('id' => '1 UNION ALL SELECT user_password FROM phpbb_users');
ct_error_assert(ct_security_request_is_attack($attack, array(), array(), $options), 'baseline signature must be recognized');
$old_limit = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1');
$blocked = ct_security_request_is_attack($attack, array(), array(), $options);
$value_blocked = ct_security_value_is_attack($attack['id'], false, array());
$unsafe_key_allowed = ct_security_key_is_safe('GLOBALS');
ini_set('pcre.backtrack_limit', $old_limit);
ct_error_assert($blocked, 'a regex resource failure must not silently allow a known attack');
ct_error_assert($value_blocked, 'signature checking itself must fail closed, independently of field-key validation');
ct_error_assert(!$unsafe_key_allowed, 'reserved keys must not pass when the key regex fails');
ct_error_assert(!ct_security_request_is_attack(array(), array('poll_option_text' => array('Discuss php://filter', 'Discuss <script> examples')), array(), $options), 'real posting form poll_option_text field must be free text');
ct_error_assert(!ct_security_request_is_attack(array(), array('poll_option' => array('Discuss php://filter', 'Discuss <script> examples')), array(), $options), 'free-text poll options must retain their field policy inside arrays');
ct_error_assert(ct_security_request_is_attack(array(), array('poll_option' => array('GLOBALS' => 'plain')), array(), $options), 'free-text arrays must still validate nested field names');
ct_error_assert(ct_security_request_is_attack(array(), array('poll_option' => array("null\0byte")), array(), $options), 'free-text arrays must still reject null bytes');
$custom = $options;
$custom['custom_rules'] = array('private-sentinel');
ct_error_assert(ct_security_request_is_attack(array(), array('poll_option' => array('private-sentinel')), array(), $custom), 'custom literal rules remain effective in free-text arrays');
ct_error_assert(ct_security_request_is_attack(array(), array('filter' => array('id' => '1 UNION SELECT 2')), array(), $options), 'ordinary parameter arrays must still be signature-scanned');
ct_error_assert(!ct_security_request_is_attack(array('token' => str_repeat('a', 4096)), array('username' => "O'Connor", 'password' => 'a complicated <script> phrase', 'poll_option_text' => array('Ja', 'Nein', 'Grüße 😀')), array(), $options), 'normal tokens, credentials, Unicode and poll options remain usable');
$nested = 'plain';
for ($depth = 0; $depth < 10; $depth++) { $nested = array($nested); }
ct_error_assert(ct_security_request_is_attack(array(), array('poll_option_text' => $nested), array(), $options), 'free text cannot bypass nesting limits');
$oversized = array_fill(0, 4001, 'plain');
ct_error_assert(ct_security_request_is_attack(array(), array('poll_option_text' => $oversized), array(), $options), 'free text cannot bypass node limits');
echo "CrackerTracker error-path tests passed.\n";
