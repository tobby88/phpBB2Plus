<?php
// Real driver, canonical owned tables, independent peer; never a live forum.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('USER'=>0,'MOD'=>2,'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth_access','FORUMS_TABLE'=>'fixture_forums') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_maintenance_roles.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_ROLES_NATIVE')!=='1'){echo "Native role checks require an explicitly enabled disposable MySQL/MariaDB fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_ROLES_PORT')?:'3306';$password=getenv('PHPBB_ROLES_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_roles_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class RnConnection {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->db,$m),$args);}
 function sql_query($sql,$tx=false){
  $GLOBALS['rn_queries'][]=$sql;if(is_callable($GLOBALS['rn_hook'])){call_user_func($GLOBALS['rn_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['rn_write']===$GLOBALS['rn_fail']){return false;}
  if($GLOBALS['rn_failure']!==''&&strpos($sql,$GLOBALS['rn_failure'])===0){return false;}
  if($sql==='COMMIT'&&$GLOBALS['rn_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);if(!$r){$GLOBALS['rn_error']=$this->db->sql_error();}return $sql==='COMMIT'&&$GLOBALS['rn_commit']==='ack'?false:$r;
 }
}
class RnDatabase extends sql_db {function sql_dedicated_connection(){$c=new RnConnection(parent::sql_dedicated_connection());$GLOBALS['rn_writer']=$c;return $c;}}
$rn_main=new RnDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);
$rn_tables=array('users','sessions','jr','groups','user_group','auth_access','forums','config');
$rn_hook=null;$rn_write=$rn_fail=0;$rn_failure=$rn_commit='';$rn_queries=array();
function rn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Native fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function rn_rows($sql){$rows=phpbb_acl_rows($GLOBALS['peer'],$sql);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function rn_snap(){$out=array();foreach($GLOBALS['rn_tables'] as $s){$rows=rn_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;}
function rn_insert($table,$values){
 foreach(rn_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}rn_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}
function rn_reset($actor='root',$scenario='repair'){
 global $userdata,$rn_hook,$rn_write,$rn_fail,$rn_failure,$rn_commit,$rn_queries,$rn_actor;
 $rn_hook=null;$rn_write=$rn_fail=0;$rn_failure=$rn_commit='';$rn_queries=array();$GLOBALS['rn_error']=null;
 rn_sql('START TRANSACTION');foreach($GLOBALS['rn_tables'] as $s){rn_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1=>2,0=>2,1=>1,2=>1,10=>0,11=>2,12=>2,13=>2,14=>2,15=>2,16=>2,17=>0,18=>3,20=>0) as $id=>$level){rn_insert('fixture_users',array('user_id'=>$id,'username'=>$id===10?'<script>Grüße</script>':'fixture-'.$id,'user_level'=>$level,'user_active'=>$id===17?0:1));}
 foreach(array(10,11) as $id){rn_insert('fixture_groups',array('group_id'=>$id,'group_name'=>'fixture-'.$id));}rn_insert('fixture_forums',array('forum_id'=>5,'forum_name'=>'fixture'));
 foreach(array(array(10,5),array(11,999),array(999,5)) as $g){rn_insert('fixture_auth_access',array('group_id'=>$g[0],'forum_id'=>$g[1],'auth_mod'=>1));}
 foreach(array(array(10,10,0),array(12,10,0),array(13,10,1),array(14,999,0),array(15,11,0),array(16,10,2),array(17,10,0)) as $m){rn_insert('fixture_user_group',array('user_id'=>$m[0],'group_id'=>$m[1],'user_pending'=>$m[2]));}
 $rn_actor=$actor==='root'?1:20;
 foreach(rn_rows('SELECT user_id FROM fixture_users') as $r){$id=(int)$r['user_id'];rn_insert('fixture_sessions',array('session_id'=>$id===$rn_actor?'fixture-sid':'session-'.$id,'session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>1));}
 if($actor==='junior'){rn_insert('fixture_jr',array('user_id'=>20,'user_jr_admin'=>md5('GeneralDB_Maintenanceadmin_db_maintenance.php')));rn_insert('fixture_sessions',array('session_id'=>'other-self','session_user_id'=>20,'session_logged_in'=>1,'session_admin'=>1));}
 if($scenario==='promote'){rn_insert('fixture_user_group',array('user_id'=>20,'group_id'=>10,'user_pending'=>0));}if($scenario==='demote'){rn_sql('UPDATE fixture_users SET user_level=2 WHERE user_id=20');}
 rn_insert('fixture_config',array('config_name'=>'board_disable','config_value'=>'0'));rn_sql('COMMIT');
 $userdata=array('user_id'=>$rn_actor,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-sid');$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-sid');
}
function rn_run(){try{return dbmtnc_synchronize_mod_state($GLOBALS['rn_main'],$_POST);}catch(PhpbbAclException $e){return 'error';}}
function rn_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function rn_revoke($kind){$id=$GLOBALS['rn_actor'];$map=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id='.$id,'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,
 'delegation'=>'DELETE FROM fixture_jr WHERE user_id='.$id,'missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=99 WHERE session_id='fixture-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-sid'",'acp'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-sid'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID' WHERE session_id='fixture-sid'");return $map[$kind];}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
 foreach($rn_tables as $s){$canonical='phpbb_'.($s==='jr'?'jr_admin_users':$s);ats_check(preg_match('/CREATE TABLE '.$canonical.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical '.$s);rn_sql(str_replace($canonical,'fixture_'.$s,$m[0]));preg_match_all('/ALTER TABLE '.$canonical.'\s+[\s\S]*?;/',$schema,$extra);foreach($extra[0] as $ddl){rn_sql(str_replace($canonical,'fixture_'.$s,$ddl));}}
 rn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;$rn_expected=array();
 foreach(array(array('root','repair'),array('junior','repair'),array('junior','promote'),array('junior','demote')) as $setup){
  list($actor,$scenario)=$setup;rn_reset($actor,$scenario);$before=rn_snap();$result=rn_run();ats_check(is_array($result),'Authorized '.$actor.' '.$scenario.' '.json_encode($rn_error));$after=rn_snap();$writes=$rn_write;$boundaries=array_values(array_filter($rn_queries,'rn_boundary'));
  $rn_expected[$actor.'|'.$scenario]=$after;
  ats_check(count($result['changed'])===($scenario==='repair'?7:8)&&!$result['skipped'],'Exact ordinary role repairs');
  foreach(array(-1=>2,0=>2,1=>1,2=>1,10=>2,11=>0,12=>2,13=>0,14=>0,15=>0,16=>0,17=>2,18=>3,20=>$scenario==='promote'?2:0) as $id=>$level){ats_check((int)rn_rows('SELECT user_level FROM fixture_users WHERE user_id='.$id)[0]['user_level']===$level,'Derived role '.$id);}
  ats_check(count(rn_rows("SELECT * FROM fixture_sessions WHERE session_id='fixture-sid'"))===1,'Exact acting session preserved');
  foreach(array('groups','user_group','auth_access','forums','jr','config') as $s){ats_check($before[$s]===$after[$s],'Unrelated state unchanged '.$s);}
  $again=rn_run();ats_check(is_array($again)&&!$again['changed']&&!$again['skipped']&&rn_snap()===$after,'No-op repeat');
  for($n=1;$n<=$writes;$n++){rn_reset($actor,$scenario);$before=rn_snap();$rn_fail=$n;ats_check(rn_run()==='error'&&rn_snap()===$before,'Whole repair rollback at write '.$n);$cases++;}
  foreach(array('fail','ack') as $kind){rn_reset($actor,$scenario);$before=rn_snap();$rn_commit=$kind;ats_check(rn_run()==='error'&&rn_snap()===($kind==='fail'?$before:$after),'COMMIT whole before/after, never false success');$rn_commit='';ats_check(is_array(rn_run())&&rn_snap()===$after,'Safe retry after uncertain commit');$cases++;}
  foreach(array('SAVEPOINT','RELEASE SAVEPOINT') as $failure){rn_reset($actor,$scenario);$before=rn_snap();$rn_failure=$failure;ats_check(rn_run()==='error'&&rn_snap()===$before,'Failed transaction boundary rolls back');$cases++;}
  $authority=$actor==='root'?'role':'delegation';
  foreach(array('inactive','missing','foreign','logout','acp','case',$authority) as $kind){rn_reset($actor,$scenario);rn_sql(rn_revoke($kind));$before=rn_snap();ats_check(rn_run()==='error'&&rn_snap()===$before,'Exact current entry authority '.$kind);$cases++;}
  if($scenario==='repair'){
   foreach(array('missing',$authority) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
    rn_reset($actor,$scenario);$seen=0;$reached=$blocked=false;$revoked=null;
    $rn_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!rn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['rn_hook']=null;$reached=true;
     if(!$GLOBALS['peer']->sql_query(rn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Real independent lock timeout');$blocked=true;}else{$revoked=rn_snap();}};
    $result=rn_run();ats_check($reached,'Every write and commit authority boundary reached');
    if($blocked){ats_check(is_array($result)&&rn_snap()===$after,'Authority revocation serializes after complete repair');rn_sql(rn_revoke($kind));$serialized++;}
    else{ats_check($result==='error'&&rn_snap()===$revoked,'Effective revocation undoes every role/session change, retaining peer changes');}$cases++;
   }}
  }
  echo $actor.' '.$scenario." native role atomicity/current authority passed\n";
 }
 // Independent target/ACL changes, before locks and between the two writes.
 foreach(array('promotion-before-lock','promotion-before-update','removed-user','new-grant','revoked-before-delete','revoked-before-update') as $change){
  rn_reset();$before=rn_snap();$reached=$blocked=false;
  $rn_hook=function($sql)use($change,&$reached,&$blocked){
   $match=$change==='promotion-before-lock'||$change==='removed-user'?strpos($sql,'SELECT user_id, username, user_level FROM fixture_users WHERE user_id = 10 FOR UPDATE')===0:
    (strpos($change,'before-update')!==false?strpos($sql,'UPDATE fixture_users SET user_level = CASE')===0:strpos($sql,'DELETE FROM fixture_sessions WHERE session_user_id = 10')===0);
   if(!$match){return;}$GLOBALS['rn_hook']=null;$reached=true;
   $query=strpos($change,'promotion')===0?'UPDATE fixture_users SET user_level=1 WHERE user_id=10':($change==='removed-user'?'DELETE FROM fixture_users WHERE user_id=10':($change==='new-grant'?'INSERT INTO fixture_user_group (user_id,group_id,user_pending) VALUES (11,10,0)':'DELETE FROM fixture_user_group WHERE user_id=10'));
   if(!$GLOBALS['peer']->sql_query($query)){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Target/grant native lock timeout');$blocked=true;}
  };
  $out=rn_run();ats_check(is_array($out)&&$reached,'Concurrent target/grant case reached');
  if($blocked){ats_check(rn_snap()===$rn_expected['root|repair'],'Blocked target change leaves a complete ordinary repair');$serialized++;}
  elseif(strpos($change,'promotion')===0){ats_check((int)rn_rows('SELECT user_level FROM fixture_users WHERE user_id=10')[0]['user_level']===ADMIN&&count(rn_rows('SELECT * FROM fixture_sessions WHERE session_user_id=10'))===1,'Late administrator and its session preserved');}
  elseif($change==='removed-user'){ats_check(!rn_rows('SELECT * FROM fixture_users WHERE user_id=10')&&count(rn_rows('SELECT * FROM fixture_sessions WHERE session_user_id=10'))===1,'Deleted account not recreated or needlessly expired');}
  elseif($change==='new-grant'){ats_check((int)rn_rows('SELECT user_level FROM fixture_users WHERE user_id=11')[0]['user_level']===MOD&&count(rn_rows('SELECT * FROM fixture_sessions WHERE session_user_id=11'))===1,'New grant prevents stale demotion/expiration');}
  else{ats_check((int)rn_rows('SELECT user_level FROM fixture_users WHERE user_id=10')[0]['user_level']===USER&&count(rn_rows('SELECT * FROM fixture_sessions WHERE session_user_id=10'))===1,'Cancelled role repair rolls back its session expiration');}
  $cases++;
 }
 foreach(array('promote','demote') as $scenario){foreach(array('missing','delegation') as $kind){
  rn_reset('junior',$scenario);$reached=$blocked=false;$revoked=null;
  $rn_hook=function($sql)use($kind,&$reached,&$blocked,&$revoked){if(strpos($sql,'UPDATE fixture_users SET user_level = CASE')!==0||strpos($sql,'WHERE user_id = 20 ')===false){return;}$GLOBALS['rn_hook']=null;$reached=true;
   if(!$GLOBALS['peer']->sql_query(rn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Self repair native authority lock');$blocked=true;}else{$revoked=rn_snap();}};
  $out=rn_run();ats_check($reached,'Self role write reached');ats_check($blocked?is_array($out)&&rn_snap()===$rn_expected['junior|'.$scenario]:$out==='error'&&rn_snap()===$revoked,'Self role and all earlier repairs roll back on effective revocation');$cases++;
 }}
 rn_reset();$revoked=null;$rn_failure='ROLLBACK TO SAVEPOINT';
 $rn_hook=function($sql)use(&$revoked){if(strpos($sql,'DELETE FROM fixture_sessions WHERE session_user_id = 10')!==0){return;}$GLOBALS['rn_hook']=null;rn_sql('INSERT INTO fixture_user_group (user_id,group_id,user_pending) VALUES (11,10,0)');$revoked=rn_snap();};
 ats_check(rn_run()==='error'&&$revoked!==null&&rn_snap()===$revoked,'Failed savepoint rollback aborts entire repair, not peer grant');
 rn_reset();$before=rn_snap();$killed=false;
 $rn_hook=function($sql)use(&$killed){if(strpos($sql,'UPDATE fixture_users SET user_level = CASE')!==0||strpos($sql,'WHERE user_id = 11 ')===false){return;}$GLOBALS['rn_hook']=null;$killed=true;rn_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['rn_writer']->db_connect_id));};
 ats_check(rn_run()==='error'&&$killed&&rn_snap()===$before,'Actual owner connection loss rolls back earlier roles and sessions');
 rn_reset();$contended=false;$rn_hook=function($sql)use(&$contended){if(strpos($sql,'SELECT role_user')!==0){return;}$GLOBALS['rn_hook']=null;$other=new attach_mutation_lock($GLOBALS['rn_main'],false);$contended=!$other->acquired;$other->release();};
 ats_check(is_array(rn_run())&&$contended&&rn_snap()===$rn_expected['root|repair'],'Independent competing writer cannot enter');
 foreach($rn_tables as $s){if($s==='config'){continue;}rn_reset();rn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=rn_snap();ats_check(rn_run()==='error'&&rn_snap()===$before,'Reject nontransactional participant '.$s);rn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 foreach(array(false,true) as $fail){rn_reset();$rn_hook=function($sql){if(strpos($sql,'UPDATE fixture_users SET user_level')!==0){return;}$GLOBALS['rn_hook']=null;rn_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");};$before=rn_snap();$rn_fail=$fail?2:0;$out=rn_run();ats_check($fail?$out==='error':is_array($out),'Availability case completes as expected');$after=rn_snap();ats_check($after['config'][0]['config_value']==='1','Concurrent external board disabling survives');if($fail){unset($before['config'],$after['config']);ats_check($before===$after,'Failed role repair does not roll back unrelated peer operation');}}
 echo 'Native moderator synchronization: '.$cases.' boundary/failure cases, '.$serialized." serialized changes passed.\n";
}finally{$rn_hook=null;$rn_fail=0;$rn_failure=$rn_commit='';$rn_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
