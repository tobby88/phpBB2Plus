<?php

$root = dirname(dirname(__DIR__));
require_once $root . '/phpBB2/includes/php_compat.php';
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
	'define(\'PHPBB_DB_RESTORE_MAX_BYTES\', 67108864)',
	'phpbb_read_limited_file($backup_file_tmpname, $is_gzip_restore, PHPBB_DB_RESTORE_MAX_BYTES)',
	"\$restore_read['status'] === 'too_large'",
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
	'addslashes($row[$field_names[$j]])',
	'file_get_contents($backup_file_tmpname)'
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

$plain_restore = tempnam(sys_get_temp_dir(), 'phpbb-db-restore-');
file_put_contents($plain_restore, 'SELECT 1;');
$plain_result = phpbb_read_limited_file($plain_restore, false, 64);
if ($plain_result['status'] !== 'ok' || $plain_result['data'] !== 'SELECT 1;')
{
	$errors[] = 'Bounded plain backup reading failed.';
}
$large_result = phpbb_read_limited_file($plain_restore, false, 4);
if ($large_result['status'] !== 'too_large' || $large_result['data'] !== '')
{
	$errors[] = 'Oversized plain backups were not rejected.';
}
file_put_contents($plain_restore, str_repeat('B', 8192));
$block_result = phpbb_read_limited_file($plain_restore, false, 8192);
if ($block_result['status'] !== 'ok' || strlen($block_result['data']) !== 8192)
{
	$errors[] = 'A backup ending on the stream block boundary was not read completely.';
}
@unlink($plain_restore);

if (extension_loaded('zlib'))
{
	$gzip_restore = tempnam(sys_get_temp_dir(), 'phpbb-db-restore-');
	$gzip_handle = gzopen($gzip_restore, 'wb');
	gzwrite($gzip_handle, str_repeat('A', 128));
	gzclose($gzip_handle);
	$gzip_result = phpbb_read_limited_file($gzip_restore, true, 32);
	if ($gzip_result['status'] !== 'too_large' || $gzip_result['data'] !== '')
	{
		$errors[] = 'Expanded gzip backups were not bounded.';
	}
	@unlink($gzip_restore);
}

if ($errors)
{
	fwrite(STDERR, implode("\n", $errors) . "\n");
	exit(1);
}

echo "Database utility safety checks passed.\n";
