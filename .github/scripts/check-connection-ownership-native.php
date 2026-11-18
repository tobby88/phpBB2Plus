<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_CONNECTION_OWNER_NATIVE')!=='1'){echo "Connection ownership checks require disposable native databases.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_CONNECTION_OWNER_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_CONNECTION_OWNER_PASSWORD')?:''));
$scripts=__DIR__;$fixture_source=file_get_contents($scripts.'/check-style-data-native.php');$cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");if($cut===false){throw new RuntimeException('Native bootstrap missing');}
$head=str_replace('__DIR__',var_export($scripts,true),substr($fixture_source,5,$cut-5));$head=str_replace('codex_style_data_','codex_connection_owner_',$head);
$body= <<<'PHP'
 sd_reset();$_POST=array('sid'=>'fixture-admin');define('CTRACKER_ACP',true);
 foreach(array('includes/functions_database_backup.php','includes/functions_board_config.php','includes/functions_kb_publication.php','includes/functions_pm_attachment_access.php','album_mod/album_root_storage.php','ctracker/classes/class_ct_adminfunctions.php')as$file){require_once$sd_source.$file;}
 $co_cases=$co_assertions=0;
 function co_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['co_assertions']++;}
 function co_rows($database,$sql){$r=$database->sql_query($sql);co_check($r!==false,'Native statement succeeds: '.$sql);try{$rows=$database->sql_fetchrowset($r);co_check(is_array($rows),'Actual native rows');return$rows;}finally{$database->sql_freeresult($r);}}
 function co_sql($database,$sql){co_check($database->sql_query($sql)!==false,'Native setup succeeds: '.$sql);}
 function co_state($database){$rows=co_rows($database,'SELECT CONNECTION_ID() id,DATABASE() schema_name,@@SESSION.autocommit automatic,@@SESSION.sql_mode sql_mode,@@SESSION.time_zone zone,@@SESSION.character_set_connection charset,@@SESSION.collation_connection collation_name,@@SESSION.innodb_lock_wait_timeout row_wait');co_check(count($rows)===1,'Complete portable session fingerprint');return$rows;}
 class ConnectionOwnerObserved extends StyleDataConnection {
  var$fault='';var$closed=0;var$queries=array();var$fault_seen=false;
  function sql_query($sql,$transaction=false){
   $this->queries[]=$sql;$identity=strpos($sql,'SELECT CONNECTION_ID() AS owned_connection_id,')===0;$sentinel=$sql==='SET TRANSACTION READ WRITE';
   if(($identity&&$this->fault==='identity-false')||($sentinel&&$this->fault==='sentinel-false')){$this->fault_seen=true;return false;}
   if(($identity&&$this->fault==='identity-throw')||($sentinel&&$this->fault==='sentinel-throw')){$this->fault_seen=true;throw new RuntimeException('Own native fault');}
   return$this->inner->sql_query($sql,$transaction);
  }
  function sql_fetchrowset($result){$rows=$this->inner->sql_fetchrowset($result);if(isset($rows[0]['owned_connection_id'])){if($this->fault==='identity-malformed'){$this->fault_seen=true;$rows[0]['owned_connection_id']=array('invalid');}if($this->fault==='identity-duplicate'){$this->fault_seen=true;$rows[]=$rows[0];}}return$rows;}
  function sql_close(){$this->closed++;return$this->inner->sql_close();}
 }
 class ConnectionOwnerSource extends StyleDataDatabase {
  var$slot=0;var$calls=0;var$borrowed=null;var$owned=array();
  function sql_dedicated_connection(){if(++$this->calls===$this->slot){return$this->borrowed;}$candidate=new ConnectionOwnerObserved($this->inner->sql_dedicated_connection());$this->owned[]=$candidate;return$candidate;}
 }
 function co_run($kind,$source){
  $owner=null;
  try{
   if($kind==='backup'){$GLOBALS['co_builder']=false;$stream=phpbb_database_backup_capture($source,array('fixture_owner_marker'),false,function($reader,$stream){$GLOBALS['co_builder']=true;co_check(count(co_rows($reader,'SELECT * FROM fixture_owner_marker'))===1,'Backup sees committed rows only');phpbb_database_backup_write($stream,'owned reader');});fclose($stream);return;}
   if($kind==='board'){$owner=new PhpbbBoardConfigWriter($source);$owner->sql_query('START TRANSACTION');}
   elseif($kind==='kb'){$owner=new PhpbbKbPublicationDatabase($source,'acp');}
   elseif($kind==='album'){$owner=new AlbumRootStorage($source);}
   elseif($kind==='pm'){$owner=new PhpbbPmAttachmentReader($source);co_check(count($owner->rows('SELECT 1 AS probe'))===1,'Actual PN reader remains usable');}
   elseif($kind==='scan'){$owner=new ct_scan_lock($source,'fixture_scan');if($owner->acquired!==1){throw new RuntimeException('Native scan owner refused');}}
   else{throw new RuntimeException('Unknown native owner');}
  }finally{if($owner){$owner->release();}}
 }
 sd_sql('CREATE TABLE fixture_owner_marker(id INT PRIMARY KEY,value VARCHAR(40)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');sd_sql("INSERT INTO fixture_owner_marker VALUES(0,'committed')");
 $co_other=$fixture.'_other';co_sql($control,'CREATE DATABASE '.$co_other.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
 co_sql($control,'CREATE TABLE '.$co_other.'.fixture_owner_marker LIKE '.$fixture.'.fixture_owner_marker');co_sql($control,"INSERT INTO ".$co_other.".fixture_owner_marker VALUES(0,'committed')");
 $co_lock='caller:'.md5($fixture);$co_borrowed=null;
 foreach(array('backup','board','kb','album','pm','scan')as$kind){
  foreach(array('normal','autocommit-off','latin1','wrapped')as$caller_kind){
   $db=new ConnectionOwnerSource($db->inner);if($caller_kind==='autocommit-off'){co_sql($db,'SET autocommit=0');}if($caller_kind==='latin1'){co_sql($db,'SET NAMES latin1');}if($caller_kind==='wrapped'){unset($db->db_connect_id);}
   co_sql($db,'START TRANSACTION');co_sql($db,"INSERT INTO fixture_owner_marker VALUES(10,'caller pending')");$before=co_state($db);co_run($kind,$db);
   co_check(co_state($db)===$before,'Successful owner preserves complete caller state');co_check(count(co_rows($db,'SELECT * FROM fixture_owner_marker'))===2&&count(co_rows($peer,'SELECT * FROM fixture_owner_marker'))===1,'Successful owner preserves pending caller row');
   foreach($db->owned as$owned){co_check($owned->closed===1,'Proven idle owner closes once');}
   co_sql($db,'ROLLBACK');co_check(count(co_rows($peer,'SELECT * FROM fixture_owner_marker'))===1,'Caller retains rollback');co_sql($db,'SET autocommit=1');co_sql($db,'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');$co_cases++;
  }
  foreach($kind==='backup'?array(1,2):array(1)as$slot){foreach(array('active','read-only','autocommit-off-active','autocommit-off-idle','identity-false','identity-throw','identity-malformed','identity-duplicate','sentinel-false','sentinel-throw','same-object','same-native','same-native-opaque','other-schema','other-charset','idle-other-schema','idle-other-charset')as$case){
   $db=new ConnectionOwnerSource($db->inner);$db->slot=$slot;co_sql($db,'START TRANSACTION');co_sql($db,"INSERT INTO fixture_owner_marker VALUES(10,'caller pending')");$source_before=co_state($db);
   $shared=in_array($case,array('same-object','same-native','same-native-opaque'),true);$other=strpos($case,'other-schema')!==false;$peer_schema=$other?$co_other:$fixture;if($case==='same-native-opaque'){unset($db->db_connect_id);}
   $co_borrowed=new ConnectionOwnerObserved($shared?$db->inner:new sql_db($host,'root',$password,$peer_schema,false));$db->borrowed=$case==='same-object'?$db:$co_borrowed;
   $active=!in_array($case,array('autocommit-off-idle','idle-other-schema','idle-other-charset'),true);
   if(!$shared){
    co_sql($co_borrowed->inner,"SET SESSION time_zone='+03:30'");co_sql($co_borrowed->inner,"SET SESSION sql_mode='STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES'");
    if(strpos($case,'autocommit-off')===0){co_sql($co_borrowed->inner,'SET autocommit=0');}
    if(strpos($case,'other-charset')!==false){co_sql($co_borrowed->inner,'SET NAMES latin1');}
    if($case==='read-only'){co_sql($co_borrowed->inner,'START TRANSACTION READ ONLY');co_rows($co_borrowed->inner,'SELECT * FROM fixture_owner_marker');}
    elseif($active){co_sql($co_borrowed->inner,'START TRANSACTION');co_sql($co_borrowed->inner,"INSERT INTO fixture_owner_marker VALUES(20,'borrowed pending')");}
   }
   $borrowed_before=co_state($co_borrowed->inner);$lock=co_rows($co_borrowed->inner,"SELECT GET_LOCK('$co_lock',0) acquired");co_check((string)$lock[0]['acquired']==='1','Fixture caller owns its advisory lock');
   if(strpos($case,'identity-')===0||strpos($case,'sentinel-')===0){$co_borrowed->fault=$case;}
   $denied=false;try{co_run($kind,$db);}catch(Exception$error){$denied=true;}
   co_check($denied,'Borrowed participant refused: '.$kind.'/'.$slot.'/'.$case);co_check($co_borrowed->closed===0,'Unproven borrowed connection stays open');
   co_check(co_state($db)===$source_before&&co_state($co_borrowed->inner)===$borrowed_before,'Refusal preserves both session fingerprints');
   $held=co_rows($peer,"SELECT IS_USED_LOCK('$co_lock') held");co_check((string)$held[0]['held']===(string)$borrowed_before[0]['id'],'Refusal preserves borrowed advisory lock');
   if(!$shared&&$active&&$case!=='read-only'){co_check(count(co_rows($co_borrowed->inner,'SELECT * FROM fixture_owner_marker'))===2,'Borrowed pending row retained');}
   if($case==='read-only'){co_check(!$co_borrowed->inner->sql_query("INSERT INTO fixture_owner_marker VALUES(21,'forbidden')"),'Borrowed read-only transaction remains read-only');}
   co_check(count(co_rows($peer,'SELECT * FROM '.$peer_schema.'.fixture_owner_marker'))===1,'No borrowed work committed');
   foreach($co_borrowed->queries as$sql){co_check(strpos($sql,'SELECT CONNECTION_ID() AS owned_connection_id,')===0||$sql==='SET TRANSACTION READ WRITE','No rollback, commit, lock or session mutation before ownership');}
   if(strpos($case,'identity-')===0||strpos($case,'sentinel-')===0){co_check($co_borrowed->fault_seen,'Actual injected uncertainty reached');}
   foreach($db->owned as$owned){co_check($owned->closed===1,'Proven idle sibling closes on refusal');}
   if($kind==='backup'){co_check(empty($GLOBALS['co_builder']),'Rejected backup cannot run output builder');}
   $co_borrowed->fault='';co_sql($co_borrowed->inner,"SELECT RELEASE_LOCK('$co_lock')");
   if(!$shared){co_sql($co_borrowed->inner,'ROLLBACK');$co_borrowed->sql_close();}$co_borrowed=null;co_sql($db,'ROLLBACK');co_check(count(co_rows($peer,'SELECT * FROM '.$peer_schema.'.fixture_owner_marker'))===1,'Borrowed caller retains rollback');$co_cases++;
  }}
 }
 co_check($co_cases===143,'All six owners and both backup factory slots covered');
 echo 'Native connection ownership: ',$co_cases,' cases, ',$co_assertions,' assertions PASS; six production owners, both backup slots, active/read-only/autocommit/uncertain/context/same-handle cases, caller rollback and advisory locks; no production access.',"\n";
PHP;
$tail= <<<'PHP'
}finally{
 if(isset($co_borrowed)&&$co_borrowed&&$co_borrowed->inner->db_connect_id){$co_borrowed->inner->sql_query('ROLLBACK');if($co_borrowed->inner!==$db->inner){$co_borrowed->sql_close();}}
 $db->sql_close();$peer->sql_close();if(isset($co_other)){$control->sql_query('DROP DATABASE IF EXISTS '.$co_other);}$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
