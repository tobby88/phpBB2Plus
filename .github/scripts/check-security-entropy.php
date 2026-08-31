<?php

require dirname(dirname(__DIR__)) . '/phpBB2/includes/php_compat.php';

function security_entropy_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Security entropy test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$token = phpbb_random_string(64, 'ABC234');
security_entropy_assert(strlen($token) === 64, 'random strings must have the requested length');
security_entropy_assert(preg_match('/^[ABC234]+$/D', $token) === 1, 'random strings must use only the selected alphabet');
security_entropy_assert(strlen(bin2hex(phpbb_random_bytes(16))) === 32, '128-bit identifiers must retain the legacy 32-character shape');

$files = array(
	'posting.php',
	'ctracker/engines/ct_visual_confirm.php',
	'includes/usercp_register.php',
	'includes/functions_arcade.php',
	'album_upload.php',
	'pafiledb/includes/functions.php',
	'includes/usercp_avatar.php',
	'includes/sessions.php',
	'includes/bbcode.php'
);
foreach ($files as $relative)
{
	$source = file_get_contents($root . '/phpBB2/' . $relative);
	security_entropy_assert(strpos($source, 'md5(dss_rand() . dss_rand()') === false, $relative . ' must not derive identifiers from the legacy PRNG');
}

$sessions = file_get_contents($root . '/phpBB2/includes/sessions.php');
security_entropy_assert(substr_count($sessions, 'bin2hex(phpbb_random_bytes(16))') >= 2, 'all auto-login key rotations must use 128-bit CSPRNG values');
security_entropy_assert(strpos($sessions, 'dss_rand() . dss_rand()') === false, 'auto-login keys must not use the legacy generator interface');

$bbcode = file_get_contents($root . '/phpBB2/includes/bbcode.php');
security_entropy_assert(strpos($bbcode, "phpbb_random_string(BBCODE_UID_LEN, '0123456789abcdef')") !== false, 'BBCode identifiers must use the shared unbiased CSPRNG helper');
security_entropy_assert(strpos($bbcode, 'mt_srand') === false, 'BBCode parsing must not mutate the process-wide legacy PRNG state');

foreach (array('posting.php', 'ctracker/engines/ct_visual_confirm.php', 'includes/usercp_register.php') as $relative)
{
	$source = file_get_contents($root . '/phpBB2/' . $relative);
	security_entropy_assert(strpos($source, "phpbb_random_string(6, '23456789ABCDEFGHJKLMNPQRSTUVWXYZ')") !== false, $relative . ' must generate unbiased visual-confirmation text');
	security_entropy_assert(strpos($source, 'bin2hex(phpbb_random_bytes(16))') !== false, $relative . ' must generate a 128-bit confirmation id');
}

echo "Security entropy checks passed.\n";
