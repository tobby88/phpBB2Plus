<?php
/**
 * Convert the configured phpBB database from latin1 to utf8mb4.
 *
 * Dry-run (default): php tools/migrate-database-utf8mb4.php
 * Apply:             php tools/migrate-database-utf8mb4.php --apply --backup-confirmed
 *
 * ALTER TABLE statements auto-commit. A verified database backup is mandatory.
 */

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "This migration may only be run from the command line.\n");
	exit(2);
}

$apply = in_array('--apply', $argv, true);
$backup_confirmed = in_array('--backup-confirmed', $argv, true);

if ($apply && !$backup_confirmed)
{
	fwrite(STDERR, "Refusing to apply: add --backup-confirmed after verifying a current backup.\n");
	exit(2);
}

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'phpBB2';
$config_file = $root . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($config_file))
{
	fwrite(STDERR, "config.php was not found.\n");
	exit(2);
}

require $config_file;

foreach ($argv as $argument)
{
	if (strpos($argument, '--database=') === 0)
	{
		$requested_database = substr($argument, strlen('--database='));
		if (!preg_match('/^[A-Za-z0-9_]+$/', $requested_database))
		{
			fwrite(STDERR, "Invalid --database name.\n");
			exit(2);
		}
		$dbname = $requested_database;
	}
}

mysqli_report(MYSQLI_REPORT_OFF);
$connection = @mysqli_connect($dbhost, $dbuser, $dbpasswd, $dbname);
if (!$connection)
{
	fwrite(STDERR, "Database connection failed: " . mysqli_connect_error() . "\n");
	exit(2);
}

if (!mysqli_set_charset($connection, 'utf8mb4'))
{
	fwrite(STDERR, "Could not select utf8mb4 for the migration connection.\n");
	exit(2);
}

function quote_identifier($identifier)
{
	return '`' . str_replace('`', '``', $identifier) . '`';
}

function query_or_fail($connection, $sql)
{
	$result = mysqli_query($connection, $sql);
	if ($result === false)
	{
		fwrite(STDERR, "SQL failed: " . mysqli_error($connection) . "\nStatement: $sql\n");
		exit(3);
	}
	return $result;
}

function scalar_value($connection, $sql)
{
	$result = query_or_fail($connection, $sql);
	$row = mysqli_fetch_row($result);
	mysqli_free_result($result);
	return $row ? $row[0] : null;
}

$database = mysqli_real_escape_string($connection, $dbname);
$table_prefix = isset($table_prefix) ? $table_prefix : 'phpbb_';
$short_index_columns = array(
	$table_prefix . 'album_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'album_sp_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'attachments_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'captcha_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'ctracker_backup' => array('config_name', ''),
	$table_prefix . 'ctracker_config' => array('ct_config_name', ''),
	$table_prefix . 'kb_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'pa_config' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'plus' => array('config_name', "DEFAULT ''"),
	$table_prefix . 'portal' => array('portal_name', "DEFAULT ''"),
);

$tables = array();
$result = query_or_fail($connection, 'SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
while ($row = mysqli_fetch_row($result))
{
	$tables[] = $row[0];
}
mysqli_free_result($result);

$character_columns = (int) scalar_value(
	$connection,
	"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$database' AND CHARACTER_SET_NAME IS NOT NULL"
);
$utf8mb4_columns = (int) scalar_value(
	$connection,
	"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$database' AND CHARACTER_SET_NAME = 'utf8mb4'"
);

echo ($apply ? "APPLY" : "DRY RUN") . " database UTF-8 migration\n";
echo "Database: $dbname\n";
echo 'Tables: ' . count($tables) . "\n";
echo "Character columns: $character_columns ($utf8mb4_columns already utf8mb4)\n\n";

foreach ($short_index_columns as $table => $column_data)
{
	$column = $column_data[0];
	$exists = (int) scalar_value(
		$connection,
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$database'" .
		" AND TABLE_NAME = '" . mysqli_real_escape_string($connection, $table) . "'" .
		" AND COLUMN_NAME = '" . mysqli_real_escape_string($connection, $column) . "'"
	);
	if (!$exists)
	{
		echo "SKIP missing " . $table . '.' . $column . "\n";
		continue;
	}

	$max_length = (int) scalar_value(
		$connection,
		'SELECT COALESCE(MAX(CHAR_LENGTH(' . quote_identifier($column) . ')), 0) FROM ' . quote_identifier($table)
	);
	if ($max_length > 191)
	{
		fwrite(STDERR, "Cannot shorten $table.$column: existing value length is $max_length.\n");
		exit(3);
	}

	$sql = 'ALTER TABLE ' . quote_identifier($table) . ' MODIFY ' . quote_identifier($column) .
		' VARCHAR(191) NOT NULL ' . $column_data[1];
	echo "$sql; -- longest value: $max_length\n";
	if ($apply)
	{
		query_or_fail($connection, $sql);
	}
}

$search_tables = array($table_prefix . 'search_wordmatch', $table_prefix . 'search_wordlist');
foreach ($search_tables as $search_table)
{
	if (!in_array($search_table, $tables, true))
	{
		continue;
	}
	$row_count = (int) scalar_value($connection, 'SELECT COUNT(*) FROM ' . quote_identifier($search_table));
	echo 'TRUNCATE TABLE ' . quote_identifier($search_table) . "; -- $row_count derived search rows; rebuild required\n";
	if ($apply)
	{
		query_or_fail($connection, 'TRUNCATE TABLE ' . quote_identifier($search_table));
	}
}

foreach ($tables as $table)
{
	$sql = 'ALTER TABLE ' . quote_identifier($table) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	echo "$sql;\n";
	if ($apply)
	{
		query_or_fail($connection, $sql);
	}
}

$database_sql = 'ALTER DATABASE ' . quote_identifier($dbname) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
echo "$database_sql;\n";
if ($apply)
{
	query_or_fail($connection, $database_sql);
}

if ($apply)
{
	$remaining = (int) scalar_value(
		$connection,
		"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$database'" .
		" AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4'"
	);
	if ($remaining !== 0)
	{
		fwrite(STDERR, "Migration finished with $remaining non-utf8mb4 character columns.\n");
		exit(4);
	}
	echo "\nMigration complete. Rebuild the phpBB search index before enabling search.\n";
}
else
{
	echo "\nDry run only. No database changes were made.\n";
}

mysqli_close($connection);
