<?php
namespace ModerationLogMigrationFixture;

// Exercise the actual planner without loading credentials or applying SQL.
function migration_check($ok,$message) { if(!$ok) { throw new \RuntimeException($message); } }
function update_table_exists($connection,$database,$table) { return $GLOBALS['log_fixture_exists']; }
function update_column_max_length($connection,$database,$table,$column) { return $GLOBALS['log_fixture_columns'][$column]['width']; }
function update_quote_identifier($value) { return '`'.str_replace('`','``',$value).'`'; }
function mysqli_real_escape_string($connection,$value) { return addslashes($value); }
function update_scalar($connection,$sql)
{
	if($sql==='SELECT VERSION()') { return $GLOBALS['log_fixture_version']; }
	migration_check(preg_match("/^SELECT ([A-Z_]+).*COLUMN_NAME = '([^']+)'$/s",$sql,$match)===1,'Bounded metadata query');
	return $GLOBALS['log_fixture_columns'][$match[2]][$match[1]];
}
$root=dirname(dirname(__DIR__));
$source=file_get_contents($root.'/update/update_from_153a.php');
$start=strpos($source,'function update_queue_log_widths('); $end=strpos($source,'function update_column_extra(',$start);
migration_check($start!==false && $end>$start,'Locate actual migration planner');
eval('namespace ModerationLogMigrationFixture;'.substr($source,$start,$end-$start));
migration_check(strpos($source,"update_queue_log_widths(\$operations, \$connection, \$dbname, \$table_prefix . 'logs');")!==false,'Planner is wired into the real updater');
foreach(array('8.4.0','10.11.14-MariaDB') as $log_fixture_version)
{
	$log_fixture_exists=true; $mariadb=strpos($log_fixture_version,'MariaDB')!==false;
	$log_fixture_columns=array(
		'username'=>array('width'=>32,'IS_NULLABLE'=>'YES','COLUMN_DEFAULT'=>$mariadb?"'O\\'Reilly\\\\draft'":"O'Reilly\\draft",'COLLATION_NAME'=>'utf8mb4_unicode_ci','COLUMN_COMMENT'=>"Author's label"),
		'user_ip'=>array('width'=>8,'IS_NULLABLE'=>'NO','COLUMN_DEFAULT'=>$mariadb?"''":'','COLLATION_NAME'=>'utf8mb4_bin','COLUMN_COMMENT'=>'')
	);
	$ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs');
	migration_check(count($ops)===2,'Both narrow columns are widened');
	migration_check(strpos($ops[0],"VARCHAR(255) COLLATE `utf8mb4_unicode_ci` NULL DEFAULT 'O\\'Reilly\\\\draft' COMMENT 'Author\\'s label'")!==false,'Name width preserves escaped literal default, nullability, collation and comment: '.$log_fixture_version);
	migration_check(strpos($ops[1],"VARCHAR(45) COLLATE `utf8mb4_bin` NOT NULL DEFAULT ''")!==false,'IP width preserves its existing constraints');
	$log_fixture_columns['username']['width']=255; $log_fixture_columns['user_ip']['width']=45;
	$ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs'); migration_check(!$ops,'Already current schema is a no-op');
	$log_fixture_columns['username']['width']=500; $log_fixture_columns['user_ip']['width']=100;
	$ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs'); migration_check(!$ops,'Never shrink a custom wider column');
	$log_fixture_columns['username']['width']=32; $log_fixture_columns['username']['COLUMN_DEFAULT']=$mariadb?'NULL':null;
	$ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs'); migration_check(strpos($ops[0],' NULL DEFAULT NULL')!==false,'Preserve SQL NULL');
	$log_fixture_columns['username']['COLUMN_DEFAULT']=$mariadb?"'NULL'":'NULL';
	$ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs'); migration_check(strpos($ops[0],"DEFAULT 'NULL'")!==false,'Do not confuse literal NULL string with SQL NULL');
	$log_fixture_exists=false; $ops=array(); update_queue_log_widths($ops,null,'fixture','fixture_logs'); migration_check(!$ops,'Absent table is left to the canonical CREATE TABLE planner');
}
echo "Moderation-log width migration planner checks passed.\n";
