<?php

$root = dirname(dirname(__DIR__));
$board = (string) file_get_contents($root . '/phpBB2/admin/admin_board.php');
$errors = array();

foreach (array(
	'$is_submit = isset($_POST[\'submit\'])',
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	'phpbb_admin_post_string($config_name)',
	'$db->sql_escape($new[$config_name])',
	'$db->sql_escape($config_name)',
	"preg_match('#(?:^|/)\\.\\.(?:/|$)#'",
	'phpbb_admin_html($report_forum_rows[$i][\'forum_name\'])',
	'foreach ($new as $config_name => $config_value)'
) as $marker)
{
	if (strpos($board, $marker) === false)
	{
		$errors[] = 'Missing board-configuration safety marker: ' . $marker;
	}
}

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
