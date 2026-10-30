<?php

$root = dirname(dirname(__DIR__));
$emailer = (string) file_get_contents($root . '/phpBB2/includes/emailer.php');
$contact = (string) file_get_contents($root . '/phpBB2/kontakt_post.php');
$errors = array();

require_once $root . '/phpBB2/includes/emailer.php';
$test_mailer = new emailer(false);
$test_mailer->email_address("valid@example.org\r\nBcc: victim@example.org");
if ($test_mailer->addresses['to'] !== '')
{
	$errors[] = 'Central mailer accepted a header-injected recipient.';
}
$test_mailer->email_address('valid@example.org');
$test_mailer->bcc('invalid address');
$test_mailer->bcc('copy@example.org');
if ($test_mailer->addresses['to'] !== 'valid@example.org' || $test_mailer->addresses['bcc'] !== array('copy@example.org'))
{
	$errors[] = 'Central mailer address normalization failed.';
}

foreach (array(
	"preg_match('/^[a-z0-9_-]{1,80}$/iD', \$template_file)",
	"preg_match('/^[a-z_]{1,30}$/D', \$template_lang)",
	'!@is_file($tpl_file) || @is_link($tpl_file)',
	"preg_replace('#[\\x00\\r\\n]+#', '', (string) \$this->subject)",
	"filter_var(\$address, FILTER_VALIDATE_EMAIL)",
	"bin2hex(phpbb_random_bytes(16))"
) as $marker)
{
	if (strpos($emailer, $marker) === false)
	{
		$errors[] = 'Missing central mailer marker: ' . $marker;
	}
}

$schema = (string) file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
if (strpos($schema, 'ct_last_mail') !== false || strpos($updater, "'ct_last_mail'") === false)
{
	$errors[] = 'Obsolete mail-cooldown column is not removed by the schema migration.';
}

if (strpos($contact, "preg_replace('/[\\x00-\\x1f\\x7f]+/', ' ', \$name)") === false)
{
	$errors[] = 'Contact control-character filtering is not byte-safe.';
}

// Profile, friend and paFileDB mail policies are exercised by the actual
// shared input/native controller/transport fixtures, including overlaps and
// failure recovery. Avoid asserting obsolete implementation strings here.

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Mail workflow safety checks passed.\n";

?>
