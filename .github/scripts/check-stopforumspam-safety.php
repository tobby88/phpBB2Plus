<?php

function stopforumspam_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "StopForumSpam safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$source = file_get_contents($root . '/phpBB2/includes/functions_validate.php');
$register = file_get_contents($root . '/phpBB2/includes/usercp_register.php');
$admin = file_get_contents($root . '/phpBB2/admin/admin_board.php');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_basic.sql');
$updater = file_get_contents($root . '/update/update_from_153a.php');

stopforumspam_assert(strpos($source, "'follow_location' => 0") !== false, 'the fixed API endpoint must not follow redirects');
stopforumspam_assert(strpos($source, "'max_redirects' => 0") !== false, 'redirects must remain disabled explicitly');
stopforumspam_assert(strpos($source, "'timeout' => 4") !== false, 'remote checks need a short timeout');
stopforumspam_assert(strpos($source, '0, 262144') !== false, 'remote responses need a size bound');
stopforumspam_assert(strpos($source, "function_exists('http_get_last_response_headers')") !== false, 'PHP 8.5 response headers must use the supported API');
stopforumspam_assert(strpos($source, '$http_response_header') === false, 'the deprecated implicit response-header variable must not be referenced directly');
stopforumspam_assert(strpos($source, 'LIBXML_NONET') !== false, 'XML parsing must prohibit secondary network access');
stopforumspam_assert(strpos($source, "!empty(\$board_config['sfs_fail_closed'])") !== false, 'API failure policy must be explicit');
stopforumspam_assert(strpos($source, '$stopforumspam_request_unavailable = true') !== false, 'one API outage must suppress repeated remote calls in the same request');
stopforumspam_assert(strpos($source, "filter_var(\$value, FILTER_VALIDATE_IP)") !== false, 'only valid client IPs may be queried');
stopforumspam_assert(strpos($register, "if (\$mode == 'register' && !\$error && !empty(\$board_config['sfs_enable']))") !== false, 'remote checks must wait for successful local validation');
stopforumspam_assert(strpos($register, 'validate_email($email, false)') !== false, 'email validation must not issue an early remote query');
stopforumspam_assert(strpos($register, 'validate_username($username, false)') !== false, 'username validation must not issue an early remote query');
foreach (array($admin, $schema, $updater) as $migration_source)
{
	stopforumspam_assert(strpos($migration_source, 'sfs_fail_closed') !== false, 'failure policy must exist in ACP and migration paths');
}

echo "StopForumSpam safety tests passed.\n";
