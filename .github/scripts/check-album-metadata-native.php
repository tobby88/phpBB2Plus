<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_METADATA_NATIVE')!=='1'){echo "Native Album metadata fixture skipped.\n";return;}
define('IN_PHPBB',true);$root=dirname(dirname(__DIR__));
require$root.'/phpBB2/includes/php_compat.php';require$root.'/phpBB2/db/mysqli.php';require$root.'/phpBB2/album_mod/album_file_coordinator.php';require __DIR__.'/album-native-observer.php';
function am_check($ok,$why){if(!$ok){throw new RuntimeException($why);}$GLOBALS['assertions']++;}
class AlbumMetadataSource extends sql_db{
 var$hook=null,$trace=array(),$owners=array(),$mode='',$row_fault='';
 function sql_dedicated_connection(){$db=parent::sql_dedicated_connection();if($this->mode!==''){am_check($db->sql_query("SET SESSION sql_mode='".$db->sql_escape($this->mode)."'"),'Own alternate SQL mode');}$owner=new AlbumMetadataObserved($db,$this);$this->owners[]=$owner;return$owner;}
 function observed_query($db,$sql,$transaction=false){$this->trace[]=$sql;if(is_callable($this->hook)&&call_user_func($this->hook,'before',$sql)===false){return false;}$r=$db->sql_query($sql,$transaction);if(is_callable($this->hook)&&call_user_func($this->hook,'after',$sql)===false){return false;}return$r;}
}
class AlbumMetadataObserved extends AlbumNativeObservedConnection{
 var$last='';
 function sql_query($sql='',$transaction=false){$this->last=$sql;return parent::sql_query($sql,$transaction);}
 function sql_fetchrowset($r=0){$rows=$this->database->sql_fetchrowset($r);$kind=$this->observer->row_fault;if($kind&&strpos($this->last,'SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION ')===0){if($kind==='missing-name'){unset($rows[0]['TABLE_NAME']);}elseif($kind==='duplicate'){$rows[1]=$rows[0];}elseif($kind==='unexpected'){$rows[0]['TABLE_NAME']='not_a_participant';}elseif($kind==='missing-format'){unset($rows[0]['ROW_FORMAT']);}elseif($kind==='null-format'){$rows[0]['ROW_FORMAT']=null;}elseif($kind==='invalid-rows'){return false;}}return$rows;}
}
function am_sql($query){$r=$GLOBALS['control']->sql_query($query);if(!$r){$e=$GLOBALS['control']->sql_error();throw new RuntimeException('Own metadata fixture SQL: '.$e['message']);}return$r;}
function am_rows($query){$r=am_sql($query);try{return$GLOBALS['control']->sql_fetchrowset($r);}finally{$GLOBALS['control']->sql_freeresult($r);}}
function am_attempt($suffix=null,$journal=false,$prefix='fixture_'){
 global$source;$owner=null;$before=count($source->owners);
 try{$owner=new AlbumFileCoordinator($source,$prefix,0);$owner->begin();if($suffix!==null){$owner->pin_authority_table($suffix);}if($journal){$owner->durable_begin();$owner->durable_commit();}return true;}
 catch(AlbumFileCoordinatorFailure$e){return false;}
 finally{if($owner){$owner->release();}for($i=$before;$i<count($source->owners);$i++){am_check(!$source->owners[$i]->database->db_connect_id,'Owned metadata reader is closed');}}
}
$assertions=0;$port=getenv('PHPBB_ALBUM_METADATA_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_METADATA_PASSWORD')?:'';am_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Own loopback port');
$base=array('users','sessions','user_group','album','album_cat','album_comment','album_rate','album_config','album_file_jobs','album_file_items');$extra=array('config','jr_admin_users','album_sp_config');$all=array_merge($base,$extra);$cases=$faults=$ddl_blocks=$assertions=0;$control=$source=$peer=null;$made=false;$schema='codex_album_metadata_'.bin2hex(phpbb_random_bytes(8));$host='127.0.0.1:'.$port;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $control=new sql_db($host,'root',$password,'',false);am_check($control->db_connect_id&&$control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own disposable metadata schema');$made=true;am_rows('USE '.$schema);$source=new AlbumMetadataSource($host,'root',$password,$schema,false);$peer=new sql_db($host,'root',$password,$schema,false);am_check($source->db_connect_id&&$peer->db_connect_id,'Independent real native connections');
 $ddl=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
 foreach(array('fixture_','MiXeD_')as$prefix){foreach($all as$s){am_check(preg_match('/^CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/m',$ddl,$m)===1,'Actual canonical definition '.$s);am_sql(str_replace('phpbb_'.$s,$prefix.$s,$m[0]));}}
 if(getenv('PHPBB_ALBUM_METADATA_MYSQL')==='8.4'){$version=am_rows('SELECT VERSION() version')[0]['version'];am_check(strpos($version,'MariaDB')===false&&preg_match('/^8\.4\./',$version),'Actual MySQL8.4 required');}
 am_check(am_attempt(null,true),'Canonical writer and journal start');$cases++;
 am_check(am_attempt(null,true,'MiXeD_'),'Configured mixed-case prefix remains compatible with server table-name rules');$cases++;
 foreach($all as$s){$dynamic=in_array($s,$extra,true)?$s:null;$table='fixture_'.$s;
  foreach(array('myisam','compact','table-collation','column-charset','column-collation','binary-column','missing','view')as$kind){
   if($kind==='myisam'){am_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');}
   elseif($kind==='compact'){am_sql('SET SESSION innodb_strict_mode=OFF');try{am_sql('ALTER TABLE '.$table.' ROW_FORMAT=COMPACT');}finally{am_sql('SET SESSION innodb_strict_mode=ON');}}
   elseif($kind==='table-collation'){am_sql('ALTER TABLE '.$table.' DEFAULT COLLATE=utf8mb4_bin');}
   elseif($kind==='column-charset'){am_sql('ALTER TABLE '.$table.' ADD own_probe VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci');}
   elseif($kind==='column-collation'){am_sql('ALTER TABLE '.$table.' ADD own_probe VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');}
   elseif($kind==='binary-column'){am_sql('ALTER TABLE '.$table.' ADD own_probe VARBINARY(20)');}
   else{am_sql('RENAME TABLE '.$table.' TO '.$table.'_saved');if($kind==='view'){am_sql('CREATE VIEW '.$table.' AS SELECT * FROM '.$table.'_saved');}}
   try{am_check(am_attempt($dynamic)===($kind==='binary-column'),'Per-participant format contract '.$s.' '.$kind);$cases++;}
   finally{
    if(in_array($kind,array('myisam','compact'),true)){am_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
    elseif($kind==='table-collation'){am_sql('ALTER TABLE '.$table.' DEFAULT COLLATE=utf8mb4_unicode_ci');}
    elseif(strpos($kind,'column')!==false){am_sql('ALTER TABLE '.$table.' DROP COLUMN own_probe');}
    else{if($kind==='view'){am_sql('DROP VIEW '.$table);}am_sql('RENAME TABLE '.$table.'_saved TO '.$table);}
   }
  }
 }
 foreach(array('missing-name','duplicate','unexpected','missing-format','null-format','invalid-rows')as$kind){$source->row_fault=$kind;try{am_check(!am_attempt(),'Incomplete/duplicate/unexpected metadata cannot be accepted');$cases++;}finally{$source->row_fault='';}}
 foreach(array('','ANSI_QUOTES','STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL')as$mode){$source->mode=$mode;am_check(am_attempt(null,true),'Real alternate SQL mode preserves complete table contract');$cases++;}$source->mode='';
 $source->trace=array();am_check(am_attempt(null,true),'Actual successful baseline');$trace=$source->trace;
 // Actual transport failures at each of the grouped physical/table/column
 // statements, for BOTH writer and journal, preserve the caller and allow retry.
 $targets=array();foreach($trace as$i=>$sql){if(strpos($sql,'SELECT * FROM fixture_')===0||strpos($sql,'SELECT TABLE_NAME,ENGINE,ROW_FORMAT,TABLE_COLLATION ')===0||strpos($sql,'SELECT TABLE_NAME FROM information_schema.COLUMNS ')===0){$targets[]=$i;}}am_check(count($targets)===6,'Exactly three fresh statements on each owned transaction');
 foreach(array('before','after')as$phase){foreach($targets as$target){$at=0;$source->hook=function($when,$sql)use($phase,$target,&$at){if($when!==$phase){return;}return$at++===$target?false:null;};try{am_check(!am_attempt(null,true),'Every grouped SQL boundary fails honestly');$faults++;}finally{$source->hook=null;}am_check(am_attempt(null,true),'Fresh operation works after the injected failure');}}
 // A real peer attempts DDL between the dictionary reads for EACH initially
 // opened table. Even empty tables must stay pinned, not only the first one.
 am_check($peer->sql_query('SET SESSION lock_wait_timeout=1'),'Bounded own peer DDL wait');
 foreach($base as$s){$fired=false;$blocked=false;$source->hook=function($when,$sql)use($s,&$fired,&$blocked,$peer){if(!$fired&&$when==='before'&&strpos($sql,'SELECT TABLE_NAME FROM information_schema.COLUMNS ')===0){$fired=true;$r=$peer->sql_query('ALTER TABLE fixture_'.$s.' ADD own_probe VARCHAR(20) CHARACTER SET latin1');$e=$peer->sql_error();$blocked=$r===false&&(int)$e['code']===1205;}};try{am_check(am_attempt()&&$fired&&$blocked,'Every batch participant has a real transaction-lifetime metadata lock');$ddl_blocks++;}finally{$source->hook=null;}
  am_check($peer->sql_query('ALTER TABLE fixture_'.$s.' ADD own_probe VARCHAR(20) CHARACTER SET latin1'),'Peer DDL works after owned release');try{am_check(!am_attempt(),'Next operation sees committed layout drift');$cases++;}finally{am_sql('ALTER TABLE fixture_'.$s.' DROP COLUMN own_probe');}
 }
 am_sql("INSERT INTO fixture_config(config_name,config_value) VALUES('own-pending','original')");
 am_check($source->sql_query('START TRANSACTION')&&$source->sql_query("UPDATE fixture_config SET config_value='pending' WHERE config_name='own-pending'"),'Own caller pending work');
 try{am_check(am_attempt('config'),'Dedicated metadata checks preserve independent caller work');am_check(am_rows("SELECT config_value FROM fixture_config WHERE config_name='own-pending'")[0]['config_value']==='original','Pending caller row is not committed');$cases++;}finally{$source->sql_query('ROLLBACK');}
 echo 'Album metadata native: '.$cases.' cases, '.$faults.' SQL faults, '.$ddl_blocks." real DDL blocks PASS; canonical participants, per-table engines/formats/collations/columns, binary fields, views/missing tables, both owned transactions, current schema and isolated callers.\n";
}finally{if($source){$source->hook=null;$source->sql_close();}if($peer){$peer->sql_close();}if($made&&preg_match('/^codex_album_metadata_[a-f0-9]{16}$/D',$schema)){$control->sql_query('DROP DATABASE '.$schema);}if($control){$control->sql_close();}restore_error_handler();}
