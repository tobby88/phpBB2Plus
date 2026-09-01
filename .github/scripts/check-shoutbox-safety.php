<?php

$root = dirname(dirname(__DIR__));
$compact = file_get_contents($root . '/phpBB2/shoutbox.php');
$full = file_get_contents($root . '/phpBB2/shoutbox_max.php');

if ($compact === false || $full === false) {
    fwrite(STDERR, "Unable to read shoutbox sources.\n");
    exit(1);
}

foreach (array('compact' => $compact, 'full' => $full) as $name => $source) {
    $checks = array(
        'POST session guard' => strpos($source, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false && strpos($source, "hash_equals((string) \$userdata['session_id']") !== false,
        'bounded shout text' => strpos($source, '$shout_max_length = 16000;') !== false,
        'driver-escaped normalized message' => strpos($source, '$db->sql_escape(stripslashes($message))') !== false,
        'driver-escaped IP flood identity' => strpos($source, '"shout_ip = \'" . $db->sql_escape($user_ip)') !== false,
        'bounded flood interval' => strpos($source, '$flood_interval = max(0, isset(') !== false,
        'bounded pruning interval' => strpos($source, '$prune_shout_days = isset(') !== false,
        'scalar pagination input' => strpos($source, "isset(\$_POST['start']) && is_scalar(\$_POST['start'])") !== false,
    );

    foreach ($checks as $label => $passed) {
        if (!$passed) {
            fwrite(STDERR, "Shoutbox safety check failed ($name): $label\n");
            exit(1);
        }
    }
}

$full_checks = array(
    'moderation identifiers are scalar' => substr_count($full, 'isset($_GET[POST_POST_URL]) && is_scalar($_GET[POST_POST_URL])') >= 2,
    'missing IP-view records are rejected' => strpos($full, 'if (!$shout_identifyer)') !== false,
    'reverse-DNS targets are allowlisted' => strpos($full, "preg_match('/^[a-f0-9]{8}$/i', \$rdns_ip_num)") !== false,
    'reverse-DNS links retain encoded IPs' => strpos($full, 'rawurlencode($shout_identifyer[\'shout_ip\'])') !== false,
	'reverse-DNS output is HTML escaped' => substr_count($full, "htmlspecialchars((string) \$ip") >= 2,
    'undefined post-row IP is gone' => strpos($full, "\$post_row['shout_ip']") === false,
    'page size cannot be zero' => strpos($full, '$shouts_per_page = max(1,') !== false,
	'page has a localized title' => strpos($full, '$page_title = $lang[\'Shoutbox\'];') !== false,
	'mini-post links have an image' => strpos($full, "'MINI_POST_IMG' => \$images['icon_minipost']") !== false,
	'mini-post links target their row' => strpos($full, "'#shout-' . intval(\$shout_row['shout_id'])") !== false,
);

foreach ($full_checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "Full shoutbox safety check failed: $label\n");
        exit(1);
    }
}

foreach (array('shoutbox_max_body.tpl', 'shoutbox_max_guest_body.tpl') as $template_name)
{
	$template_source = file_get_contents($root . '/phpBB2/templates/fisubsilversh/' . $template_name);
	if ($template_source === false || strpos($template_source, '<tr id="shout-{shoutrow.U_SHOUT_ID}">') === false)
	{
		fwrite(STDERR, "Full shoutbox template check failed: $template_name has no stable row target\n");
		exit(1);
	}
}

echo "Shoutbox safety checks passed.\n";
