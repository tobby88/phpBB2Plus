<?php
// Repair only derived category snapshots; never reconstruct missing parents.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
require_once __DIR__ . '/innodb_migration.php';

function plus_album_comment_parent_names($prefix)
{
	if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]{1,40}$/D', $prefix)) { throw new RuntimeException('Invalid Album comment namespace'); }
	return array('categories'=>$prefix.'album_cat', 'pictures'=>$prefix.'album', 'comments'=>$prefix.'album_comment');
}

function plus_album_comment_parent_metadata($db, $names, $canonical)
{
	$found = array();
	$keys = array('categories'=>'cat_id', 'pictures'=>'pic_id', 'comments'=>'comment_id');
	$required = array('categories'=>array('cat_id'), 'pictures'=>array('pic_id','pic_cat_id'), 'comments'=>array('comment_id','comment_pic_id','comment_cat_id'));
	foreach ($names as $kind=>$table) {
		$meta = plus_storage_metadata($db, $table);
		if ($meta === null) { continue; }
		if ($canonical && ($meta['ENGINE'] !== 'InnoDB' || strtolower($meta['ROW_FORMAT']) !== 'dynamic' || $meta['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) { throw new RuntimeException('Complete canonical Album storage before repairing comment categories'); }
		$columns = plus_storage_rows($db, 'SHOW FULL COLUMNS FROM '.plus_storage_identifier($table)); $fields = array();
		foreach ($columns as $column) {
			if ($canonical && $column['Collation'] !== null && $column['Collation'] !== 'utf8mb4_unicode_ci') { throw new RuntimeException('Complete Album column encoding before repairing comment categories'); }
			$fields[$column['Field']] = $column;
		}
		if (isset($fields['__plus_parent_target'])) { throw new RuntimeException('Reserved Album migration alias is already a custom field'); }
		foreach ($required[$kind] as $field) {
			if (!isset($fields[$field]) || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/D', strtolower($fields[$field]['Type']))) { throw new RuntimeException('Album comment repair requires integer identities and categories'); }
		}
		$primary = plus_storage_rows($db, 'SHOW INDEX FROM '.plus_storage_identifier($table)." WHERE Key_name='PRIMARY'");
		if (count($primary) !== 1 || $primary[0]['Column_name'] !== $keys[$kind]) { throw new RuntimeException('Album comment repair requires unique primary identities'); }
		$found[$kind] = $fields;
	}
	if ($found && count($found) !== 3) { throw new RuntimeException('Album comment parent table inventory is incomplete'); }
	return $found;
}

function plus_album_comment_parent_plan($db, $prefix, $locked = false)
{
	if (mysqli_character_set_name($db) !== 'utf8mb4') { throw new RuntimeException('Album comment repair requires utf8mb4'); }
	$names = plus_album_comment_parent_names($prefix);
	$fields = plus_album_comment_parent_metadata($db, $names, false);
	if (!$fields) { return array('installed'=>false, 'changes'=>0, 'sql'=>''); }
	if ($locked) {
		// Stream row/range locks in primary-key order, without retaining comments.
		foreach (array('categories'=>'cat_id','pictures'=>'pic_id','comments'=>'comment_id') as $kind=>$key) {
			$result = mysqli_query($db, 'SELECT '.$key.' FROM '.plus_storage_identifier($names[$kind]).' ORDER BY '.$key.' FOR UPDATE', MYSQLI_USE_RESULT);
			if (!$result) { throw new RuntimeException('Cannot lock Album comment parent inventory'); }
			while (mysqli_fetch_row($result)) {} mysqli_free_result($result);
			if (mysqli_errno($db)) { throw new RuntimeException('Album comment parent inventory interrupted'); }
		}
		$fields = plus_album_comment_parent_metadata($db, $names, true);
		$triggers = plus_storage_rows($db, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND HEX(EVENT_OBJECT_TABLE)='".strtoupper(bin2hex($names['comments']))."'");
		if ($triggers) { throw new RuntimeException('Custom Album comment triggers need manual review before category repair'); }
	}
	$from = plus_storage_identifier($names['comments']).' c INNER JOIN '.plus_storage_identifier($names['pictures']).' p ON p.pic_id=c.comment_pic_id INNER JOIN '.plus_storage_identifier($names['categories']).' k ON k.cat_id=p.pic_cat_id';
	$where = 'NOT(c.comment_cat_id <=> p.pic_cat_id)';
	$type = preg_replace('/\([0-9]+\)/', '', strtolower($fields['comments']['comment_cat_id']['Type']));
	$bounds = array('tinyint'=>array('-128','127'),'tinyint unsigned'=>array('0','255'), 'smallint'=>array('-32768','32767'),'smallint unsigned'=>array('0','65535'), 'mediumint'=>array('-8388608','8388607'),'mediumint unsigned'=>array('0','16777215'), 'int'=>array('-2147483648','2147483647'),'int unsigned'=>array('0','4294967295'), 'bigint'=>array('-9223372036854775808','9223372036854775807'),'bigint unsigned'=>array('0','18446744073709551615'));
	$limit = $bounds[$type];
	if (plus_storage_rows($db, 'SELECT c.comment_id FROM '.$from.' WHERE '.$where.' AND (p.pic_cat_id<'.$limit[0].' OR p.pic_cat_id>'.$limit[1].') LIMIT 1')) { throw new RuntimeException('Current picture category exceeds custom comment category capacity'); }
	$count = plus_storage_rows($db, 'SELECT COUNT(*) n FROM '.$from.' WHERE '.$where);
	if (count($count) !== 1 || !preg_match('/^[0-9]{1,10}$/D', (string)$count[0]['n']) || (float)$count[0]['n'] > 2147483647) { throw new RuntimeException('Unsupported Album comment repair inventory size'); }
	return array('installed'=>true, 'changes'=>(int)$count[0]['n'], 'sql'=>'UPDATE '.$from.' SET c.comment_cat_id=p.pic_cat_id WHERE '.$where);
}

function plus_album_comment_parent_digest($db, $prefix, $expected)
{
	$names = plus_album_comment_parent_names($prefix);
	$sql = 'SELECT c.*,CASE WHEN k.cat_id IS NULL THEN c.comment_cat_id ELSE p.pic_cat_id END AS __plus_parent_target FROM '.plus_storage_identifier($names['comments']).' c LEFT JOIN '.plus_storage_identifier($names['pictures']).' p ON p.pic_id=c.comment_pic_id LEFT JOIN '.plus_storage_identifier($names['categories']).' k ON k.cat_id=p.pic_cat_id ORDER BY c.comment_id';
	$result = mysqli_query($db, $sql, MYSQLI_USE_RESULT);
	if (!$result) { throw new RuntimeException('Cannot inventory Album comment data'); }
	$hash = hash_init('sha256');
	try {
		while ($row = mysqli_fetch_assoc($result)) {
			if ($expected) { $row['comment_cat_id'] = $row['__plus_parent_target']; }
			unset($row['__plus_parent_target']);
			// Normalize driver native-number options without normalizing any bytes.
			foreach ($row as $key=>$value) { if ($value !== null) { $row[$key] = (string)$value; } }
			hash_update($hash, serialize($row));
		}
		if (mysqli_errno($db)) { throw new RuntimeException('Album comment data inventory interrupted'); }
	} finally { mysqli_free_result($result); }
	return hash_final($hash);
}

function plus_album_comment_parent_print_plan($plan)
{
	echo 'Album comment category snapshot changes: '.$plan['changes']."\n";
	if ($plan['changes']) { echo $plan['sql'].";\n"; }
}

function plus_album_comment_parent_apply($db, $prefix, $backup, $maintenance, $wait = 30)
{
	if ($backup !== true || $maintenance !== true) { throw new RuntimeException('Album comment repair requires verified backup and stopped web/cron writers'); }
	if (!is_int($wait) || $wait < 1 || $wait > 30) { throw new RuntimeException('Invalid Album comment repair wait limit'); }
	plus_album_comment_parent_names($prefix);
	$identity = plus_storage_rows($db, 'SELECT DATABASE() db,CONNECTION_ID() id,@@SESSION.autocommit automatic,@@SESSION.sql_mode mode,@@SESSION.innodb_lock_wait_timeout row_wait,@@SESSION.lock_wait_timeout metadata_wait'); $identity = $identity[0];
	if (!$identity['db'] || (string)$identity['automatic'] !== '1') { throw new RuntimeException('Album comment repair requires an idle autocommit connection'); }
	// Refuse a caller transaction before changing its settings or taking locks.
	plus_storage_query($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	$owned = false; $held = array();
	try {
		plus_storage_query($db, 'START TRANSACTION'); $owned = true;
		foreach (array('plus_innodb_'.sha1($identity['db']), 'album_files_'.sha1($identity['db']."\0".$prefix)) as $lock) {
			$r = plus_storage_rows($db, "SELECT IS_USED_LOCK('".$lock."') owner");
			if (count($r) !== 1 || ($r[0]['owner'] !== null && (string)$r[0]['owner'] === (string)$identity['id'])) { throw new RuntimeException('Cannot adopt a borrowed Album migration lock'); }
			$r = plus_storage_rows($db, "SELECT GET_LOCK('".$lock."',0) held");
			if (count($r) !== 1 || (string)$r[0]['held'] !== '1') { throw new RuntimeException('Another Album writer or migration is running'); } $held[] = $lock;
		}
		foreach ($held as $lock) { $r = plus_storage_rows($db, "SELECT IS_USED_LOCK('".$lock."') owner"); if (count($r) !== 1 || (string)$r[0]['owner'] !== (string)$identity['id']) { throw new RuntimeException('Album migration lock ownership changed'); } }
		plus_storage_query($db, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		plus_storage_query($db, 'SET SESSION innodb_lock_wait_timeout='.$wait); plus_storage_query($db, 'SET SESSION lock_wait_timeout='.$wait);
		$plan = plus_album_comment_parent_plan($db, $prefix, true);
		if ($plan['installed']) {
			$expected = plus_album_comment_parent_digest($db, $prefix, true);
			if ($plan['changes']) {
				plus_storage_query($db, $plan['sql']);
				if (mysqli_affected_rows($db) !== $plan['changes'] || plus_storage_rows($db, 'SHOW WARNINGS')) { throw new RuntimeException('Album comment category repair affected an unexpected inventory'); }
			}
			if (plus_album_comment_parent_plan($db, $prefix, true)['changes'] || plus_album_comment_parent_digest($db, $prefix, false) !== $expected) { throw new RuntimeException('Album comment category or unchanged data verification failed'); }
		}
		try { plus_storage_query($db, 'COMMIT'); }
		catch (Exception $error) { throw new RuntimeException('Album comment repair commit confirmation failed; keep writers stopped and inspect before retrying'); }
		catch (Error $error) { throw new RuntimeException('Album comment repair commit confirmation failed; keep writers stopped and inspect before retrying'); }
		$owned = false; return $plan['changes'];
	} finally {
		if ($owned) { try { mysqli_query($db, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
		try {
			plus_storage_query($db, "SET SESSION sql_mode='".mysqli_real_escape_string($db, $identity['mode'])."'");
			plus_storage_query($db, 'SET SESSION innodb_lock_wait_timeout='.(int)$identity['row_wait']); plus_storage_query($db, 'SET SESSION lock_wait_timeout='.(int)$identity['metadata_wait']);
		} finally { foreach (array_reverse($held) as $lock) { plus_storage_query($db, "DO RELEASE_LOCK('".$lock."')"); } }
	}
}
