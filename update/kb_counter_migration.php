<?php
// Derived-data repair only. No bootstrap, schema changes or article deletion.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
if (!defined('IN_PHPBB')) { define('IN_PHPBB', true); }
require_once dirname(__DIR__) . '/phpBB2/includes/functions_kb_counter_policy.php';

function plus_kb_counter_query($connection, $sql)
{
	try { $result = @mysqli_query($connection, $sql); }
	catch (Exception $error) { throw new RuntimeException('Knowledge Base counter query failed'); }
	catch (Error $error) { throw new RuntimeException('Knowledge Base counter query failed'); }
	if ($result === false) { throw new RuntimeException('Knowledge Base counter query failed'); }
	return $result;
}

function plus_kb_counter_rows($connection, $sql)
{
	$result = plus_kb_counter_query($connection, $sql); $rows = array();
	while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
	mysqli_free_result($result); return $rows;
}

function plus_kb_counter_names($prefix)
{
	if (!is_string($prefix) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $prefix) !== 1) { throw new RuntimeException('Invalid Knowledge Base table prefix'); }
	$names = array('categories'=>$prefix . 'kb_categories', 'articles'=>$prefix . 'kb_articles');
	foreach ($names as $name) { if (strlen($name) > 64) { throw new RuntimeException('Knowledge Base table identifier exceeds server capacity'); } }
	return $names;
}

function plus_kb_counter_metadata($connection, $names)
{
	$categories = mysqli_real_escape_string($connection, $names['categories']); $articles = mysqli_real_escape_string($connection, $names['articles']);
	$rows = plus_kb_counter_rows($connection, "SELECT TABLE_NAME,TABLE_TYPE,ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (HEX(TABLE_NAME)=HEX('$categories') OR HEX(TABLE_NAME)=HEX('$articles'))");
	if (!$rows) { return array(); }
	$found = array(); foreach ($rows as $row) { $found[$row['TABLE_NAME']] = $row; }
	if (count($found) !== 2 || !isset($found[$names['categories']], $found[$names['articles']])) { throw new RuntimeException('Knowledge Base table inventory is incomplete'); }
	foreach ($found as $row) { if ($row['TABLE_TYPE'] !== 'BASE TABLE') { throw new RuntimeException('Knowledge Base repair requires base tables'); } }
	return $found;
}

function plus_kb_counter_plan($connection, $prefix, $locked = false)
{
	$names = plus_kb_counter_names($prefix); $metadata = plus_kb_counter_metadata($connection, $names);
	if (!$metadata) { return array('installed'=>false, 'table'=>$names['categories'], 'expected'=>array(), 'changes'=>array()); }
	if ($locked)
	{
		// Pin metadata before checking it; DDL cannot replace storage under the
		// owned transaction. Re-read its current definition after taking the MDL.
		foreach ($names as $table) { $r = plus_kb_counter_query($connection, 'SELECT * FROM `' . $table . '` LIMIT 0'); mysqli_free_result($r); }
		$metadata = plus_kb_counter_metadata($connection, $names);
		foreach ($metadata as $row)
		{
			if ($row['ENGINE'] !== 'InnoDB' || strtolower($row['ROW_FORMAT']) !== 'dynamic' || $row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')
			{ throw new RuntimeException('Complete the InnoDB/utf8mb4 storage migration before repairing Knowledge Base counters'); }
		}
	}
	$tail = $locked ? ' FOR UPDATE' : '';
	$categories = plus_kb_counter_rows($connection, 'SELECT category_id,parent,number_articles FROM `' . $names['categories'] . '` ORDER BY category_id' . $tail);
	$result = plus_kb_counter_query($connection, 'SELECT article_id,article_category_id,approved FROM `' . $names['articles'] . '` ORDER BY article_id' . $tail);
	$counts = array();
	while ($row = mysqli_fetch_assoc($result))
	{
		if ((string) $row['approved'] !== '1') { continue; }
		$id = kb_counter_category_id($row['article_category_id']); $current = isset($counts[$id]) ? $counts[$id] : 0;
		if ($current >= 16777215) { mysqli_free_result($result); throw new RuntimeException('Knowledge Base article count exceeds bundled capacity'); }
		$counts[$id] = $current + 1;
	}
	mysqli_free_result($result);
	$plan = kb_counter_rebuild_plan($categories, $counts); $plan['installed'] = true; $plan['table'] = $names['categories']; return $plan;
}

function plus_kb_counter_print_plan($plan)
{
	echo 'Knowledge Base category counter updates: ' . count($plan['changes']) . "\n";
	foreach ($plan['changes'] as $id=>$change)
	{ echo 'UPDATE `' . $plan['table'] . '` SET number_articles=' . $change['new'] . ' WHERE category_id=' . $id . ";\n"; }
}

function plus_kb_counter_apply($connection, $prefix, $backup_confirmed, $maintenance_confirmed)
{
	if (!$backup_confirmed || !$maintenance_confirmed) { throw new RuntimeException('Knowledge Base repair requires verified backup and stopped web/cron writers'); }
	plus_kb_counter_names($prefix); $owned = false;
	try
	{
		// Next-transaction SET is rejected inside an existing transaction, so
		// START TRANSACTION can never silently commit a caller's pending work.
		plus_kb_counter_query($connection, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		plus_kb_counter_query($connection, "SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		plus_kb_counter_query($connection, 'START TRANSACTION'); $owned = true;
		$plan = plus_kb_counter_plan($connection, $prefix, true);
		foreach ($plan['changes'] as $id=>$change)
		{
			plus_kb_counter_query($connection, 'UPDATE `' . $plan['table'] . '` SET number_articles=' . $change['new'] . ' WHERE category_id=' . $id);
			if (mysqli_affected_rows($connection) !== 1) { throw new RuntimeException('Knowledge Base counter repair was not applied exactly once'); }
		}
		if (plus_kb_counter_plan($connection, $prefix, true)['changes']) { throw new RuntimeException('Knowledge Base counter repair verification failed'); }
		try { plus_kb_counter_query($connection, 'COMMIT'); }
		catch (Exception $error) { throw new RuntimeException('Knowledge Base counter commit confirmation failed; inspect before retrying'); }
		$owned = false; return count($plan['changes']);
	}
	catch (Exception $error)
	{
		if ($owned)
		{
			try { @mysqli_query($connection, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {}
		}
		throw $error;
	}
	catch (Error $error)
	{
		if ($owned)
		{
			try { @mysqli_query($connection, 'ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {}
		}
		throw $error;
	}
}
