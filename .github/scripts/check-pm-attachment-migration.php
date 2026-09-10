<?php
namespace PmReadMigrationFixture;
// Reuse metadata adapters only; execute the actual additive attachment planner.
$fixture = file_get_contents(__DIR__ . '/check-pm-read-migration.php');
$end = strpos($fixture, '$root = dirname');
if ($end === false) { throw new \RuntimeException('Migration fixture boundary'); }
eval(substr($fixture,5,$end-5));
$source = file_get_contents(dirname(dirname(__DIR__)) . '/update/update_from_153a.php');
foreach (array('update_queue_column'=>'update_queue_topic_notification_columns','update_queue_pm_attachment_columns'=>'update_queue_default') as $name=>$next)
{
	$a = strpos($source,'function '.$name.'('); $b = strpos($source,'function '.$next.'(',$a);
	check($a !== false && $b > $a,'Actual attachment migration planner');
	eval('namespace PmReadMigrationFixture;'.substr($source,$a,$b-$a));
}
check(strpos($source,"update_queue_pm_attachment_columns(\$operations, \$connection, \$dbname, \$table_prefix . 'attachments_desc');")!==false,'Upgrade calls attachment reservation planner');
$schema = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/install/schemas/mysql_schema.sql');
preg_match('/CREATE TABLE phpbb_attachments_desc \((.*?)\) ENGINE/s',$schema,$table);
check(isset($table[1]),'Canonical attachment table');
$definitions = array();
foreach (array('pm_write_token','pm_write_slot') as $field)
{
	check(preg_match('/^\s+'.$field.'\s+([^,]+),/m',$table[1],$match)===1,'Canonical reservation field');
	$definitions[$field]=$match[1];
}
check(strpos($table[1],'UNIQUE KEY pm_write_slot (pm_write_token, pm_write_slot)')!==false,'Unique operation slot in installer');
foreach (range(0,3) as $existing)
{
	$pm_read_columns=array_slice(array_keys($definitions),0,min(2,$existing));$pm_read_index=$existing===3;$operations=array();
	update_queue_pm_attachment_columns($operations,null,'fixture','custom_attachments_desc');
	check(count($operations)===3-$existing,'Only missing attachment reservation schema');
	foreach($operations as $sql){check(strpos($sql,'ALTER TABLE `custom_attachments_desc` ADD ')===0,'Additive custom-prefix changes only');}
}
$dsn=getenv('PHPBB_PM_REPAIR_TEST_DSN');
if($dsn!==false&&$dsn!=='')
{
	check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_pm_repair_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Owned local schema only');
	$connection=new \PDO($dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
	try
	{
		foreach(array('MyISAM','InnoDB') as $engine)
		{
			foreach(range(0,3) as $existing)
			{
				$connection->exec('DROP TABLE IF EXISTS custom_attachments_desc');
				$operations=array();update_queue_pm_attachment_columns($operations,$connection,'fixture','custom_attachments_desc');
				check(!$operations,'Absent legacy table is not altered');
				$connection->exec('CREATE TABLE custom_attachments_desc (attach_id INTEGER PRIMARY KEY,physical_filename VARCHAR(255)) ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4');
				$connection->exec("INSERT INTO custom_attachments_desc VALUES(1,'Grüße.txt'),(2,'Grüße.txt')");
				foreach(array_slice($definitions,0,min(2,$existing),true) as $field=>$definition){$connection->exec('ALTER TABLE custom_attachments_desc ADD '.$field.' '.$definition);}
				if($existing===3){$connection->exec('ALTER TABLE custom_attachments_desc ADD UNIQUE KEY pm_write_slot (pm_write_token,pm_write_slot)');}
				$operations=array();update_queue_pm_attachment_columns($operations,$connection,'fixture','custom_attachments_desc');
				check(count($operations)===3-$existing,'Native missing/partial plan');
				foreach($operations as $sql){$connection->exec($sql);}
				check((int)$connection->query("SELECT COUNT(*) FROM custom_attachments_desc WHERE physical_filename='Grüße.txt' AND pm_write_token IS NULL AND pm_write_slot=0")->fetchColumn()===2,'All historical registrations and UTF8 names unchanged');
				$operations=array();update_queue_pm_attachment_columns($operations,$connection,'fixture','custom_attachments_desc');check(!$operations,'Repeated migration no-op');
				$connection->exec("UPDATE custom_attachments_desc SET pm_write_token='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE attach_id=1");
				$caught=false;try{$connection->exec("UPDATE custom_attachments_desc SET pm_write_token='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE attach_id=2");}catch(\PDOException $e){$caught=true;}
				check($caught,'Duplicate job slot rejected');
				$connection->exec("UPDATE custom_attachments_desc SET pm_write_token='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',pm_write_slot=1 WHERE attach_id=2");
			}
		}
	}
	finally{$connection->exec('DROP TABLE IF EXISTS custom_attachments_desc');}
}
echo "Additive attachment reservation migration checks passed.\n";
