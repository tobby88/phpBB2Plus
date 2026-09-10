<?php
// Shared by the post-1.53a updater and private deployment tooling. No bootstrap
// or automatic writes: callers must stop ALL web/cron writers and confirm backup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }

function plus_storage_query($db, $sql)
{
	$result = mysqli_query($db, $sql);
	if ($result === false) { throw new RuntimeException('Storage migration SQL failed: ' . mysqli_error($db)); }
	return $result;
}

function plus_storage_rows($db, $sql)
{
	$result = plus_storage_query($db, $sql); $rows = array();
	while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
	mysqli_free_result($result); return $rows;
}

function plus_storage_identifier($name)
{
	if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)) { throw new RuntimeException('Invalid storage identifier'); }
	return '`' . $name . '`';
}

function plus_storage_tables($schema, $prefix)
{
	plus_storage_identifier($prefix);
	preg_match_all('/CREATE TABLE\s+`?(phpbb_[A-Za-z0-9_]+)`?\s*\(/i', $schema, $matches);
	if (!$matches[1]) { throw new RuntimeException('Canonical storage table list is empty'); }
	$tables = array();
	foreach ($matches[1] as $name) { $name = $prefix . substr($name, 6); plus_storage_identifier($name); $tables[] = $name; }
	// Optional/runtime-created tables belonging to bundled extensions. Do not
	// select every table sharing a prefix: unrelated extensions stay untouched.
	foreach (array('ctracker_backup', 'ina_pms') as $suffix) { $name = $prefix . $suffix; plus_storage_identifier($name); $tables[] = $name; }
	return array_values(array_unique($tables));
}

function plus_storage_metadata($db, $table)
{
	plus_storage_identifier($table);
	$rows = plus_storage_rows($db, "SELECT ENGINE,TABLE_TYPE,TABLE_COLLATION,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . mysqli_real_escape_string($db, $table) . "'");
	if (!$rows) { return null; }
	if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE') { throw new RuntimeException('Not a base table: ' . $table); }
	return $rows[0];
}

function plus_storage_plan($db, $tables)
{
	$support = plus_storage_rows($db, "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE='InnoDB'");
	if (count($support) !== 1 || !in_array(strtoupper($support[0]['SUPPORT']), array('YES', 'DEFAULT'), true)) { throw new RuntimeException('InnoDB must be enabled'); }
	$plan = array();
	foreach (array_unique($tables) as $table)
	{
		$meta = plus_storage_metadata($db, $table);
		if (!$meta || strtoupper($meta['ENGINE']) === 'INNODB') { continue; }
		if (!in_array(strtoupper($meta['ENGINE']), array('MYISAM','MEMORY','HEAP'), true)) { throw new RuntimeException('Unsupported source engine for ' . $table); }
		$columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . plus_storage_identifier($table));
		$indexes = plus_storage_rows($db, 'SHOW INDEX FROM ' . plus_storage_identifier($table));
		foreach ($columns as $column)
		{
			if (stripos($column['Extra'], 'auto_increment') === false) { continue; }
			$first = false;
			foreach ($indexes as $index) { if ($index['Column_name'] === $column['Field'] && (int) $index['Seq_in_index'] === 1) { $first = true; } }
			if (!$first) { throw new RuntimeException('Grouped AUTO_INCREMENT needs manual review: ' . $table); }
		}
		// No IGNORE, no key/constraint removal, no character conversion. Strict
		// rebuild must reject unsupported rows/indexes instead of changing data.
		$plan[$table] = 'ALTER TABLE ' . plus_storage_identifier($table) . ' ENGINE=InnoDB ROW_FORMAT=DYNAMIC MAX_ROWS=0';
	}
	return $plan;
}

function plus_storage_signature($db, $table)
{
	$columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . plus_storage_identifier($table));
	$indexes = plus_storage_rows($db, 'SHOW INDEX FROM ' . plus_storage_identifier($table));
	// SHOW COLUMNS may report a NOT NULL UNIQUE key as PRI when InnoDB
	// chooses it as the clustered key. The actual index definitions below win.
	foreach ($columns as &$column) { unset($column['Key']); } unset($column);
	foreach ($indexes as &$index)
	{
		unset($index['Cardinality'], $index['Packed']);
		// A physical HASH -> BTREE rebuild is expected; uniqueness, full indexed
		// columns, order and prefix lengths must still match exactly.
		if ($index['Index_type'] === 'HASH') { $index['Index_type'] = 'BTREE'; $index['Collation'] = 'A'; }
	} unset($index);
	return array($columns, $indexes);
}

function plus_storage_counter_at_least($actual, $minimum)
{
	if (!preg_match('/^[0-9]+$/D', (string) $actual) || !preg_match('/^[0-9]+$/D', (string) $minimum)) { return false; }
	$actual = ltrim((string) $actual, '0'); $minimum = ltrim((string) $minimum, '0');
	return strlen($actual) > strlen($minimum) || (strlen($actual) === strlen($minimum) && strcmp($actual, $minimum) >= 0);
}

function plus_storage_apply($db, $tables, $backup_confirmed, $maintenance_confirmed, $progress = null)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Verified backup and stopped web/cron writers are required'); }
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode, @@SESSION.innodb_strict_mode AS strict_mode, @@SESSION.lock_wait_timeout AS wait_timeout');
	$lock = 'plus_innodb_' . sha1($identity[0]['db']);
	$held = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
	if ((string) $held[0]['held'] !== '1') { throw new RuntimeException('Another storage migration is running'); }
	try
	{
		$plan = plus_storage_plan($db, $tables);
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION','NO_AUTO_VALUE_ON_ZERO')");
		plus_storage_query($db, 'SET SESSION innodb_strict_mode=ON');
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=30');
		foreach ($plan as $table => $sql)
		{
			$before = plus_storage_metadata($db, $table);
			$signature = plus_storage_signature($db, $table);
			$count = plus_storage_rows($db, 'SELECT COUNT(*) AS n FROM ' . plus_storage_identifier($table));
			plus_storage_query($db, $sql);
			$warnings = plus_storage_rows($db, 'SHOW WARNINGS');
			if ($warnings) { throw new RuntimeException('Review conversion warnings for ' . $table . '; earlier changes remain applied'); }
			$after = plus_storage_metadata($db, $table);
			$after_count = plus_storage_rows($db, 'SELECT COUNT(*) AS n FROM ' . plus_storage_identifier($table));
			if (!$after || strtoupper($after['ENGINE']) !== 'INNODB' || $before['TABLE_COLLATION'] !== $after['TABLE_COLLATION'] ||
				$signature !== plus_storage_signature($db, $table) || $count !== $after_count ||
				($before['AUTO_INCREMENT'] !== null && !plus_storage_counter_at_least($after['AUTO_INCREMENT'], $before['AUTO_INCREMENT'])))
			{ throw new RuntimeException('Conversion verification failed for ' . $table . '; do not reopen writers'); }
			if (is_callable($progress)) { call_user_func($progress, $table, $count[0]['n']); }
		}
		if (plus_storage_plan($db, $tables)) { throw new RuntimeException('Storage conversion incomplete'); }
		return count($plan);
	}
	finally
	{
		// DDL commits per table; a retry resumes remaining tables, not a rollback.
		try
		{
			plus_storage_query($db, "SET SESSION sql_mode='" . mysqli_real_escape_string($db, $identity[0]['mode']) . "'");
			plus_storage_query($db, 'SET SESSION innodb_strict_mode=' . (int) $identity[0]['strict_mode']);
			plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . (int) $identity[0]['wait_timeout']);
		}
		finally { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); }
	}
}
