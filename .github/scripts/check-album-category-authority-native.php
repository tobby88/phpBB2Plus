<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_CATEGORY_NATIVE')!=='1'){echo "Native Album category authority fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');define('USER_GROUP_TABLE','fixture_members');$table_prefix='fixture_';
$source=dirname(dirname(__DIR__)).'/phpBB2/';require$source.'includes/php_compat.php';require$source.'db/mysqli.php';require$source.'album_mod/album_constants.php';require$source.'album_mod/album_functions.php';require$source.'album_mod/album_hierarchy_auth.php';require$source.'album_mod/album_hierarchy_sql.php';require$source.'album_mod/album_category_storage.php';require __DIR__.'/album-native-session.php';require __DIR__.'/album-native-observer.php';
function aca_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($type,$message){throw new RuntimeException($message);}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
class AlbumCategoryObservedSource extends sql_db {
 var$queries=array();var$fail_at=0;var$hook=null;var$worker_mode='';var$worker_directory='';var$worker_gate_done=false;
 function reset_observer($failure=0){$this->queries=array();$this->fail_at=$failure;$this->hook=null;}
 function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
 function observed_query($database,$sql,$transaction=false){
  $this->queries[]=$sql;if($this->fail_at>0&&count($this->queries)===$this->fail_at){return false;}
  if(strpos($sql,'INSERT INTO fixture_album_cat')===0&&is_callable($this->hook)){$hook=$this->hook;$this->hook=null;call_user_func($hook);}
  $ownerLock=strpos($sql,'SELECT user_id FROM fixture_users WHERE ')===0&&strpos($sql,' FOR UPDATE')!==false;
  if($ownerLock&&$this->worker_mode==='peer'&&!$this->worker_gate_done){aca_check(file_put_contents($this->worker_directory.'/peer-attempt','1')!==false,'Peer reached actual owner-lock request');$this->worker_gate_done=true;}
  $result=$database->sql_query($sql,$transaction);
  if($ownerLock&&$result&&$this->worker_mode==='holder'&&!$this->worker_gate_done){
   $this->worker_gate_done=true;aca_check(file_put_contents($this->worker_directory.'/holder-locked','1')!==false,'Holder acquired actual owner row');$deadline=microtime(true)+8;
   while(!is_file($this->worker_directory.'/peer-attempt')){if(microtime(true)>$deadline){throw new RuntimeException('Owned category peer barrier deadline');}usleep(10000);clearstatcache();}
  }
  return$result;
 }
}
$body=file_get_contents($source.'includes/functions.php');aca_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms',$body,$m)===1,'Actual group-list parser');eval($m[0]);function album_sql_id_list($ids){return phpbb_sql_id_list($ids);}
$body=file_get_contents($source.'album_personal_cat_admin.php');foreach(array('album_personal_permission_value','album_personal_category_text')as$function){aca_check(preg_match('/^function '.$function.'\(.*?^\}/ms',$body,$m)===1,'Actual controller input helper');eval($m[0]);}
$start=strpos($body,"\t\t\t// Get posting variables.");$end=strpos($body,"\t\t\t// Return a message...",$start);aca_check($start!==false&&$end>$start,'Actual complete new-category POST handler');$handler=substr($body,$start,$end-$start);
$loader="require_once dirname(__FILE__) . '/album_mod/album_category_storage.php';";aca_check(substr_count($handler,$loader)===1,'Exactly one actual provider handoff');$handler=str_replace($loader,'// Exact provider already loaded by this isolated fixture.',$handler);
$port=getenv('PHPBB_ALBUM_CATEGORY_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_CATEGORY_PASSWORD')?:'';aca_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
if(isset($argv[1])&&$argv[1]==='worker'){
 aca_check(isset($argv[2],$argv[3],$argv[4],$argv[5])&&preg_match('/^codex_album_cat_grant_[a-f0-9]{16}$/D',$argv[2])&&in_array($argv[3],array('holder','peer'),true)&&in_array($argv[4],array('normal','slashes','quotes'),true)&&in_array($argv[5],array('1','20'),true),'Only actual owned category worker inputs');
 $schema=$argv[2];$db=new AlbumCategoryObservedSource($host,'root',$password,$schema,false);aca_check($db->db_connect_id,'Own worker database');$db->worker_mode=$argv[3];$db->worker_directory=sys_get_temp_dir().'/'.$schema.'-workers';aca_check(is_dir($db->worker_directory)&&!is_link($db->worker_directory),'Own worker barrier directory');
 $userdata=array('user_id'=>7,'username'=>'Synthetic','user_level'=>0,'user_active'=>1,'session_logged_in'=>true,'session_id'=>str_repeat('a',32));$album_data=array();
 $album_config=array('rate'=>1,'comment'=>1,'personal_gallery'=>ALBUM_USER,'personal_gallery_view'=>ALBUM_GUEST,'personal_gallery_private'=>'10','personal_allow_gallery_mod'=>1,'personal_allow_sub_categories'=>1,'personal_sub_category_limit'=>(int)$argv[5]);
 $mode=$argv[4]==='normal'?'':($argv[4]==='slashes'?'STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES':'STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES');aca_check($db->sql_query("SET SESSION sql_mode='".$mode."'"),'Worker SQL mode');
 try{$ok=album_personal_category_create(7,ALBUM_ROOT_CATEGORY,'Concurrent '.$argv[3],"Mode 'slash' \\ 😀",array('view'=>ALBUM_USER,'upload'=>ALBUM_PRIVATE,'rate'=>ALBUM_USER,'comment'=>ALBUM_USER));echo json_encode(array('success'=>$ok)),"\n";}finally{$db->sql_close();}exit(0);
}
$control=new sql_db($host,'root',$password,'',false);aca_check($control->db_connect_id,'Own native control');$schema='codex_album_cat_grant_'.bin2hex(phpbb_random_bytes(8));$created=false;$db=null;$cases=0;$children=array();$workers=sys_get_temp_dir().'/'.$schema.'-workers';
function aca_sql($sql){$r=$GLOBALS['control']->sql_query($sql);aca_check($r!==false,'Own synthetic fixture SQL');return$r;}
function aca_rows($sql){$r=aca_sql($sql);$rows=$GLOBALS['control']->sql_fetchrowset($r);$GLOBALS['control']->sql_freeresult($r);return$rows;}
function aca_start_child($role,$mode,$limit=20){
 $command=escapeshellarg(PHP_BINARY).' ';if(DIRECTORY_SEPARATOR==='\\'){$command.='-n -d '.escapeshellarg('extension_dir='.ini_get('extension_dir')).' -d extension=php_mysqli.dll -d extension=php_openssl.dll ';if(extension_loaded('mbstring')){$command.='-d extension=php_mbstring.dll ';}}
 $command.=escapeshellarg(__FILE__).' worker '.escapeshellarg($GLOBALS['schema']).' '.escapeshellarg($role).' '.escapeshellarg($mode).' '.escapeshellarg((string)$limit);$process=proc_open($command,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,null,array('bypass_shell'=>true));aca_check(is_resource($process),'Own category child');fclose($pipes[0]);$GLOBALS['children'][]=array($process,$pipes);return count($GLOBALS['children'])-1;
}
function aca_finish_child($index,$expected=true){
 $entry=$GLOBALS['children'][$index];$deadline=microtime(true)+12;do{$status=proc_get_status($entry[0]);if(!$status['running']){break;}if(microtime(true)>$deadline){throw new RuntimeException('Own category child deadline');}usleep(10000);}while(true);
 $out=stream_get_contents($entry[1][1]);$errors=stream_get_contents($entry[1][2]);fclose($entry[1][1]);fclose($entry[1][2]);$closed=proc_close($entry[0]);$GLOBALS['children'][$index]=null;$code=$status['exitcode']>=0?$status['exitcode']:$closed;$result=json_decode(trim($out),true);aca_check($code===0&&$errors===''&&is_array($result)&&$result['success']===$expected,'Actual concurrent category child has expected grant: '.$errors.$out);
}
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 aca_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;aca_sql('USE '.$schema);
 aca_sql('CREATE TABLE fixture_users(user_id MEDIUMINT PRIMARY KEY,username VARCHAR(255),user_level TINYINT,user_active TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');aca_sql("INSERT INTO fixture_users VALUES(7,'Actor',1,1),(8,'Other owner',0,1)");
 aca_sql('CREATE TABLE fixture_members(user_id MEDIUMINT,group_id MEDIUMINT,user_pending TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $ddl=file_get_contents($source.'install/schemas/mysql_schema.sql');aca_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/',$ddl,$m)===1,'Canonical owned category schema');aca_sql(str_replace('phpbb_album_cat','fixture_album_cat',$m[0]));album_native_session_schema($control,$source);
 $db=new AlbumCategoryObservedSource($host,'root',$password,$schema,false);$original=$db;$sid=str_repeat('a',32);
 $album_config=array('rate'=>1,'comment'=>1,'personal_gallery'=>ALBUM_USER,'personal_gallery_view'=>ALBUM_GUEST,'personal_gallery_private'=>'10','personal_allow_gallery_mod'=>1,'personal_allow_sub_categories'=>1,'personal_sub_category_limit'=>20);
 foreach(array('unchanged-admin','revoked-admin','inactive-actor','logged-out-session','expired-session','missing-session','foreign-session','unchanged-owner')as$case){
  aca_sql('DELETE FROM fixture_album_cat');aca_sql('UPDATE fixture_users SET user_active=1,user_level=0');aca_sql('UPDATE fixture_users SET user_level='.($case==='unchanged-owner'?'0':'1').' WHERE user_id=7');album_native_session_seed($control,7,$sid);
  $owner=$case==='unchanged-owner'?7:8;aca_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_desc,cat_order,cat_user_id,cat_parent,cat_view_level,cat_upload_level) VALUES(10,'Existing root','',0,".$owner.',0,'.ALBUM_GUEST.','.ALBUM_PRIVATE.')');
  $r=$db->sql_query('SELECT user_id,username,user_level,user_active FROM fixture_users WHERE user_id=7');$userdata=$db->sql_fetchrow($r);$db->sql_freeresult($r);$userdata['session_logged_in']=true;$userdata['session_id']=$sid;$snapshot=$userdata;$album_data=array('fixture'=>'preserve on failure');
  if($case==='revoked-admin'){aca_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');}
  if($case==='inactive-actor'){aca_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=7');}
  if($case==='logged-out-session'){aca_sql('UPDATE fixture_sessions SET session_logged_in=0');}
  if($case==='expired-session'){aca_sql('UPDATE fixture_sessions SET session_time='.(time()-3601));}
  if($case==='missing-session'){aca_sql('DELETE FROM fixture_sessions');}
  if($case==='foreign-session'){aca_sql('UPDATE fixture_sessions SET session_user_id=8');}
  $before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');$users=aca_rows('SELECT * FROM fixture_users ORDER BY user_id');$sessions=aca_rows('SELECT * FROM fixture_sessions ORDER BY session_id');
  $allowed=in_array($case,array('unchanged-admin','unchanged-owner'),true);$ok=album_personal_category_create($owner,10,'Synthetic child','Grüße 😀',array('view'=>ALBUM_ADMIN,'upload'=>ALBUM_PRIVATE,'rate'=>ALBUM_USER,'comment'=>ALBUM_USER));
  aca_check($ok===$allowed,'Current actual category grant: '.$case);$rows=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');aca_check($allowed?count($rows)===2:$rows===$before,'Only authorized category insert: '.$case);
  aca_check($db===$original&&$userdata===$snapshot&&aca_rows('SELECT * FROM fixture_users ORDER BY user_id')===$users&&aca_rows('SELECT * FROM fixture_sessions ORDER BY session_id')===$sessions,'Restore caller globals; no account or session rewriting');$cases++;
  aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$album_user_id=$owner;$lang=array('No_valid_category_selected'=>'invalid-title','Personal_category_creation_failed'=>'creation-refused');
  $_POST=array('cat_title'=>addslashes("Grüße 😀 & <'title>"),'cat_desc'=>addslashes("Line \\ note 😀"),'cat_parent_id'=>'10','cat_view_level'=>(string)ALBUM_ADMIN);$denied=false;
  try{eval($handler);}catch(RuntimeException$error){aca_check($error->getMessage()==='creation-refused','Actual controller only renders expected failed grant');$denied=true;}
  $rows=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');aca_check($denied===!$allowed&&count($rows)===($allowed?2:1),'Actual POST handoff enforces same fresh grant');
  if($allowed){aca_check($rows[1]['cat_title']==='Grüße 😀 &amp; &lt;&#039;title&gt;'&&$rows[1]['cat_desc']==="Line \\ note 😀",'Actual POST input and dedicated SQL preserve Unicode, HTML and slash bytes');}
  aca_check($db===$original&&$userdata===$snapshot&&aca_rows('SELECT * FROM fixture_users ORDER BY user_id')===$users&&aca_rows('SELECT * FROM fixture_sessions ORDER BY session_id')===$sessions,'Actual POST handler preserves caller/account/session context');$cases++;
 }
 // The last valid owner remains the baseline for actual statement failures.
 $levels=array('view'=>ALBUM_USER,'upload'=>ALBUM_PRIVATE,'rate'=>ALBUM_USER,'comment'=>ALBUM_USER);aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$db->reset_observer();
 aca_check(album_personal_category_create(7,10,'Baseline','Grüße 😀',$levels),'Native fault baseline succeeds');$statements=count($db->queries);aca_check($statements>10&&$statements<100,'Bounded actual writer statement inventory');$cases++;
 for($failure=1;$failure<=$statements;$failure++){
  aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');$snapshot=$userdata;$album_data=array('fixture'=>'fault context');$db->reset_observer($failure);
  aca_check(album_personal_category_create(7,10,'Fault candidate','Preserve',$levels)===false,'Every actual owned statement can fail closed: '.$failure);
  aca_check(aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id')===$before&&$userdata===$snapshot&&$db===$original&&$album_data===array('fixture'=>'fault context'),'Statement failure rolls back only owned work and restores context');$cases++;
 }
 $db->reset_observer();aca_sql('SET SESSION innodb_lock_wait_timeout=0');$blocked=0;
 $db->hook=function()use(&$blocked){foreach(array('UPDATE fixture_users SET user_level=1 WHERE user_id=7','UPDATE fixture_sessions SET session_logged_in=0')as$sql){$r=$GLOBALS['control']->sql_query($sql);$error=$GLOBALS['control']->sql_error();aca_check($r===false&&(int)$error['code']===1205,'Actual 1205 proves current account and login rows stay locked until insert');$blocked++;}};
 aca_check(album_personal_category_create(7,10,'Locked grant','Preserve',$levels)&&$blocked===2,'Actual independent role/logout writers cannot pass grant before commit');$cases++;
 aca_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');aca_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');aca_sql('UPDATE fixture_sessions SET session_logged_in=0');album_native_session_seed($control,7,$sid);$db->reset_observer();
 aca_sql('CREATE TABLE fixture_caller(id INT PRIMARY KEY,body VARCHAR(40)) ENGINE=InnoDB');aca_sql("INSERT INTO fixture_caller VALUES(1,'original')");
 foreach(array(true,false)as$allowed){
  aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');if($allowed){album_native_session_seed($control,7,$sid);}else{aca_sql('DELETE FROM fixture_sessions');}
  aca_check($db->sql_query('START TRANSACTION')&&$db->sql_query("UPDATE fixture_caller SET body='caller pending' WHERE id=1"),'Begin unrelated caller-owned transaction');$snapshot=$userdata;
  aca_check(album_personal_category_create(7,10,'Independent writer','Preserve',$levels)===$allowed,'Dedicated operation does not borrow caller transaction');
  $r=$db->sql_query('SELECT body FROM fixture_caller WHERE id=1');$pending=$db->sql_fetchrow($r);$db->sql_freeresult($r);
  $outside=aca_rows('SELECT body FROM fixture_caller WHERE id=1');aca_check($pending['body']==='caller pending'&&count($outside)===1&&$outside[0]['body']==='original'&&$db===$original&&$userdata===$snapshot,'Neither successful nor denied creation commits or rolls back caller work');aca_check($db->sql_query('ROLLBACK'),'Caller controls its own rollback');
  aca_check(count(aca_rows('SELECT cat_id FROM fixture_album_cat WHERE cat_parent=10'))===($allowed?1:0),'Caller rollback never undoes an independent authorized commit');$cases++;
 }
 album_native_session_seed($control,7,$sid);$db->reset_observer();aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$album_config['personal_sub_category_limit']=1;
 aca_check(album_personal_category_create(7,10,'Last slot','Preserve',$levels),'Exactly one remaining subcategory slot works');$before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');aca_check(!album_personal_category_create(7,10,'Over limit','Preserve',$levels)&&aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id')===$before,'Configured limit cannot be bypassed by another POST');$cases+=2;
 $album_config['personal_sub_category_limit']=-1;aca_check(album_personal_category_create(7,10,'Unlimited','Preserve',$levels),'Historical negative unlimited policy retained');$cases++;
 foreach(array(null,array(),'invalid',1.5,'2147483648','-2147483649')as$invalid){$album_config['personal_sub_category_limit']=$invalid;$before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');aca_check(!album_personal_category_create(7,10,'Invalid policy','Preserve',$levels)&&aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id')===$before,'Malformed category policy cannot create rows');$cases++;}
 $album_config['personal_sub_category_limit']=0;aca_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');aca_check(!album_personal_category_create(7,10,'Disabled slot','Preserve',$levels),'Zero category limit follows disabled UI even for administrators');aca_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');$cases++;$album_config['personal_sub_category_limit']=20;
 aca_check(mkdir($workers,0700),'Own category worker barriers');
 foreach(array(20,1)as$limit){foreach(array('normal','slashes','quotes')as$mode){
  aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$first=aca_start_child('holder',$mode,$limit);$deadline=microtime(true)+8;
  while(!is_file($workers.'/holder-locked')){if(microtime(true)>$deadline){throw new RuntimeException('Owned category holder deadline');}usleep(10000);clearstatcache();}
  $second=aca_start_child('peer',$mode,$limit);aca_finish_child($first);aca_finish_child($second,$limit===20);$rows=aca_rows('SELECT cat_title,cat_desc,cat_order FROM fixture_album_cat WHERE cat_parent=10 ORDER BY cat_order');
  aca_check(count($rows)===($limit===20?2:1)&&$rows[0]['cat_title']==='Concurrent holder'&&(int)$rows[0]['cat_order']===10&&$rows[0]['cat_desc']==="Mode 'slash' \\ 😀",'Concurrent actual requests honor quota, order and SQL-mode text');
  if($limit===20){aca_check($rows[1]['cat_title']==='Concurrent peer'&&(int)$rows[1]['cat_order']===20&&$rows[1]['cat_desc']===$rows[0]['cat_desc'],'Two allowed concurrent requests receive distinct order values');}
  unlink($workers.'/holder-locked');unlink($workers.'/peer-attempt');$cases++;
 }}
 $matrix=array(
  array(7,ALBUM_ROOT_CATEGORY,str_repeat('&#039;',255),str_repeat('😀',65535),$levels,true),
  array(7,10,str_repeat('😀',255),"SQL 'literal' \\ 😀",$levels,true),
  array(7,10,'FROM fixture_members','Ordinary stored text',$levels,true),
  array(7,10,'&amp;amp;','Literal entity',$levels,true),
  array(7,10,str_repeat('a',256),'Too long',$levels,false),
  array(7,10,'Title',str_repeat('x',65536),$levels,false),
  array(7,10,'<raw>','Noncanonical title',$levels,false),
  array(7,10,'&','Noncanonical ampersand',$levels,false),
  array(7,10,'   ','Whitespace title',$levels,false),
  array(7,10,"\xc3",'Invalid UTF8',$levels,false),
  array(7,10,'Title',"\xc3",$levels,false),
  array(7,0,'Title','Invalid parent',$levels,false),
  array(7,-2,'Title','Invalid alias',$levels,false),
  array(7,16777216,'Title','Overwide parent',$levels,false),
  array(7,999,'Title','Absent parent',$levels,false),
  array(8,10,'Title','Other owner',$levels,false),
  array(0,10,'Title','Guest owner',$levels,false),
  array(8388608,10,'Title','Overwide owner',$levels,false),
  array('7',10,'Title','Typed API owner',$levels,false),
  array(7,10,'Title','Missing permissions',array(),false),
  array(7,10,'Title','Nested permissions',array('view'=>array(),'upload'=>0,'rate'=>0,'comment'=>0),false)
 );
 foreach(array('','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','STRICT_ALL_TABLES,ANSI_QUOTES,NO_BACKSLASH_ESCAPES')as$mode){
  aca_check($db->sql_query("SET SESSION sql_mode='".$mode."'"),'Caller mode matrix');$r=$db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time');$settings=$db->sql_fetchrow($r);$db->sql_freeresult($r);
  foreach($matrix as$case){
   aca_sql('DELETE FROM fixture_album_cat WHERE cat_id<>10');$before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');$snapshot=$userdata;$album_data=array('fixture'=>'input context');$db->reset_observer();
   $ok=album_personal_category_create($case[0],$case[1],$case[2],$case[3],$case[4]);aca_check($ok===$case[5],'Actual API logical/typed/canonical/parent boundary');$rows=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');
   aca_check($ok?(count($rows)===2&&$rows[1]['cat_title']===$case[2]&&$rows[1]['cat_desc']===$case[3]):$rows===$before,'Exact complete Unicode/entity/slash storage or unchanged denied data');
   $r=$db->sql_query('SELECT @@SESSION.sql_mode AS mode,@@SESSION.innodb_lock_wait_timeout AS wait_time');$after=$db->sql_fetchrow($r);$db->sql_freeresult($r);aca_check($settings===$after&&$db===$original&&$userdata===$snapshot&&($ok?$album_data===array():$album_data===array('fixture'=>'input context')),'Every SQL-mode outcome restores original caller settings and context');$cases++;
  }
 }
 foreach(array(null,'invalid',new stdClass())as$invalid){$snapshot=$userdata;$userdata=$invalid;$before=aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');aca_check(!album_personal_category_create(7,10,'Invalid actor','Preserve',$levels)&&$userdata===$invalid&&aca_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id')===$before,'Malformed request identity fails without warnings or writes');$userdata=$snapshot;$cases++;}
 echo'Native Album category authority: ',$cases," real create/current-role/account/session/SQL-fault/caller-TX/process-concurrency/input/SQL-mode cases PASS; owned loopback fixtures only.\n";
}finally{
 foreach($children as$entry){if(!$entry){continue;}$status=proc_get_status($entry[0]);if($status['running']){proc_terminate($entry[0]);}foreach(array(1,2)as$pipe){if(is_resource($entry[1][$pipe])){fclose($entry[1][$pipe]);}}proc_close($entry[0]);}
 if($db){$db->sql_close();}if($created){$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();if(is_dir($workers)){aca_check(!is_link($workers),'Only own actual worker directory cleanup');foreach(array('holder-locked','peer-attempt')as$name){if(is_file($workers.'/'.$name)){unlink($workers.'/'.$name);}}rmdir($workers);}restore_error_handler();error_reporting($reporting);
}
