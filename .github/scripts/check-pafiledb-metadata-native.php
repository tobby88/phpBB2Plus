<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_METADATA_NATIVE')!=='1'){echo "Native Download metadata fixture skipped.\n";return;}
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__));
require$root.'/phpBB2/includes/php_compat.php';require$root.'/phpBB2/db/mysqli.php';require$root.'/phpBB2/pafiledb/includes/functions_file_coordinator.php';
function pam_check($ok,$why){if(!$ok){throw new RuntimeException($why);}$GLOBALS['assertions']++;}
class PaMetadataSource extends sql_db{
 var$hook=null,$trace=array(),$owners=array(),$mode='',$row_fault='',$last_errno=0;
 function sql_dedicated_connection(){$db=parent::sql_dedicated_connection();if($this->mode!==''){pam_check($db->sql_query("SET SESSION sql_mode='".$db->sql_escape($this->mode)."'"),'Own alternate SQL mode');}$owner=new PaMetadataObserved($db,$this);$this->owners[]=$owner;return$owner;}
 function observed_query($db,$sql,$transaction=false){$this->trace[]=$sql;if(is_callable($this->hook)&&call_user_func($this->hook,'before',$sql)===false){return false;}$r=$db->sql_query($sql,$transaction);if($r===false){$error=$db->sql_error();$this->last_errno=(int)$error['code'];}if(is_callable($this->hook)&&call_user_func($this->hook,'after',$sql)===false){return false;}return$r;}
}
class PaMetadataObserved{
 var$database,$db_connect_id,$observer,$last='';
 function __construct($db,$observer){$this->database=$db;$this->db_connect_id=$db->db_connect_id;$this->observer=$observer;}
 function __call($method,$args){return call_user_func_array(array($this->database,$method),$args);}
 function sql_close(){return$this->database->sql_close();}
 function sql_query($sql='',$transaction=false){$this->last=$sql;return$this->observer->observed_query($this->database,$sql,$transaction);}
 function sql_fetchrowset($r=0){$rows=$this->database->sql_fetchrowset($r);$kind=$this->observer->row_fault;if($kind&&strpos($this->last,'SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION ')===0){if($kind==='missing-name'){unset($rows[0]['TABLE_NAME']);}elseif($kind==='duplicate'){$rows[1]=$rows[0];}elseif($kind==='unexpected'){$rows[0]['TABLE_NAME']='not_a_participant';}elseif($kind==='missing-format'){unset($rows[0]['ROW_FORMAT']);}elseif($kind==='null-format'){$rows[0]['ROW_FORMAT']=null;}elseif($kind==='invalid-rows'){return false;}}return$rows;}
}
function pam_sql($query){$r=$GLOBALS['control']->sql_query($query);if(!$r){$e=$GLOBALS['control']->sql_error();throw new RuntimeException('Own metadata fixture SQL: '.$e['message']);}return$r;}
function pam_rows($query){$r=pam_sql($query);try{return$GLOBALS['control']->sql_fetchrowset($r);}finally{$GLOBALS['control']->sql_freeresult($r);}}
function pam_attempt($suffix=null,$journal=false,$prefix='fixture_'){
 global$source;$owner=null;$before=count($source->owners);
 try{$owner=new PaFileDbFileCoordinator($source,$prefix,0);$owner->begin();$owner->guard_commit(function(){return true;});if($suffix!==null){$owner->pin_authority_table($suffix);}if($journal){$owner->durable_begin();$owner->durable_commit();}return true;}
 catch(PaFileDbFileCoordinatorFailure$e){return false;}
 finally{if($owner){$owner->release();}for($i=$before;$i<count($source->owners);$i++){pam_check(!$source->owners[$i]->database->db_connect_id,'Owned metadata reader is closed');}}
}
function pam_blocked_ddl($peer,$sql){
 $native=$peer->db_connect_id;$id=(int)$native->thread_id;
 pam_check($id>0&&mysqli_query($native,$sql,MYSQLI_ASYNC)===true,'Actual independent asynchronous DDL started');
 $waiting=false;$ready=false;$killed=false;$stopped=false;$errno=0;
 try{
  $until=microtime(true)+2;
  do{
   $row=pam_rows('SELECT STATE,INFO FROM information_schema.PROCESSLIST WHERE ID='.$id);
   if(count($row)===1&&$row[0]['INFO']===$sql&&stripos((string)$row[0]['STATE'],'metadata lock')!==false){$waiting=true;break;}
   $read=array($native);$error=$reject=array();if(mysqli_poll($read,$error,$reject,0,5000)>0){$ready=true;break;}
  }while(microtime(true)<$until);
 }finally{
  if(!$ready){$killed=$GLOBALS['control']->sql_query('KILL QUERY '.$id)!==false;}
  $until=microtime(true)+2;
  do{$read=array($native);$error=$reject=array();if(mysqli_poll($read,$error,$reject,0,10000)>0){$result=@mysqli_reap_async_query($native);$errno=(int)$native->errno;if($result instanceof mysqli_result){mysqli_free_result($result);}$stopped=true;break;}}while(microtime(true)<$until);
 }
 pam_check($stopped,'Own asynchronous DDL was reaped');
 return$waiting&&$killed&&$errno===1317;
}

$assertions=0;$port=getenv('PHPBB_PA_METADATA_PORT')?:'3306';$password=getenv('PHPBB_PA_METADATA_PASSWORD')?:'';pam_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');
$base=array('users','sessions','user_group','groups','config','jr_admin_users','pa_config','pa_cat','pa_files','pa_votes','pa_comments','pa_custom','pa_customdata','pa_mirrors','pa_license','pa_auth','pa_file_journal','pa_download_info');$extra=array();$all=array_merge($base,$extra);$cases=$faults=$ddl_blocks=$assertions=0;$control=$source=$peer=null;$made=false;$schema='codex_pa_metadata_'.bin2hex(phpbb_random_bytes(8));$host='127.0.0.1:'.$port;
$grant_user='codex_pam_'.bin2hex(phpbb_random_bytes(8));$grant_created=false;$restricted=null;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $control=new sql_db($host,'root',$password,'',false);pam_check($control->db_connect_id&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable metadata schema');$made=true;pam_rows('USE '.$schema);$source=new PaMetadataSource($host,'root',$password,$schema,false);$peer=new sql_db($host,'root',$password,$schema,false);pam_check($source->db_connect_id&&$peer->db_connect_id,'Independent real native connections');
 $ddl=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
 foreach(array('fixture_','MiXeD_')as$prefix){foreach($all as$s){pam_check(preg_match('/^CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/m',$ddl,$m)===1,'Actual canonical definition '.$s);pam_sql(str_replace('phpbb_'.$s,$prefix.$s,$m[0]));}}
 if(getenv('PHPBB_PA_METADATA_MYSQL')==='8.4'){$version=pam_rows('SELECT VERSION() version')[0]['version'];pam_check(strpos($version,'MariaDB')===false&&preg_match('/^8\.4\./',$version),'Actual MySQL8.4 required');}
 pam_check(pam_attempt(null,true),'Canonical writer and journal start');$cases++;
 pam_check(pam_attempt(null,true,'MiXeD_'),'Configured mixed-case prefix remains compatible with server table-name rules');$cases++;
 foreach($all as$s){$dynamic=in_array($s,$extra,true)?$s:null;$table='fixture_'.$s;
  foreach(array('myisam','compact','table-collation','column-charset','column-collation','binary-column','missing','view')as$kind){
   if($kind==='myisam'){pam_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');}
   elseif($kind==='compact'){pam_sql('SET SESSION innodb_strict_mode=OFF');try{pam_sql('ALTER TABLE '.$table.' ROW_FORMAT=COMPACT');}finally{pam_sql('SET SESSION innodb_strict_mode=ON');}}
   elseif($kind==='table-collation'){pam_sql('ALTER TABLE '.$table.' DEFAULT COLLATE=utf8mb4_bin');}
   elseif($kind==='column-charset'){pam_sql('ALTER TABLE '.$table.' ADD own_probe VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci');}
   elseif($kind==='column-collation'){pam_sql('ALTER TABLE '.$table.' ADD own_probe VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');}
   elseif($kind==='binary-column'){pam_sql('ALTER TABLE '.$table.' ADD own_probe VARBINARY(20)');}
   else{pam_sql('RENAME TABLE '.$table.' TO '.$table.'_saved');if($kind==='view'){pam_sql('CREATE VIEW '.$table.' AS SELECT * FROM '.$table.'_saved');}}
   try{pam_check(pam_attempt($dynamic)===($kind==='binary-column'&&$s!=='pa_file_journal'),'Per-participant format contract '.$s.' '.$kind);$cases++;}
   finally{
    if(in_array($kind,array('myisam','compact'),true)){pam_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
    elseif($kind==='table-collation'){pam_sql('ALTER TABLE '.$table.' DEFAULT COLLATE=utf8mb4_unicode_ci');}
    elseif(strpos($kind,'column')!==false){pam_sql('ALTER TABLE '.$table.' DROP COLUMN own_probe');}
    else{if($kind==='view'){pam_sql('DROP VIEW '.$table);}pam_sql('RENAME TABLE '.$table.'_saved TO '.$table);}
   }
  }
 }
 foreach(array('missing-name','duplicate','unexpected','missing-format','null-format','invalid-rows')as$kind){$source->row_fault=$kind;try{pam_check(!pam_attempt(),'Incomplete/duplicate/unexpected metadata cannot be accepted');$cases++;}finally{$source->row_fault='';}}
 foreach(array('','ANSI_QUOTES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL')as$mode){$source->mode=$mode;pam_check(pam_attempt(null,true),'Real alternate SQL mode preserves complete table contract');$cases++;}$source->mode='';
 // COLUMNS only exposes columns visible to the SQL user. Keep the original
 // all-column opening check: partial column grants cannot hide layout drift.
 $grant_password=bin2hex(phpbb_random_bytes(32));$grant_account="'".$grant_user."'@'127.0.0.1'";
 pam_sql('CREATE USER '.$grant_account." IDENTIFIED BY '".$grant_password."'");$grant_created=true;
 foreach($base as$s){pam_sql('GRANT SELECT'.($s==='users'?' (user_id)':'').' ON '.$schema.'.fixture_'.$s.' TO '.$grant_account);}
 $privileged=$source;$restricted=new PaMetadataSource($host,$grant_user,$grant_password,$schema,false);pam_check($restricted->db_connect_id,'Own restricted SQL user');
 try{$source=$restricted;pam_check(!pam_attempt()&&in_array($restricted->last_errno,array(1142,1143),true),'Partial native column privileges cannot bypass the all-column participant opening');$cases++;
  pam_sql('GRANT SELECT ON '.$schema.'.fixture_users TO '.$grant_account);pam_check(pam_attempt(null,true),'Same native user succeeds after full participant SELECT is granted');$cases++;
 }finally{$source=$privileged;$restricted->sql_close();$restricted=null;}
 pam_sql('DROP USER '.$grant_account);$grant_created=false;
 $source->trace=array();pam_check(pam_attempt(null,true),'Actual successful baseline');$trace=$source->trace;
 // Actual transport failures at each of the grouped physical/table/column
 // statements, for BOTH writer and journal, preserve the caller and allow retry.
 $targets=array();foreach($trace as$i=>$sql){if(strpos($sql,'SELECT * FROM fixture_')===0||strpos($sql,'SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION ')===0||strpos($sql,'SELECT TABLE_NAME FROM information_schema.COLUMNS ')===0||strpos($sql,'SHOW FULL COLUMNS FROM fixture_pa_file_journal')===0||strpos($sql,'SHOW INDEX FROM fixture_pa_file_journal')===0||strpos($sql,'SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES ')===0){$targets[]=$i;}}pam_check(count($targets)===7,'Three grouped writer checks, exact journal shape and two independent journal pins');

 foreach(array('before','after')as$phase){foreach($targets as$target){$at=0;$source->hook=function($when,$sql)use($phase,$target,&$at){if($when!==$phase){return;}return$at++===$target?false:null;};try{pam_check(!pam_attempt(null,true),'Every grouped SQL boundary fails honestly');$faults++;}finally{$source->hook=null;}pam_check(pam_attempt(null,true),'Fresh operation works after the injected failure');}}
 // Start actual peer DDL asynchronously, prove the server is waiting on
 // the exact table metadata lock, then cancel ONLY our peer query. This keeps
 // the full 18-table lock check without eighteen deliberate timeout seconds.
 foreach($base as$s){$fired=false;$blocked=false;$source->hook=function($when,$sql)use($s,&$fired,&$blocked,$peer){if(!$fired&&$when==='before'&&strpos($sql,'SELECT TABLE_NAME FROM information_schema.COLUMNS ')===0){$fired=true;$blocked=pam_blocked_ddl($peer,'ALTER TABLE fixture_'.$s.' ADD own_probe VARCHAR(20) CHARACTER SET latin1');}};try{pam_check(pam_attempt()&&$fired&&$blocked,'Every batch participant has a real transaction-lifetime metadata lock');$ddl_blocks++;}finally{$source->hook=null;}
  pam_check($peer->sql_query('ALTER TABLE fixture_'.$s.' ADD own_probe VARCHAR(20) CHARACTER SET latin1'),'Peer DDL works after owned release');try{pam_check(!pam_attempt(),'Next operation sees committed layout drift');$cases++;}finally{pam_sql('ALTER TABLE fixture_'.$s.' DROP COLUMN own_probe');}
 }
 pam_sql("INSERT INTO fixture_config(config_name,config_value) VALUES('own-pending','original')");
 pam_check($source->sql_query('START TRANSACTION')&&$source->sql_query("UPDATE fixture_config SET config_value='pending' WHERE config_name='own-pending'"),'Own caller pending work');
 try{pam_check(pam_attempt('config'),'Dedicated metadata checks preserve independent caller work');pam_check(pam_rows("SELECT config_value FROM fixture_config WHERE config_name='own-pending'")[0]['config_value']==='original','Pending caller row is not committed');$cases++;}finally{$source->sql_query('ROLLBACK');}
 echo 'Download metadata native: '.$cases.' cases, '.$faults.' SQL faults, '.$ddl_blocks." real DDL blocks PASS; canonical participants, per-table engines/formats/collations/columns, binary fields, views/missing tables, exact journal columns/indexes, native column grants, both owned transactions, current schema and isolated callers.\n";
}finally{if($restricted){$restricted->sql_close();}if($source){$source->hook=null;$source->sql_close();}if($peer){$peer->sql_close();}if($grant_created&&preg_match('/^codex_pam_[a-f0-9]{16}$/D',$grant_user)){$control->sql_query("DROP USER '".$grant_user."'@'127.0.0.1'");}if($made&&preg_match('/^codex_pa_metadata_[a-f0-9]{16}$/D',$schema)){$control->sql_query('DROP DATABASE '.$schema);}if($control){$control->sql_close();}restore_error_handler();}
