<?php

$root = dirname(dirname(__DIR__));
$sessions = (string) file_get_contents($root . '/phpBB2/includes/sessions.php');
$errors = array();

foreach (array(
	'if (!empty($login) && $session_id !== \'\')',
	"DELETE FROM " . '" . SESSIONS_TABLE',
	"AND session_ip = '",
	"\$session_id = '';",
	'bin2hex(phpbb_random_bytes(16))',
	'else if ($sessionmethod == SESSION_METHOD_COOKIE)',
	"AND user_id = " . '" . (int) $user_id'
) as $marker)
{
	if (strpos($sessions, $marker) === false)
	{
		$errors[] = 'Missing authenticated-session rotation marker: ' . $marker;
	}
}

if (strpos($sessions, "if ( \$userdata['session_user_id'] != ANONYMOUS )") !== false)
{
	$errors[] = 'The inverted Plus SID branch still exposes authenticated sessions in URLs.';
}
if (substr_count($sessions, 'AND user_id = " . (int) $user_id') < 2)
{
	$errors[] = 'Auto-login key rotations are not consistently bound to their user.';
}

$rotation = strpos($sessions, 'if (!empty($login) && $session_id !== \'\')');
$reuse = strpos($sessions, 'SET session_user_id = $user_id');
$generation = strpos($sessions, 'bin2hex(phpbb_random_bytes(16))');
if ($rotation === false || $reuse === false || $generation === false || !($rotation < $reuse && $reuse < $generation))
{
	$errors[] = 'Authenticated session ids are not invalidated before the legacy reuse path.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Authenticated session rotation checks passed.\n";

?>
