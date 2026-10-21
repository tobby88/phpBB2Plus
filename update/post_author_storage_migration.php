<?php
// Widen only the stored guest byline, not account-name policy. DDL auto-commits.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_post_author_table($prefix)
{
	if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,59}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid post author table prefix'); }
	return $prefix . 'posts';
}
function plus_post_author_default_is_literal($server, $reported)
{
	if (!is_string($server)) { return false; }
	// MySQL identifies expression defaults in SHOW COLUMNS.Extra; the caller
	// already refuses any nonempty Extra. MariaDB leaves Extra empty instead.
	if (stripos($server, 'MariaDB') === false) { return true; }
	$server = preg_replace('/^5\.5\.5-/', '', $server);
	if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/D', $server, $version) !== 1) { return false; }
	if (version_compare($version[1], '10.2.1', '<')) { return true; }
	// MariaDB 10.2.1..6 cannot distinguish literal and expression metadata.
	if (version_compare($version[1], '10.2.7', '<')) { return false; }
	// Since 10.2.7 information_schema.COLUMNS quotes string literals, doubling
	// their inner quotes. Accept one complete literal token, not an expression.
	// https://mariadb.com/docs/release-notes/community-server/old-releases/10.2/10.2.7
	return is_string($reported) && preg_match("/^'(?:[^']|'')*'$/sD", $reported) === 1;
}
function plus_post_author_literal_default($db, $table, $column)
{
	if ($column['Default'] === null) { return null; }
	$defaults = plus_storage_rows($db, "SELECT VERSION() AS server_version,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . mysqli_real_escape_string($db,$table) . "') AND COLUMN_NAME='post_username'");
	if (count($defaults) !== 1 || !plus_post_author_default_is_literal($defaults[0]['server_version'],$defaults[0]['COLUMN_DEFAULT'])) { throw new RuntimeException('Expression or ambiguous post author defaults require manual review before widening; no definition is rewritten'); }
	// MariaDB's SHOW/information_schema strings can replace supplementary UTF-8
	// characters with '?'. Read the actual known-constant value, not its printed
	// metadata. DEFAULT() reads no stored user value; one existing row suffices.
	$values = plus_storage_rows($db, 'SELECT DEFAULT(post_username) AS literal_default FROM ' . plus_storage_identifier($table) . ' LIMIT 1');
	if (!$values) {
		$mode = plus_storage_rows($db,'SELECT @@SESSION.sql_mode AS mode');$mode=$mode[0]['mode'];
		if (!is_string($mode) || preg_match('/^[A-Z0-9_,]*$/D',$mode)!==1) { throw new RuntimeException('Cannot preserve default-reader SQL mode'); }
		$relax = in_array('ONLY_FULL_GROUP_BY',explode(',',$mode),true);
		// This known constant is not row-dependent. Relax only the grouping
		// check for its read-only empty aggregate, then restore every mode.
		// MySQL's ANSI alias would re-enable the grouping check; its expanded
		// concrete flags remain in @@sql_mode, so omit only that alias too.
		if ($relax) { plus_storage_query($db,"SET SESSION sql_mode='".implode(',',array_diff(explode(',',$mode),array('ONLY_FULL_GROUP_BY','ANSI')))."'"); }
		try { $values = plus_storage_rows($db, 'SELECT DEFAULT(post_username) AS literal_default,COUNT(*) AS default_row_probe FROM ' . plus_storage_identifier($table) . ' WHERE 0'); }
		finally { if ($relax) { plus_storage_query($db,"SET SESSION sql_mode='".$mode."'"); } }
		if (count($values)!==1 || (string)$values[0]['default_row_probe']!=='0') { throw new RuntimeException('Cannot read empty-table post author default'); }
		// Some MariaDB versions null-complement an empty NOT NULL aggregate.
		// Unambiguous printed text is usable; '?' may instead hide a lost SMP
		// character, so never guess that definition or write replacement text.
		if ($values[0]['literal_default']===null && $column['Null']==='NO' && is_string($column['Default']) && strpos($column['Default'],'?')===false && strpos($column['Default'],"\xef\xbf\xbd")===false) { $values[0]['literal_default']=$column['Default']; }
	}
	if (count($values) !== 1 || !is_string($values[0]['literal_default']) || preg_match('//u',$values[0]['literal_default']) !== 1) { throw new RuntimeException('Cannot prove exact empty-table UTF8 post author default; manual review required before widening'); }
	return $values[0]['literal_default'];
}
function plus_post_author_plan($db, $prefix, $canonical = false)
{
	if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('Post author migration requires an exact utf8mb4 connection'); }
	$table = plus_post_author_table($prefix); $quoted = plus_storage_identifier($table);
	$meta = plus_storage_metadata($db, $table);
	if (!$meta) { throw new RuntimeException('Missing post author table'); }
	if ($canonical && ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Complete canonical storage migration before post author capacity migration'); }
	$fields = array(); foreach (plus_storage_rows($db, 'SHOW FULL COLUMNS FROM ' . $quoted) as $column) { $fields[$column['Field']] = $column; }
	if (!isset($fields['post_id'], $fields['post_username']) || !preg_match('/^varchar\(([0-9]+)\)$/iD', $fields['post_username']['Type'], $width) || $fields['post_username']['Extra'] !== '') { throw new RuntimeException('Custom post author definition requires manual review'); }
	$primary = plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted . " WHERE Key_name='PRIMARY'");
	if (count($primary) !== 1 || $primary[0]['Column_name'] !== 'post_id') { throw new RuntimeException('Post identities require manual review'); }
	$column = $fields['post_username'];
	if ($canonical && $column['Collation'] !== 'utf8mb4_unicode_ci') { throw new RuntimeException('Complete UTF8 post author migration first'); }
	// Never narrow a wider custom byline merely because current values fit.
	if ((int)$width[1] >= 1530) { return array(); }
	$literal_default = plus_post_author_literal_default($db,$table,$column);
	foreach (plus_storage_rows($db, 'SHOW INDEX FROM ' . $quoted) as $index) {
		if ($index['Column_name'] === 'post_username' && $index['Index_type'] === 'BTREE' && $index['Sub_part'] === null) { throw new RuntimeException('A full guest-name index needs manual review before widening; no index is shortened or removed'); }
	}
	if (!is_string($column['Collation']) || !preg_match('/^[A-Za-z0-9_]+$/D', $column['Collation'])) { throw new RuntimeException('Unsupported post author collation'); }
	$mode = plus_storage_rows($db, 'SELECT @@SESSION.sql_mode AS mode');
	$no_backslashes = in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode[0]['mode']), true);
	$quote = function($value) use ($db, $no_backslashes) { return "'" . ($no_backslashes ? str_replace("'", "''", $value) : mysqli_real_escape_string($db, $value)) . "'"; };
	$definition = 'VARCHAR(1530) COLLATE ' . plus_storage_identifier($column['Collation']) . ($column['Null'] === 'YES' ? ' NULL' : ' NOT NULL');
	if ($column['Default'] !== null) { $definition .= ' DEFAULT ' . $quote($literal_default); }
	elseif ($column['Null'] === 'YES') { $definition .= ' DEFAULT NULL'; }
	$definition .= ' COMMENT ' . $quote($column['Comment']);
	$parts = array('MODIFY `post_username` ' . $definition);
	if ($meta['AUTO_INCREMENT'] !== null) {
		if (!preg_match('/^[0-9]+$/D', (string)$meta['AUTO_INCREMENT'])) { throw new RuntimeException('Unsupported post identity floor'); }
		$parts[] = 'AUTO_INCREMENT=' . $meta['AUTO_INCREMENT'];
	}
	return array($table => array('sql' => 'ALTER TABLE ' . $quoted . ' ' . implode(', ', $parts)));
}
function plus_post_author_digest($db, $table)
{
	$result = mysqli_query($db, 'SELECT * FROM ' . plus_storage_identifier($table) . ' ORDER BY post_id', MYSQLI_USE_RESULT);
	if (!$result) { throw new RuntimeException('Cannot verify post author contents'); }
	$hash = hash_init('sha256');
	try {
		while ($row = mysqli_fetch_assoc($result)) { hash_update($hash, serialize($row)); }
		if (mysqli_errno($db)) { throw new RuntimeException('Post author content inventory interrupted'); }
	} finally { mysqli_free_result($result); }
	return hash_final($hash);
}
function plus_post_author_apply($db, $prefix, $backup_confirmed, $maintenance_confirmed, $wait_seconds = 30)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Post author migration requires a verified backup and stopped web/cron writers'); }
	if (!is_int($wait_seconds) || $wait_seconds < 1 || $wait_seconds > 30) { throw new RuntimeException('Invalid post author DDL wait limit'); }
	plus_post_author_table($prefix);
	$identity = plus_storage_rows($db, 'SELECT DATABASE() AS db,@@SESSION.autocommit AS automatic,@@SESSION.sql_mode AS mode,@@SESSION.lock_wait_timeout AS wait_timeout'); $identity = $identity[0];
	if (!$identity['db'] || (string)$identity['automatic'] !== '1') { throw new RuntimeException('Post author DDL requires an idle autocommit connection'); }
	// Refuse active caller transactions without implicit commit or rollback.
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$owned = false; $held = false; $lock = 'plus_innodb_' . sha1($identity['db']);
	try {
		plus_storage_query($db, 'START TRANSACTION READ ONLY'); $owned = true;
		$rows = plus_storage_rows($db, "SELECT GET_LOCK('" . $lock . "',0) AS held");
		if (count($rows) !== 1 || (string)$rows[0]['held'] !== '1') { throw new RuntimeException('Another schema migration is running'); } $held = true;
		$plan = plus_post_author_plan($db, $prefix, true); $before = array();
		foreach ($plan as $table => $item) {
			$signature = plus_storage_signature($db,$table);$default=null;
			foreach ($signature[0] as $column) { if ($column['Field']==='post_username') { $default=plus_post_author_literal_default($db,$table,$column); } }
			$before[$table] = array('meta'=>plus_storage_metadata($db,$table),'signature'=>$signature,'default'=>$default,'digest'=>plus_post_author_digest($db,$table));
		}
		plus_storage_query($db, 'COMMIT'); $owned = false;
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
		plus_storage_query($db, 'SET SESSION lock_wait_timeout=' . $wait_seconds);
		foreach ($plan as $table => $item) {
			// Keep every writer stopped after any DDL error; never claim rollback.
			plus_storage_query($db, $item['sql']);
			if (plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Post author rebuild reported warnings; inspect before retrying'); }
			$after = plus_storage_signature($db,$table); $signature = $before[$table]['signature'];
			foreach ($after[0] as $column) { if ($column['Field']==='post_username' && plus_post_author_literal_default($db,$table,$column) !== $before[$table]['default']) { throw new RuntimeException('Post author default value changed; keep writers stopped'); } }
			foreach ($signature[0] as $i => $column) { if ($column['Field'] === 'post_username') { $signature[0][$i]['Type'] = 'varchar(1530)'; } }
			$meta = $before[$table]['meta']; $after_meta = plus_storage_metadata($db,$table); $floor = $meta['AUTO_INCREMENT']; $after_floor = $after_meta['AUTO_INCREMENT']; unset($meta['AUTO_INCREMENT'],$after_meta['AUTO_INCREMENT']);
			if ($signature !== $after || $meta !== $after_meta || ($floor !== null && !plus_storage_counter_at_least($after_floor,$floor)) || $before[$table]['digest'] !== plus_post_author_digest($db,$table)) { throw new RuntimeException('Post author schema/data verification failed; keep writers stopped'); }
		}
		if (plus_post_author_plan($db,$prefix,true)) { throw new RuntimeException('Post author migration incomplete'); }
		return count($plan);
	} finally {
		if ($owned) { try { mysqli_query($db,'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
		try {
			plus_storage_query($db,"SET SESSION sql_mode='" . mysqli_real_escape_string($db,$identity['mode']) . "'");
			plus_storage_query($db,'SET SESSION lock_wait_timeout=' . (int)$identity['wait_timeout']);
		} finally { if ($held) { plus_storage_query($db,"DO RELEASE_LOCK('" . $lock . "')"); } }
	}
}
