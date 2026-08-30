<?php

$root = dirname(dirname(__DIR__));
$admin = $root . '/phpBB2/admin/admin_db_utilities.php';
$body = (string) file_get_contents($admin);
$errors = array();

$required = array(
	"in_array(\$perform, array('backup', 'restore'), true)",
	"in_array(SQL_LAYER, array('mysql', 'mysql4', 'mysqli'), true)",
	'phpbb_admin_require_post_session();',
	'phpbb_admin_session_field()',
	"in_array(\$backup_type, array('full', 'structure', 'data'), true)",
	'strpos($table_name, $table_prefix) === 0',
	"preg_match('/^[A-Za-z0-9_]+$/D', \$additional_table)",
	'is_uploaded_file($backup_file_tmpname)',
	"preg_match('/\\.sql(?:\\.gz)?$/iD', \$backup_file_name)",
	"isset(\$_FILES['backup_file'])",
	'SHOW CREATE TABLE ',
	"\$db->sql_escape(\$row[\$field_names[\$j]])",
	'SET FOREIGN_KEY_CHECKS=0;',
	'X-Content-Type-Options: nosniff'
);

foreach ($required as $marker)
{
	if (strpos($body, $marker) === false)
	{
		$errors[] = 'Missing database utility safety marker: ' . $marker;
	}
}

$forbidden = array(
	"\$_GET['additional_tables']",
	"\$_GET['backup_type']",
	"\$_GET['gzipcompress']",
	"\$_GET['backupstart']",
	"\$_GET['startdownload']",
	'$HTTP_POST_FILES',
	'quotemeta($additional_tables)',
	'<meta http-equiv="refresh"',
	'@each(',
	'addslashes($row[$field_names[$j]])'
);

foreach ($forbidden as $marker)
{
	if (strpos($body, $marker) !== false)
	{
		$errors[] = 'Legacy database utility path remains: ' . $marker;
	}
}

if (substr_count($body, 'phpbb_admin_require_post_session();') < 2)
{
	$errors[] = 'Backup and restore must each enforce the AdminCP POST token.';
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Database utility safety checks passed.\n";
