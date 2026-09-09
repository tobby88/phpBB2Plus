<?php
namespace UserRemovalMigrationFixture;
function removal_check($ok,$message) { if (!$ok) { throw new \RuntimeException($message); } }
function update_table_exists($connection,$database,$table) { return in_array($table,$GLOBALS['removal_existing'],true); }
$root=dirname(dirname(__DIR__));
$source=file_get_contents($root.'/update/update_from_153a.php');
$begin=strpos($source,'function update_extract_create_tables('); $end=strpos($source,'function update_extract_seed_statements(',$begin);
removal_check($begin!==false && $end>$begin,'Canonical table extraction found');
eval('namespace UserRemovalMigrationFixture;'.substr($source,$begin,$end-$begin));
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
$all=update_extract_create_tables($schema); $create_statements=array();
foreach (array('user_removals','user_removal_items') as $table)
{
    removal_check(isset($all['phpbb_'.$table]),'Updater includes '.$table);
    $create_statements['phpbb_'.$table]=$all['phpbb_'.$table];
}
$begin=strpos($source,'foreach ($create_statements as $generic_table => $generic_sql)');
$end=strpos($source,'update_queue_log_widths(',$begin);
removal_check($begin!==false && $end>$begin,'Actual missing-table planner found');
$body=substr($source,$begin,$end-$begin); $connection=null; $dbname='fixture'; $table_prefix='custom_';
foreach (array(array(),array('custom_user_removals'),array('custom_user_removals','custom_user_removal_items')) as $removal_existing)
{
    $operations=array(); eval('namespace UserRemovalMigrationFixture;'.$body);
    removal_check(count($operations)===2-count($removal_existing),'Missing tables only; partial migration resumes and repeated run is empty');
    foreach ($operations as $sql)
    {
        removal_check(strpos($sql,'CREATE TABLE custom_user_removal')===0 && strpos($sql,'phpbb_')===false,'Custom prefix is honored');
        removal_check(!preg_match('/\b(?:DELETE|UPDATE|DROP|TRUNCATE|INSERT)\b/i',$sql),'Journal migration does not alter existing records');
        removal_check(strpos($sql,'PRIMARY KEY')!==false && strpos($sql,'utf8mb4')!==false,'Canonical keys and Unicode storage retained');
    }
}
removal_check(strpos($create_statements['phpbb_user_removals'],'UNIQUE KEY user_id')!==false,'One pending removal per account');
removal_check(preg_match('/related_id int\(11\) NOT NULL/',$create_statements['phpbb_user_removal_items'])===1,'Guest PM recipient marker remains signed');
removal_check(strpos($create_statements['phpbb_user_removals'],'user_password')===false,'No stored credential column in journal');
echo "Additive account removal journal migration checks passed.\n";
