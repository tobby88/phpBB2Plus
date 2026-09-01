<?php

function admin_ip_privacy_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Admin IP privacy check failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$admin_index = file_get_contents($root . '/phpBB2/admin/index.php');
$admin_index_template = file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/index_body.tpl');
$arcade_scores = file_get_contents($root . '/phpBB2/admin/admin_arcade_scores.php');
$public_source = $admin_index . $admin_index_template . $arcade_scores;

admin_ip_privacy_assert(stripos($public_source, 'network-tools.com') === false,
	'AdminCP source must not send visitor addresses to a third-party Whois site');
admin_ip_privacy_assert(strpos($admin_index, '"IP_ADDRESS" => phpbb_admin_html($reg_ip)') !== false &&
	strpos($admin_index, '"IP_ADDRESS" => phpbb_admin_html($guest_ip)') !== false,
	'online visitor addresses must be escaped for local display');
admin_ip_privacy_assert(strpos($admin_index_template, 'U_WHOIS_IP') === false,
	'the online overview still renders an external Whois link');
admin_ip_privacy_assert(strpos($arcade_scores, 'filter_var($score_info[$i][\'player_ip\'], FILTER_VALIDATE_IP)') !== false,
	'Arcade score addresses must be validated before local display');

echo "Admin IP privacy checks passed.\n";

?>
