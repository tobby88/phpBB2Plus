<?php

$root = dirname(dirname(__DIR__));
$emailer = (string) file_get_contents($root . '/phpBB2/includes/emailer.php');
$profile_email = (string) file_get_contents($root . '/phpBB2/includes/usercp_email.php');
$friend = (string) file_get_contents($root . '/phpBB2/tellafriend.php');
$contact = (string) file_get_contents($root . '/phpBB2/kontakt_post.php');
$errors = array();

foreach (array(
	"preg_match('/^[a-z0-9_-]{1,80}$/iD', \$template_file)",
	"preg_match('/^[a-z_]{1,30}$/D', \$template_lang)",
	'!@is_file($tpl_file) || @is_link($tpl_file)',
	"preg_replace('#[\\x00\\r\\n]+#', '', (string) \$this->subject)"
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
	"\$emailer->from(\$board_config['board_email'])",
	'$emailer->email_address($friendemail)'
) as $marker)
{
	if (strpos($friend, $marker) === false)
	{
		$errors[] = 'Missing tell-a-friend marker: ' . $marker;
	}
}
if (strpos($friend, '$friendname . \' <\' . $friendemail') !== false)
{
	$errors[] = 'Tell-a-friend still embeds an untrusted display name in the recipient header.';
}

if (strpos($contact, "preg_replace('/[\\x00-\\x1f\\x7f]+/', ' ', \$name)") === false)
{
	$errors[] = 'Contact control-character filtering is not byte-safe.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Mail workflow safety checks passed.\n";

?>
