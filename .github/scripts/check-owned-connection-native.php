<?php
// Actual common writer and CAPTCHA consumer; only our own loopback schema.
if(PHP_SAPI!=='cli'||getenv('PHPBB_OWNED_NATIVE')!=='1'){echo "Owned connection checks require explicit disposable storage.\n";return;}
define('IN_PHPBB',true);define('ATTACHMENTS_TABLE','fixture_attachments');define('CONFIRM_TABLE','fixture_confirm');define('SESSIONS_TABLE','fixture_sessions');define('CONFIG_TABLE','fixture_config');
$app=dirname(dirname(__DIR__)).'/phpBB2/';require$app.'includes/php_compat.php';require$app.'db/mysqli.php';require$app.'includes/functions_confirmation.php';require __DIR__.'/native-connection-release-fixture.php';
function ow_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$port=getenv('PHPBB_OWNED_PORT')?:'3306';ow_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');$host='127.0.0.1:'.$port;$password=getenv('PHPBB_OWNED_PASSWORD')?:'';
$schema='codex_owned_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);ow_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable schema');
$ow_mode='normal';$ow_trace=array();$ow_candidates=array();$ow_calls=0;$ow_fault_phase='';$ow_fault_index=0;$ow_seen=0;$ow_fired=false;$root=$peer=null;
class OwnedCandidate{
 var$inner;var$db_connect_id;var$id;var$fresh;var$adopted=false;
 function __construct($inner,$fresh){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;$this->fresh=$fresh;$r=$inner->sql_query('SELECT CONNECTION_ID() id');$row=$inner->sql_fetchrow($r);$inner->sql_freeresult($r);$this->id=(int)$row['id'];}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['ow_trace'][]=$sql;$n=++$GLOBALS['ow_seen'];if(strpos($sql,'SELECT GET_LOCK(')===0){$this->adopted=true;}
  $identity=strpos($sql,'SELECT CONNECTION_ID() id,DATABASE() schema_name')===0;
  if($identity&&in_array($GLOBALS['ow_mode'],array('busy-false','busy-exception','busy-error'),true)){
   if($GLOBALS['ow_mode']==='busy-false'){return false;}if($GLOBALS['ow_mode']==='busy-error'&&PHP_VERSION_ID>=70000){throw new Error('Synthetic guard error');}throw new RuntimeException('Synthetic guard exception');
  }
  $target=!$GLOBALS['ow_fired']&&$n===$GLOBALS['ow_fault_index'];if($target&&$GLOBALS['ow_fault_phase']==='before'){$GLOBALS['ow_fired']=true;return false;}
  $result=$this->inner->sql_query($sql,$transaction);if($target&&$GLOBALS['ow_fault_phase']==='after'){$GLOBALS['ow_fired']=true;return false;}return$result;
 }
}
class OwnedNativeSource extends sql_db{
 function sql_dedicated_connection(){
  $GLOBALS['ow_calls']++;$mode=$GLOBALS['ow_mode'];if($mode==='missing'){return false;}if($mode==='throw'){throw new RuntimeException('Synthetic factory failure');}if($mode==='borrow'){return$this;}
  $fresh=!in_array($mode,array('alias','busy','manual','foreign','busy-lock','idle-lock','busy-false','busy-exception','busy-error'),true);
  $inner=$mode==='alias'?$this:($fresh?parent::sql_dedicated_connection():$GLOBALS['peer']);if($mode==='fresh-manual'){$inner->sql_query('SET autocommit=0');}
  $candidate=new OwnedCandidate($inner,$fresh);$GLOBALS['ow_candidates'][]=$candidate;return$candidate;
 }
}
function ow_sql($sql){$r=$GLOBALS['control']->sql_query($sql);ow_check($r!==false,'Own fixture SQL');return$r;}
function ow_rows($sql){$r=ow_sql($sql);try{return$GLOBALS['control']->sql_fetchrowset($r);}finally{$GLOBALS['control']->sql_freeresult($r);}}
function ow_value($connection,$sql,$key){$r=$connection->sql_query($sql);ow_check($r!==false,'Connection remains usable');try{$row=$connection->sql_fetchrow($r);return$row[$key];}finally{$connection->sql_freeresult($r);}}
function ow_reset(){
 $GLOBALS['ow_mode']='normal';$GLOBALS['ow_fault_phase']='';$GLOBALS['ow_fault_index']=$GLOBALS['ow_seen']=$GLOBALS['ow_calls']=0;$GLOBALS['ow_fired']=false;$GLOBALS['ow_trace']=array();
 ow_sql('DELETE FROM fixture_confirm');ow_sql('DELETE FROM fixture_sessions');ow_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time) VALUES('".str_repeat('a',32)."',-1,0,".time().')');
}
function ow_invoke(){try{return phpbb_confirmation_challenge($GLOBALS['root'],array('user_id'=>-1,'session_id'=>str_repeat('a',32),'session_logged_in'=>false));}catch(PhpbbOwnedStorageFailure$error){return false;}}
function ow_request(){
 $GLOBALS['ow_seen']=0;$GLOBALS['ow_trace']=array();$offset=count($GLOBALS['ow_candidates']);
 try{return ow_invoke();}
 finally{
  $fresh=array();for($i=$offset;$i<count($GLOBALS['ow_candidates']);$i++){$candidate=$GLOBALS['ow_candidates'][$i];if($candidate->adopted){ow_check(!$candidate->inner->db_connect_id,'Accepted owner closes its own connection');}if($candidate->fresh){$fresh[]=$candidate->id;$GLOBALS['ow_candidates'][$i]=null;}}
  unset($candidate);$GLOBALS['ow_candidates']=array_values(array_filter($GLOBALS['ow_candidates']));ow_check(native_connections_released('ow_rows',$fresh),'Fresh rejected/accepted connections reclaimed without touching borrowed owners');
 }
}
try{
 ow_sql('USE '.$schema);$root=new OwnedNativeSource($host,'root',$password,$schema,false);$peer=new sql_db($host,'root',$password,$schema,false);
 $ddl=file_get_contents($app.'install/schemas/mysql_schema.sql');foreach(array('confirm','sessions','config')as$name){ow_check(preg_match('/^CREATE TABLE `?phpbb_'.$name.'`?\s*\([\s\S]*?;/m',$ddl,$m)===1,'Canonical consumer participant');ow_sql(str_replace('phpbb_'.$name,'fixture_'.$name,$m[0]));}
 ow_sql('CREATE TABLE fixture_marker(id INT PRIMARY KEY,marker INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');ow_sql('INSERT INTO fixture_marker VALUES(1,7),(2,7)');ow_sql("INSERT INTO fixture_config(config_name,config_value) VALUES('session_length','3600')");$cases=$failures=0;
 foreach(array('normal','missing','throw','borrow','alias','busy','manual','foreign','busy-lock','idle-lock','busy-false','busy-exception','busy-error','fresh-manual')as$mode){
  ow_reset();ow_check($root->sql_query('START TRANSACTION')&&$root->sql_query('UPDATE fixture_marker SET marker=8 WHERE id=1'),'Caller pending work');$busy=in_array($mode,array('busy','manual','foreign','busy-lock','busy-false','busy-exception','busy-error'),true);$lock=in_array($mode,array('busy-lock','idle-lock'),true);
  $namespace='attachment:'.md5($schema."\0".ATTACHMENTS_TABLE);if($lock){ow_check(ow_value($peer,"SELECT GET_LOCK('".$namespace."',0) held",'held')==='1','Borrowed peer owns preexisting coordinator');}
  if($busy){ow_check($peer->sql_query($mode==='manual'?'SET autocommit=0':'START TRANSACTION')&&$peer->sql_query('UPDATE fixture_marker SET marker=9 WHERE id=2'),'Borrowed pending work');}if($mode==='foreign'){ow_check($peer->sql_query('USE information_schema'),'Borrowed schema');}$ow_mode=$mode;
  try{$result=ow_request();ow_check(($mode==='normal')===is_string($result),'Only a real independent idle factory accepted: '.$mode);ow_check(ow_value($root,'SELECT marker FROM fixture_marker WHERE id=1','marker')==='8'&&$root->db_connect_id&&$peer->db_connect_id,'Original physical connections and caller work remain open');
   if($mode==='foreign'){ow_check($peer->sql_query('USE '.$schema),'Restore borrowed schema');}if($busy){ow_check(ow_value($peer,'SELECT marker FROM fixture_marker WHERE id=2','marker')==='9'&&ow_rows('SELECT marker FROM fixture_marker WHERE id=2')[0]['marker']==='7','Borrowed changes remain private and pending');}
   if($mode==='manual'){ow_check(ow_value($peer,'SELECT @@SESSION.autocommit automatic','automatic')==='0','Borrowed autocommit mode unchanged');}if($lock){ow_check((string)ow_value($peer,"SELECT IS_USED_LOCK('".$namespace."') owner",'owner')===(string)mysqli_thread_id($peer->db_connect_id),'Borrowed coordinator never released');}
   ow_check(count(ow_rows('SELECT * FROM fixture_confirm'))===($mode==='normal'?1:0),'Rejected provider cannot publish CAPTCHA rows');$cases++;
  }finally{$ow_mode='normal';if($mode==='foreign'){$peer->sql_query('USE '.$schema);}if($busy){$peer->sql_query('ROLLBACK');}if($mode==='manual'){$peer->sql_query('SET autocommit=1');}if($lock){$peer->sql_query("SELECT RELEASE_LOCK('".$namespace."')");}$root->sql_query('ROLLBACK');$ow_candidates=array();}
  ow_check(ow_rows('SELECT marker FROM fixture_marker ORDER BY id')[0]['marker']==='7','Caller retains rollback authority');
 }
 ow_reset();ow_check(is_string(ow_request()),'Inventory full actual consumer protocol');$protocol=$ow_trace;
 foreach(array('before','after')as$phase){foreach($protocol as$i=>$sql){if($sql==='ROLLBACK'){continue;}ow_reset();$ow_fault_phase=$phase;$ow_fault_index=$i+1;$out=ow_request();ow_check($ow_fired,'Every native SQL fault reached');$rows=ow_rows('SELECT * FROM fixture_confirm');$committed=$phase==='after'&&$sql==='COMMIT'&&strpos(implode("\n",array_slice($protocol,0,$i)),'INSERT INTO fixture_confirm ')!==false;ow_check($out===false&&count($rows)===($committed?1:0),'Fault refuses acknowledgement and keeps actual consumer commit atomic');$failures++;}}
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1')as$legacy){ow_reset();ow_sql('ALTER TABLE fixture_config '.$legacy);try{ow_check(ow_request()===false&&!ow_rows('SELECT * FROM fixture_confirm'),'Canonical guard retained');$cases++;}finally{ow_sql('ALTER TABLE fixture_config ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');}}
 echo 'Owned connection native: '.$cases.' cases, '.$failures." SQL failures PASS; actual CAPTCHA consumer, pending caller/borrower preservation, alias/foreign/manual/fault/lock factories, confirmed-only ownership, canonical participants and complete/uncertain commits.\n";
}finally{
 $ow_fault_index=0;$ow_mode='normal';foreach($ow_candidates as$c){if($c->fresh&&$c->inner->db_connect_id){$c->inner->sql_close();}}if($peer&&$peer->db_connect_id){$peer->sql_query('ROLLBACK');$peer->sql_close();}if($root){$root->sql_query('ROLLBACK');$root->sql_close();}ow_check($control->sql_query('DROP DATABASE '.$schema),'Remove only own schema');$control->sql_close();
}
