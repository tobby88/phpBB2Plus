<?php
define('IN_PHPBB', true);
define('CTRACKER_SECURITY_NO_AUTO_RUN', true);
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/engines/ct_security.php';
function ct_error_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "CrackerTracker error-path test failed: $message\n"); exit(1); }
}
$options = array('post_free_text' => ct_security_free_post_fields(), 'scan_post' => true);
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
