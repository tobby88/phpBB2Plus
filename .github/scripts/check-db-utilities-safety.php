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
	"message_die(GENERAL_MESSAGE, \$lang['Restore_offline_only'])",
	'SHOW CREATE TABLE ',
	"\$db->sql_escape(\$row[\$field_names[\$j]])",
	'SET FOREIGN_KEY_CHECKS=0;',
	'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;',
	'STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION',
	'SET FOREIGN_KEY_CHECKS=@phpbb_backup_old_foreign_keys;',
	'X-Content-Type-Options: nosniff',
	'phpbb_database_backup_capture($db, $tables, $do_gzip_compress,',
	'fstat($backup_stream)',
	'fpassthru($backup_stream)',
	"finally { fclose(\$backup_stream); }"
);

foreach ($required as $marker)
{
	if (strpos($body, $marker) === false)
	{
		$errors[] = 'Missing database utility safety marker: ' . $marker;
	}
}

$forbidden = array(
	'pg_get_sequences',
	'get_table_def_postgresql',
	'get_table_content_postgresql',
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
	'file_get_contents($backup_file_tmpname)',
	"\$_FILES['backup_file']",
	'split_sql_file($sql_query',
	"\$_POST['restore_start']"
);

foreach ($forbidden as $marker)
{
	if (strpos($body, $marker) !== false)
	{
		$errors[] = 'Legacy database utility path remains: ' . $marker;
	}
}

if (substr_count($body, 'phpbb_admin_require_post_session();') < 1)
{
	$errors[] = 'Backup must enforce the AdminCP POST token; SQL restore is blocked altogether.';
}

$backup = (string) file_get_contents($root . '/phpBB2/includes/functions_database_backup.php');
foreach (array('sql_dedicated_connection', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY',
    'READ COMMITTED', 'LOCK IN SHARE MODE', 'phpbb_database_backup_authority($authority); $authority->commit();',
    'tmpfile()', 'stream_filter_remove($filter)', 'if (!$success && is_resource($stream))',
    'HEX(TABLE_NAME)', "\$rows[0]['ENGINE'] !== 'InnoDB'", 'hash_equals(') as $marker)
{
    if (strpos($backup, $marker) === false) { $errors[] = 'Missing staged backup lifecycle marker: ' . $marker; }
}
if (strpos($body, 'phpbb_database_backup_capture(') > strpos($body, "header('Pragma: no-cache')"))
{
    $errors[] = 'Download headers precede completed backup capture.';
}
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
require_once $root . '/phpBB2/includes/functions_database_backup.php';
// A closed/failed temporary stream cannot quietly produce a successful dump.
$closed_stream = tmpfile(); fclose($closed_stream); $rejected = false;
try { phpbb_database_backup_write($closed_stream, 'not publishable'); }
catch (PhpbbDatabaseBackupException $error) { $rejected = true; }
if (!$rejected) { $errors[] = 'Failed spool writes were accepted.'; }

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
