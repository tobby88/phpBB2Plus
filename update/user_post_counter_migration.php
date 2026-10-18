<?php
// Rebuild derived public user counters only; never rewrite/delete stored posts.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }

function plus_user_posts_query($connection, $sql, $mode = MYSQLI_STORE_RESULT)
{
	try { $result = @mysqli_query($connection, $sql, $mode); }
	catch (Exception $error) { throw new RuntimeException('User post counter query failed'); }
	catch (Error $error) { throw new RuntimeException('User post counter query failed'); }
	if ($result === false) { throw new RuntimeException('User post counter query failed'); } return $result;
}
function plus_user_posts_rows($connection, $sql)
{
	$result = plus_user_posts_query($connection, $sql); $rows = array();
	while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; } mysqli_free_result($result); return $rows;
}
function plus_user_posts_names($prefix)
{
	if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,58}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid user post counter table prefix'); }
	return array('users'=>$prefix . 'users', 'posts'=>$prefix . 'posts', 'forums'=>$prefix . 'forums');
}
function plus_user_posts_metadata($connection, $names, $locked)
{
	$hex = array(); foreach ($names as $name) { $hex[] = "'" . strtoupper(bin2hex($name)) . "'"; }
	$rows = plus_user_posts_rows($connection, 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME) IN (' . implode(',', $hex) . ')');
	if (!$rows) { return false; }
	if (count($rows) !== 3) { throw new RuntimeException('User post counter table inventory is incomplete'); }
	$found = array(); foreach ($rows as $row) { $found[$row['TABLE_NAME']] = $row; }
	foreach ($names as $kind=>$name) {
		if (!isset($found[$name]) || $found[$name]['TABLE_TYPE'] !== 'BASE TABLE') { throw new RuntimeException('User post counter repair requires base tables'); }
		if (!$locked) { continue; } $row = $found[$name]; $literal = "'" . strtoupper(bin2hex($name)) . "'";
		if ($row['ENGINE'] !== 'InnoDB' || strtolower($row['ROW_FORMAT']) !== 'dynamic' || $row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') { throw new RuntimeException('Complete the supported storage migration before repairing user post counters'); }
		$legacy = plus_user_posts_rows($connection, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=" . $literal . " AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
		$key = $kind === 'users' ? 'user_id' : ($kind === 'posts' ? 'post_id' : 'forum_id');
		$primary = plus_user_posts_rows($connection, "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=" . $literal . " AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
		if ($legacy || count($primary) !== 1 || $primary[0]['COLUMN_NAME'] !== $key) { throw new RuntimeException('User post counter repair requires canonical storage and unique identities'); }
		$fields = $kind === 'users' ? array('user_id','user_posts') : ($kind === 'posts' ? array('post_id','poster_id','forum_id') : array('forum_id','count_posts'));
		$types = plus_user_posts_rows($connection, "SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=" . $literal . " AND COLUMN_NAME IN ('" . implode("','", $fields) . "')");
		if (count($types) !== count($fields)) { throw new RuntimeException('User post counter columns are incomplete'); }
		foreach ($types as $type) {
			if ($kind === 'forums' && $type['COLUMN_NAME'] === 'count_posts' && $type['DATA_TYPE'] === 'char' && (int)$type['CHARACTER_MAXIMUM_LENGTH'] === 1) { continue; }
			if (!in_array($type['DATA_TYPE'], array('tinyint','smallint','mediumint','int','bigint'), true)) { throw new RuntimeException('User post counter repair requires canonical identities, counters and policy flags'); }
		}
	}
	return true;
}
function plus_user_posts_plan($connection, $prefix, $locked = false)
{
	$names = plus_user_posts_names($prefix);
	$empty = array('installed'=>false, 'changes'=>0, 'sql'=>'');
	if (!plus_user_posts_metadata($connection, $names, false)) { return $empty; }
	if ($locked) {
		foreach ($names as $name) { $r = plus_user_posts_query($connection, 'SELECT * FROM `' . $name . '` LIMIT 0'); mysqli_free_result($r); }
		plus_user_posts_metadata($connection, $names, true);
		foreach (array('users'=>'user_id','posts'=>'post_id','forums'=>'forum_id') as $kind=>$key) {
			// Drain unbuffered IDs: hold row/range locks without copying post bodies
			// or an entire large post-ID result into PHP memory.
			$r = plus_user_posts_query($connection, 'SELECT ' . $key . ' FROM `' . $names[$kind] . '` ORDER BY ' . $key . ' FOR UPDATE', MYSQLI_USE_RESULT);
			while (mysqli_fetch_row($r)) {} mysqli_free_result($r);
			if (mysqli_errno($connection)) { throw new RuntimeException('User post counter lock inventory failed'); }
		}
	}
	$invalid = plus_user_posts_rows($connection, 'SELECT forum_id FROM `' . $names['forums'] . "` WHERE count_posts IS NULL OR HEX(CAST(count_posts AS CHAR)) NOT IN ('30','31') LIMIT 1");
	if ($invalid) { throw new RuntimeException('User post counter repair requires explicit forum counting policies'); }
	$counts = '(SELECT p.poster_id,COUNT(*) AS number_posts FROM `' . $names['posts'] . '` p INNER JOIN `' . $names['forums'] . '` f ON f.forum_id=p.forum_id AND f.count_posts<>0 GROUP BY p.poster_id) counted';
	$from = '`' . $names['users'] . '` u LEFT JOIN ' . $counts . ' ON counted.poster_id=u.user_id';
	$where = 'u.user_id>0 AND NOT (u.user_posts <=> COALESCE(counted.number_posts,0))';
	$capacity = plus_user_posts_rows($connection, 'SELECT u.user_id FROM ' . $from . ' WHERE u.user_id>0 AND counted.number_posts>16777215 LIMIT 1');
	if ($capacity) { throw new RuntimeException('User post count exceeds bundled counter capacity'); }
	$rows = plus_user_posts_rows($connection, 'SELECT COUNT(*) AS changed FROM ' . $from . ' WHERE ' . $where);
	if (count($rows) !== 1 || preg_match('/^[0-9]{1,10}$/D', $rows[0]['changed']) !== 1 || (float)$rows[0]['changed'] > 2147483647) { throw new RuntimeException('Unsupported user post counter inventory size'); }
	return array('installed'=>true, 'changes'=>(int)$rows[0]['changed'], 'sql'=>'UPDATE ' . $from . ' SET u.user_posts=COALESCE(counted.number_posts,0) WHERE ' . $where);
}
function plus_user_posts_print_plan($plan)
{
	echo 'Derived user post counter changes: ' . $plan['changes'] . "\n";
	if ($plan['changes']) { echo $plan['sql'] . ";\n"; }
}
function plus_user_posts_apply($connection, $prefix, $backup, $maintenance)
{
	if (!$backup || !$maintenance) { throw new RuntimeException('User post counter repair requires verified backup and stopped web/cron writers'); }
	plus_user_posts_names($prefix); $owned = false;
	try {
		// Next-transaction SET refuses an existing caller transaction first.
		plus_user_posts_query($connection, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		plus_user_posts_query($connection, "SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		plus_user_posts_query($connection, 'START TRANSACTION'); $owned = true;
		$plan = plus_user_posts_plan($connection, $prefix, true);
		if ($plan['changes']) {
			plus_user_posts_query($connection, $plan['sql']);
			if (mysqli_affected_rows($connection) !== $plan['changes']) { throw new RuntimeException('User post counter repair affected an unexpected inventory'); }
		}
		if (plus_user_posts_plan($connection, $prefix, true)['changes']) { throw new RuntimeException('User post counter repair verification failed'); }
		try { plus_user_posts_query($connection, 'COMMIT'); }
		catch (Exception $error) { throw new RuntimeException('User post counter commit confirmation failed; inspect before retrying'); }
		$owned = false; return $plan['changes'];
	} catch (Exception $error) {
		if ($owned) { try { @mysqli_query($connection, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} } throw $error;
	} catch (Error $error) {
		if ($owned) { try { @mysqli_query($connection, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} } throw $error;
	}
}
