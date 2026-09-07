<?php
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/classes/class_log_manager.php';
$HTTP_SERVER_VARS = array();
$HTTP_ENV_VARS = array();
$manager = new log_manager();
function ct_redaction_assert($ok, $message)
{
	if (!$ok) { fwrite(STDERR, "CrackerTracker redaction test failed: $message\n"); exit(1); }
}
$query = 'page=2&new_password=FakeSecret123&account%5Btoken%5D=FakeToken456';
$normal = $manager->redact_query_string($query);
ct_redaction_assert(strpos($normal, 'page=2') !== false && strpos($normal, 'FakeSecret123') === false && strpos($normal, 'FakeToken456') === false, 'normal redaction must retain harmless context and remove credentials');
$limit = ini_get('pcre.backtrack_limit');
ini_set('pcre.backtrack_limit', '1');
$sensitive = $manager->sensitive_query_key('new_password');
$redacted = $manager->redact_query_string($query);
$url = $manager->redact_url_query('https://forum.example/profile.php?' . $query);
ini_set('pcre.backtrack_limit', $limit);
ct_redaction_assert($sensitive, 'a failed regex must not classify a credential key as harmless');
foreach (array($redacted, $url) as $value)
{
	ct_redaction_assert(strpos($value, 'FakeSecret123') === false && strpos($value, 'FakeToken456') === false, 'query/referer fallback must never retain secret values');
}
echo "CrackerTracker redaction error tests passed.\n";
