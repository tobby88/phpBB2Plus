<?php
// Preserve logical category limits and legacy HTML storage. DDL auto-commits.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';
require_once __DIR__ . '/post_author_storage_migration.php';

function plus_album_text_table($prefix)
{
	if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,55}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid Album category table prefix'); }
	return $prefix . 'album_cat';
}
function plus_album_text_default($db, $table, $field, $column)
{
	if (!in_array($field, array('cat_title', 'cat_desc'), true)) { throw new RuntimeException('Unknown Album text field'); }
	if ($column['Default'] === null) { return null; }
	$defaults = plus_storage_rows($db, "SELECT VERSION() AS server_version,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . mysqli_real_escape_string($db, $table) . "') AND COLUMN_NAME='" . $field . "'");
	if (count($defaults) !== 1) { throw new RuntimeException('Cannot inventory Album text default'); }
	$literal = plus_post_author_default_is_literal($defaults[0]['server_version'], $defaults[0]['COLUMN_DEFAULT']);
	// MariaDB prints TEXT literals with backslash quoting, unlike VARCHAR's
	// doubled quotes. Accept one complete literal token only; never execute or
	// decode that potentially lossy metadata. DEFAULT(field) reads the real value.
	if (!$literal && in_array(strtolower($column['Type']), array('tinytext', 'text', 'mediumtext', 'longtext'), true) && stripos($defaults[0]['server_version'], 'MariaDB') !== false) {
		$server = preg_replace('/^5\.5\.5-/', '', $defaults[0]['server_version']);
		$literal = preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/D', $server, $version) === 1 && version_compare($version[1], '10.2.7', '>=') && is_string($defaults[0]['COLUMN_DEFAULT']) && preg_match("/^'(?:[^'\\\\]|\\\\[\\s\\S]|'')*'$/sD", $defaults[0]['COLUMN_DEFAULT']) === 1;
	}
	if (!$literal) { throw new RuntimeException('Expression or ambiguous Album text default requires manual review'); }
	$expression = 'DEFAULT(' . plus_storage_identifier($field) . ')';
	$values = plus_storage_rows($db, 'SELECT ' . $expression . ' AS literal_default FROM ' . plus_storage_identifier($table) . ' LIMIT 1');
	if (!$values) {
		$mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode'); $mode = $mode[0]['mode'];
		if (!is_string($mode) || preg_match('/^[A-Z0-9_,]*$/D', $mode) !== 1) { throw new RuntimeException('Cannot preserve Album default-reader SQL mode'); }
		$relax = in_array('ONLY_FULL_GROUP_BY', explode(',', $mode), true);
		if ($relax) { plus_storage_query($db, "SET SESSION sql_mode='" . implode(',', array_diff(explode(',', $mode), array('ONLY_FULL_GROUP_BY', 'ANSI'))) . "'"); }
		try { $values = plus_storage_rows($db, 'SELECT ' . $expression . ' AS literal_default,COUNT(*) AS default_row_probe FROM ' . plus_storage_identifier($table) . ' WHERE 0'); }
		finally { if ($relax) { plus_storage_query($db, "SET SESSION sql_mode='" . $mode . "'"); } }
		if (count($values) !== 1 || (string) $values[0]['default_row_probe'] !== '0') { throw new RuntimeException('Cannot read empty-table Album default'); }
		if ($values[0]['literal_default'] === null && $column['Null'] === 'NO' && is_string($column['Default']) && strpos($column['Default'], '?') === false && strpos($column['Default'], "\xef\xbf\xbd") === false) { $values[0]['literal_default'] = $column['Default']; }
	}
	if (count($values) !== 1 || !is_string($values[0]['literal_default']) || preg_match('//u', $values[0]['literal_default']) !== 1) { throw new RuntimeException('Cannot prove exact Album default; manual review required, no replacement definition is written'); }
	return $values[0]['literal_default'];
}
function plus_album_text_plan($db, $prefix, $canonical = false)
{
	if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('Album text migration requires an exact utf8mb4 connection'); }
	$table = plus_album_text_table($prefix); $quoted = plus_storage_identifier($table);
	$meta = plus_storage_metadata($db, $table); if (!$meta) { return array(); }
	if ($canonical) {
		if ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { throw new RuntimeException('Complete canonical storage migration before Album text migration'); }
		$bad = plus_storage_rows($db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)='" . strtoupper(bin2hex($table)) . "' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
		if ($bad) { throw new RuntimeException('Complete canonical UTF8 Album column migration first'); }
	}
	$fields = array(); foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted) as $column) { $fields[$column['Field']] = $column; }
	if (!isset($fields['cat_id'], $fields['cat_title'], $fields['cat_desc'])) { throw new RuntimeException('Album category fields are incomplete'); }
	$primary = plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted . " WHERE Key_name='PRIMARY'");
	if (count($primary) !== 1 || $primary[0]['Column_name'] !== 'cat_id') { throw new RuntimeException('Album category identities require manual review'); }
	$changes = array();
	foreach (array('cat_title', 'cat_desc') as $field) {
		$column = $fields[$field]; $type = strtolower($column['Type']);
		if ($column['Extra'] !== '' || !is_string($column['Collation']) || !preg_match('/^[A-Za-z0-9_]+$/D', $column['Collation'])) { throw new RuntimeException('Custom generated or unsupported Album text field requires manual review'); }
		if ($field === 'cat_title') {
			if (preg_match('/^varchar\(([0-9]+)\)$/D', $type, $width)) { if ((int) $width[1] >= 1530) { continue; } }
			elseif (in_array($type, array('text', 'mediumtext', 'longtext'), true)) { continue; }
			elseif ($type !== 'tinytext') { throw new RuntimeException('Unsupported Album title type; no custom field is narrowed'); }
			$changes[$field] = 'varchar(1530)';
		} else {
			if (in_array($type, array('mediumtext', 'longtext'), true)) { continue; }
			if (!in_array($type, array('tinytext', 'text'), true)) { throw new RuntimeException('Unsupported Album description type'); }
			$changes[$field] = 'mediumtext';
		}
	}
	if (!$changes) { return array(); }
	foreach (plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted) as $index) {
		if (isset($changes[$index['Column_name']]) && $index['Index_type'] === 'BTREE' && $index['Sub_part'] === null) { throw new RuntimeException('A full Album text index needs manual review; no index is shortened or removed'); }
	}
	$mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
	$no_backslashes = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true);
	$quote = function($value) use ($db, $no_backslashes) { return "'" . ($no_backslashes ? str_replace("'", "''", $value) : mysqli_real_escape_string($db, $value)) . "'"; };
	$parts = array();
	foreach ($changes as $field => $type) {
		$column = $fields[$field]; $default = plus_album_text_default($db, $table, $field, $column);
		$definition = strtoupper($type) . ' COLLATE ' . plus_storage_identifier($column['Collation']) . ($column['Null'] === 'YES' ? ' NULL' : ' NOT NULL');
		if ($column['Default'] !== null) { $definition .= ' DEFAULT ' . $quote($default); }
		elseif ($column['Null'] === 'YES') { $definition .= ' DEFAULT NULL'; }
		$parts[] = 'MODIFY ' . plus_storage_identifier($field) . ' ' . $definition . ' COMMENT ' . $quote($column['Comment']);
	}
	if ($meta['AUTO_INCREMENT'] !== null) {
		if (!preg_match('/^[0-9]+$/D', (string) $meta['AUTO_INCREMENT'])) { throw new RuntimeException('Unsupported Album identity floor'); }
		$parts[] = 'AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
	}
	return array($table => array('sql' => 'ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts), 'types' => $changes));
}
function plus_album_text_digest($db, $table)
{
	$result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table) . ' ORDER BY cat_id', MYSQLI_USE_RESULT);
	if (!$result) { throw new RuntimeException('Cannot verify Album category contents'); }
	$hash = hash_init('sha256');
	try { while ($row = mysqli_fetch_assoc($result)) { hash_update($hash, serialize($row)); } if (mysqli_errno($db)) { throw new RuntimeException('Album content inventory interrupted'); } }
	finally { mysqli_free_result($result); }
	return hash_final($hash);
}
function plus_album_text_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Album text migration requires a verified backup and stopped web/cron writers'); }
	if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid Album DDL wait limit'); }
	plus_album_text_table($prefix);
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
	if (!$identity['db'] || (string) $identity['automatic'] !== '1') { throw new RuntimeException('Album DDL requires an idle autocommit connection'); }
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$owned = false; $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
	try {
		plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
		$rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
		if (count($rows) !== 1 || (string) $rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
		$plan = plus_album_text_plan($db, $prefix, true); $before = array();
		foreach ($plan as $table => $item) {
			$signature = plus_storage_signature($db, $table); $defaults = array();
			foreach ($signature[0] as $column) { if (isset($item['types'][$column['Field']])) { $defaults[$column['Field']] = plus_album_text_default($db, $table, $column['Field'], $column); } }
			$before[$table] = array('meta' => plus_storage_metadata($db, $table), 'signature' => $signature, 'defaults' => $defaults, 'digest' => plus_album_text_digest($db, $table));
		}
		plus_storage_query($db, 'COMMIT'); $owned = false;
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
		foreach ($plan as $table => $item) {
			// DDL cannot be rolled back. Retain stopped writers after any failure.
			plus_storage_query($db, $item['sql']);
			if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Album text rebuild reported warnings; inspect before retrying'); }
			$after = plus_storage_signature($db, $table); $signature = $before[$table]['signature'];
			foreach ($after[0] as $column) { if (isset($item['types'][$column['Field']]) && plus_album_text_default($db, $table, $column['Field'], $column) !== $before[$table]['defaults'][$column['Field']]) { throw new RuntimeException('Album text default changed; keep writers stopped'); } }
			foreach ($signature[0] as $index => $column) { if (isset($item['types'][$column['Field']])) { $signature[0][$index]['Type'] = $item['types'][$column['Field']]; } }
			$meta = $before[$table]['meta']; $after_meta = plus_storage_metadata($db, $table); $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'], $after_meta['AUTO_INCREMENT']);
			if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor, $floor)) || $before[$table]['digest'] !== plus_album_text_digest($db, $table)) { throw new RuntimeException('Album text schema/data verification failed; keep writers stopped'); }
		}
		if (plus_album_text_plan($db, $prefix, true)) { throw new RuntimeException('Album text capacity migration incomplete'); }
		return count($plan);
	} finally {
		if ($owned) { try { mysqli_query($db, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
		try { plus_storage_query($db, "SET SESSION sql_mode='" . mysqli_real_escape_string($db, $identity['mode']) . "'"); plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . (int) $identity['wait_timeout']); }
		finally { if ($held) { plus_storage_query($db, "DO RELEASE_LOCK('" . $lock . "')"); } }
	}
}
