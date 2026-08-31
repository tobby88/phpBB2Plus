<?php

$root = dirname(dirname(__DIR__));
$emailer = (string) file_get_contents($root . '/phpBB2/includes/emailer.php');
$profile_email = (string) file_get_contents($root . '/phpBB2/includes/usercp_email.php');
$friend = (string) file_get_contents($root . '/phpBB2/tellafriend.php');
$contact = (string) file_get_contents($root . '/phpBB2/kontakt_post.php');
$pafiledb_email = (string) file_get_contents($root . '/phpBB2/pafiledb/modules/pa_email.php');
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

foreach (array(
	'strlen($submitted_subject) <= 200',
	'strlen($submitted_message) <= 10000',
	"ctracker_rate_limit_cooldown_remaining('mail-user'",
	"ctracker_rate_limit_mark_success('mail-user'",
	"\$emailer->from(\$board_config['board_email'])",
	"htmlspecialchars(\$row['user_absence_text'], ENT_QUOTES, 'UTF-8')",
	"\$board_config['server_name']"
) as $marker)
{
	if (strpos($profile_email, $marker) === false)
	{
		$errors[] = 'Missing board-email marker: ' . $marker;
	}
}

foreach (array(
	'$link_port !== $board_port',
	"ctracker_rate_limit_cooldown_remaining('mail-user'",
	"ctracker_rate_limit_mark_success('mail-user'",
	"\$emailer->from(\$board_config['board_email'])",
	'$emailer->email_address($friendemail)'
) as $marker)
{
	if (strpos($friend, $marker) === false)
	{
		$errors[] = 'Missing tell-a-friend marker: ' . $marker;
	}
}
if (strpos($profile_email, 'ct_last_mail') !== false || strpos($friend, 'ct_last_mail') !== false)
{
	$errors[] = 'Mail workflows still use the pre-send user timestamp.';
}
$profile_submit = strpos($profile_email, "if ( isset(\$_POST['submit']) )");
$profile_cooldown = strpos($profile_email, "ctracker_rate_limit_cooldown_remaining('mail-user'");
$profile_send = strpos($profile_email, '$emailer->send()');
$profile_mark = strpos($profile_email, "ctracker_rate_limit_mark_success('mail-user'");
$friend_send = strpos($friend, '$emailer->send()');
$friend_mark = strpos($friend, "ctracker_rate_limit_mark_success('mail-user'");
if ($profile_submit === false || $profile_cooldown < $profile_submit ||
	$profile_send === false || $profile_mark < $profile_send ||
	$friend_send === false || $friend_mark < $friend_send)
{
	$errors[] = 'Mail cooldown is not scoped to submission and successful primary delivery.';
}

$schema = (string) file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$updater = (string) file_get_contents($root . '/update/update_from_153a.php');
if (strpos($schema, 'ct_last_mail') !== false || strpos($updater, "'ct_last_mail'") === false)
{
	$errors[] = 'Obsolete mail-cooldown column is not removed by the schema migration.';
}
if (strpos($friend, '$friendname . \' <\' . $friendemail') !== false)
{
	$errors[] = 'Tell-a-friend still embeds an untrusted display name in the recipient header.';
}

if (strpos($contact, "preg_replace('/[\\x00-\\x1f\\x7f]+/', ' ', \$name)") === false)
{
	$errors[] = 'Contact control-character filtering is not byte-safe.';
}

foreach (array(
	"substr(trim((string) \$_POST['subject']), 0, 200)",
	"substr(trim((string) \$_POST['message']), 0, 10000)",
	"\$emailer->from(\$board_config['board_email'])",
	'$emailer->replyto($sender_email)'
) as $marker)
{
	if (strpos($pafiledb_email, $marker) === false)
	{
		$errors[] = 'Missing paFileDB mail marker: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Mail workflow safety checks passed.\n";

?>
