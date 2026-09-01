<?php

function retired_google_counter_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Retired Google counter check failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$runtime_files = array(
	'phpBB2/includes/page_header.php',
	'phpBB2/templates/fisubsilversh/index_body.tpl',
	'phpBB2/templates/fisubsilversh/index_body_plus.tpl',
	'phpBB2/language/lang_english/lang_main.php',
	'phpBB2/language/lang_german/lang_main.php',
	'phpBB2/install/schemas/mysql_basic.sql'
);
foreach ($runtime_files as $relative)
{
	$source = file_get_contents($root . '/' . $relative);
	retired_google_counter_assert(strpos($source, 'google_visit_counter') === false &&
		strpos($source, 'GOOGLE_VISIT_COUNTER') === false &&
		strpos($source, 'Google Visit Counter') === false,
		$relative . ' still exposes the spoofable counter');
}

$updater = file_get_contents($root . '/update/update_from_153a.php');
retired_google_counter_assert(strpos($updater, "WHERE config_name = 'google_visit_counter'") !== false,
	'the 1.53a upgrade path does not remove the obsolete configuration row');
retired_google_counter_assert(strpos($updater, "'Google Visit Counter'") !== false,
	'the 1.53a upgrade path does not remove the obsolete credits row');

echo "Retired Google counter checks passed.\n";

?>
