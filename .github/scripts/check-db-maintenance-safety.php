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
$erc = file_get_contents($root . '/phpBB2/admin/erc.php');
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
dbmtnc_safety_assert(strpos($admin, 'phpbb_normalize_host($server_name_candidate') !== false, 'restored server names must use the shared host validator');
dbmtnc_safety_assert(strpos($admin, '$value_sql = $db->sql_escape($value);') !== false, 'restored configuration values must be SQL-escaped');
dbmtnc_safety_assert(strpos($english, "\$lang['Confirm_dbmtnc_action']") !== false, 'English generic write confirmation is missing');
dbmtnc_safety_assert(strpos($german, "\$lang['Confirm_dbmtnc_action']") !== false, 'German generic write confirmation is missing');

foreach ($templates as $template)
{
	dbmtnc_safety_assert(strpos($template, '{S_HIDDEN_FIELDS}') !== false, 'maintenance forms must render their session fields');
}

foreach (array(
	"isset(\$_GET['token']) && is_scalar(\$_GET['token'])",
	"isset(\$_COOKIE['phpbb_erc_token']) && is_scalar(\$_COOKIE['phpbb_erc_token'])",
	"setcookie('phpbb_erc_token', \$erc_token, 0, '/; SameSite=Strict', '', true, true)",
	"header('Location: erc.php', true, 303)",
	"header('Referrer-Policy: no-referrer')",
	"header('Cache-Control: no-store, private, max-age=0')"
) as $marker)
{
	dbmtnc_safety_assert(strpos($erc, $marker) !== false, 'Emergency console hardening is missing: ' . $marker);
}
dbmtnc_safety_assert(strpos($erc, "\$_REQUEST['token']") === false, 'Emergency capability tokens must not be cookie-merged through REQUEST.');
dbmtnc_safety_assert(strpos($erc, 'phpbb_normalize_host($server_name_candidate') !== false, 'ERC-restored server names must use the shared host validator');
dbmtnc_safety_assert(strpos($erc, '$value_sql = $db->sql_escape($value);') !== false, 'ERC-restored configuration values must be SQL-escaped');

echo "Database-maintenance safety tests passed.\n";
