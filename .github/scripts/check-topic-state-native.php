<?php
// Canonical, disposable loopback MariaDB tables; never load a site config.
if(PHP_SAPI!=='cli'||getenv('PHPBB_STATE_NATIVE')!=='1'){echo "Native topic state checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';
require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';require $phpbb_root_path.'includes/functions_topic_state.php';
require $phpbb_root_path.'includes/sessions.php';
function sn_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class StateNativeResponse extends RuntimeException {}
function message_die($level,$message){throw new StateNativeResponse($message);}
class StateNativeAjax extends RuntimeException {var $value;function __construct($v){$this->value=$v;}}
function AJAX_message_die($v){throw new StateNativeAjax($v);}
class StateNativeTemplate {var $vars=array();function assign_vars($vars){$this->vars=$vars;}}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_STATE_PORT')?:'3306';sn_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_STATE_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_state_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);sn_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);
$sn_hook=null;$sn_queries=array();$sn_write=$sn_fail=0;$sn_prefix=$sn_commit='';
class StateNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['sn_queries'][]=$sql;if(is_callable($GLOBALS['sn_hook'])){call_user_func($GLOBALS['sn_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['sn_write']===$GLOBALS['sn_fail']){return false;}
  if($GLOBALS['sn_prefix']!==''&&strpos($sql,$GLOBALS['sn_prefix'])===0){return false;}
  if($sql==='COMMIT'&&$GLOBALS['sn_commit']==='fail'){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['sn_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['sn_commit']==='ack'?false:$r;
 }
}
class StateNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new StateNativeConnection(parent::sql_dedicated_connection());$GLOBALS['sn_writer']=$c;return $c;}}
$db=new StateNativeDatabase($host,'root',$password,$schema,false);
function sn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);sn_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function sn_rows($sql){$r=sn_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function sn_insert($table,$values){
 foreach(sn_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}sn_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}
$sn_tables=array('users','sessions','forums','topics','user_group','auth_access','logs');
function sn_snap(){
 $out=array();foreach($GLOBALS['sn_tables'] as $s){$rows=sn_rows('SELECT * FROM fixture_'.$s);if($s==='logs'){foreach($rows as &$row){sn_check((int)$row['log_time']>0,'Real audit timestamp');$row['log_time']='timestamp';}unset($row);}
  usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function sn_reset($actor='root',$mode='lock'){
 global $sn_hook,$sn_queries,$sn_write,$sn_fail,$sn_prefix,$sn_commit,$userdata,$client_ip;
 $sn_hook=null;$sn_queries=array();$sn_write=$sn_fail=0;$sn_prefix=$sn_commit='';$GLOBALS['sn_error']=null;
 sn_sql('START TRANSACTION');foreach($GLOBALS['sn_tables'] as $s){sn_sql('DELETE FROM fixture_'.$s);}sn_sql('COMMIT');sn_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');
 sn_sql('START TRANSACTION');
 foreach(array(8,9) as $id){sn_insert('fixture_users',array('user_id'=>$id,'username'=>"Grüße O'Reilly 😀",'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1));}
 sn_insert('fixture_sessions',array('session_id'=>'review-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>0));
 sn_insert('fixture_user_group',array('group_id'=>10,'user_id'=>8,'user_pending'=>0));sn_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>3,'auth_mod'=>$actor==='root'?0:1));
 foreach(array(3,4) as $id){sn_insert('fixture_forums',array('forum_id'=>$id,'forum_name'=>'fixture','forum_status'=>FORUM_UNLOCKED));}
 foreach(array(100=>3,101=>3,200=>4) as $id=>$forum){sn_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>$forum,'topic_title'=>"Grüße O'Reilly 😀",'topic_desc'=>'unchanged','topic_poster'=>9,'topic_status'=>$mode==='unlock'?TOPIC_LOCKED:TOPIC_UNLOCKED,'topic_type'=>$mode==='normalise'?POST_STICKY:POST_NORMAL));}
 sn_sql('COMMIT');$userdata=array('user_id'=>8,'username'=>"Grüße O'Reilly 😀",'user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'review-sid');$client_ip='2001:db8::42';
}
function sn_run($mode='lock'){try{return phpbb_moderate_topic_state($GLOBALS['db'],3,array(101,100),$mode);}catch(PhpbbTopicStateException $e){return 'error';}}
function sn_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function sn_revoke($kind){$changes=array('missing'=>"DELETE FROM fixture_sessions WHERE session_id='review-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=9 WHERE session_id='review-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='review-sid'",'case'=>"UPDATE fixture_sessions SET session_id='REVIEW-SID' WHERE session_id='review-sid'",'role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0 WHERE forum_id=3','membership'=>'DELETE FROM fixture_user_group WHERE user_id=8','pending'=>'UPDATE fixture_user_group SET user_pending=1 WHERE user_id=8','read'=>'UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3');return $changes[$kind];}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 require dirname(dirname(__DIR__)).'/update/innodb_migration.php';$migrated=plus_storage_tables($canonical,'fixture_');
 foreach($sn_tables as $suffix){$name='phpbb_'.$suffix;sn_check(in_array('fixture_'.$suffix,$migrated,true),'Normal migration covers '.$suffix);sn_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);sn_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){sn_sql(str_replace($name,'fixture_'.$suffix,$ddl));}}
 sn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 $modes=array('lock'=>array('topic_status',1),'unlock'=>array('topic_status',0),'sticky'=>array('topic_type',1),'announce'=>array('topic_type',2),'normalise'=>array('topic_type',0));
 foreach(array('root','moderator') as $actor){foreach($modes as $action=>$desired){
  sn_reset($actor,$action);$before=sn_snap();sn_check(sn_run($action)===array(100,101),'Complete state '.$actor.'/'.$action.' '.json_encode($sn_error));$after=sn_snap();$writes=$sn_write;
  sn_check(count($after['logs'])===2,'Two audit records');foreach(array(100,101) as $id){sn_check((int)sn_rows('SELECT '.$desired[0].' FROM fixture_topics WHERE topic_id='.$id)[0][$desired[0]]===$desired[1],'Exact desired state');}
  $expected=$before;foreach($expected['topics'] as &$row){if(in_array((int)$row['topic_id'],array(100,101),true)){$row[$desired[0]]=(string)$desired[1];}}unset($row);$expected['logs']=$after['logs'];sn_check($expected===$after,'Every unrelated canonical field retained');
  foreach($after['logs'] as $row){sn_check($row['username']===$userdata['username']&&$row['user_ip']===$client_ip,'UTF-8 audit name and IPv6 retained');}
  sn_check(sn_run($action)===array()&&sn_snap()===$after,'No-op replay never duplicates audit');
  for($n=1;$n<=$writes;$n++){sn_reset($actor,$action);$before=sn_snap();$sn_fail=$n;sn_check(sn_run($action)==='error'&&sn_snap()===$before,'Whole batch rollback at write '.$n.'/'.$action);$cases++;}
  foreach(array('fail','ack') as $kind){sn_reset($actor,$action);$before=sn_snap();$sn_commit=$kind;sn_check(sn_run($action)==='error'&&sn_snap()===($kind==='fail'?$before:$after),'Failed/lost COMMIT is all-before or all-after');$sn_commit='';if($kind==='ack'){sn_check(sn_run($action)===array()&&sn_snap()===$after,'Uncertain completion replay does not duplicate audit');}$cases++;}
 }}
 foreach(array('root','moderator') as $actor){
  sn_reset($actor);sn_run();$after=sn_snap();$boundaries=array_values(array_filter($sn_queries,'sn_boundary'));
  foreach(array('missing','foreign','logout','case','inactive',$actor==='root'?'role':'grant') as $kind){sn_reset($actor);sn_sql(sn_revoke($kind));$before=sn_snap();sn_check(sn_run()==='error'&&sn_snap()===$before,'Current entry authority '.$kind);$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   sn_reset($actor);$seen=0;$reached=$blocked=false;$revoked=null;
   $sn_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!sn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['sn_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(sn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sn_check((int)$e['code']===1205,'Independent authority lock timeout');$blocked=true;}else{$revoked=sn_snap();}};
   $out=sn_run();sn_check($reached,'Every write/commit boundary reached');
   if($blocked){sn_check($out===array(100,101)&&sn_snap()===$after,'Revocation serializes after complete action');sn_sql(sn_revoke($kind));$serialized++;}
   else{sn_check($out==='error'&&sn_snap()===$revoked,'Effective revocation rolls back all topics/logs but retains independent change');}$cases++;
  }}
 }
 foreach(array('membership','pending','read') as $kind){sn_reset('moderator');$reached=$blocked=false;$revoked=null;
  $sn_hook=function($sql)use($kind,&$reached,&$blocked,&$revoked){if(strpos($sql,'INSERT INTO fixture_logs')!==0){return;}$GLOBALS['sn_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sn_check((int)$e['code']===1205,'Permission row lock');$blocked=true;}else{$revoked=sn_snap();}};
  $out=sn_run();sn_check($reached&&($blocked?$out===array(100,101):$out==='error'&&sn_snap()===$revoked),'Independent permission change is rolled back or serialized');$cases++;
 }
 foreach($sn_tables as $s){sn_reset();sn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=sn_snap();sn_check(sn_run()==='error'&&sn_snap()===$before,'Reject legacy participant '.$s);sn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('row','table-charset','column-charset') as $kind){
  sn_reset();$ddl=$kind==='row'?'ROW_FORMAT=COMPACT':($kind==='table-charset'?'DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci':'MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
  sn_sql('ALTER TABLE fixture_sessions '.$ddl);$before=sn_snap();sn_check(sn_run()==='error'&&sn_snap()===$before,'Reject alternate storage format '.$kind);
  sn_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');$cases++;
 }
 foreach(array('SELECT * FROM fixture_logs','SELECT ENGINE, ROW_FORMAT','SET SESSION','START TRANSACTION','SELECT topic_id') as $prefix){sn_reset();$before=sn_snap();$sn_prefix=$prefix;sn_check(sn_run()==='error'&&sn_snap()===$before,'Setup/read failure never writes');$cases++;}
 sn_reset();$before=sn_snap();$killed=false;$sn_hook=function($sql)use(&$killed){if(strpos($sql,'INSERT INTO fixture_logs')!==0){return;}$GLOBALS['sn_hook']=null;$killed=true;sn_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['sn_writer']->db_connect_id));};
 sn_check(sn_run()==='error'&&$killed&&sn_snap()===$before,'Actual connection loss undoes topic update');
 sn_reset();$reached=false;$sn_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_topics')!==0){return;}$GLOBALS['sn_hook']=null;$other=new attach_mutation_lock($GLOBALS['db'],false);$reached=!$other->acquired;$other->release();};sn_check(sn_run()===array(100,101)&&$reached,'Competing writer cannot enter');
 // Execute real controller branches, including their error/success responses.
 $controller=file_get_contents($phpbb_root_path.'modcp.php');$a=strpos($controller,"\tcase 'lock':",strpos($controller,'// Do major work'));$b=strpos($controller,"\tcase 'split':",$a);sn_check($a!==false&&$b>$a,'Actual state controller found');$branch='switch($mode){'.substr($controller,$a,$b-$a).'}';
 $ajax=file_get_contents($phpbb_root_path.'ajax.php');$a=strpos($ajax,'function ajax_scalar_value(');$b=strpos($ajax,'// Get SID and check it',$a);sn_check($a!==false&&$b>$a,'Actual AJAX request helpers');eval(substr($ajax,$a,$b-$a));
 $a=strpos($ajax,"else if (\$mode == 'lock_topic')");$b=strpos($ajax,"else if (\$mode == 'mark_topic')",$a);sn_check($a!==false&&$b>$a,'Actual AJAX controller found');$ajax_branch=substr($ajax,$a+5,$b-$a-5);
 if(!defined('AJAX_ERROR')){define('AJAX_ERROR',0);}if(!defined('AJAX_LOCK_TOPIC')){define('AJAX_LOCK_TOPIC',7);}
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('success','failure','ack','denied') as $kind){foreach(array_keys($modes) as $mode){
  sn_reset('moderator',$mode);$before=sn_snap();$template=new StateNativeTemplate();$forum_id=3;$topic_id=0;$topic_id_list=array(100,101);$is_auth=array('auth_sticky'=>true,'auth_announce'=>true);$SID='sid=review-sid';
  if($kind==='failure'){$sn_fail=2;}if($kind==='ack'){$sn_commit='ack';}if($kind==='denied'){sn_sql(sn_revoke('missing'));$before=sn_snap();}
  $message='';try{eval($branch);}catch(StateNativeResponse $e){$message=$e->getMessage();}sn_check($message!==''&&(!empty($template->vars)===($kind==='success')),'Only confirmed action returns success redirect');
  if($kind==='failure'||$kind==='denied'){sn_check(sn_snap()===$before,'Controller failure leaves no partial data');}if($kind==='ack'){sn_check(count(sn_rows('SELECT * FROM fixture_logs'))===2,'Uncertain controller completion is atomic');}
 }
 foreach(array(0,1) as $desired){sn_reset('moderator',$desired?'lock':'unlock');$before=sn_snap();$mode='lock_topic';$HTTP_GET_VARS=array();$HTTP_POST_VARS=array('t'=>100,'lock_status'=>(string)$desired);$images=array('topic_mod_lock'=>'lock.gif','topic_mod_unlock'=>'unlock.gif','reply_new'=>'reply.gif','reply_locked'=>'locked.gif');
  if($kind==='failure'){$sn_fail=2;}if($kind==='ack'){$sn_commit='ack';}if($kind==='denied'){sn_sql(sn_revoke('missing'));$before=sn_snap();}
  $reply=null;try{eval($ajax_branch);}catch(StateNativeAjax $e){$reply=$e->value;}sn_check(is_array($reply)&&(($reply['result']===AJAX_LOCK_TOPIC)===($kind==='success')),'AJAX never reports unconfirmed success');
  if($kind==='success'){sn_check($reply['locked']===$desired&&strpos($reply['linkurl'],'mod_token=')!==false,'AJAX state and signed fallback match');}elseif($kind!=='ack'){sn_check(sn_snap()===$before,'AJAX failure fully rolls back');}else{sn_check(count(sn_rows('SELECT * FROM fixture_logs'))===1,'AJAX uncertain completion includes audit');}
 }
 }}
 echo 'Native topic state: '.$cases.' failure/boundary cases, '.$serialized." serialized changes; all five modes and both controllers passed.\n";
}finally{$sn_hook=null;$sn_fail=0;$sn_prefix=$sn_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
