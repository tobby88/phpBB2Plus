<?php

define('IN_PHPBB', true);
define('CTRACKER_IPBLOCKER_NO_AUTO_RUN', true);
require dirname(dirname(__DIR__)) . '/phpBB2/ctracker/engines/ct_ipblocker.php';

$cases = array(
	array(true, '192.0.2.7', '192.0.2.0/24', 'IPv4 CIDR member'),
	array(false, '192.0.3.7', '192.0.2.0/24', 'IPv4 CIDR outsider'),
	array(true, '2001:db8::42', '2001:db8::/32', 'IPv6 CIDR member'),
	array(false, '2001:db9::42', '2001:db8::/32', 'IPv6 CIDR outsider'),
	array(true, 'BadBrowser v5.1', '*badbrowser v*', 'case-insensitive wildcard'),
	array(true, 'Mozilla/5.0 (compatible)', 'Mozilla/5.0*', 'slash-safe User-Agent'),
	array(true, 'abXXcdYYef', 'a*b*c*d*e*f', 'multiple bounded wildcards'),
	array(true, 'literal\\path', 'literal\\path', 'literal backslash'),
	array(false, 'Mozilla/5.0', 'Mozilla/6.0*', 'non-matching wildcard'),
	array(false, 'anything', '*********', 'excessive wildcard count'),
	array(false, str_repeat('x', 4097), '*', 'oversized target')
);
$errors = array();
foreach ($cases as $case)
{
	$actual = strpos($case[3], 'CIDR') !== false
		? ctracker_ip_matches_cidr($case[1], $case[2])
		: ctracker_blocklist_pattern_matches($case[2], $case[1]);
	if ($actual !== $case[0])
	{
		$errors[] = $case[3];
	}
}

if ($errors)
{
	fwrite(STDERR, 'Blocklist checks failed: ' . implode(', ', $errors) . "\n");
	exit(1);
}

echo "CrackerTracker blocklist matching passed.\n";

?>
