<?php
// Actual ACP response and native import between independently zoned sessions.
$bt_source_zone=$mode==='timezone-east-west'?'+12:45':($mode==='timezone-west-east'?'-09:30':'+02:00');
$bt_target_zone=$mode==='timezone-east-west'?'-12:00':($mode==='timezone-west-east'?'+05:45':'-05:00');
$bt_fault=false;
class BackupTimezoneDatabase extends StyleDataDatabase {
 function sql_dedicated_connection(){
  $inner=$this->inner->sql_dedicated_connection();
  // Emulate a non-UTC server default on only the fixture's fresh connection.
  sd_check($inner->sql_query("SET SESSION time_zone='".$GLOBALS['bt_source_zone']."'"),'Fixture dedicated default zone');
  return new StyleDataConnection($inner);
 }
}
$db=new BackupTimezoneDatabase($db->inner);
sd_sql("SET SESSION time_zone='".$bt_source_zone."'");
sd_sql("CREATE TABLE fixture_timezone(id INT PRIMARY KEY,instant TIMESTAMP(6) NULL DEFAULT '2008-06-01 12:34:56.123456',wall_clock DATETIME(6) NULL DEFAULT '2008-06-01 12:34:56.123456',optional_instant TIMESTAMP(6) NULL DEFAULT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
sd_sql("INSERT INTO fixture_timezone VALUES(1,'2008-06-01 12:34:56.123456','2008-06-01 12:34:56.123456',NULL),(2,'2037-01-02 03:04:05.654321','2037-01-02 03:04:05.654321','1971-01-02 03:04:05.000001'),(3,NULL,NULL,NULL),(4,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00')");
sd_sql('INSERT INTO fixture_timezone(id) VALUES(5)');
$bt_query='SELECT id,UNIX_TIMESTAMP(instant) AS instant_epoch,MICROSECOND(instant) AS instant_fraction,wall_clock,UNIX_TIMESTAMP(optional_instant) AS optional_epoch FROM fixture_timezone ORDER BY id';
$bt_expected=phpbb_acl_rows($peer,$bt_query);
sd_check($db->sql_query("SET SESSION time_zone='+03:30'"),'Caller has an independent zone');
sd_check($db->sql_query('START TRANSACTION'),'Caller owns a pending transaction');
sd_check($db->sql_query("UPDATE fixture_roundtrip SET content='timezone caller pending' WHERE id=0"),'Caller has uncommitted data');
if($mode==='timezone-utc-failure'){
 $sd_failure="SET SESSION time_zone='+00:00'";
 $sd_hook=function($sql){if($sql==="SET SESSION time_zone='+00:00'"){$GLOBALS['bt_fault']=true;}};
}
if($mode==='timezone-read-failure'){
 $sd_hook=function($sql){if($sql==='SELECT * FROM `fixture_timezone`'){$GLOBALS['bt_fault']=true;$GLOBALS['sd_failure']=$sql;}};
}
function bt_definition($database){
 sd_check($database->sql_query("SET SESSION time_zone='+00:00'"),'Read schema defaults in canonical zone');
 return phpbb_acl_rows($database,'SHOW CREATE TABLE fixture_timezone')[0]['Create Table'];
}
function bt_finish($restore,$dump,$mode,$denied){
 global$db,$peer,$bt_expected,$bt_query,$bt_fault,$bt_source_zone,$bt_target_zone;
 sd_check(phpbb_acl_rows($peer,$bt_query)===$bt_expected,'Backup never changes timestamp source values');
 $pending=phpbb_acl_rows($db,'SELECT content FROM fixture_roundtrip WHERE id=0');
 sd_check($pending[0]['content']==='timezone caller pending','Caller transaction remains pending and usable');
 sd_check($db->sql_query('ROLLBACK'),'Only fixture caller rolls back its pending transaction');
 if($denied){sd_check($bt_fault,'Actual timezone setup or row-read failure reached');}
 else{
  $actual=phpbb_acl_rows($restore,$bt_query);
  sd_check($actual===($mode==='timezone-structure'?array():$bt_expected),'Import preserves exact timestamp instants/fractions/NULL/zero and literal DATETIME values');
  sd_check(bt_definition($peer)===bt_definition($restore),'Timestamp and DATETIME literal defaults retain their original meaning');
  sd_check($restore->sql_query('INSERT INTO fixture_timezone(id) VALUES(99)'),'Restored defaults are executable');
  $default=phpbb_acl_rows($restore,str_replace('ORDER BY id','WHERE id=99',$bt_query))[0];
  $expected=$bt_expected[4];$default['id']=$default[0]=$expected['id'];
  sd_check($default===$expected,'New rows use the exact original timestamp and wall-clock defaults');
 }
 echo 'Native backup timezone ',substr($mode,9),': actual ACP/cross-zone/instant/default/DATETIME/caller/importer/failure roundtrip PASS; ',json_encode(array('source_zone'=>$bt_source_zone,'target_zone'=>$bt_target_zone,'rows'=>count($bt_expected),'denied'=>$denied,'production_access'=>false)),"\n";
}
