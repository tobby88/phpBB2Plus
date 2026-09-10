<?php
namespace PmWriteReceiptsFixture;
$GLOBALS['pm_migration_kind'] = 'write';
require __DIR__ . '/check-pm-read-migration.php';
function update_table_exists($connection, $database, $table)
{
	if ($connection instanceof \PDO) { return \PmReadMigrationFixture\update_table_exists($connection, $database, $table); }
	return $GLOBALS['pm_receipt_exists'];
}
$source = file_get_contents(dirname(dirname(__DIR__)) . '/update/update_from_153a.php');
$start = strpos($source, 'function update_extract_create_tables('); $end = strpos($source, 'function update_extract_seed_statements(', $start);
\PmReadMigrationFixture\check($start !== false && $end > $start, 'Actual canonical extraction');
eval('namespace PmWriteReceiptsFixture;' . substr($source, $start, $end - $start));
$schema = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_schema.sql');
$tables = update_extract_create_tables($schema);
\PmReadMigrationFixture\check(isset($tables['phpbb_pm_write_receipts']), 'Receipt table included by canonical updater');
$create_statements = array('phpbb_pm_write_receipts'=>$tables['phpbb_pm_write_receipts']);
\PmReadMigrationFixture\check(strpos($source, "update_queue_pm_receipt_columns(\$operations, \$connection, \$dbname, \$table_prefix . 'pm_write_receipts');") !== false, 'Updater invokes notification migration');
foreach (array(false,true) as $has_notification_state)
{
	$GLOBALS['pm_read_columns'] = $has_notification_state ? array('notify_state') : array();
	$operations = array();
	\PmReadMigrationFixture\update_queue_pm_receipt_columns($operations,null,'fixture','custom_pm_write_receipts');
	\PmReadMigrationFixture\check(count($operations) === ($has_notification_state ? 0 : 1), 'Only missing notification state is added');
	if ($operations) { \PmReadMigrationFixture\check(strpos($operations[0], 'ADD `notify_state` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0') !== false, 'Historical requests remain ineligible for notification'); }
}
\PmReadMigrationFixture\check(strpos($tables['phpbb_pm_write_receipts'], 'privmsgs_text') === false && strpos($tables['phpbb_pm_write_receipts'], 'payload') === false, 'Receipts retain no PM body');
$start = strpos($source, 'foreach ($create_statements as $generic_table => $generic_sql)'); $end = strpos($source, 'update_queue_log_widths(', $start);
\PmReadMigrationFixture\check($start !== false && $end > $start, 'Actual missing-table planner');
$body = substr($source, $start, $end - $start); $table_prefix = 'custom_'; $dbname = 'fixture'; $connection = null;
foreach (array(false,true) as $pm_receipt_exists)
{
	$operations = array(); eval('namespace PmWriteReceiptsFixture;' . $body);
	\PmReadMigrationFixture\check(count($operations) === ($pm_receipt_exists ? 0 : 1), 'Missing receipt table only; repeat preserves receipts');
}
$dsn = getenv('PHPBB_PM_REPAIR_TEST_DSN');
if ($dsn !== false && $dsn !== '')
{
	// The shared column suite above has already validated the owned local DSN.
	$connection = new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
	try
	{
		$operations = array(); eval('namespace PmWriteReceiptsFixture;' . $body);
		foreach ($operations as $sql) { $connection->exec($sql); }
		$connection->exec("INSERT INTO custom_pm_write_receipts (request_token,request_hash,user_id,message_id,created_at) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,42,123)");
		$operations = array(); eval('namespace PmWriteReceiptsFixture;' . $body);
		\PmReadMigrationFixture\check(!$operations && (int)$connection->query('SELECT message_id FROM custom_pm_write_receipts')->fetchColumn() === 42, 'Native repeat preserves existing receipt');
		\PmReadMigrationFixture\check((int)$connection->query('SELECT notify_state FROM custom_pm_write_receipts')->fetchColumn() === 0, 'Fresh receipt schema defaults to no historical notification');
		foreach (array('MyISAM','InnoDB') as $engine)
		{
			$connection->exec('DROP TABLE custom_pm_write_receipts');
			$operations=array();
			\PmReadMigrationFixture\update_queue_pm_receipt_columns($operations,$connection,'fixture','custom_pm_write_receipts');
			\PmReadMigrationFixture\check(!$operations,'Missing receipt table is handled by canonical creation, not ALTER');
			$connection->exec('CREATE TABLE custom_pm_write_receipts (request_token CHAR(32) PRIMARY KEY,request_hash CHAR(64),user_id INTEGER,message_id INTEGER,created_at INTEGER) ENGINE='.$engine);
			$connection->exec("INSERT INTO custom_pm_write_receipts VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',1,42,123)");
			$operations=array();
			\PmReadMigrationFixture\update_queue_pm_receipt_columns($operations,$connection,'fixture','custom_pm_write_receipts');
			\PmReadMigrationFixture\check(count($operations)===1,'Native legacy receipt receives one additive column');
			foreach ($operations as $sql) { $connection->exec($sql); }
			\PmReadMigrationFixture\check((int)$connection->query('SELECT COUNT(*) FROM custom_pm_write_receipts WHERE user_id=1 AND message_id=42 AND created_at=123 AND notify_state=0')->fetchColumn()===1,'Migration preserves legacy receipt and cannot send historical mail');
			$connection->exec('UPDATE custom_pm_write_receipts SET notify_state=2');
			$operations=array();
			\PmReadMigrationFixture\update_queue_pm_receipt_columns($operations,$connection,'fixture','custom_pm_write_receipts');
			\PmReadMigrationFixture\check(!$operations && (int)$connection->query('SELECT notify_state FROM custom_pm_write_receipts')->fetchColumn()===2,'Repeated migration retains claimed state');
		}
	}
	finally { $connection->exec('DROP TABLE IF EXISTS custom_pm_write_receipts'); }
}
echo "PM write receipt migration checks passed.\n";
