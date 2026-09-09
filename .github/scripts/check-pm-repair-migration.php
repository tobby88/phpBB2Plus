<?php
namespace PmRepairMigrationFixture;
function pm_migration_check($ok,$message) { if (!$ok) { throw new \RuntimeException($message); } }
function update_table_exists($connection,$database,$table) {
    if ($connection instanceof \PDO) {
        $statement=$connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $statement->execute(array($table));return (int)$statement->fetchColumn()===1;
    }
    return in_array($table,$GLOBALS['pm_migration_existing'],true);
}
$root=dirname(dirname(__DIR__));
$source=file_get_contents($root.'/update/update_from_153a.php');
$begin=strpos($source,'function update_extract_create_tables('); $end=strpos($source,'function update_extract_seed_statements(',$begin);
pm_migration_check($begin!==false && $end>$begin,'Canonical table extraction found');
eval('namespace PmRepairMigrationFixture;'.substr($source,$begin,$end-$begin));
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
$all=update_extract_create_tables($schema); $create_statements=array();
foreach (array('pm_repair_jobs','pm_repair_items') as $table)
{
    pm_migration_check(isset($all['phpbb_'.$table]),'Updater includes '.$table);
    $create_statements['phpbb_'.$table]=$all['phpbb_'.$table];
}
$begin=strpos($source,'foreach ($create_statements as $generic_table => $generic_sql)');
$end=strpos($source,'update_queue_log_widths(',$begin);
pm_migration_check($begin!==false && $end>$begin,'Actual missing-table planner found');
$body=substr($source,$begin,$end-$begin); $connection=null; $dbname='fixture'; $table_prefix='custom_';
foreach (array(array(),array('custom_pm_repair_jobs'),array('custom_pm_repair_jobs','custom_pm_repair_items')) as $pm_migration_existing)
{
    $operations=array(); eval('namespace PmRepairMigrationFixture;'.$body);
    pm_migration_check(count($operations)===2-count($pm_migration_existing),'Missing tables only; partial migration resumes and repeated run is empty');
    foreach ($operations as $sql)
    {
        pm_migration_check(strpos($sql,'CREATE TABLE custom_pm_repair')===0 && strpos($sql,'phpbb_')===false,'Custom prefix is honored');
        pm_migration_check(!preg_match('/\b(?:DELETE|UPDATE|DROP|TRUNCATE|INSERT)\b/i',$sql),'Journal migration does not alter existing records');
        pm_migration_check(strpos($sql,'PRIMARY KEY')!==false && strpos($sql,'utf8mb4')!==false,'Canonical keys and Unicode storage retained');
    }
}
pm_migration_check(strpos($create_statements['phpbb_pm_repair_jobs'],'UNIQUE KEY message_id')!==false,'One pending repair per message');
pm_migration_check(preg_match('/to_user_id int\(11\) NOT NULL/',$create_statements['phpbb_pm_repair_jobs'])===1,'Deleted PM participants remain signed');
pm_migration_check(strpos($create_statements['phpbb_pm_repair_jobs'],'user_password')===false,'No stored credential column in journal');
echo "Additive PM repair journal migration checks passed.\n";
$native_dsn=getenv('PHPBB_PM_MIGRATION_TEST_DSN');
if($native_dsn!==false && $native_dsn!=='') {
    pm_migration_check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_pm_migration_[a-f0-9]{16};charset=utf8mb4$/D',$native_dsn)===1,'Only owned native migration schemas');
    $connection=new \PDO($native_dsn,'root','',array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
    try {
        foreach(array(0,1,2) as $existing_count) {
            $connection->exec('DROP TABLE IF EXISTS custom_pm_repair_items,custom_pm_repair_jobs');
            $position=0;
            foreach($create_statements as $sql) { if($position++<$existing_count){$connection->exec(str_replace('phpbb_','custom_',$sql));} }
            if($existing_count>0){$connection->exec("INSERT INTO custom_pm_repair_jobs VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',42,'deleted_users','prepared',8,-1,1,123,456,8,789)");}
            $operations=array();eval('namespace PmRepairMigrationFixture;'.$body);
            pm_migration_check(count($operations)===2-$existing_count,'Actual planner uses current native table existence');
            foreach($operations as $sql){$connection->exec($sql);}
            pm_migration_check((int)$connection->query('SELECT COUNT(*) FROM custom_pm_repair_jobs')->fetchColumn()===($existing_count>0?1:0),'Existing recovery records preserved');
            if($existing_count>0){pm_migration_check((int)$connection->query('SELECT to_user_id FROM custom_pm_repair_jobs')->fetchColumn()===-1,'Signed sentinel preserved by actual DDL');}
            $operations=array();eval('namespace PmRepairMigrationFixture;'.$body);pm_migration_check(!$operations,'Repeated actual native migration is empty');
            $indexes=$connection->query('SHOW INDEX FROM custom_pm_repair_jobs')->fetchAll(\PDO::FETCH_ASSOC);$message_unique=false;
            foreach($indexes as $index){if($index['Key_name']==='message_id' && (int)$index['Non_unique']===0 && $index['Column_name']==='message_id'){$message_unique=true;}}
            pm_migration_check($message_unique,'Native journal has one pending job per message');
        }
        echo "Native missing/partial/repeated PM journal migration passed.\n";
    } finally {$connection->exec('DROP TABLE IF EXISTS custom_pm_repair_items,custom_pm_repair_jobs');}
}
