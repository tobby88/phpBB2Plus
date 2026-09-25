<?php
// Schema-only migration. Callers must stop every web/cron writer before apply.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_style_ids_plan($db, $prefix)
{
	plus_storage_identifier($prefix);
	$plan = array();
	$mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
	$no_backslashes = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true);
	foreach (array('themes'=>'themes_id', 'users'=>'user_style', 'themes_name'=>'themes_id') as $suffix=>$field)
	{
		$table = $prefix . $suffix; $quoted = plus_storage_identifier($table);
		if (!plus_storage_metadata($db, $table)) { throw new RuntimeException('Missing style table: ' . $table); }
		$columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted);
		$column = null;
		foreach ($columns as $candidate) { if ($candidate['Field'] === $field) { $column = $candidate; } }
		if (!$column || !preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/Di', $column['Type']) ||
			($suffix !== 'themes' && $column['Extra'] !== ''))
		{ throw new RuntimeException('Unsupported style ID definition: ' . $table . '.' . $field); }
		$primary = plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted . " WHERE Key_name='PRIMARY'");
		$expected_key = $suffix === 'users' ? 'user_id' : 'themes_id';
		if (count($primary) !== 1 || $primary[0]['Column_name'] !== $expected_key) { throw new RuntimeException('Unexpected style table primary key: ' . $table); }
		// Do not silently truncate, remap or delete custom/invalid references.
		$invalid = plus_storage_rows($db, 'SELECT COUNT(*) AS n FROM ' . $quoted . ' WHERE ' . plus_storage_identifier($field) . ' < 0 OR ' . plus_storage_identifier($field) . ' > 16777215');
		if ((string) $invalid[0]['n'] !== '0') { throw new RuntimeException('Out-of-range style IDs require manual review: ' . $table); }
		if ($column['Default'] !== null && (!preg_match('/^[0-9]+$/D', (string) $column['Default']) || !plus_storage_counter_at_least('16777215', $column['Default'])))
		{ throw new RuntimeException('Out-of-range style ID default: ' . $table); }
		$correct_type = (bool) preg_match('/^mediumint(?:\([0-9]+\))? unsigned$/Di', $column['Type']);
		if ($suffix === 'themes')
		{
			if (!$correct_type || $column['Null'] !== 'NO' || $column['Extra'] !== 'auto_increment') { throw new RuntimeException('Unsupported source theme ID; review before migrating references'); }
			continue;
		}
		if ($correct_type && ($suffix !== 'users' || $column['Null'] === 'YES')) { continue; }
		$nullable = $suffix === 'users' || $column['Null'] === 'YES';
		$definition = 'MEDIUMINT UNSIGNED ' . ($nullable ? 'NULL' : 'NOT NULL');
		if ($column['Default'] !== null) { $definition .= ' DEFAULT ' . (int) $column['Default']; }
		elseif ($nullable) { $definition .= ' DEFAULT NULL'; }
		$comment = $no_backslashes ? str_replace("'", "''", $column['Comment']) : mysqli_real_escape_string($db, $column['Comment']);
		$definition .= " COMMENT '" . $comment . "'";
		$plan[$table] = array('column'=>$field, 'key'=>$expected_key,
			'sql'=>'ALTER TABLE ' . $quoted . ' MODIFY ' . plus_storage_identifier($field) . ' ' . $definition);
	}
	return $plan;
}

function plus_style_ids_digest($db, $table, $key)
{
	$result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table) . ' ORDER BY ' . plus_storage_identifier($key), MYSQLI_USE_RESULT);
	if (!$result) { throw new RuntimeException('Cannot verify style table contents'); }
	$hash = hash_init('sha256');
	try { while ($row = mysqli_fetch_assoc($result)) { hash_update($hash, serialize($row)); } }
	finally { mysqli_free_result($result); }
	return hash_final($hash);
}

function plus_style_ids_restore($db, $identity, $lock)
{
	try
	{
		plus_storage_query($db, "SET SESSION sql_mode='" . mysqli_real_escape_string($db, $identity['mode']) . "'");
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . (int) $identity['wait_timeout']);
	}
	finally { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); }
}

function plus_style_ids_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Verified backup and stopped web/cron writers are required'); }
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db, @@SESSION.sql_mode AS mode, @@SESSION.lock_wait_timeout AS wait_timeout');
	$identity = $identity[0]; $lock = 'plus_innodb_' . sha1($identity['db']);
	$held = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
	if ((string) $held[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); }
	try
	{
		// Check ALL columns/values before the first DDL. Rebuilds auto-commit;
		// interruptions require a retry, never a claimed transaction rollback.
		$plan = plus_style_ids_plan($db, $prefix);
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=30');
		foreach ($plan as $table=>$item)
		{
			$meta = plus_storage_metadata($db, $table);
			$signature = plus_storage_signature($db, $table);
			$digest = plus_style_ids_digest($db, $table, $item['key']);
			plus_storage_query($db, $item['sql']);
			if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Style ID rebuild reported warnings: ' . $table); }
			$after = plus_storage_signature($db, $table);
			foreach ($signature[0] as $i=>$column)
			{
				if ($column['Field'] !== $item['column']) { continue; }
				$signature[0][$i]['Type'] = $after[0][$i]['Type'];
				if ($item['column'] === 'user_style') { $signature[0][$i]['Null'] = 'YES'; }
			}
			foreach ($signature[1] as $i=>$index)
			{
				if ($item['column'] === 'user_style' && $index['Column_name'] === 'user_style') { $signature[1][$i]['Null'] = 'YES'; }
			}
			$after_meta = plus_storage_metadata($db, $table);
			$counter = $meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT']);
			$after_counter = $after_meta['AUTO_INCREMENT']; unset($after_meta['AUTO_INCREMENT']);
			if ($signature !== $after || $meta !== $after_meta ||
				($counter !== null && !plus_storage_counter_at_least($after_counter, $counter)) ||
				$digest !== plus_style_ids_digest($db, $table, $item['key']))
			{ throw new RuntimeException('Style ID rebuild verification failed: ' . $table); }
		}
		if (plus_style_ids_plan($db, $prefix)) { throw new RuntimeException('Style ID migration incomplete'); }
		return count($plan);
	}
	finally { plus_style_ids_restore($db, $identity, $lock); }
}
