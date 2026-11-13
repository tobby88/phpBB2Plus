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
if (in_array('mail', array_map('trim', explode(',', ini_get('disable_functions'))), true))
{
	require __DIR__ . '/contact-workflow-fixture.php';
}
else
{
	// Keep the configured command unchanged, but make real delivery impossible
	// in the child that exercises actual controller/mailer code.
	$crypto = DIRECTORY_SEPARATOR === '\\' && PHP_VERSION_ID < 70000 ? ' -d extension=' . escapeshellarg(ini_get('extension_dir') . '/php_openssl.dll') : '';
	// Keep the configured Linux modules (notably ctype/OpenSSL). The bundled
	// Windows test runtimes use their explicit standalone extension setup.
	$isolated = DIRECTORY_SEPARATOR === '\\' ? ' -n' : '';
	$lines = array(); $status = 1;
	exec(escapeshellarg(PHP_BINARY) . $isolated . ' -d date.timezone=UTC -d disable_functions=mail ' . $crypto . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $lines, $status);
	if ($status !== 0 || count($lines) !== 2 || $lines[0] !== 'Mail workflow safety checks passed.' || strpos($lines[1], 'Contact workflow: 78 actual controller/mailer/file cases, 2 EN/DE XS forms PASS;') !== 0)
	{
		fwrite(STDERR, implode("\n", $lines) . "\n"); exit(1);
	}
	echo $lines[1], "\n";
}

?>
