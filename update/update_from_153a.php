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
		$sql = rtrim(preg_replace('/^INSERT INTO/i', 'INSERT IGNORE INTO', trim($match[0])), "; \t\r\n");
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
	$schema_has_password_capacity = (bool) preg_match('/user_password\s+varchar\(255\)/i', $schema_source)
		&& (bool) preg_match('/user_newpasswd\s+varchar\(255\)/i', $schema_source);
	$schema_has_ip_capacity = (bool) preg_match('/ct_last_used_ip\s+varchar\(\s*45\s*\)/i', $schema_source)
		&& (bool) preg_match('/ct_last_ip\s+varchar\(\s*45\s*\)/i', $schema_source)
		&& (bool) preg_match('/ct_login_ip`?\s+varchar\(45\)/i', $schema_source);
	$schema_has_checksum_capacity = (bool) preg_match('/`hash`\s+varchar\(64\)/i', $schema_source);
	$schema_has_atomic_blocklist_ids = (bool) preg_match('/CREATE TABLE\s+`phpbb_ctracker_ipblocker`.*?`id`\s+mediumint\(8\)\s+unsigned\s+NOT NULL\s+AUTO_INCREMENT/is', $schema_source);
	$schema_has_stable_login_history = (bool) preg_match('/CREATE TABLE\s+`phpbb_ctracker_loginhistory`.*?`ct_login_id`\s+bigint\(20\)\s+unsigned\s+NOT NULL\s+AUTO_INCREMENT.*?KEY\s+`ct_user_time`/is', $schema_source);
	$basic_has_legacy_blocklist = (bool) preg_match('/INSERT INTO\s+`?phpbb_ctracker_ipblocker/i', $basic_source);
	$schema_has_current_contacts = (bool) preg_match('/user_signal\s+varchar\(255\)/i', $schema_source)
		&& (bool) preg_match('/user_threema\s+varchar\(255\)/i', $schema_source);
	$schema_has_split_password_timestamps = (bool) preg_match('/ct_last_pw_reset\s+INT\(\s*11\s*\)\s+DEFAULT\s+0/i', $schema_source)
		&& (bool) preg_match('/ct_last_pw_change\s+INT\(\s*11\s*\)\s+DEFAULT\s+0/i', $schema_source)
		&& (bool) preg_match("/\('password_timestamps_split',\s*'1'\)/i", $basic_source);
	$schema_has_public_styles = (bool) preg_match('/theme_public\s+tinyint\(1\)/i', $schema_source);
	$basic_has_named_theme_insert = (bool) preg_match('/INSERT INTO\s+phpbb_themes\s*\([^;]*theme_public[^;]*\)\s*VALUES/i', $basic_source);
	$theme_seed_count = preg_match_all('/^INSERT INTO\s+phpbb_themes\s*\(/im', $basic_source, $unused_theme_matches);
	$standard_style_config_count = count(update_read_theme_info($forum_root, 'fisubsilversh'));
	$has_patch_markers = (bool) preg_match('/^\+/m', $schema_source . "\n" . $basic_source);
	if ($arcade_tables !== 17 || $ctracker_tables !== 6 || !isset($create_statements['phpbb_logs']) || count($seed_statements) < 46 || !$schema_has_password_capacity || !$schema_has_ip_capacity || !$schema_has_checksum_capacity || !$schema_has_atomic_blocklist_ids || !$schema_has_stable_login_history || $basic_has_legacy_blocklist || !$schema_has_current_contacts || !$schema_has_split_password_timestamps || !$schema_has_public_styles || !$basic_has_named_theme_insert || $theme_seed_count !== 1 || $standard_style_config_count !== 1 || $has_patch_markers)
	{
		fwrite(STDERR, "Schema self-test failed: $arcade_tables Arcade tables, $ctracker_tables CrackerTracker tables, " . count($seed_statements) . " seed statements, password capacity " . ($schema_has_password_capacity ? 'ok' : 'invalid') . ", split password timestamps " . ($schema_has_split_password_timestamps ? 'ok' : 'invalid') . ", IP capacity " . ($schema_has_ip_capacity ? 'ok' : 'invalid') . ", checksum capacity " . ($schema_has_checksum_capacity ? 'ok' : 'invalid') . ", blocklist IDs " . ($schema_has_atomic_blocklist_ids ? 'atomic' : 'legacy') . ", login history " . ($schema_has_stable_login_history ? 'stable' : 'legacy') . ", legacy blocklist seeds " . ($basic_has_legacy_blocklist ? 'present' : 'none') . ", current contacts " . ($schema_has_current_contacts ? 'ok' : 'invalid') . ", public styles " . ($schema_has_public_styles && $basic_has_named_theme_insert ? 'ok' : 'invalid') . ", standard style $theme_seed_count seed/$standard_style_config_count config, patch markers " . ($has_patch_markers ? 'present' : 'none') . ".\n");
		exit(3);
	}
	echo "Schema self-test passed: $arcade_tables Arcade tables, $ctracker_tables CrackerTracker tables, " . count($seed_statements) . " seed statements, adaptive-password, IPv6 and SHA-256 checksum columns ready.\n";
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

function update_column_max_length($connection, $database, $table, $column)
{
	$sql = "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" .
		mysqli_real_escape_string($connection, $database) . "' AND TABLE_NAME = '" .
		mysqli_real_escape_string($connection, $table) . "' AND COLUMN_NAME = '" .
		mysqli_real_escape_string($connection, $column) . "'";
	$value = update_scalar($connection, $sql);
	return $value === null ? 0 : (int) $value;
}

function update_column_extra($connection, $database, $table, $column)
{
	$sql = "SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" .
		mysqli_real_escape_string($connection, $database) . "' AND TABLE_NAME = '" .
		mysqli_real_escape_string($connection, $table) . "' AND COLUMN_NAME = '" .
		mysqli_real_escape_string($connection, $column) . "'";
	$value = update_scalar($connection, $sql);
	return $value === null ? '' : strtolower((string) $value);
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
	$exists_sql = 'SELECT COUNT(*) FROM ' . update_quote_identifier($table) . ' WHERE ' .
		update_quote_identifier($key_column) . " = '" . mysqli_real_escape_string($connection, $key) . "'";
	if ((int) update_scalar($connection, $exists_sql) > 0)
	{
		return;
	}
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

function update_read_theme_info($forum_root, $template_name)
{
	if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $template_name))
	{
		return array();
	}
	$filename = $forum_root . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template_name . DIRECTORY_SEPARATOR . 'theme_info.cfg';
	$contents = @file_get_contents($filename);
	if ($contents === false || strlen($contents) < 1 || strlen($contents) > 1048576)
	{
		return array();
	}

	$allowed_keys = array(
		'template_name', 'style_name', 'head_stylesheet', 'body_background',
		'body_bgcolor', 'body_text', 'body_link', 'body_vlink', 'body_alink',
		'body_hlink', 'tr_color1', 'tr_color2', 'tr_color3', 'tr_class1',
		'tr_class2', 'tr_class3', 'th_color1', 'th_color2', 'th_color3',
		'th_class1', 'th_class2', 'th_class3', 'td_color1', 'td_color2',
		'td_color3', 'td_class1', 'td_class2', 'td_class3', 'fontface1',
		'fontface2', 'fontface3', 'fontsize1', 'fontsize2', 'fontsize3',
		'fontcolor1', 'fontcolor2', 'fontcolor3', 'span_class1', 'span_class2',
		'span_class3', 'div_class1', 'div_class2', 'div_class3', 'row_class1',
		'row_class2', 'row_class3', 'col_class1', 'col_class2', 'col_class3',
		'img_size_poll', 'img_size_privmsg'
	);
	$rows = array();
	$pattern = '/^\s*\$([A-Za-z0-9_.-]+)\s*\[\s*([0-9]+)\s*\]\s*\[\s*[\'\"]([A-Za-z0-9_]+)[\'\"]\s*\]\s*=\s*\"(.*)\";\s*$/D';
	foreach (preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $contents)) as $line)
	{
		$trimmed = trim($line);
		if ($trimmed === '' || $trimmed === '<?php' || $trimmed === '?>' ||
			substr($trimmed, 0, 2) === '//' || substr($trimmed, 0, 1) === '#')
		{
			continue;
		}
		if (strlen($line) > 8192 || !preg_match($pattern, $line, $match))
		{
			return array();
		}
		$index = (int) $match[2];
		$key = $match[3];
		if ($match[1] !== $template_name || $index > 20 || !in_array($key, $allowed_keys, true) || strlen($match[4]) > 4096)
		{
			return array();
		}
		$value = stripcslashes($match[4]);
		if (preg_match('/[\x00-\x1f\x7f]/', $value))
		{
			return array();
		}
		$rows[$index][$key] = $value;
	}

	ksort($rows);
	$rows = array_values($rows);
	foreach ($rows as $row)
	{
		if (empty($row['style_name']) || !isset($row['template_name']) || $row['template_name'] !== $template_name)
		{
			return array();
		}
	}
	return $rows;
}

function update_queue_standard_style(&$operations, $connection, $forum_root, $themes_table, $themes_name_table, $users_table, $config_table)
{
	foreach (update_read_theme_info($forum_root, 'fisubsilversh') as $style)
	{
		$template_sql = mysqli_real_escape_string($connection, $style['template_name']);
		$style_sql = mysqli_real_escape_string($connection, $style['style_name']);
		$exists_sql = 'SELECT COUNT(*) FROM ' . update_quote_identifier($themes_table) .
			" WHERE template_name = '$template_sql' AND style_name = '$style_sql'";
		if ((int) update_scalar($connection, $exists_sql) === 0)
		{
			$style['theme_public'] = '1';
			$columns = array();
			$values = array();
			foreach ($style as $column => $value)
			{
				$columns[] = update_quote_identifier($column);
				$values[] = "'" . mysqli_real_escape_string($connection, $value) . "'";
			}
			$operations[] = 'INSERT INTO ' . update_quote_identifier($themes_table) . ' (' . implode(', ', $columns) . ') SELECT ' .
				implode(', ', $values) . ' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ' . update_quote_identifier($themes_table) .
				" WHERE template_name = '$template_sql' AND style_name = '$style_sql')";
		}
	}

	$themes_sql = update_quote_identifier($themes_table);
	$themes_name_sql = update_quote_identifier($themes_name_table);
	$users_sql = update_quote_identifier($users_table);
	$config_sql = update_quote_identifier($config_table);
	$standard_id_select = 'SELECT themes_id FROM ' . $themes_sql . " WHERE template_name = 'fisubsilversh' ORDER BY themes_id LIMIT 1";
	$standard_id = '(' . $standard_id_select . ')';
	$operations[] = 'UPDATE ' . $config_sql . ' SET config_value = ' . $standard_id . " WHERE config_name = 'default_style'";
	$operations[] = 'UPDATE ' . $config_sql . " SET config_value = 'fisubsilversh' WHERE config_name = 'xs_def_template'";
	$operations[] = 'UPDATE ' . $users_sql . ' SET user_style = ' . $standard_id . ' WHERE user_style IS NULL OR user_style <> ' . $standard_id;
	$operations[] = 'DELETE FROM ' . $themes_name_sql . ' WHERE themes_id NOT IN (' . $standard_id_select . ')';
	$operations[] = 'DELETE FROM ' . $themes_sql . " WHERE template_name <> 'fisubsilversh'";
	$operations[] = 'DELETE FROM ' . $themes_sql . ' WHERE themes_id <> (SELECT themes_id FROM (SELECT themes_id FROM ' .
		$themes_sql . " WHERE template_name = 'fisubsilversh' ORDER BY themes_id LIMIT 1) standard_theme)";
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
	'ct_last_pw_reset' => 'INT(11) DEFAULT 0',
	'ct_enable_ip_warn' => 'TINYINT(1) DEFAULT 1',
	'ct_last_used_ip' => "VARCHAR(45) DEFAULT '0.0.0.0'",
	'ct_last_ip' => "VARCHAR(45) DEFAULT '0.0.0.0'",
	'ct_last_pw_change' => 'INT(11) DEFAULT 0',
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
	'user_signal' => 'VARCHAR(255) DEFAULT NULL',
	'user_threema' => 'VARCHAR(255) DEFAULT NULL',
	'user_reg_ip' => 'VARCHAR(45) DEFAULT NULL',
	'user_reg_host' => 'VARCHAR(255) DEFAULT NULL'
);
foreach ($user_columns as $column => $definition)
{
	update_queue_column($operations, $connection, $dbname, $table_prefix . 'users', $column, $definition);
}
$users_table = $table_prefix . 'users';
$ctracker_config_table = $table_prefix . 'ctracker_config';
$operations[] = 'UPDATE ' . update_quote_identifier($ctracker_config_table) .
	" SET ct_config_value = '1' WHERE ct_config_name = 'spammer_blockmode' AND CAST(ct_config_value AS UNSIGNED) > 0";
$operations[] = 'DELETE FROM ' . update_quote_identifier($ctracker_config_table) .
	" WHERE ct_config_name = 'logsize_spammer'";
// Retire the original board-global registration locks. One completed signup
// used to block every visitor for reg_blocktime seconds, while reg_lastip
// could block a shared IP indefinitely. Runtime protection is now stored per
// verified IP in ctracker_rate_limits.
$operations[] = 'DELETE FROM ' . update_quote_identifier($ctracker_config_table) .
	" WHERE ct_config_name IN ('reg_last_reg', 'reg_lastip', 'reg_ip_scan')";
// CrackerTracker 5 historically overloaded ct_last_pw_reset with both a
// minutes-long reset-request cooldown and a days-long password-age deadline.
// Migrate once to the otherwise unused ct_last_pw_change timestamp and clear
// the ambiguous legacy cooldown. Fresh installs carry the marker already.
$operations[] = 'UPDATE ' . update_quote_identifier($users_table) . ' SET ' .
	'ct_last_pw_change = CASE WHEN user_passwd_change > 0 THEN user_passwd_change ' .
	'WHEN user_regdate > 0 THEN user_regdate ELSE UNIX_TIMESTAMP() END, ct_last_pw_reset = 0 ' .
	'WHERE NOT EXISTS (SELECT 1 FROM ' . update_quote_identifier($ctracker_config_table) .
	" WHERE ct_config_name = 'password_timestamps_split')";
if (update_column_max_length($connection, $dbname, $users_table, 'user_password') < 255)
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($users_table) .
		' MODIFY `user_password` VARCHAR(255) NOT NULL';
}
if (update_column_max_length($connection, $dbname, $users_table, 'user_newpasswd') < 255)
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($users_table) .
		' MODIFY `user_newpasswd` VARCHAR(255) DEFAULT NULL';
}
foreach (array('ct_last_used_ip', 'ct_last_ip') as $ip_column)
{
	if (update_column_exists($connection, $dbname, $users_table, $ip_column) &&
		update_column_max_length($connection, $dbname, $users_table, $ip_column) < 45)
	{
		$operations[] = 'ALTER TABLE ' . update_quote_identifier($users_table) .
			' MODIFY ' . update_quote_identifier($ip_column) . " VARCHAR(45) DEFAULT '0.0.0.0'";
	}
}
$login_history_table = $table_prefix . 'ctracker_loginhistory';
if (update_table_exists($connection, $dbname, $login_history_table) &&
	!update_column_exists($connection, $dbname, $login_history_table, 'ct_login_id'))
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($login_history_table) .
		' ADD `ct_login_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST';
}
if (update_table_exists($connection, $dbname, $login_history_table) &&
	!update_index_exists($connection, $dbname, $login_history_table, 'ct_user_time'))
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($login_history_table) .
		' ADD INDEX `ct_user_time` (`ct_user_id`, `ct_login_time`, `ct_login_id`)';
}
if (update_table_exists($connection, $dbname, $login_history_table) &&
	update_column_max_length($connection, $dbname, $login_history_table, 'ct_login_ip') < 45)
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($login_history_table) .
		' MODIFY `ct_login_ip` VARCHAR(45) DEFAULT NULL';
}
$filechk_table = $table_prefix . 'ctracker_filechk';
if (update_table_exists($connection, $dbname, $filechk_table) &&
	update_column_max_length($connection, $dbname, $filechk_table, 'hash') < 64)
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($filechk_table) .
		' MODIFY `hash` VARCHAR(64) DEFAULT NULL';
}
$ipblocker_table = $table_prefix . 'ctracker_ipblocker';
if (update_table_exists($connection, $dbname, $ipblocker_table) &&
	strpos(update_column_extra($connection, $dbname, $ipblocker_table, 'id'), 'auto_increment') === false)
{
	$operations[] = 'ALTER TABLE ' . update_quote_identifier($ipblocker_table) .
		' MODIFY `id` MEDIUMINT(8) UNSIGNED NOT NULL AUTO_INCREMENT';
}

// The original package seeded spoofable User-Agent strings from 2006. Remove
// only those exact factory rows; administrator-created block rules survive.
$legacy_blocklist_values = array(
	'*WebStripper*', '*NetMechanic*', '*CherryPicker*', '*EmailCollector*',
	'*EmailSiphon*', '*WebBandit*', '*EmailWolf*', '*ExtractorPro*',
	'*SiteSnagger*', '*CheeseBot*', '*ia_archiver*', '*Website Quester*',
	'*WebZip*', '*moget*', '*WebSauger*', '*WebCopier*', '*WWW-Collector*',
	'*InfoNaviRobot*', '*Harvest*', '*Bullseye*', '*LinkWalker*',
	'*LinkextractorPro*', '*WebProxy*', '*BlowFish*', '*WebEnhancer*',
	'*TightTwatBot*', '*LinkScan*', '*WebDownloader*', 'lwp',
	'*BruteForce*', 'lwp-*', '*anonym*'
);
$quoted_legacy_blocklist = array();
foreach ($legacy_blocklist_values as $legacy_blocklist_value)
{
	$quoted_legacy_blocklist[] = "'" . mysqli_real_escape_string($connection, $legacy_blocklist_value) . "'";
}
$operations[] = 'DELETE FROM ' . update_quote_identifier($ipblocker_table) .
	' WHERE `ct_blocker_value` IN (' . implode(', ', $quoted_legacy_blocklist) . ')';
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
update_queue_column($operations, $connection, $dbname, $table_prefix . 'themes', 'theme_public', "TINYINT(1) UNSIGNED NOT NULL DEFAULT '1'");
update_queue_standard_style(
	$operations,
	$connection,
	$forum_root,
	$table_prefix . 'themes',
	$table_prefix . 'themes_name',
	$table_prefix . 'users',
	$table_prefix . 'config'
);

$config_defaults = array(
	'xs_def_template' => 'fisubsilversh',
	'cookie_consent_enable' => '1',
	'sfs_enable' => '0',
	'sfs_fail_closed' => '0',
	'password_hashing' => '1',
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
$operations[] = 'DELETE FROM ' . update_quote_identifier($table_prefix . 'config') .
	" WHERE config_name = 'google_visit_counter'";
$operations[] = 'UPDATE ' . update_quote_identifier($table_prefix . 'banner') .
	' SET banner_type = 2 WHERE banner_type = 4';

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

// The original monthly-highscore MOD stored only a username snapshot. Add a
// stable account reference, recover it from the all-time score history before
// that history's names are normalized, and then display the current username.
$arcade_monthly_table = $table_prefix . 'ina_highscore';
$arcade_all_time_table = $table_prefix . 'ina_at_scores';
if (update_table_exists($connection, $dbname, $arcade_monthly_table))
{
	update_queue_column($operations, $connection, $dbname, $arcade_monthly_table, 'highscore_user_id', 'MEDIUMINT(8) NOT NULL DEFAULT 0');
	if (!update_index_exists($connection, $dbname, $arcade_monthly_table, 'highscore_user_id'))
	{
		$operations[] = 'ALTER TABLE ' . update_quote_identifier($arcade_monthly_table) .
			' ADD INDEX `highscore_user_id` (`highscore_user_id`)';
	}
	if (update_table_exists($connection, $dbname, $arcade_all_time_table))
	{
		$operations[] = 'UPDATE ' . update_quote_identifier($arcade_monthly_table) . ' h INNER JOIN (' .
			'SELECT player_name, MIN(player_id) AS player_id FROM ' . update_quote_identifier($arcade_all_time_table) .
			' WHERE player_id > 0 GROUP BY player_name HAVING COUNT(DISTINCT player_id) = 1' .
			') s ON s.player_name = h.highscore_player SET h.highscore_user_id = s.player_id WHERE h.highscore_user_id = 0';
		$operations[] = 'UPDATE ' . update_quote_identifier($arcade_monthly_table) . ' h INNER JOIN (' .
			'SELECT h2.highscore_id, MIN(s.player_id) AS player_id FROM ' . update_quote_identifier($arcade_monthly_table) .
			' h2 INNER JOIN ' . update_quote_identifier($arcade_all_time_table) .
			' s ON s.game_name = h2.highscore_game AND s.score = h2.highscore_score ' .
			'WHERE h2.highscore_user_id = 0 AND s.player_id > 0 GROUP BY h2.highscore_id ' .
			'HAVING COUNT(DISTINCT s.player_id) = 1' .
			') matched ON matched.highscore_id = h.highscore_id SET h.highscore_user_id = matched.player_id ' .
			'WHERE h.highscore_user_id = 0';
	}
	$operations[] = 'UPDATE ' . update_quote_identifier($arcade_monthly_table) . ' h INNER JOIN ' .
		update_quote_identifier($users_table) . ' u ON u.user_id = h.highscore_user_id ' .
		'SET h.highscore_player = u.username WHERE h.highscore_user_id > 0 AND h.highscore_player <> u.username';
}

// All-time Arcade scores historically kept a display-name snapshot in
// addition to the authoritative user ID. Keep existing rows consistent after
// account renames; runtime views also join the users table directly.
if (update_table_exists($connection, $dbname, $arcade_all_time_table) &&
	update_column_exists($connection, $dbname, $arcade_all_time_table, 'player_name'))
{
	$arcade_mismatch_sql = 'SELECT COUNT(*) FROM ' . update_quote_identifier($arcade_all_time_table) . ' s INNER JOIN ' .
		update_quote_identifier($users_table) . ' u ON u.user_id = s.player_id ' .
		'WHERE s.player_id > 0 AND (s.player_name IS NULL OR s.player_name <> u.username)';
	if ((int) update_scalar($connection, $arcade_mismatch_sql) > 0)
	{
		$operations[] = 'UPDATE ' . update_quote_identifier($arcade_all_time_table) . ' s INNER JOIN ' .
			update_quote_identifier($users_table) . ' u ON u.user_id = s.player_id SET s.player_name = u.username ' .
			"WHERE s.player_id > 0 AND (s.player_name IS NULL OR s.player_name <> u.username)";
	}
}

// Reconcile the remaining MOD display-name snapshots which have stable user
// IDs. The monthly highscore archive has no user ID and is therefore updated
// at rename time, where both the old and new unique username are known.
$username_snapshot_tables = array(
	array($table_prefix . 'album', 'pic_user_id', 'pic_username'),
	array($table_prefix . 'album_comment', 'comment_user_id', 'comment_username'),
	array($table_prefix . 'ina_comment', 'comment_user_id', 'comment_username'),
	array($table_prefix . 'shout', 'shout_user_id', 'shout_username')
);
foreach ($username_snapshot_tables as $snapshot)
{
	list($snapshot_table, $snapshot_user_column, $snapshot_name_column) = $snapshot;
	if (!update_table_exists($connection, $dbname, $snapshot_table) ||
		!update_column_exists($connection, $dbname, $snapshot_table, $snapshot_user_column) ||
		!update_column_exists($connection, $dbname, $snapshot_table, $snapshot_name_column))
	{
		continue;
	}

	$snapshot_table_sql = update_quote_identifier($snapshot_table);
	$snapshot_user_sql = update_quote_identifier($snapshot_user_column);
	$snapshot_name_sql = update_quote_identifier($snapshot_name_column);
	$mismatch_sql = 'SELECT COUNT(*) FROM ' . $snapshot_table_sql . ' s INNER JOIN ' .
		update_quote_identifier($users_table) . ' u ON u.user_id = s.' . $snapshot_user_sql . ' ' .
		'WHERE s.' . $snapshot_user_sql . ' > 0 AND (s.' . $snapshot_name_sql . ' IS NULL OR s.' . $snapshot_name_sql . ' <> u.username)';
	if ((int) update_scalar($connection, $mismatch_sql) > 0)
	{
		$operations[] = 'UPDATE ' . $snapshot_table_sql . ' s INNER JOIN ' .
			update_quote_identifier($users_table) . ' u ON u.user_id = s.' . $snapshot_user_sql .
			' SET s.' . $snapshot_name_sql . ' = u.username WHERE s.' . $snapshot_user_sql .
			' > 0 AND (s.' . $snapshot_name_sql . ' IS NULL OR s.' . $snapshot_name_sql . ' <> u.username)';
	}
}

// Keep the public components/credits list useful on upgraded installations.
// The original sample .hl file used to insert a bogus placeholder row whenever
// the public page was opened. It is no longer distributed or scanned there.
$hacks_table = update_quote_identifier($table_prefix . 'hacks_list');
$operations[] = "DELETE FROM $hacks_table WHERE hack_name = 'Hack Name' OR hack_file LIKE '%nivisec_hack_list_auto_insert.hl'";
$operations[] = "DELETE FROM $hacks_table WHERE hack_name IN ('Cracker Tracker Professional 2nd Ed.', 'CrackerTracker Professional 2nd Ed.', 'CrackerTracker Professional G5', 'IntegraMOD Responsive Styles', 'Google Visit Counter')";
$credit_rows = array(
	array('Birthday Mod', 'Adds birthday and age information to user profiles and posts.', 'Niels', 'http://mods.db9.dk', '1.5.7'),
	array('Photo Album Addon v2 for phpBB2', 'Integrated phpBB-based photo album and gallery management system.', 'Smartor', 'http://smartor.is-root.com', '2.0.53'),
	array('Recent Topics (third version)', 'Shows recent topics for selectable time periods.', 'Acid', '', '1.22'),
	array('Staff Site Mod', 'Displays the board staff and their roles on a dedicated page.', 'Acid', '', '2.2.3'),
	array('Album Hierarchy Mod', 'Adds nested categories to the integrated Photo Album.', 'IdleVoid', '', '1.30'),
	array('CrackerTracker Professional G5', 'Integrated security system for detecting and blocking known attacks and abusive requests.', 'cback', 'http://www.cback.de', '5.0.6'),
	array('Admin Userlist', 'User administration list with filtering, safe bulk status, ban and group actions, and Color Groups integration.', 'Brent Pirolli, Eric Faerber; updated by Helter', '', '2.1'),
	array('Arcade Mod Plus', 'Integrated arcade framework; game packages and user-generated game data are not distributed.', 'dEfEndEr, Napoleon and contributors', 'http://www.phpbb-arcade.com', '2.1.8'),
	array('Nuffload Album Upload', 'Multiple and archive upload support for the integrated photo album.', 'Nuffmon', 'http://www.nuffmon.oftheweek.de', '1.4.2'),
	array('DB Maintenance Mod', 'Administration tools for database consistency checks and search-index maintenance.', 'Philipp Kordowich', 'http://phpbb.kordowich.net/', '1.3.8'),
	array('Cookie Consent', 'Configurable cookie information banner integrated from IntegraMOD.', 'Helter', 'https://www.integramod.com/', '1.0.0'),
	array('Stop Forum Spam', 'Optional IP, email and username checks against the Stop Forum Spam service during registration.', 'gat0r; updated by Helter', 'https://www.stopforumspam.com/', '2.1'),
	array('Log Actions MOD', 'Records moderation actions and provides an administration log.', 'Morpheus', '', '1.1.6'),
	array('Enhanced Log Actions', 'Extends moderation logging to sticky, announcement and normal topic changes.', 'François-Xavier', '', '1.1.0'),
	array('Registration IP', 'Records the server-verified IP address used for account registration.', 'Woody', '', '1.1.2 adapted'),
	array('Admin Userlist ColorGroups Compatibility', 'Uses Color Groups formatting in the Admin Userlist.', 'Brent Pirolli, Octavius', '', '1.0.1'),
	array('Arcade Rewards API', 'Optional integration layer used by Arcade Mod Plus for Cash and Allowance reward systems.', 'Xore, Napoleon, dEfEndEr', 'http://www.phpbb-arcade.com', '2.1.6'),
	array('IntegraMOD Social Profile Fields', 'Modern Facebook, Instagram, Pinterest, Twitter/X, Skype, Telegram, LinkedIn, TikTok and Discord profile fields.', 'IntegraMOD contributors', 'https://www.integramod.com/', ''),
	array('Ruffle Flash Emulator', 'Bundled WebAssembly runtime for playing preserved Flash arcade games in modern browsers; this is a runtime component, not a phpBB MOD.', 'Ruffle contributors', 'https://ruffle.rs/', '0.5.0')
);
foreach ($credit_rows as $credit)
{
	$values = array();
	foreach ($credit as $value) { $values[] = "'" . mysqli_real_escape_string($connection, $value) . "'"; }
	$operations[] = "INSERT INTO $hacks_table (hack_add_date, hack_name, hack_desc, hack_author, hack_author_email, hack_author_website, hack_version, hack_hide, hack_download_url, hack_file, hack_file_mtime) VALUES (0, " . $values[0] . ', ' . $values[1] . ', ' . $values[2] . ", '', " . $values[3] . ', ' . $values[4] . ", 'No', '', '', 0) ON DUPLICATE KEY UPDATE hack_desc = VALUES(hack_desc), hack_author = VALUES(hack_author), hack_author_website = VALUES(hack_author_website), hack_version = VALUES(hack_version)";
}

// The account-wide visual-confirmation lock was disabled because an
// unauthenticated visitor could set it on somebody else's account. Remove its
// now-unused CrackerTracker 5 columns independently of the 4.x cleanup below.
foreach (array('ct_login_count', 'ct_login_vconfirm') as $obsolete_login_column)
{
	update_queue_drop_column(
		$operations,
		$connection,
		$dbname,
		$table_prefix . 'users',
		$obsolete_login_column
	);
}

foreach (array('ct_last_post', 'ct_post_counter') as $obsolete_posting_column)
{
	update_queue_drop_column(
		$operations,
		$connection,
		$dbname,
		$table_prefix . 'users',
		$obsolete_posting_column
	);
}

update_queue_drop_column(
	$operations,
	$connection,
	$dbname,
	$table_prefix . 'users',
	'ct_last_mail'
);

foreach (array('ct_search_time', 'ct_search_count') as $obsolete_search_column)
{
	update_queue_drop_column(
		$operations,
		$connection,
		$dbname,
		$table_prefix . 'users',
		$obsolete_search_column
	);
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
