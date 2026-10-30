<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_CATEGORY_WRITE_NATIVE')!=='1'){echo "Concurrent category writes require an explicitly enabled disposable database.\n";return;}
$original=file_get_contents(__DIR__.'/check-pafiledb-category-write-native.php');$fixture_dir=var_export(__DIR__,true);
$cut=strpos($original," foreach(array('root','delegated')as");$setup_end=strpos($original,'$port=getenv(');
if($cut===false||$setup_end===false){throw new RuntimeException('Category fixture boundaries changed');}
$definitions=str_replace('__DIR__',$fixture_dir,substr($original,5,$setup_end-5));
if(isset($argv[1])&&$argv[1]==='peer'){
 if(isset($argv[3])&&$argv[3]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
 eval($definitions);$name=isset($argv[2])?$argv[2]:'';
 if(!preg_match('/^codex_pa_category_concurrency_[a-f0-9]{16}$/D',$name)){throw new RuntimeException('Own concurrent fixture schema required');}
 class CategoryConcurrentConnection extends CategoryWriteConnection{
  function sql_query($sql,$transaction=false){
   if(strpos($sql,'SELECT GET_LOCK(')===0){$r=$this->inner->sql_query('SELECT CONNECTION_ID() id');$row=$this->inner->sql_fetchrow($r);$this->inner->sql_freeresult($r);echo 'PEER_LOCK:'.$row['id']."\n";fflush(STDOUT);}
   return parent::sql_query($sql,$transaction);
  }
 }
 class CategoryConcurrentDatabase extends CategoryWriteDatabase{function sql_dedicated_connection(){$wrapper=parent::sql_dedicated_connection();return new CategoryConcurrentConnection($wrapper->inner);}}
 $port=getenv('PHPBB_PA_CATEGORY_WRITE_PORT')?:'3306';if(!preg_match('/^[0-9]{1,5}$/D',$port)){throw new RuntimeException('Own port required');}$host='127.0.0.1:'.$port;$password=getenv('PHPBB_PA_CATEGORY_WRITE_PASSWORD')?:'';
 $cw_cases=$cw_writes=$cw_fail=0;$cw_ack=false;$cw_commit='';$cw_queries=$cw_after=array();$cw_hook=$cw_after_commit=null;
 $db=new CategoryConcurrentDatabase($host,'root',$password,$name,false);$userdata=array('user_id'=>8,'user_level'=>USER,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'category-8');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=cw_post(array('sid'=>'category-8','cat_name'=>'Concurrent root','cat_desc'=>'Concurrent description','cat_parent'=>'2','cat_allow_file'=>'1','cat_allow_ratings'=>'1','cat_allow_comments'=>'1'));
 try{pafiledb_category_save($db,4,$_POST);throw new RuntimeException('Concurrent inverse move unexpectedly succeeded');}
 catch(PaFileDbCategoryFailure$error){if($error->getMessage()!=='PA_category_tree_invalid'){throw$error;}echo json_encode(array('rejected'=>$error->getMessage())),"\n";}
 finally{$db->sql_close();}
 exit;
}
$head=str_replace('__DIR__',$fixture_dir,substr($original,5,$cut-5));$head=str_replace('codex_pa_category_write_','codex_pa_category_concurrency_',$head);
$body=<<<'PHP'
 cw_reset('root','edit');$child=null;$pipes=array();$waiting=false;
 $cw_hook=function($sql)use(&$child,&$pipes,&$waiting,$name){
  if($sql!=='COMMIT'){return;}$GLOBALS['cw_hook']=null;try{
  $command=escapeshellarg(PHP_BINARY);if(strncasecmp(PHP_OS,'WIN',3)===0){$command.=' -n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll';}
  $command.=' -d date.timezone=UTC '.escapeshellarg(__FILE__).' peer '.escapeshellarg($name).' '.(defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw');
  $child=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));
  if(!is_resource($child)){throw new RuntimeException('Own concurrent worker unavailable');}fclose($pipes[0]);$signal=fgets($pipes[1]);
  if(!is_string($signal)||!preg_match('/^PEER_LOCK:([1-9][0-9]*)\s*$/D',$signal,$m)){throw new RuntimeException('Own concurrent worker did not reach its lease: '.json_encode(array($signal,stream_get_contents($pipes[1]),stream_get_contents($pipes[2]))));}
  for($attempt=0;$attempt<100;$attempt++){
   $rows=cw_rows('SELECT STATE AS wait_state FROM information_schema.PROCESSLIST WHERE ID='.(int)$m[1]." AND DB='".$name."'");
   if(count($rows)===1&&stripos($rows[0]['wait_state'],'user lock')!==false){$waiting=true;break;}usleep(20000);
  }
  cw_check($waiting,'Independent inverse move waits on the actual shared mutation lease before the first commit');
  }catch(Exception$error){fwrite(STDERR,'Concurrent fixture hook: '.$error->getMessage()."\n");throw$error;}
 };
 cw_check(pafiledb_category_save($db,2,$_POST)===2,'First move commits under its own current authority');$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($child);$child=null;$proof=json_decode(trim($output),true);
 cw_check($code===0&&$errors===''&&isset($proof['rejected'])&&$proof['rejected']==='PA_category_tree_invalid','Second editor rechecks committed topology and rejects inverse cycle');
 $rows=cw_rows('SELECT cat_id,cat_parent FROM fixture_pa_cat WHERE cat_id IN(2,4) ORDER BY cat_id');
 cw_check((int)$rows[0]['cat_parent']===4&&$rows[1]['cat_parent']===null,'Only the first complete move persists; root parent representation retained');
 echo 'Native concurrent category writes: actual independent root/delegated workers, observed lease wait and rejected inverse cycle PASS.',"\n";
PHP;
$tail=<<<'PHP'
}finally{
 $cw_hook=$cw_after_commit=null;
 if(isset($child)&&is_resource($child)){if(isset($pipes[1])&&is_resource($pipes[1])){stream_get_contents($pipes[1]);fclose($pipes[1]);}if(isset($pipes[2])&&is_resource($pipes[2])){stream_get_contents($pipes[2]);fclose($pipes[2]);}proc_close($child);}
 if($db){$db->sql_close();}if($peer){$peer->sql_close();}if(!preg_match('/^codex_pa_category_concurrency_[a-f0-9]{16}$/D',$name)){throw new RuntimeException('Own concurrent schema required');}if($created){$control->sql_query('DROP DATABASE '.$name);}$control->sql_close();restore_error_handler();
}
PHP;
$body=str_replace('__FILE__',var_export(__FILE__,true),$body);eval($head.$body.$tail);
