<?php
// Canonical installer tables and strict seed values shared by list/sort checks.
function uacp_list_fixture_tables()
{
    return array('config','sessions','users','forums','topics','posts','auth_access','user_group','privmsgs','attachments','attachments_desc');
}
function uacp_list_fixture_install($db,$root,$prefix)
{
    if(preg_match('/^[A-Za-z0-9_]{1,32}$/D',$prefix)!==1){throw new RuntimeException('Own fixture prefix required');}
    $ddl=file_get_contents($root.'install/schemas/mysql_schema.sql');
    foreach(uacp_list_fixture_tables()as$name){if(preg_match('/^CREATE TABLE phpbb_'.$name.'\s*\([\s\S]*?;/m',$ddl,$m)!==1||!$db->sql_query(str_replace('phpbb_'.$name,$prefix.$name,$m[0]))){throw new RuntimeException('Actual canonical fixture schema required');}}
}
function uacp_list_fixture_insert($db,$table,$values)
{
    static$cache=array();
    if(preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)!==1){throw new RuntimeException('Own fixture table required');}
    $key=$db->dbname.':'.$table;
    if(!isset($cache[$key])){$r=$db->sql_query('SHOW COLUMNS FROM '.$table);if(!$r){throw new RuntimeException('Fixture columns unavailable');}try{$cache[$key]=$db->sql_fetchrowset($r);}finally{$db->sql_freeresult($r);}}
    foreach($cache[$key]as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/int|float|decimal|double|numeric|bit/i',$c['Type'])?0:'';}}
    $set=array();foreach($values as$column=>$value){$set[]='`'.$column.'`='.($value===null?'NULL':"'".$db->sql_escape((string)$value)."'");}
    if(!$db->sql_query('INSERT INTO '.$table.' SET '.implode(',',$set))){$error=$db->sql_error();throw new RuntimeException('Own fixture seed failed ('.(int)$error['code'].'): '.$error['message']);}
}
