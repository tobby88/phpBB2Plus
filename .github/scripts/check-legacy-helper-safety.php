<?php

$root = dirname(dirname(__DIR__));
$checks = array(
	'phpBB2/smilie_creator.php' => array(
		"is_scalar(\$_GET['mode'])",
		"preg_match('/^smilie([1-9][0-9]*)\\.png$/i'",
		"make_jumpbox('viewforum.' . \$phpEx)",
	),
	'phpBB2/text2schild.php' => array(
		"function_exists('gd_info')",
		"in_array(intval(\$smilie), \$smilie_ids, true)",
		"header('X-Content-Type-Options: nosniff')",
	),
	'phpBB2/quick_reply.php' => array(
		'is_scalar($mode_value)',
		'$total_posts > 0',
		"htmlspecialchars(\$last_msg, ENT_QUOTES, 'UTF-8')",
	),
	'phpBB2/recent.php' => array(
		'is_array($tracking_topics)',
		"max(1, intval(\$board_config['posts_per_page']))",
		'$topic_length = max(1, min(200, intval($topic_length)))',
		'$topic_limit = max(1, min(100, intval($topic_limit)))',
		'$except_forums_sql = phpbb_sql_id_list($except_forums)',
		"phpbb_sql_id_list(\$forum_ids)",
		"htmlspecialchars(\$line[\$i]['forum_name'], ENT_QUOTES, 'UTF-8')",
	),
	'phpBB2/hacks_list.php' => array(
		"preg_match('/^[a-z0-9_-]+$/i', \$userdata['user_lang'])",
		'is_scalar($value)',
	),
);

$failed = false;
foreach ($checks as $relative => $markers)
{
	$source = file_get_contents($root . '/' . $relative);
	foreach ($markers as $marker)
	{
		if (strpos($source, $marker) === false)
		{
			fwrite(STDERR, $relative . ': missing safety marker: ' . $marker . PHP_EOL);
			$failed = true;
		}
	}
}

if ($failed)
{
	exit(1);
}

echo "Legacy helper safety checks passed.\n";
