<?php
// DDL auto-commits. Preserve stored statistics; never invent lost historical votes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_kb_rating_table($prefix)
{
	if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,53}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid KB rating table prefix'); }
	return $prefix . 'kb_articles';
}
function plus_kb_rating_plan($db, $prefix, $canonical = false)
{
	$table = plus_kb_rating_table($prefix); $quoted = plus_storage_identifier($table);
	$meta = plus_storage_metadata($db, $table); if (!$meta) { return array(); }
	if ($canonical) {
		if ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { throw new RuntimeException('Complete canonical storage migration before KB rating capacity migration'); }
		$bad = plus_storage_rows($db, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)='" . strtoupper(bin2hex($table)) . "' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME NOT IN ('utf8mb4_unicode_ci','utf8mb4_bin'))");
		if ($bad) { throw new RuntimeException('KB rating migration requires supported UTF8 storage'); }
	}
	$columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted); $fields = array();
	foreach ($columns as $column) { $fields[$column['Field']] = $column; }
	foreach (array('article_id','article_rating','article_totalvotes') as $field) { if (!isset($fields[$field])) { throw new RuntimeException('KB rating columns are incomplete'); } }
	if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/iD', $fields['article_id']['Type'])) { throw new RuntimeException('KB article identities require manual review'); }
	foreach (array('article_rating','article_totalvotes') as $field) {
		if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|double|float)(?:\([0-9]+(?:,[0-9]+)?\))?(?: unsigned)?$/iD', $fields[$field]['Type']) || $fields[$field]['Extra'] !== '') { throw new RuntimeException('Unsupported KB rating source column'); }
	}
	// This is a capacity increase for the bundled schema, not permission to
	// shrink a custom wider/extra-scale definition merely because current rows fit.
	if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/iD', $fields['article_totalvotes']['Type'])) { throw new RuntimeException('Custom non-integer KB vote counter requires manual review; it is not narrowed'); }
	$sum_type=$fields['article_rating']['Type'];
	if (preg_match('/^(?:decimal|double|float)\(([0-9]+),([0-9]+)\)(?: unsigned)?$/iD',$sum_type,$precision)) {
		if ((int)$precision[1]-(int)$precision[2]>26 || (int)$precision[2]>4) { throw new RuntimeException('Wider custom KB rating precision requires manual review; it is not narrowed'); }
	} elseif (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/iD',$sum_type)) { throw new RuntimeException('Custom floating KB rating capacity requires manual review'); }
	$duplicates = plus_storage_rows($db, 'SELECT article_id FROM ' . $quoted . ' GROUP BY article_id HAVING COUNT(*)<>1 LIMIT 1');
	$bad = plus_storage_rows($db, 'SELECT article_id FROM ' . $quoted . ' WHERE article_id IS NULL OR article_id<=0 OR article_rating IS NULL OR article_rating<0 OR article_rating>=100000000000000000000000000 OR article_rating<>CAST(article_rating AS DECIMAL(30,4)) OR article_totalvotes IS NULL OR article_totalvotes<0 OR article_totalvotes>18446744073709551615 OR article_totalvotes<>FLOOR(article_totalvotes) LIMIT 1');
	if ($duplicates || $bad) { throw new RuntimeException('Ambiguous or unrepresentable KB rating data requires manual review; no rows are removed or remapped'); }
	$rating = $fields['article_rating']; $count = $fields['article_totalvotes'];
	$correct = strcasecmp($rating['Type'], 'decimal(30,4)') === 0 && $rating['Null'] === 'NO' && (string)$rating['Default'] === '0.0000' &&
		preg_match('/^bigint(?:\([0-9]+\))? unsigned$/iD', $count['Type']) === 1 && $count['Null'] === 'NO' && (string)$count['Default'] === '0';
	if ($correct) { return array(); }
	$mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode'); $no_backslashes = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true);
	$definitions = array('article_rating'=>'DECIMAL(30,4) NOT NULL DEFAULT 0.0000', 'article_totalvotes'=>'BIGINT UNSIGNED NOT NULL DEFAULT 0'); $parts = array();
	foreach ($definitions as $field=>$definition) {
		$comment = $no_backslashes ? str_replace("'", "''", $fields[$field]['Comment']) : mysqli_real_escape_string($db, $fields[$field]['Comment']);
		$parts[] = 'MODIFY ' . plus_storage_identifier($field) . ' ' . $definition . " COMMENT '" . $comment . "'";
	}
	if ($meta['AUTO_INCREMENT'] !== null) {
		if (!preg_match('/^[0-9]+$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Unsupported KB article identity floor'); }
		$parts[] = 'AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
	}
	return array($table=>array('sql'=>'ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts)));
}

function plus_kb_rating_digest($db, $table)
{
	$fields = array(); foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . plus_storage_identifier($table)) as $row) { $fields[] = $row['Field']; }
	$aliases = array(); foreach (array('sum','count') as $kind) {
		$name = '__plus_kb_rating_' . $kind; $index = 0;
		while (in_array($name, $fields, true) || in_array($name, $aliases, true)) { $name = '__plus_kb_rating_' . $kind . '_' . (++$index); }
		$aliases[$kind] = $name;
	}
	$sql = 'SELECT t.*,CAST(article_rating AS DECIMAL(30,4)) AS ' . plus_storage_identifier($aliases['sum']) . ',CAST(article_totalvotes AS DECIMAL(20,0)) AS ' . plus_storage_identifier($aliases['count']) . ' FROM ' . plus_storage_identifier($table) . ' t ORDER BY article_id';
	$result = mysqli_query($db, $sql, MYSQLI_USE_RESULT); if (!$result) { throw new RuntimeException('Cannot verify KB rating table data'); }
	$hash = hash_init('sha256');
	try {
		while ($row = mysqli_fetch_assoc($result)) {
			$row['article_rating'] = $row[$aliases['sum']]; $row['article_totalvotes'] = $row[$aliases['count']]; unset($row[$aliases['sum']], $row[$aliases['count']]);
			hash_update($hash, serialize($row));
		}
		if (mysqli_errno($db)) { throw new RuntimeException('KB rating data inventory was interrupted'); }
	} finally { mysqli_free_result($result); }
	return hash_final($hash);
}

function plus_kb_rating_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('KB rating migration requires a verified backup and stopped web/cron writers'); }
	if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid KB rating DDL wait limit'); }
	plus_kb_rating_table($prefix);
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
	if (!$identity['db'] || (string)$identity['automatic'] !== '1') { throw new RuntimeException('KB rating DDL requires an idle autocommit connection'); }
	// This refuses an already active caller transaction without implicit COMMIT.
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$owned = false; $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
	try {
		plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
		$rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
		if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
		$plan = plus_kb_rating_plan($db, $prefix, true); $before = array();
		foreach ($plan as $table=>$item) { $before[$table] = array('meta'=>plus_storage_metadata($db,$table),'signature'=>plus_storage_signature($db,$table),'digest'=>plus_kb_rating_digest($db,$table)); }
		plus_storage_query($db, 'COMMIT'); $owned = false;
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
		foreach ($plan as $table=>$item) {
			// DDL is NOT rollbackable. Keep every writer stopped after an error;
			// inspect current schema/data and retry the idempotent migration.
			plus_storage_query($db, $item['sql']);
			if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('KB rating capacity rebuild reported warnings; inspect before retrying'); }
			$after = plus_storage_signature($db,$table); $signature = $before[$table]['signature'];
			foreach ($signature[0] as $i=>$column) {
				if (!in_array($column['Field'],array('article_rating','article_totalvotes'),true)) { continue; }
				$signature[0][$i]['Type'] = $after[0][$i]['Type']; $signature[0][$i]['Null'] = 'NO'; $signature[0][$i]['Default'] = $after[0][$i]['Default'];
			}
			$meta = $before[$table]['meta']; $after_meta = plus_storage_metadata($db,$table); $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
			if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor,$floor)) || $before[$table]['digest'] !== plus_kb_rating_digest($db,$table)) { throw new RuntimeException('KB rating capacity data/schema verification failed; keep writers stopped'); }
		}
		if (plus_kb_rating_plan($db,$prefix,true)) { throw new RuntimeException('KB rating capacity migration incomplete'); }
		return count($plan);
	} finally {
		if ($owned) { try { mysqli_query($db,'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
		try {
			plus_storage_query($db,"SET SESSION sql_mode='" . mysqli_real_escape_string($db,$identity['mode']) . "'");
			plus_storage_query($db,'SET SESSION lock_wait_timeout=' . (int)$identity['wait_timeout']);
		} finally { if ($held) { plus_storage_query($db,"DO RELEASE_LOCK('" . $lock . "')"); } }
	}
}
