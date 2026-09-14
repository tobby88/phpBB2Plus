<?php

$root = dirname(dirname(__DIR__));
$board = (string) file_get_contents($root . '/phpBB2/admin/admin_board.php');
$writer = (string) file_get_contents($root . '/phpBB2/includes/functions_board_config.php');
$errors = array();

foreach (array(
	'$is_submit = isset($_POST[\'submit\'])',
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'phpbb_board_config_save($db, $_POST)',
	'phpbb_admin_html($report_forum_rows[$i][\'forum_name\'])',
	'foreach ($new as $config_name => $config_value)'
) as $marker)
{
	if (strpos($board, $marker) === false)
	{
		$errors[] = 'Missing board-configuration safety marker: ' . $marker;
	}
}

foreach (array('stripslashes($encoded)', '$db->sql_escape($value)', '$db->sql_escape($key)',
	'phpbb_normalize_host($server_name_candidate', 'phpbb_normalize_port($value', 'phpbb_normalize_script_path($value',
	"preg_match('#(?:^|/)\\.\\.(?:/|$)#'", 'START TRANSACTION', 'LOCK IN SHARE MODE', 'ROLLBACK') as $marker)
{
	if (strpos($writer, $marker) === false) { $errors[] = 'Missing board writer safety marker: ' . $marker; }
}
if (strpos($board, '"UPDATE " . CONFIG_TABLE') !== false) { $errors[] = 'Old nontransactional controller writer remains'; }

foreach (array('$HTTP_POST_VARS', 'str_replace("\\\'", "\'\'", $new[$config_name])', '<input type="hidden" name="sid"') as $marker)
{
	if (strpos($board, $marker) !== false)
	{
		$errors[] = 'Legacy board-configuration path remains: ' . $marker;
	}
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Board configuration safety checks passed.\n";
