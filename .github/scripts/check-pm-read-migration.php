<?php
namespace PmReadMigrationFixture;
$pm_migration_kind = isset($GLOBALS['pm_migration_kind']) && $GLOBALS['pm_migration_kind'] === 'write' ? 'write' : 'read';
function plan(&$operations, $connection, $database, $table)
{
	if ($GLOBALS['pm_migration_kind'] === 'write') { update_queue_pm_write_columns($operations, $connection, $database, $table); }
	else { update_queue_pm_read_columns($operations, $connection, $database, $table); }
}
function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
function update_quote_identifier($name) { return '`' . $name . '`'; }
function update_table_exists($connection, $database, $table)
{
	if ($connection instanceof \PDO)
	{
		$s = $connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
		$s->execute(array($table)); return (int)$s->fetchColumn() === 1;
	}
	return true;
}
function update_column_exists($connection, $database, $table, $column)
{
	if ($connection instanceof \PDO)
	{
		$s = $connection->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
		$s->execute(array($table, $column)); return (int)$s->fetchColumn() === 1;
	}
	return in_array($column, $GLOBALS['pm_read_columns'], true);
}
function update_index_exists($connection, $database, $table, $index)
{
	if ($connection instanceof \PDO)
	{
		$s = $connection->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
		$s->execute(array($table, $index)); return (int)$s->fetchColumn() > 0;
	}
	return $GLOBALS['pm_read_index'];
}
$root = dirname(dirname(__DIR__));
$source = file_get_contents($root . '/update/update_from_153a.php');
foreach (array('update_queue_column'=>'update_queue_topic_notification_columns',('update_queue_pm_' . $pm_migration_kind . '_columns')=>'update_queue_default') as $function=>$next)
{
	$start = strpos($source, 'function ' . $function . '('); $end = strpos($source, 'function ' . $next . '(', $start);
	check($start !== false && $end > $start, 'Actual migration function boundary');
	eval('namespace PmReadMigrationFixture;' . substr($source, $start, $end - $start));
}
check(strpos($source, 'update_queue_pm_' . $pm_migration_kind . "_columns(\$operations, \$connection, \$dbname, \$table_prefix . 'privmsgs');") !== false, 'Updater invokes canonical planner');
$schema = file_get_contents($root . '/phpBB2/install/schemas/mysql_schema.sql');
$fields = $pm_migration_kind === 'read' ? array('privmsgs_read_token','privmsgs_read_copy_id','privmsgs_copy_token') : array('privmsgs_write_token','privmsgs_write_hash','privmsgs_write_payload');
$token_field = $pm_migration_kind === 'read' ? 'privmsgs_copy_token' : 'privmsgs_write_token';
$empty_fields = $pm_migration_kind === 'read' ? "privmsgs_copy_token IS NULL AND privmsgs_read_token='' AND privmsgs_read_copy_id=0" : "privmsgs_write_token IS NULL AND privmsgs_write_hash='' AND privmsgs_write_payload IS NULL";
foreach ($fields as $field)
{
	check(preg_match('/^\s+' . $field . '\s+([^,]+),/m', $schema, $match) === 1, 'Installer column ' . $field);
	$definitions[$field] = $match[1];
}
check(strpos($schema, 'UNIQUE KEY ' . $token_field . ' (' . $token_field . ')') !== false, 'Installer has unique operation identity');
foreach (array(0,1,2,3,4) as $existing)
{
	$pm_read_columns = array_slice(array_keys($definitions), 0, min($existing, 3)); $pm_read_index = $existing === 4;
	$operations = array(); plan($operations, null, 'fixture', 'custom_privmsgs');
	check(count($operations) === 4 - $existing, 'Only missing columns/index; repeated planner is empty');
	foreach ($operations as $sql)
	{
		check(strpos($sql, 'ALTER TABLE `custom_privmsgs` ADD ') === 0, 'Additive custom-prefix migration');
		check(!preg_match('/\b(?:UPDATE|DELETE|INSERT|DROP|TRUNCATE)\b/i', $sql), 'No changes to historical PMs');
	}
}
echo 'Additive PM ' . $pm_migration_kind . " migration checks passed.\n";
$dsn = getenv('PHPBB_PM_REPAIR_TEST_DSN');
if ($dsn !== false && $dsn !== '')
{
	check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_pm_repair_[a-f0-9]{16};charset=utf8mb4$/D', $dsn) === 1, 'Only owned native schemas');
	$connection = new \PDO($dsn, 'root', '', array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
	try
	{
		foreach (array('MyISAM','InnoDB') as $engine)
		{
			foreach (array(0,1,2,3,4) as $existing)
			{
				$connection->exec('DROP TABLE IF EXISTS custom_privmsgs');
				$connection->exec('CREATE TABLE custom_privmsgs (privmsgs_id INTEGER PRIMARY KEY, body TEXT) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4');
				$connection->exec("INSERT INTO custom_privmsgs VALUES (1,'Grüße'),(2,'Grüße')");
				foreach (array_slice($definitions, 0, min(3, $existing), true) as $field=>$definition)
				{
					$connection->exec('ALTER TABLE custom_privmsgs ADD ' . $field . ' ' . $definition);
				}
				if ($existing === 4) { $connection->exec('ALTER TABLE custom_privmsgs ADD UNIQUE KEY ' . $token_field . ' (' . $token_field . ')'); }
				$operations = array(); plan($operations, $connection, 'fixture', 'custom_privmsgs');
				check(count($operations) === 4 - $existing, 'Native missing/partial migration plan');
				foreach ($operations as $sql) { $connection->exec($sql); }
				check((int)$connection->query("SELECT COUNT(*) FROM custom_privmsgs WHERE body='Grüße' AND " . $empty_fields)->fetchColumn() === 2, 'Historical copies preserved independently');
				$operations = array(); plan($operations, $connection, 'fixture', 'custom_privmsgs');
				check(!$operations, 'Native repeated migration empty');
				$connection->exec('UPDATE custom_privmsgs SET ' . $token_field . "='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE privmsgs_id=1");
				$caught = false;
				try { $connection->exec('UPDATE custom_privmsgs SET ' . $token_field . "='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE privmsgs_id=2"); }
				catch (\PDOException $error) { $caught = true; }
				check($caught, 'Unique token prevents duplicate allocation');
			}
		}
		echo 'Native MyISAM/InnoDB missing/partial/repeated PM ' . $pm_migration_kind . " migration passed.\n";
	}
	finally { $connection->exec('DROP TABLE IF EXISTS custom_privmsgs'); }
}
