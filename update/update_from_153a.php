<?php
/**
 * Bring an existing phpBB2 Plus 1.53a database to this repository's schema.
 *
 * Dry run: php update/update_from_153a.php
 * Apply:   php update/update_from_153a.php --apply --backup-confirmed
 * Test:    php update/update_from_153a.php --self-test
 *
 * This script is intentionally CLI-only and idempotent. Existing settings and
 * data win over installation defaults. DDL statements auto-commit, therefore
 * a verified database backup is mandatory before --apply is accepted.
 */

if (PHP_SAPI !== 'cli')
{
	http_response_code(404);
	exit(2);
}

$project_root = dirname(__DIR__);
$forum_root = $project_root . DIRECTORY_SEPARATOR . 'phpBB2';
$schema_file = $forum_root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'mysql_schema.sql';
$basic_file = $forum_root . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'mysql_basic.sql';

function update_usage()
{
	echo "phpBB2 Plus post-1.53a database updater\n\n";
	echo "  php update/update_from_153a.php                         Dry run\n";
	echo "  php update/update_from_153a.php --apply --backup-confirmed  Apply\n";
	echo "  php update/update_from_153a.php --database=clone_name       Use a clone\n";
	echo "  php update/update_from_153a.php --self-test                 Test schema input\n";
}

function update_extract_create_tables($schema)
{
	$pattern = '~CREATE TABLE\s+`?(phpbb_(?:(?:ina|ctracker)_[A-Za-z0-9_]+|logs))`?\s*\(.*?\)\s*ENGINE\s*=\s*MyISAM[^;]*;~is';
	preg_match_all($pattern, $schema, $matches, PREG_SET_ORDER);
	$statements = array();
	foreach ($matches as $match)
	{
		$statements[$match[1]] = trim($match[0]);
	}
	return $statements;
}

function update_extract_seed_statements($basic)
{
	$pattern = '~INSERT INTO\s+`?(phpbb_(?:ina_data|ctracker_config|ctracker_ipblocker))`?\s*.*?;~is';
	preg_match_all($pattern, $basic, $matches, PREG_SET_ORDER);
	$statements = array();
	foreach ($matches as $match)
	{
		$sql = preg_replace('/^INSERT INTO/i', 'INSERT IGNORE INTO', trim($match[0]));
		$statements[] = $sql;
	}
	return $statements;
}

if (in_array('--help', $argv, true) || in_array('-h', $argv, true))
{
	update_usage();
	exit(0);
}

if (!is_file($schema_file) || !is_file($basic_file))
{
	fwrite(STDERR, "Fresh-install schema files were not found.\n");
	exit(2);
}

$schema_source = file_get_contents($schema_file);
$basic_source = file_get_contents($basic_file);
$create_statements = update_extract_create_tables($schema_source);
$seed_statements = update_extract_seed_statements($basic_source);

if (in_array('--self-test', $argv, true))
{
	$arcade_tables = 0;
	$ctracker_tables = 0;
	foreach (array_keys($create_statements) as $table)
	{
		if (strpos($table, 'phpbb_ina_') === 0) { $arcade_tables++; }
		if (strpos($table, 'phpbb_ctracker_') === 0) { $ctracker_tables++; }
	}
	if ($arcade_tables !== 18 || $ctracker_tables !== 5 || !isset($create_statements['phpbb_logs']) || count($seed_statements) < 66)
	{
		fwrite(STDERR, "Schema self-test failed: $arcade_tables Arcade tables, $ctracker_tables CrackerTracker tables, " . count($seed_statements) . " seed statements.\n");
		exit(3);
	}
	echo "Schema self-test passed: $arcade_tables Arcade tables, $ctracker_tables CrackerTracker tables, " . count($seed_statements) . " seed statements.\n";
	exit(0);
}

$apply = in_array('--apply', $argv, true);
$backup_confirmed = in_array('--backup-confirmed', $argv, true);
if ($apply && !$backup_confirmed)
{
	fwrite(STDERR, "Refusing to apply: add --backup-confirmed after verifying a current backup.\n");
	exit(2);
}

$config_file = $forum_root . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($config_file))
{
	fwrite(STDERR, "phpBB2/config.php was not found.\n");
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

$required_config = array('dbhost', 'dbuser', 'dbpasswd', 'dbname', 'table_prefix');
foreach ($required_config as $variable)
{
	if (!isset($$variable))
	{
		fwrite(STDERR, "config.php does not define \$$variable.\n");
		exit(2);
	}
}
if (!preg_match('/^[A-Za-z0-9_]+$/', $table_prefix))
{
	fwrite(STDERR, "The configured table prefix is not safe to use.\n");
	exit(2);
}
if (isset($dbms) && !in_array($dbms, array('mysql', 'mysql4', 'mysqli'), true))
{
	fwrite(STDERR, "Only MySQL/MariaDB installations are supported by this updater.\n");
	exit(2);
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
	fwrite(STDERR, "Could not select utf8mb4 for the update connection.\n");
	exit(2);
}

function update_quote_identifier($identifier)
{
	return '`' . str_replace('`', '``', $identifier) . '`';
}

function update_query_or_fail($connection, $sql)
{
	$result = mysqli_query($connection, $sql);
	if ($result === false)
	{
		fwrite(STDERR, "SQL failed: " . mysqli_error($connection) . "\nStatement: $sql\n");
		exit(3);
	}
	return $result;
}

function update_scalar($connection, $sql)
{
	$result = update_query_or_fail($connection, $sql);
	$row = mysqli_fetch_row($result);
	mysqli_free_result($result);
	return $row ? $row[0] : null;
}

function update_table_exists($connection, $database, $table)
{
	$sql = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" .
		mysqli_real_escape_string($connection, $database) . "' AND TABLE_NAME = '" .
		mysqli_real_escape_string($connection, $table) . "'";
	return (int) update_scalar($connection, $sql) > 0;
}

function update_column_exists($connection, $database, $table, $column)
{
	$sql = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" .
		mysqli_real_escape_string($connection, $database) . "' AND TABLE_NAME = '" .
		mysqli_real_escape_string($connection, $table) . "' AND COLUMN_NAME = '" .
		mysqli_real_escape_string($connection, $column) . "'";
	return (int) update_scalar($connection, $sql) > 0;
}

function update_index_exists($connection, $database, $table, $index)
{
	$sql = "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '" .
		mysqli_real_escape_string($connection, $database) . "' AND TABLE_NAME = '" .
		mysqli_real_escape_string($connection, $table) . "' AND INDEX_NAME = '" .
		mysqli_real_escape_string($connection, $index) . "'";
	return (int) update_scalar($connection, $sql) > 0;
}

function update_queue_column(&$operations, $connection, $database, $table, $column, $definition)
{
	if (!update_column_exists($connection, $database, $table, $column))
	{
		$operations[] = 'ALTER TABLE ' . update_quote_identifier($table) . ' ADD ' .
			update_quote_identifier($column) . ' ' . $definition;
	}
}

function update_queue_default(&$operations, $connection, $table, $key_column, $value_column, $key, $value)
{
	$sql = 'INSERT IGNORE INTO ' . update_quote_identifier($table) . ' (' .
		update_quote_identifier($key_column) . ', ' . update_quote_identifier($value_column) . ') VALUES (\'' .
		mysqli_real_escape_string($connection, $key) . '\', \'' .
		mysqli_real_escape_string($connection, $value) . '\')';
	$operations[] = $sql;
}

function update_queue_drop_table(&$operations, $connection, $database, $table)
{
	if (update_table_exists($connection, $database, $table))
	{
		$operations[] = 'DROP TABLE ' . update_quote_identifier($table);
	}
}

function update_queue_drop_column(&$operations, $connection, $database, $table, $column)
{
	if (update_column_exists($connection, $database, $table, $column))
	{
		$operations[] = 'ALTER TABLE ' . update_quote_identifier($table) . ' DROP ' .
			update_quote_identifier($column);
	}
}

$operations = array();

// Reuse the fresh-install schema as the canonical definition for restored
// Arcade and CrackerTracker tables.
foreach ($create_statements as $generic_table => $generic_sql)
{
	$table = preg_replace('/^phpbb_/', $table_prefix, $generic_table);
	if (!update_table_exists($connection, $dbname, $table))
	{
		$operations[] = preg_replace('/\bphpbb_/', $table_prefix, $generic_sql);
	}
}

$user_columns = array(
	'games_block_pm' => 'TINYINT(1) NOT NULL DEFAULT 1',
	'arcade_banned' => 'INT(11) NOT NULL DEFAULT 0',
	'ct_search_time' => 'INT(11) DEFAULT 1',
	'ct_search_count' => 'MEDIUMINT(8) DEFAULT 1',
	'ct_last_mail' => 'INT(11) DEFAULT 1',
	'ct_last_post' => 'INT(11) DEFAULT 1',
	'ct_post_counter' => 'MEDIUMINT(8) DEFAULT 1',
	'ct_last_pw_reset' => 'INT(11) DEFAULT 1',
	'ct_enable_ip_warn' => 'TINYINT(1) DEFAULT 1',
	'ct_last_used_ip' => "VARCHAR(16) DEFAULT '0.0.0.0'",
	'ct_last_ip' => "VARCHAR(16) DEFAULT '0.0.0.0'",
	'ct_login_count' => 'MEDIUMINT(8) DEFAULT 1',
	'ct_login_vconfirm' => 'TINYINT(1) DEFAULT 0',
	'ct_last_pw_change' => 'INT(11) DEFAULT 1',
	'ct_global_msg_read' => 'TINYINT(1) DEFAULT 0',
	'ct_miserable_user' => 'TINYINT(1) DEFAULT 0',
	'user_fb' => 'VARCHAR(255) DEFAULT NULL',
	'user_ig' => 'VARCHAR(255) DEFAULT NULL',
	'user_pt' => 'VARCHAR(255) DEFAULT NULL',
	'user_twr' => 'VARCHAR(255) DEFAULT NULL',
	'user_skp' => 'VARCHAR(255) DEFAULT NULL',
	'user_tg' => 'VARCHAR(255) DEFAULT NULL',
	'user_li' => 'VARCHAR(255) DEFAULT NULL',
	'user_tt' => 'VARCHAR(255) DEFAULT NULL',
	'user_dc' => 'VARCHAR(255) DEFAULT NULL',
	'user_reg_ip' => 'VARCHAR(45) DEFAULT NULL',
	'user_reg_host' => 'VARCHAR(255) DEFAULT NULL'
);
foreach ($user_columns as $column => $definition)
{
	update_queue_column($operations, $connection, $dbname, $table_prefix . 'users', $column, $definition);
}
if (!update_index_exists($connection, $dbname, $table_prefix . 'users', 'user_reg_ip'))
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($table_prefix . 'users') .
		' ADD INDEX `user_reg_ip` (`user_reg_ip`)';
}

foreach (array('div_class1', 'div_class2', 'div_class3', 'row_class1', 'row_class2', 'row_class3', 'col_class1', 'col_class2', 'col_class3') as $column)
{
	update_queue_column($operations, $connection, $dbname, $table_prefix . 'themes', $column, 'VARCHAR(25) DEFAULT NULL');
	update_queue_column($operations, $connection, $dbname, $table_prefix . 'themes_name', $column . '_name', 'VARCHAR(50) DEFAULT NULL');
}

$config_defaults = array(
	'cookie_consent_enable' => '1',
	'sfs_enable' => '0',
	'dbmtnc_rebuild_end' => '0',
	'dbmtnc_rebuild_pos' => '-1',
	'dbmtnc_rebuildcfg_maxmemory' => '500',
	'dbmtnc_rebuildcfg_minposts' => '3',
	'dbmtnc_rebuildcfg_php3only' => '0',
	'dbmtnc_rebuildcfg_php3pps' => '1',
	'dbmtnc_rebuildcfg_php4pps' => '8',
	'dbmtnc_rebuildcfg_timelimit' => '240',
	'dbmtnc_rebuildcfg_timeoverwrite' => '0',
	'dbmtnc_disallow_postcounter' => '0',
	'dbmtnc_disallow_rebuild' => '0'
);
foreach ($config_defaults as $key => $value)
{
	update_queue_default($operations, $connection, $table_prefix . 'config', 'config_name', 'config_value', $key, $value);
}

$album_defaults = array(
	'path_to_bin' => 'cgi-bin/', 'perl_uploader' => '0', 'show_progress_bar' => '0',
	'close_on_finish' => '1', 'max_pause' => '10', 'simple_format' => '0',
	'multiple_uploads' => '1', 'max_uploads' => '10', 'zip_uploads' => '1',
	'resize_pic' => '0', 'resize_width' => '600', 'resize_height' => '600',
	'resize_quality' => '70'
);
foreach ($album_defaults as $key => $value)
{
	update_queue_default($operations, $connection, $table_prefix . 'album_config', 'config_name', 'config_value', $key, $value);
}

foreach ($seed_statements as $seed_sql)
{
	$operations[] = preg_replace('/\bphpbb_/', $table_prefix, $seed_sql);
}

// Keep the public components/credits list useful on upgraded installations.
// The original sample .hl file used to insert a bogus placeholder row whenever
// the public page was opened. It is no longer distributed or scanned there.
$hacks_table = update_quote_identifier($table_prefix . 'hacks_list');
$operations[] = "DELETE FROM $hacks_table WHERE hack_name = 'Hack Name' OR hack_file LIKE '%nivisec_hack_list_auto_insert.hl'";
$operations[] = "DELETE FROM $hacks_table WHERE hack_name IN ('Cracker Tracker Professional 2nd Ed.', 'CrackerTracker Professional 2nd Ed.', 'CrackerTracker Professional G5')";
$credit_rows = array(
	array('Birthday Mod', 'Adds birthday and age information to user profiles and posts.', 'Niels', 'http://mods.db9.dk', '1.5.7'),
	array('Photo Album Addon v2 for phpBB2', 'Integrated phpBB-based photo album and gallery management system.', 'Smartor', 'http://smartor.is-root.com', '2.0.53'),
	array('Recent Topics (third version)', 'Shows recent topics for selectable time periods.', 'Acid', '', '1.22'),
	array('Staff Site Mod', 'Displays the board staff and their roles on a dedicated page.', 'Acid', '', '2.2.3'),
	array('Album Hierarchy Mod', 'Adds nested categories to the integrated Photo Album.', 'IdleVoid', '', '1.30'),
	array('CrackerTracker Professional G5', 'Integrated security system for detecting and blocking known attacks and abusive requests.', 'cback', 'http://www.cback.de', '5.0.6'),
	array('Admin Userlist', 'User administration list with filtering, safe bulk status, ban and group actions, and Color Groups integration.', 'Brent Pirolli, Eric Faerber, Helter, Smartor', '', '2.1'),
	array('Arcade Mod Plus', 'Integrated arcade framework; game packages and user-generated game data are not distributed.', 'Arcade Mod Plus contributors', '', '2.1.8'),
	array('Nuffload Album Upload', 'Multiple and archive upload support for the integrated photo album.', 'Nuffload contributors', '', '1.4.2'),
	array('DB Maintenance Mod', 'Administration tools for database consistency checks and search-index maintenance.', 'DB Maintenance contributors', '', '1.3.8'),
	array('Cookie Consent', 'Displays the configurable cookie information banner.', 'IntegraMOD contributors', '', 'integrated'),
	array('Stop Forum Spam', 'Optional registration checks against the Stop Forum Spam service.', 'Stop Forum Spam MOD contributors', 'https://www.stopforumspam.com/', '2.0'),
	array('Log Actions MOD', 'Records moderation actions and provides an administration log.', 'Morpheus', '', '1.1.6'),
	array('Enhanced Log Actions', 'Extends moderation logging to sticky, announcement and normal topic changes.', 'François-Xavier', '', '1.1.0'),
	array('Registration IP', 'Records the server-verified IP address used for account registration.', 'Woody', '', '1.1.2 adapted'),
	array('Admin Userlist ColorGroups Compatibility', 'Uses Color Groups formatting in the Admin Userlist.', 'Brent Pirolli, Octavius', '', '1.0.1')
);
foreach ($credit_rows as $credit)
{
	$values = array();
	foreach ($credit as $value) { $values[] = "'" . mysqli_real_escape_string($connection, $value) . "'"; }
	$operations[] = "DELETE FROM $hacks_table WHERE hack_name = " . $values[0];
	$operations[] = "INSERT INTO $hacks_table (hack_add_date, hack_name, hack_desc, hack_author, hack_author_email, hack_author_website, hack_version, hack_hide, hack_download_url, hack_file, hack_file_mtime) VALUES (0, " . $values[0] . ', ' . $values[1] . ', ' . $values[2] . ", '', " . $values[3] . ', ' . $values[4] . ", 'No', '', '', 0)";
}

// CrackerTracker 5 is a complete redevelopment. Its official 4.x-to-5.x
// instructions explicitly remove these incompatible 4.x objects after the
// new files and schema have been installed; old settings and logs cannot be
// migrated. Queue the cleanup only for objects which still exist.
$legacy_cleanup_start = count($operations);
foreach (array('ctrack', 'ct_filter', 'ct_viskey') as $legacy_table)
{
	update_queue_drop_table($operations, $connection, $dbname, $table_prefix . $legacy_table);
}

foreach (array(
	'ct_logintry', 'ct_unsucclogin', 'ct_pwreset', 'ct_mailcount',
	'ct_postcount', 'ct_posttime', 'ct_searchcount', 'ct_searchtime'
) as $legacy_column)
{
	update_queue_drop_column(
		$operations,
		$connection,
		$dbname,
		$table_prefix . 'users',
		$legacy_column
	);
}
$legacy_cleanup_count = count($operations) - $legacy_cleanup_start;

$version_table = $table_prefix . 'config';
$version_sql = 'SELECT config_value FROM ' . update_quote_identifier($version_table) . " WHERE config_name = 'version'";
$current_version = (string) update_scalar($connection, $version_sql);
if ($current_version === '.0.22')
{
	$operations[] = 'UPDATE ' . update_quote_identifier($version_table) . " SET config_value = '.0.23' WHERE config_name = 'version'";
}

echo ($apply ? 'APPLY' : 'DRY RUN') . " post-1.53a database update\n";
echo "Database: $dbname\n";
echo "Table prefix: $table_prefix\n";
echo 'Operations: ' . count($operations) . "\n\n";

if ($legacy_cleanup_count > 0)
{
	echo "WARNING: $legacy_cleanup_count incompatible CrackerTracker 4.x database objects will be removed. Their old settings and logs cannot be migrated.\n\n";
}

foreach ($operations as $sql)
{
	echo $sql . ";\n";
	if ($apply)
	{
		update_query_or_fail($connection, $sql);
	}
}

if ($apply)
{
	echo "\nDatabase update complete. Incompatible CrackerTracker 4.x tables and user columns were removed when present, as required by the official 4.x-to-5.x upgrade path.\n";
}
else
{
	echo "\nDry run only. No database changes were made.\n";
}

mysqli_close($connection);
