<?php

function dbmtnc_safety_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Database-maintenance safety test failed: $message\n");
		exit(1);
	}
}

$root = dirname(dirname(__DIR__));
$admin = file_get_contents($root . '/phpBB2/admin/admin_db_maintenance.php');
$english = file_get_contents($root . '/phpBB2/language/lang_english/lang_dbmtnc.php');
$german = file_get_contents($root . '/phpBB2/language/lang_german/lang_dbmtnc.php');
$templates = array(
	file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/dbmtnc_confirm_body.tpl'),
	file_get_contents($root . '/phpBB2/templates/fisubsilversh/admin/dbmtnc_config_body.tpl')
);

dbmtnc_safety_assert(strpos($admin, 'phpbb_admin_require_post_session();') !== false, 'confirmed maintenance and configuration writes must require a POST session token');
dbmtnc_safety_assert(strpos($admin, "array('', 'start', 'perform')") !== false, 'maintenance modes must use a strict allowlist');
dbmtnc_safety_assert(strpos($admin, "array('perform_rebuild', 'synchronize_post_direct')") !== false, 'only explicit long-running continuations may bypass POST');
dbmtnc_safety_assert(strpos($admin, 'dbmtnc_continuation_token') !== false, 'long-running continuations must carry a session-bound token');
dbmtnc_safety_assert(substr_count($admin, 'dbmtnc_continuation_url(') >= 6, 'every emitted continuation URL must use the signed helper');
dbmtnc_safety_assert(strpos($admin, 'mode=perform&amp;function=check_post') === false, 'ordinary maintenance must not be linked as a GET mutation');
dbmtnc_safety_assert(strpos($admin, '$HTTP_POST_VARS') === false, 'configuration writes must use scalar-checked POST values');
dbmtnc_safety_assert(strpos($admin, 'dbmtnc_post_int') !== false, 'numeric configuration values must pass through one scalar validator');
dbmtnc_safety_assert(strpos($admin, "phpbb_admin_html(\$function)") !== false, 'confirmed function names must be escaped in hidden fields');
dbmtnc_safety_assert(strpos($english, "\$lang['Confirm_dbmtnc_action']") !== false, 'English generic write confirmation is missing');
dbmtnc_safety_assert(strpos($german, "\$lang['Confirm_dbmtnc_action']") !== false, 'German generic write confirmation is missing');

foreach ($templates as $template)
{
	dbmtnc_safety_assert(strpos($template, '{S_HIDDEN_FIELDS}') !== false, 'maintenance forms must render their session fields');
}

echo "Database-maintenance safety tests passed.\n";
