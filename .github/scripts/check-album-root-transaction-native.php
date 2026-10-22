<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_TRANSACTION_NATIVE')!=='1'){echo "Native Album root transaction fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');define('USER_GROUP_TABLE','fixture_members');$table_prefix='fixture_';
$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'db/mysqli.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_functions.php';require $source.'album_mod/album_hierarchy_auth.php';require $source.'album_mod/album_hierarchy_sql.php';require __DIR__.'/album-native-observer.php';
class AlbumTransactionExit extends RuntimeException{}
function message_die($type,$message){throw new AlbumTransactionExit($message);}
function at_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
function album_sql_id_list($ids){return implode(',',array_map('intval',explode(',',$ids)));}
class AlbumTransactionConnection extends AlbumNativeObservedConnection{
 function sql_close(){$this->observer->closed++;return $this->database->sql_close();}
}
class AlbumTransactionSource extends sql_db{
 var$queries=array();var$failure=0;var$kind='';var$closed=0;var$otherSchema='';var$barrier='';var$worker='';
 function reset_case($failure=0,$kind=''){$this->queries=array();$this->failure=$failure;$this->kind=$kind;$this->closed=0;}
 function sql_query($sql='',$transaction=false){
  if(strpos($sql,'SELECT CONNECTION_ID()')===0&&$this->kind==='source-metadata-false'){return false;}
  if(strpos($sql,'SELECT CONNECTION_ID()')===0&&$this->kind==='source-metadata-throw'){throw new RuntimeException('Synthetic source metadata failure');}
  return parent::sql_query($sql,$transaction);
 }
 function sql_dedicated_connection(){
  if($this->kind==='same-object'){return$this;}
  if($this->kind==='throw'){throw new RuntimeException('Synthetic factory failure');}
  if($this->kind==='borrowed-handle'){return new AlbumTransactionConnection($this,$this);}
  $connection=parent::sql_dedicated_connection();
  if($this->kind==='wrong-schema'){at_check($connection->sql_query('USE '.$this->otherSchema)!==false,'Own alternate schema');}
  return new AlbumTransactionConnection($connection,$this);
 }
 function observed_query($database,$sql,$transaction=false){
  $this->queries[]=$sql;$number=count($this->queries);
  if($number===$this->failure){return false;}
  if($this->kind==='lost-commit-ack'&&$sql==='COMMIT'){at_check($database->sql_query($sql)!==false,'Actual unacknowledged commit');return false;}
  if($this->worker==='peer'&&$sql==='SELECT user_id FROM fixture_users WHERE user_id=7 FOR UPDATE'){file_put_contents($this->barrier.'/peer-attempt','1');}
  $result=$database->sql_query($sql,$transaction);
  if($this->worker==='hold'&&$sql==='SELECT user_id FROM fixture_users WHERE user_id=7 FOR UPDATE'&&$result){
   file_put_contents($this->barrier.'/holder-locked','1');$deadline=microtime(true)+8;
   while(!is_file($this->barrier.'/peer-attempt')){if(microtime(true)>$deadline){throw new RuntimeException('Peer did not reach owned row lock');}usleep(10000);clearstatcache();}
  }
  return$result;
 }
}
$port=getenv('PHPBB_ALBUM_TRANSACTION_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_TRANSACTION_PASSWORD')?:'';
at_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
$userdata=array('user_id'=>7,'session_logged_in'=>true,'user_level'=>0);$album_config=array('personal_gallery'=>ALBUM_USER,'personal_gallery_private'=>'10');$album_data=array();$lang=array('Personal_Gallery_Of_User'=>'Gallery of %s','Personal_gallery_creation_failed'=>'creation-failed');
if(isset($argv[1])&&$argv[1]==='worker'){
 at_check(count($argv)===5&&preg_match('/^codex_album_tx_[a-f0-9]{16}$/D',$argv[2])&&in_array($argv[3],array('hold','peer'),true)&&in_array($argv[4],array('normal','slashes','quotes'),true),'Only own bounded child context');
 $db=new AlbumTransactionSource($host,'root',$password,$argv[2],false);$db->barrier=sys_get_temp_dir().'/'.$argv[2];$db->worker=$argv[3];
 at_check(is_dir($db->barrier),'Existing private parent fixture');$modes=array('normal'=>'','slashes'=>'NO_BACKSLASH_ESCAPES','quotes'=>'ANSI_QUOTES');at_check($db->sql_query("SET SESSION sql_mode='".$modes[$argv[4]]."'")!==false,'Child request SQL mode');
 try{$ok=album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE);$root=album_get_personal_root_id(7);echo json_encode(array('success'=>$ok,'root'=>(int)$root)),"\n";}
 finally{$db->sql_close();}exit(0);
}
$schema='codex_album_tx_'.bin2hex(phpbb_random_bytes(8));$otherSchema=$schema.'_other';$fixture=sys_get_temp_dir().'/'.$schema;
$control=new sql_db($host,'root',$password,'',false);at_check($control->db_connect_id,'Owned native control connection');$db=null;$created=false;$otherCreated=false;$children=array();$cases=0;
function at_sql($sql){$r=$GLOBALS['control']->sql_query($sql);at_check($r!==false,'Owned fixture SQL');return$r;}
function at_rows($sql){$r=at_sql($sql);$rows=$GLOBALS['control']->sql_fetchrowset($r);$GLOBALS['control']->sql_freeresult($r);return$rows;}
function at_count(){return(int)at_rows('SELECT COUNT(*) AS n FROM fixture_album_cat')[0]['n'];}
function at_failed_create(){try{return album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===false;}catch(AlbumTransactionExit$e){return true;}}
function at_start_child($mode,$sqlMode){
 $command=escapeshellarg(PHP_BINARY).' ';
 if(DIRECTORY_SEPARATOR==='\\'){$command.='-n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll ';if(extension_loaded('mbstring')){$command.='-d extension=php_mbstring.dll ';}}
 $command.=escapeshellarg(__FILE__).' worker '.escapeshellarg($GLOBALS['schema']).' '.escapeshellarg($mode).' '.escapeshellarg($sqlMode);
 $process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));at_check(is_resource($process),'Owned child started');fclose($pipes[0]);
 $GLOBALS['children'][]=array($process,$pipes);return count($GLOBALS['children'])-1;
}
function at_finish_child($index){
 $entry=$GLOBALS['children'][$index];$deadline=microtime(true)+12;
 do{$status=proc_get_status($entry[0]);if(!$status['running']){break;}if(microtime(true)>$deadline){throw new RuntimeException('Owned child deadline');}usleep(10000);}while(true);
 $out=stream_get_contents($entry[1][1]);$errors=stream_get_contents($entry[1][2]);fclose($entry[1][1]);fclose($entry[1][2]);$closed=proc_close($entry[0]);$GLOBALS['children'][$index]=null;
 $code=$status['exitcode']>=0?$status['exitcode']:$closed;$result=json_decode(trim($out),true);
 at_check($code===0&&$errors===''&&is_array($result)&&$result['success']===true&&$result['root']>0,'Actual concurrent creator succeeds: '.$errors.$out);return$result;
}
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 at_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;at_sql('CREATE DATABASE '.$otherSchema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$otherCreated=true;at_sql('USE '.$schema);
 $ddl=file_get_contents($source.'install/schemas/mysql_schema.sql');at_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/',$ddl,$match)===1,'Canonical category schema');at_sql(str_replace('phpbb_album_cat','fixture_album_cat',$match[0]));
 at_sql('CREATE TABLE fixture_users(user_id MEDIUMINT NOT NULL,username VARCHAR(25) NOT NULL,PRIMARY KEY(user_id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 at_sql('CREATE TABLE fixture_members(user_id MEDIUMINT,group_id MEDIUMINT,user_pending TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 at_sql('CREATE TABLE fixture_probe(id INT PRIMARY KEY,v INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');at_sql('INSERT INTO fixture_probe VALUES(1,1)');
 at_sql("INSERT INTO fixture_users VALUES(7,'".$control->sql_escape("O'Connor \\ 😀")."'),(8,'Independent owner')");at_sql('INSERT INTO fixture_members VALUES(7,10,0)');
 $db=new AlbumTransactionSource($host,'root',$password,$schema,false);$db->otherSchema=$otherSchema;$original=$db;
 $db->reset_case();at_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===true&&at_count()===1&&$GLOBALS['db']===$original&&$db->closed===1,'Real owned transaction commits/closes and restores caller');$baseline=$db->queries;$cases++;
 foreach($baseline as$index=>$sql){at_sql('DELETE FROM fixture_album_cat');$db->reset_case($index+1);at_check(at_failed_create()&&at_count()===0&&$GLOBALS['db']===$original&&$db->closed===1,'Every owned statement failure rolls back/closes without caller damage: '.($index+1));$cases++;}
 foreach(array('same-object','borrowed-handle','throw','wrong-schema','source-metadata-false','source-metadata-throw')as$kind){at_sql('DELETE FROM fixture_album_cat');$db->reset_case(0,$kind);at_check(at_failed_create()&&at_count()===0&&$db->sql_query('SELECT 1')!==false&&$db->closed===($kind==='wrong-schema'?1:0),'Reject unsafe dedicated factory without closing caller: '.$kind);$cases++;}
 at_sql('DELETE FROM fixture_album_cat');$db->reset_case(0,'lost-commit-ack');at_check(at_failed_create()&&at_count()===1&&$db->closed===1,'Unacknowledged real commit is not reported as success');$cases++;
 $db->reset_case();at_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===true&&at_count()===1,'Retry after lost commit acknowledgement is idempotent');$cases++;
 at_sql('DELETE FROM fixture_album_cat');$db->reset_case();$r=$db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time');$before=$db->sql_fetchrow($r);$db->sql_freeresult($r);
 at_check($db->sql_query('START TRANSACTION')&&$db->sql_query('UPDATE fixture_probe SET v=2 WHERE id=1'),'Unrelated caller transaction');$r=$db->sql_query('SELECT v FROM fixture_probe WHERE id=1');$db->sql_freeresult($r);
 at_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===true&&at_count()===1,'Independent root commit does not borrow caller transaction');
 $r=$db->sql_query('SELECT v FROM fixture_probe WHERE id=1');$pending=$db->sql_fetchrow($r);$db->sql_freeresult($r);at_check((int)$pending['v']===2&&(int)at_rows('SELECT v FROM fixture_probe WHERE id=1')[0]['v']===1,'Caller remains uncommitted and unrolled back');$cases++;
 $cat_id=album_get_personal_root_id(7);at_check((int)$cat_id<=0,'Older repeatable-read caller snapshot may not see independent root');
 $upload=file_get_contents($source.'album_upload.php');at_check(preg_match('/\$cat_id = album_get_personal_root_id\(\$album_user_id\);\s*if \(\(int\) \$cat_id <= 0\)\s*\{.*?\}/s',$upload,$guard)===1,'Actual upload visibility guard');$album_user_id=7;$failed=false;try{eval($guard[0]);}catch(AlbumTransactionExit$e){$failed=$e->getMessage()==='creation-failed';}at_check($failed,'Stale caller snapshot stops before virtual-category upload');$cases++;
 $admin=file_get_contents($source.'album_personal_cat_admin.php');at_check(preg_match('/if \(\(int\) album_get_personal_root_id\(\$album_user_id\) <= 0\)\s*\{.*?\}/s',$admin,$guard)===1,'Actual category-administration visibility guard');$failed=false;try{eval($guard[0]);}catch(AlbumTransactionExit$e){$failed=$e->getMessage()==='creation-failed';}at_check($failed,'Stale caller snapshot cannot continue category administration');$cases++;
 at_check($db->sql_query('ROLLBACK')!==false&&(int)at_rows('SELECT v FROM fixture_probe WHERE id=1')[0]['v']===1,'Caller retains its own rollback');$r=$db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time');$after=$db->sql_fetchrow($r);$db->sql_freeresult($r);at_check($after===$before,'Caller SQL mode and lock timeout unchanged');$cases++;
 foreach(array('fixture_users','fixture_album_cat')as$table){foreach(array('engine','format','charset')as$change){at_sql('DELETE FROM fixture_album_cat');at_sql($change==='engine'?'ALTER TABLE '.$table.' ENGINE=MyISAM':($change==='format'?'ALTER TABLE '.$table.' ROW_FORMAT=COMPACT':'ALTER TABLE '.$table.' DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci'));$db->reset_case();at_check(at_failed_create()&&at_count()===0,'Reject legacy table before writing: '.$table.'/'.$change);at_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}}
 at_check(mkdir($fixture,0700),'Own parallel-worker barriers');
 foreach(array('normal','slashes','quotes')as$mode){
  at_sql('DELETE FROM fixture_album_cat');$first=at_start_child('hold',$mode);$deadline=microtime(true)+8;
  while(!is_file($fixture.'/holder-locked')){if(microtime(true)>$deadline){throw new RuntimeException('Holder failed to acquire owner lock');}usleep(10000);clearstatcache();}
  $second=at_start_child('peer',$mode);$a=at_finish_child($first);$b=at_finish_child($second);
  at_check($a['root']===$b['root']&&at_count()===1,'Two actual concurrent requests resolve one root: '.$mode);
  $row=at_rows('SELECT cat_title FROM fixture_album_cat')[0];at_check($row['cat_title']==="Gallery of O'Connor \\ 😀",'Concurrent root text retained across SQL modes');unlink($fixture.'/holder-locked');unlink($fixture.'/peer-attempt');$cases++;
 }
 echo 'Native Album root transactions: '.$cases.' dedicated/fault/caller-transaction/storage/real-process concurrency cases PASS; '.count($baseline)." owned statements fault-injected; no live HTTP/data.\n";
}finally{
 foreach($children as$entry){if(!$entry){continue;}$status=proc_get_status($entry[0]);if($status['running']){proc_terminate($entry[0]);}foreach(array(1,2)as$pipe){if(is_resource($entry[1][$pipe])){fclose($entry[1][$pipe]);}}proc_close($entry[0]);}
 if($db){$db->sql_close();}if($created){$control->sql_query('DROP DATABASE '.$schema);}if($otherCreated){$control->sql_query('DROP DATABASE '.$otherSchema);}$control->sql_close();
 if(is_dir($fixture)){foreach(array('holder-locked','peer-attempt')as$name){if(is_file($fixture.'/'.$name)){unlink($fixture.'/'.$name);}}rmdir($fixture);}restore_error_handler();error_reporting($reporting);
}
