<?php
// Schema-only primitive. Never read/delete image files or create synthetic jobs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_album_file_journal_type($type)
{
	return preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)/', '$1', strtolower(preg_replace('/\s+/', ' ', trim($type))));
}

function plus_album_file_journal_definitions($prefix, $schema = null)
{
	if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix)) { throw new RuntimeException('Invalid Album journal prefix'); }
	if ($schema === null) { $schema = file_get_contents(dirname(__DIR__) . '/phpBB2/install/schemas/mysql_schema.sql'); }
	if (!is_string($schema)) { throw new RuntimeException('Canonical Album journal schema unavailable'); }
	$definitions = array();
	foreach (array('album_file_jobs', 'album_file_items') as $suffix)
	{
		$pattern = '/^CREATE TABLE phpbb_' . $suffix . ' \(\r?\n(.*?)\r?\n\) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/ms';
		if (preg_match_all($pattern, $schema, $matches, PREG_SET_ORDER) !== 1) { throw new RuntimeException('Canonical modern Album journal definition missing or ambiguous'); }
		$columns = array(); $indexes = array();
		foreach (preg_split('/\r?\n/', $matches[0][1]) as $line)
		{
			$line = rtrim(trim($line), ',');
			if (preg_match('/^(PRIMARY KEY|UNIQUE KEY ([A-Za-z0-9_]+)|KEY ([A-Za-z0-9_]+))\s*\(([A-Za-z0-9_, ]+)\)$/iD', $line, $key))
			{
				$name = strtoupper($key[1]) === 'PRIMARY KEY' ? 'PRIMARY' : (!empty($key[2]) ? $key[2] : $key[3]);
				if (isset($indexes[$name])) { throw new RuntimeException('Duplicate canonical Album journal index'); }
				$indexes[$name] = array(); $unique = strtoupper(substr($key[1], 0, 3)) !== 'KEY';
				foreach (explode(',', $key[4]) as $field) {
					$field = trim($field); if (!isset($columns[$field])) { throw new RuntimeException('Unknown canonical journal index field'); }
					$indexes[$name][] = array('column' => $field, 'non_unique' => $unique ? 0 : 1, 'sub_part' => null, 'type' => 'BTREE', 'order' => 'A');
				}
				continue;
			}
			if (!preg_match("/^([a-z][a-z0-9_]*)\\s+((?:char|varchar)\\([0-9]+\\)|(?:tinyint|smallint|mediumint|int|bigint)(?:\\([0-9]+\\))?(?:\\s+unsigned)?)\\s+(NOT NULL|NULL)(?:\\s+DEFAULT\\s+(NULL|'[0-9]+'|[0-9]+))?$/iD", $line, $column) || isset($columns[$column[1]])) { throw new RuntimeException('Unsupported canonical Album journal column definition'); }
			$type = plus_album_file_journal_type($column[2]);
			$columns[$column[1]] = array('type' => $type, 'nullable' => strtoupper($column[3]) === 'NULL' ? 'YES' : 'NO',
				'default' => isset($column[4]) && strtoupper($column[4]) !== 'NULL' ? trim($column[4], "'") : null,
				'collation' => preg_match('/^(?:var)?char\(/', $type) ? 'utf8mb4_unicode_ci' : null, 'extra' => '');
		}
		$required = $suffix === 'album_file_jobs' ? array('operation_id','schema_version','operation_kind','operation_state','actor_id','owner_id','category_id','picture_id','binding_hash','capability_hash','item_count','picture_count','created_at','completed_at') : array('operation_id','item_index','item_hash','file_scope','filename','file_present','file_bytes','file_sha256','item_state');
		if (array_keys($columns) !== $required || !isset($indexes['PRIMARY']) || ($suffix === 'album_file_jobs' && !isset($indexes['state_created'])) || ($suffix === 'album_file_items' && !isset($indexes['operation_item']))) { throw new RuntimeException('Canonical Album journal protocol fields/indexes changed'); }
		$key_fields = $suffix === 'album_file_jobs' ? array('PRIMARY' => array('operation_id'), 'state_created' => array('operation_state','created_at')) : array('PRIMARY' => array('operation_id','item_index'), 'operation_item' => array('operation_id','item_hash'));
		if (count($indexes) !== count($key_fields)) { throw new RuntimeException('Unexpected canonical Album journal constraint'); }
		foreach ($key_fields as $key_name => $fields)
		{
			$actual_fields = array(); foreach ($indexes[$key_name] as $part) { $actual_fields[] = $part['column']; if ($part['non_unique'] !== ($key_name === 'state_created' ? 1 : 0)) { throw new RuntimeException('Album journal uniqueness protocol changed'); } }
			if ($actual_fields !== $fields) { throw new RuntimeException('Album journal exact-byte identity index changed'); }
		}
		ksort($indexes, SORT_STRING); $table = $prefix . $suffix; plus_storage_identifier($table);
		$sql = preg_replace('/^CREATE TABLE phpbb_' . $suffix . '/', 'CREATE TABLE ' . plus_storage_identifier($table), $matches[0][0]);
		$definitions[$table] = array('sql' => rtrim($sql, ';'), 'columns' => $columns, 'indexes' => $indexes);
	}
	return $definitions;
}

function plus_album_file_journal_assert($db, $table, $definition)
{
	$meta = plus_storage_metadata($db, $table); if ($meta === null) { return false; }
	if ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $meta['AUTO_INCREMENT'] !== null) { throw new RuntimeException('Album journal storage is not canonical; no table is adopted or rewritten'); }
	$columns = array();
	foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . plus_storage_identifier($table)) as $column)
	{
		$columns[$column['Field']] = array('type' => plus_album_file_journal_type($column['Type']), 'nullable' => $column['Null'], 'default' => $column['Default'], 'collation' => $column['Collation'], 'extra' => $column['Extra']);
	}
	if ($columns !== $definition['columns']) { throw new RuntimeException('Album journal columns differ; manual review required, no existing data is altered'); }
	$indexes = array();
	foreach (plus_storage_rows($db, 'SHOW INDEX FROM ' . plus_storage_identifier($table)) as $index)
	{
		if ((isset($index['Visible']) && $index['Visible'] !== 'YES') || (isset($index['Ignored']) && $index['Ignored'] !== 'NO')) { throw new RuntimeException('Album journal index is disabled/invisible'); }
		$indexes[$index['Key_name']][(int) $index['Seq_in_index']] = array('column' => $index['Column_name'], 'non_unique' => (int) $index['Non_unique'], 'sub_part' => $index['Sub_part'], 'type' => $index['Index_type'], 'order' => $index['Collation']);
	}
	foreach ($indexes as &$parts) { ksort($parts, SORT_NUMERIC); $parts = array_values($parts); } unset($parts); ksort($indexes, SORT_STRING);
	if ($indexes !== $definition['indexes']) { throw new RuntimeException('Album journal indexes differ; no constraint is dropped or replaced'); }
	return true;
}

function plus_album_file_journal_plan($db, $prefix)
{
	if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('Album journal migration needs an utf8mb4 connection'); }
	$plan = array();
	foreach (plus_album_file_journal_definitions($prefix) as $table => $definition) { if (!plus_album_file_journal_assert($db, $table, $definition)) { $plan[$table] = $definition['sql']; } }
	return $plan;
}

function plus_album_file_journal_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
	if ($backup_confirmed !== true || $maintenance_confirmed !== true || !is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Album journal migration requires verified backup, stopped web/cron writers and a bounded DDL wait'); }
	plus_album_file_journal_definitions($prefix);
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,CONNECTION_ID() AS id,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout,@@SESSION.innodb_strict_mode AS strict_mode');
	if (count($identity) !== 1 || !$identity[0]['db'] || (string) $identity[0]['automatic'] !== '1') { throw new RuntimeException('Album journal DDL needs an idle autocommit connection'); } $identity = $identity[0];
	// This fails BEFORE taking ownership when an explicit caller TX is open.
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$owned = false; $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
	try
	{
		plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
		$current = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
		if (count($current) !== 1 || $current[0]['owner'] !== null) { throw new RuntimeException('Schema migration lock is already owned; no lock is adopted'); }
		$rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
		if (count($rows) !== 1 || (string) $rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
		$plan = plus_album_file_journal_plan($db, $prefix);
		plus_storage_query($db, 'COMMIT'); $owned = false;
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
		plus_storage_query($db, 'SET SESSION innodb_strict_mode=ON'); plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
		foreach ($plan as $table => $sql)
		{
			$owner = plus_storage_rows($db, "SELECT IS_USED_LOCK('" . $lock . "') AS owner");
			if (count($owner) !== 1 || (string) $owner[0]['owner'] !== (string) $identity['id']) { throw new RuntimeException('Album journal migration lock lost'); }
			// Plain CREATE, never IF NOT EXISTS: a concurrent foreign object is
			// an error, not a reason to trust or replace an unknown definition.
			plus_storage_query($db, $sql);
			if (plus_storage_rows($db, 'SHOW WARNINGS') || !plus_album_file_journal_assert($db, $table, plus_album_file_journal_definitions($prefix)[$table])) { throw new RuntimeException('Album journal DDL verification failed; keep writers stopped and inspect before retry'); }
		}
		if (plus_album_file_journal_plan($db, $prefix)) { throw new RuntimeException('Album journal schema migration incomplete'); }
		return count($plan);
	}
	finally
	{
		if ($owned) { try { mysqli_query($db, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
		try {
			plus_storage_query($db, "SET SESSION sql_mode='" . mysqli_real_escape_string($db, $identity['mode']) . "'");
			plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . (int) $identity['wait_timeout']);
			plus_storage_query($db, 'SET SESSION innodb_strict_mode=' . ((int) $identity['strict_mode'] ? 'ON' : 'OFF'));
		} finally { if ($held) { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); } }
	}
}
