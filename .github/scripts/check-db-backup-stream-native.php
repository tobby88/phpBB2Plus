<?php
// Real ACP export under a memory limit smaller than the selected table. Import
// its completed spool line by line, rather than hiding a second huge buffer in
// the fixture's response capture. Faults affect only owned native connections.
if(PHP_SAPI!=='cli'||getenv('PHPBB_DB_BACKUP_NATIVE')!=='1'){echo "Native streaming backups require an explicitly enabled disposable database.\n";return;}
$mode=isset($argv[1])?$argv[1]:'plain';
if(!in_array($mode,array('plain','gzip','kill-query','disconnect','fetch-error','early-release','overlap','sink-failure'),true)){throw new RuntimeException('Unknown streaming backup case');}
$scripts=__DIR__;putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_DB_BACKUP_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_DB_BACKUP_PASSWORD')?:''));
$fixture_source=file_get_contents($scripts.'/check-style-data-native.php');$cut=strpos($fixture_source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Native fixture boundary missing');}
$head=str_replace('__DIR__',var_export($scripts,true),substr($fixture_source,5,$cut-5));$head=str_replace('codex_style_data_','codex_backup_stream_',$head);
$body= <<<'PHP'
 sd_reset();$table_prefix='fixture_';$dbname=$fixture;$capture=false;$restore=null;$restore_name=$fixture.'_restore';
 $bs_mode=$mode;$bs_fault=false;$bs_guard=false;$bs_delivered=false;$bs_streamed=false;$bs_inserted=0;$bs_sql_bytes=0;
 $lang['Database_backup_failed']='Native streaming backup refused';
 class BackupStreamConnection extends StyleDataConnection {
  var$reading=false;var$seen=0;var$synthetic_error=false;
  function sql_query_unbuffered($sql){$result=parent::sql_query_unbuffered($sql);$this->reading=$sql==='SELECT * FROM `fixture_large`';$this->seen=0;if($this->reading){$GLOBALS['bs_streamed']=true;}return$result;}
  function sql_fetchrow($result=0){
   if($this->reading&&$GLOBALS['bs_mode']==='fetch-error'&&$this->seen===1){$this->synthetic_error=true;$GLOBALS['bs_fault']=true;return false;}
   $row=$this->inner->sql_fetchrow($result);
   if($this->reading&&is_array($row)&&++$this->seen===1){
    $kind=$GLOBALS['bs_mode'];
    if($kind==='kill-query'||$kind==='disconnect'){
     $id=(int)mysqli_thread_id($this->db_connect_id);
     sd_check($GLOBALS['peer']->sql_query('KILL '.($kind==='kill-query'?'QUERY ':'CONNECTION ').$id),'Cancel only actual owned data reader');$GLOBALS['bs_fault']=true;
    }elseif($kind==='early-release'||$kind==='overlap'){
     if($kind==='early-release'){sd_check($GLOBALS['db']->sql_freeresult($result),'Prematurely free the actual owned stream');}
     $GLOBALS['bs_fault']=true;$before=count($GLOBALS['sd_queries']);$denied=false;
     try{$GLOBALS['db']->sql_query('SELECT 1');}catch(PhpbbDatabaseBackupException$error){$denied=true;}
     $GLOBALS['bs_guard']=$denied&&count($GLOBALS['sd_queries'])===$before;
     if($kind==='overlap'){throw new PhpbbDatabaseBackupException('Owned competing-query probe');}return false;
    }
    elseif($kind==='sink-failure'){sd_check(is_resource($GLOBALS['phpbb_backup_output']),'Actual capture spool exists');fclose($GLOBALS['phpbb_backup_output']);$GLOBALS['bs_fault']=true;}
   }
   return$row;
  }
  function sql_error($result=0){return$this->synthetic_error?array('code'=>2013,'message'=>'Own injected fetch failure'):$this->inner->sql_error($result);}
 }
 class BackupStreamDatabase extends StyleDataDatabase {function sql_dedicated_connection(){return new BackupStreamConnection($this->inner->sql_dedicated_connection());}}
 $db=new BackupStreamDatabase($db->inner);$caller=$db;
 // Large synthetic seed/import rows need no replication history. Keep this
 // session-only fixture optimization off the application's reader/writer API.
 sd_check($peer->sql_query('SET SESSION sql_log_bin=0'),'Own synthetic seed omits replication log');
 sd_sql('CREATE TABLE fixture_large(id INT NOT NULL PRIMARY KEY,content MEDIUMTEXT NOT NULL,opaque BLOB NOT NULL,optional_value VARCHAR(8) NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $large=in_array($mode,array('plain','gzip','kill-query','disconnect'),true);$rows=$large?96:3;
 for($id=1;$id<=$rows;$id++){
  sd_sql("INSERT INTO fixture_large VALUES(".$id.",CONCAT('row".$id.":',REPEAT('x',".($large?1048540:128)."),CONVERT(0x4772c3bcc39f6520f09f988020275c0a001a USING utf8mb4)),0xff8000275c0a001a,".($id%2?"NULL":"''").")");
 }
 $fingerprint_sql='SELECT id,SHA2(content,256) content_hash,OCTET_LENGTH(content) content_bytes,HEX(opaque) opaque,optional_value FROM fixture_large ORDER BY id';
 $expected=phpbb_acl_rows($peer,$fingerprint_sql);
 function bs_deliver($stream){
  global$restore,$restore_name,$host,$password,$bs_inserted,$bs_sql_bytes;
  $GLOBALS['bs_delivered']=true;
  sd_check(in_array($GLOBALS['bs_mode'],array('plain','gzip'),true),'A failed capture cannot reach response delivery');
  sd_check($GLOBALS['control']->sql_query('CREATE DATABASE '.$restore_name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own streaming restoration schema');
  $restore=new sql_db($host,'root',$password,$restore_name,false);
  sd_check($restore->sql_query('SET SESSION sql_log_bin=0'),'Own synthetic restoration omits replication log');
  if($GLOBALS['bs_mode']==='gzip'){sd_check(stream_filter_append($stream,'zlib.inflate',STREAM_FILTER_READ,array('window'=>31))!==false,'Actual completed gzip spool decodes');}
  $statement='';
  while(($line=fgets($stream))!==false){
   $bs_sql_bytes+=strlen($line);if($statement===''&&(trim($line)===''||substr($line,0,1)==='#')){continue;}
   $statement.=$line;sd_check(strlen($statement)<2097152,'Fixture importer holds one bounded actual statement');
   if(substr(rtrim($line),-1)===';'){
    sd_check($restore->sql_query($statement),'Each actual completed-spool statement imports');
    if(strpos($statement,'INSERT INTO `fixture_large`')===0){$bs_inserted++;}$statement='';
   }
  }
  sd_check(feof($stream)&&trim($statement)==='','Complete actual spool read to EOF');
 }
 $_GET=$HTTP_GET_VARS=array();$_POST=$HTTP_POST_VARS=array('perform'=>'backup','backupstart'=>'1','backup_type'=>'full','sid'=>'fixture-admin');
 if($mode==='gzip'){sd_check(function_exists('gzdecode'),'Gzip extension');$_POST['gzipcompress']=$HTTP_POST_VARS['gzipcompress']='1';}
 $controller=file_get_contents($sd_source.'admin/admin_db_utilities.php');
 $controller=str_replace('exit;','throw new StyleDataExit("backup");',$controller,$exits);
 $controller=str_replace('fpassthru($backup_stream)','bs_deliver($backup_stream)',$controller,$deliveries);
 sd_check($exits===1&&$deliveries===1,'Only terminal response and bounded response-sink seams');
 ob_start();$capture=true;
 try{eval(substr(str_replace('__DIR__',var_export($sd_source.'admin',true),$controller),5));throw new RuntimeException('Actual streaming controller did not terminate');}catch(StyleDataExit$error){$outcome=$error->getMessage();}
 $output=ob_get_clean();$capture=false;
 sd_check($output===''&&$bs_streamed&&$db===$caller,'Actual controller restores caller and never buffers response bytes in fixture');
 if($large&&in_array($mode,array('plain','gzip'),true)){
  sd_check($outcome==='backup'&&$bs_delivered&&$bs_inserted===96&&$bs_sql_bytes>100000000,'All96MiB actual rows delivered only after complete capture');
  sd_check(phpbb_acl_rows($restore,$fingerprint_sql)===$expected,'All restored row hashes, lengths, binary/NULL/empty values match exactly');
 }else{sd_check($outcome!=='backup'&&!$bs_delivered&&$bs_fault,'Interrupted/invalid stream publishes no partial backup');}
 sd_check(!in_array($mode,array('early-release','overlap'),true)||$bs_guard,'Active/failed cursor rejects even identity queries before any native SQL can clear its error');
 sd_check(phpbb_acl_rows($peer,$fingerprint_sql)===$expected,'Streaming backup and its failures never alter source rows');
 sd_check($db->sql_query('SELECT 1'),'Original caller connection remains usable');
 $deadline=microtime(true)+2;
 do{$connections=phpbb_acl_rows($peer,'SELECT ID FROM information_schema.PROCESSLIST WHERE DB=DATABASE()');if(count($connections)===2){break;}usleep(10000);}while(microtime(true)<$deadline);
 sd_check(count($connections)===2,'Every dedicated capture connection is actually reclaimed');
 $peak=memory_get_peak_usage(true);sd_check($peak<33554432,'Complete actual ACP export/import stays below32MiB');
 $proof=array('mode'=>$mode,'peak_bytes'=>$peak,'source_rows'=>$rows,'response_delivered'=>$bs_delivered,'real_mail_sent'=>false,'production_access'=>false);
PHP;
$tail= <<<'PHP'
}finally{
 if(isset($capture)&&$capture){ob_end_clean();}
 if(isset($restore)&&$restore!==null){$restore->sql_close();}
 if(isset($restore_name)){$control->sql_query('DROP DATABASE IF EXISTS '.$restore_name);}
 $db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}
 foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
echo 'Native backup stream ',$mode,': actual ACP/owned-cursor/whole-capture/roundtrip/memory checks PASS; ',json_encode($proof),"\n";
PHP;
eval($head.$body.$tail);
