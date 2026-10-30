<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_PA_CATEGORY_WRITE_NATIVE')!=='1'){echo "Native download category writes require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);if(isset($argv[1])&&$argv[1]==='legacy'){define('PHPBB_LEGACY_REQUEST_ESCAPED',true);}
$project=dirname(dirname(__DIR__));$phpbb_root_path=$project.'/phpBB2/';$phpEx='php';$table_prefix='fixture_';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'attach_mod/includes/constants.php';require$phpbb_root_path.'pafiledb/includes/pafiledb_constants.php';require$phpbb_root_path.'db/mysqli.php';
require$phpbb_root_path.'pafiledb/includes/functions.php';require$phpbb_root_path.'pafiledb/includes/functions_pafiledb.php';require$phpbb_root_path.'pafiledb/includes/functions_category_storage.php';require$phpbb_root_path.'includes/functions_jr_admin.php';require __DIR__.'/native-fixture-locks.php';
function append_sid($url,$html=true){return$url;}
$lang=array();foreach(array('lang_main','lang_admin','lang_pafiledb','lang_admin_pafiledb')as$file){require$phpbb_root_path.'language/lang_english/'.$file.'.php';}
function message_die($level,$text){throw new RuntimeException($text);}
function cw_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['cw_cases']++;}
class CategoryWriteConnection{
 public$inner,$db_connect_id,$active=false;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_query($sql,$transaction=false){
  if($sql==='START TRANSACTION'){$this->active=true;}
  if(!$this->active){return$this->inner->sql_query($sql,$transaction);}
  if($GLOBALS['cw_ack']){$GLOBALS['cw_after'][]=$sql;}$GLOBALS['cw_queries'][]=$sql;
  if(is_callable($GLOBALS['cw_hook'])){call_user_func($GLOBALS['cw_hook'],$sql,$this);}
  if(preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)&&++$GLOBALS['cw_writes']===$GLOBALS['cw_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['cw_commit']==='fail'){return false;}
  $r=$this->inner->sql_query($sql,$transaction);
  if($sql==='COMMIT'&&$r&&$GLOBALS['cw_commit']==='ack'){$this->active=false;return false;}
  if($sql==='COMMIT'&&$r){$GLOBALS['cw_ack']=true;if(is_callable($GLOBALS['cw_after_commit'])){call_user_func($GLOBALS['cw_after_commit'],$this);}}
  if(($sql==='COMMIT'||$sql==='ROLLBACK')&&$r){$this->active=false;}return$r;
 }
}
class CategoryWriteDatabase extends sql_db{
 public$factories=0;
 function sql_dedicated_connection(){$this->factories++;return new CategoryWriteConnection(parent::sql_dedicated_connection());}
 function sql_query($sql='',$transaction=false){if(preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql)){throw new RuntimeException('Category model wrote outside its owning connection');}return parent::sql_query($sql,$transaction);}
}
function cw_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);if(!$r){throw new RuntimeException('Own category write fixture SQL failed: '.json_encode($GLOBALS['peer']->sql_error()));}return$r;}
function cw_rows($sql){$r=cw_sql($sql);try{return$GLOBALS['peer']->sql_fetchrowset($r);}finally{$GLOBALS['peer']->sql_freeresult($r);}}
function cw_insert($table,$values){
 foreach($GLOBALS['cw_columns'][$table]as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}cw_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).')VALUES('.implode(',',$encoded).')');
}
function cw_snapshot(){return cw_rows('SELECT * FROM fixture_pa_cat ORDER BY cat_id');}
function cw_post($data){return defined('PHPBB_LEGACY_REQUEST_ESCAPED')?phpbb_addslashes_recursive($data):$data;}
function cw_reset($actor,$kind){
 global$userdata,$db;
 $GLOBALS['cw_hook']=$GLOBALS['cw_after_commit']=null;$GLOBALS['cw_ack']=false;$GLOBALS['cw_after']=$GLOBALS['cw_queries']=array();$GLOBALS['cw_fail']=$GLOBALS['cw_writes']=0;$GLOBALS['cw_commit']='';$GLOBALS['cw_current_kind']=$kind;$db=$GLOBALS['cw_main'];
 cw_sql('DELETE FROM fixture_pa_cat');cw_sql('ALTER TABLE fixture_pa_cat AUTO_INCREMENT=6');cw_sql('START TRANSACTION');
 foreach(array('fixture_users','fixture_sessions','fixture_jr_admin_users','fixture_config')as$table){cw_sql('DELETE FROM '.$table);}
 foreach(array(7,8,9)as$id){cw_insert('fixture_users',array('user_id'=>$id,'username'=>'Editor'.$id,'user_level'=>$id===7?ADMIN:USER,'user_active'=>1));}
 foreach(array(7,8)as$id){cw_insert('fixture_sessions',array('session_id'=>'category-'.$id,'session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>1,'session_time'=>time()));}
 cw_insert('fixture_config',array('config_name'=>'session_length','config_value'=>'3600'));
 $routes=jr_admin_authorization_routes();$hash=array_search('admin_pa_category.php',$routes,true);cw_check($hash!==false,'Registered exact category administration route');cw_insert('fixture_jr_admin_users',array('user_id'=>8,'user_jr_admin'=>$hash));
 foreach(array(1=>array(0,10,1),2=>array(1,10,1),3=>array(2,10,1),4=>array(null,20,1),5=>array(1,20,0))as$id=>$data){cw_insert('fixture_pa_cat',array('cat_id'=>$id,'cat_parent'=>$data[0],'cat_name'=>'Category'.$id,'cat_desc'=>'Description'.$id,'parents_data'=>'legacy'.$id,'cat_order'=>$data[1],'cat_allow_file'=>$data[2],'cat_files'=>8,'cat_last_file_id'=>17,'cat_last_file_name'=>'Old','cat_last_file_time'=>123));}
 cw_sql('COMMIT');$id=$actor==='root'?7:8;$GLOBALS['cw_actor']=$id;
 $userdata=array('user_id'=>$id,'user_level'=>$id===7?ADMIN:USER,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'category-'.$id);
 $_SERVER['REQUEST_METHOD']='POST';$_POST=cw_post(array('sid'=>'category-'.$id,'cat_name'=>"Grüße O'Reilly \\ path &amp;",'cat_desc'=>"<b>Ä 😀</b>\nSecond",'cat_parent'=>$kind==='new'?'1':'4','cat_allow_file'=>'1','cat_allow_ratings'=>'0','cat_allow_comments'=>'1'));
 $GLOBALS['cw_policy']=new pafiledb();foreach(cw_snapshot()as$row){$GLOBALS['cw_policy']->cat_rowset[(int)$row['cat_id']]=$row;}$GLOBALS['cw_queries']=array();$db->factories=0;
}
function cw_run(){global$cat_id;return$GLOBALS['cw_policy']->update_add_cat($GLOBALS['cw_current_kind']==='new'?0:2);}
function cw_denied($key=null){$id=cw_run();$errors=$GLOBALS['cw_policy']->error;cw_check($id===null&&count($errors)===1,'Rejected category save has no published ID');if($key!==null){cw_check($errors[0]===$GLOBALS['lang'][$key],'Controlled localized category rejection');}}
function cw_revoke($kind){$id=$GLOBALS['cw_actor'];$changes=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id='.$id,'grant'=>'DELETE FROM fixture_jr_admin_users WHERE user_id='.$id,'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,'missing'=>"DELETE FROM fixture_sessions WHERE session_id='category-".$id."'",'logout'=>'UPDATE fixture_sessions SET session_logged_in=0 WHERE session_user_id='.$id,'acp'=>'UPDATE fixture_sessions SET session_admin=0 WHERE session_user_id='.$id,'foreign'=>'UPDATE fixture_sessions SET session_user_id=9 WHERE session_user_id='.$id,'case'=>"UPDATE fixture_sessions SET session_id='CATEGORY-".$id."' WHERE session_user_id=".$id,'expired'=>'UPDATE fixture_sessions SET session_time=1 WHERE session_user_id='.$id);return$changes[$kind];}
function cw_boundary($sql){return preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)||$sql==='COMMIT';}
$port=getenv('PHPBB_PA_CATEGORY_WRITE_PORT')?:'3306';$password=getenv('PHPBB_PA_CATEGORY_WRITE_PASSWORD')?:'';if(!preg_match('/^[0-9]{1,5}$/D',$port)||(int)$port<1||(int)$port>65535){throw new RuntimeException('Own loopback port required');}
$name='codex_pa_category_write_'.bin2hex(phpbb_random_bytes(8));$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);$peer=$db=null;$created=false;$cw_cases=$cw_serialized=0;$cw_columns=array();$cw_queries=$cw_after=array();$cw_hook=$cw_after_commit=null;$cw_ack=false;$cw_writes=$cw_fail=0;$cw_commit='';
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 cw_check($control->sql_query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own category writer schema');$created=true;$peer=new sql_db($host,'root',$password,$name,false);$cw_main=$db=new CategoryWriteDatabase($host,'root',$password,$name,false);
 $schema=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');foreach(array('pa_cat','users','sessions','jr_admin_users','config')as$suffix){cw_check(preg_match('/^CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/m',$schema,$m)===1,'Canonical writer participant');cw_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));$cw_columns['fixture_'.$suffix]=cw_rows('SHOW COLUMNS FROM fixture_'.$suffix);}
 foreach(array('root','delegated')as$actor){foreach(array('new','edit')as$kind){
  cw_reset($actor,$kind);$before=cw_snapshot();$id=cw_run();cw_check($id===($kind==='new'?6:2)&&!$cw_policy->error&&$cw_ack,'Authorized exact-route category save');$after=cw_snapshot();$writes=$cw_writes;$boundaries=array_values(array_filter($cw_queries,'cw_boundary'));
  $row=cw_rows('SELECT * FROM fixture_pa_cat WHERE cat_id='.$id)[0];cw_check($row['cat_name']===htmlspecialchars("Grüße O'Reilly \\ path &amp;",ENT_QUOTES,'UTF-8')&&$row['cat_desc']===htmlspecialchars("<b>Ä 😀</b>\nSecond",ENT_QUOTES,'UTF-8'),'Actual raw/legacy Unicode, apostrophe, backslash and literal entities preserved');
  cw_check($row['parents_data']===($kind==='new'?'':'legacy2'),'Existing ancestry data retained; fresh strict-schema category initialized');
  foreach($after as$r){cw_check((int)$r['cat_files']===-1&&(int)$r['cat_last_file_id']===0&&$r['cat_last_file_name']===''&&(int)$r['cat_last_file_time']===0,'Derived file metadata invalidated inside accepted transaction');}
  $queries=$cw_after;$cw_policy->_pafiledb();cw_check($cw_after===$queries&&$cw_main->factories===1,'No deferred writes or extra writer after acknowledged save');
  for($fail=1;$fail<=$writes;$fail++){cw_reset($actor,$kind);$before=cw_snapshot();$cw_fail=$fail;cw_denied('PA_category_failed');cw_check(cw_snapshot()===$before&&!$cw_ack,'Every write failure rolls back category/order/cache together');}
  foreach(array('fail','ack')as$commit){cw_reset($actor,$kind);$before=cw_snapshot();$cw_commit=$commit;cw_denied('PA_category_failed');cw_check(cw_snapshot()===($commit==='fail'?$before:$after)&&!$cw_ack,'Failed/lost COMMIT never publishes false success');}
  foreach(array('inactive','missing','logout','acp','foreign','case','expired',$actor==='root'?'role':'grant')as$change){cw_reset($actor,$kind);cw_sql(cw_revoke($change));$before=cw_snapshot();cw_denied();cw_check(cw_snapshot()===$before,'Current revoked authority preserves category data');}
  foreach(array('missing',$actor==='root'?'role':'grant')as$change){for($nth=1;$nth<=count($boundaries);$nth++){
   cw_reset($actor,$kind);$seen=0;$reached=$blocked=false;$cw_hook=function($sql)use($change,$nth,&$seen,&$reached,&$blocked){if(!cw_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['cw_hook']=null;$reached=true;$probe=native_fixture_conflict_query($GLOBALS['peer'],cw_revoke($change));cw_check($probe[0]===false&&(int)$probe[1]['code']===1205,'Current role/session stays locked through each write/commit');$blocked=true;};
   cw_check(cw_run()===($kind==='new'?6:2)&&$reached&&$blocked&&cw_snapshot()===$after,'Serialized revocation follows complete accepted save');$cw_serialized++;cw_sql(cw_revoke($change));cw_denied();
  }}
  cw_reset($actor,$kind);$cw_hook=function($sql){if(strpos($sql,"SELECT config_value FROM ".CONFIG_TABLE)===0){$GLOBALS['cw_hook']=null;cw_sql(cw_revoke($GLOBALS['cw_actor']===7?'role':'grant'));}};$before=cw_snapshot();cw_denied();cw_check(cw_snapshot()===$before,'Authority revoked before actor locks is rejected');
  foreach(array('none','role','missing','disconnect')as$change){cw_reset($actor,$kind);$cw_after_commit=function($connection)use($change){if($change==='disconnect'){cw_sql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}elseif($change!=='none'){cw_sql(cw_revoke($change==='role'&&$GLOBALS['cw_actor']===8?'grant':$change));}};cw_check(cw_run()===($kind==='new'?6:2)&&cw_snapshot()===$after&&!$cw_after,'Accepted save survives later revocation/disconnect without more SQL');}
  echo $actor.'/'.$kind." actual category save/rollback/current-authority checks passed\n";
 }}
 foreach(array('self','descendant','cached-descendant','ancestor-cycle','missing-ancestor','missing-parent','missing-target')as$case){
  cw_reset('root','edit');
  if($case==='self'){$_POST['cat_parent']='2';}elseif($case==='descendant'){$_POST['cat_parent']='3';}
  elseif($case==='cached-descendant'){cw_sql('UPDATE fixture_pa_cat SET cat_parent=3 WHERE cat_id=4');}
  elseif($case==='ancestor-cycle'){cw_sql('UPDATE fixture_pa_cat SET cat_parent=3 WHERE cat_id=4');cw_sql('UPDATE fixture_pa_cat SET cat_parent=4 WHERE cat_id=3');}
  elseif($case==='missing-ancestor'){cw_sql('UPDATE fixture_pa_cat SET cat_parent=99 WHERE cat_id=4');}
  elseif($case==='missing-parent'){cw_sql('DELETE FROM fixture_pa_cat WHERE cat_id=4');}
  else{cw_sql('DELETE FROM fixture_pa_cat WHERE cat_id=2');}
  $before=cw_snapshot();cw_denied($case==='missing-target'?'PA_category_changed':'PA_category_tree_invalid');cw_check(cw_snapshot()===$before,'Cycle/missing/stale-tree rejection leaves every row unchanged');
 }
 cw_reset('root','edit');cw_sql('UPDATE fixture_pa_cat SET cat_parent=3 WHERE cat_id=2');$_POST['cat_parent']='0';cw_check(cw_run()===2&&(int)cw_rows('SELECT cat_parent FROM fixture_pa_cat WHERE cat_id=2')[0]['cat_parent']===0,'Valid root move can repair an existing cycle');
 cw_reset('delegated','edit');$before=cw_snapshot();$cw_hook=function($sql){if(strpos($sql,'SELECT * FROM '.PA_CATEGORY_TABLE)===0){$GLOBALS['cw_hook']=null;$GLOBALS['userdata']=array('user_id'=>7,'user_level'=>ADMIN,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'category-7');}};cw_denied('Not_Authorised');cw_check(cw_snapshot()===$before,'A different global ACP identity cannot replace the frozen writer actor');
 foreach(array('cat_parent'=>array('bad'),'cat_allow_file'=>'2','cat_name'=>"\xff",'cat_desc'=>"bad\0value",'sid'=>'wrong')as$field=>$value){cw_reset('root','edit');$_POST[$field]=$value;$before=cw_snapshot();cw_denied();cw_check(cw_snapshot()===$before&&$cw_main->factories===0,'Malformed whole request rejected before opening writer');}
 foreach(array('pa_cat','users','sessions','jr_admin_users','config')as$suffix){cw_reset('root','edit');cw_sql('ALTER TABLE fixture_'.$suffix.' ENGINE=MyISAM');$before=cw_snapshot();cw_denied('PA_category_upgrade');cw_check(cw_snapshot()===$before,'Nontransactional participant rejected before data mutation');cw_sql('ALTER TABLE fixture_'.$suffix.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 echo 'Native category writes: ',$cw_cases,' checks; ',$cw_serialized," serialized boundary revocations; exact model, strict schema, tree, Unicode, failures and authority PASS.\n";
}finally{
 $cw_hook=$cw_after_commit=null;if($db){$db->sql_close();}if($peer){$peer->sql_close();}if(!preg_match('/^codex_pa_category_write_[a-f0-9]{16}$/D',$name)){throw new RuntimeException('Own category writer schema required');}if($created){$control->sql_query('DROP DATABASE '.$name);}$control->sql_close();restore_error_handler();
}
