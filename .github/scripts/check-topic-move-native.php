<?php
// Actual mysqli driver, canonical owned tables and an independent peer.
if(PHP_SAPI!=='cli'||getenv('PHPBB_MOVE_NATIVE')!=='1'){echo "Native topic move checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';
require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';require $phpbb_root_path.'includes/functions_topic_move.php';
function mn_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class MoveNativeResponse extends RuntimeException {}
function message_die($level,$message){throw new MoveNativeResponse($message);}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_MOVE_PORT')?:'3306';mn_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_MOVE_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_move_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);mn_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);
$mn_hook=null;$mn_queries=array();$mn_write=$mn_fail=0;$mn_prefix=$mn_commit='';
class MoveNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['mn_queries'][]=$sql;if(is_callable($GLOBALS['mn_hook'])){call_user_func($GLOBALS['mn_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['mn_write']===$GLOBALS['mn_fail']){return false;}
  if($GLOBALS['mn_prefix']!==''&&strpos($sql,$GLOBALS['mn_prefix'])===0){return false;}
  if($sql==='COMMIT'&&$GLOBALS['mn_commit']==='fail'){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['mn_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['mn_commit']==='ack'?false:$r;
 }
}
class MoveNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new MoveNativeConnection(parent::sql_dedicated_connection());$GLOBALS['mn_writer']=$c;return $c;}}
$db=new MoveNativeDatabase($host,'root',$password,$schema,false);
function mn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);mn_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function mn_rows($sql){$r=mn_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function mn_insert($table,$values){
 foreach(mn_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}mn_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}
$mn_tables=array('users','sessions','forums','topics','posts','groups','user_group','auth_access','bookmarks','topics_watch','topic_view','logs','config');
function mn_snap(){
 $out=array();foreach($GLOBALS['mn_tables'] as $s){$rows=mn_rows('SELECT * FROM fixture_'.$s);if($s==='logs'){foreach($rows as &$row){mn_check((int)$row['log_time']>0,'Real audit timestamp');$row['log_time']='timestamp';}unset($row);}
  usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function mn_reset($actor='root'){
 global $mn_hook,$mn_queries,$mn_write,$mn_fail,$mn_prefix,$mn_commit,$userdata,$client_ip;
 $mn_hook=null;$mn_queries=array();$mn_write=$mn_fail=0;$mn_prefix=$mn_commit='';$GLOBALS['mn_error']=null;
 mn_sql('START TRANSACTION');foreach($GLOBALS['mn_tables'] as $s){mn_sql('DELETE FROM fixture_'.$s);}mn_sql('COMMIT');mn_sql('ALTER TABLE fixture_topics AUTO_INCREMENT=301');mn_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');
 mn_sql('START TRANSACTION');
 foreach(array(8,9) as $id){mn_insert('fixture_users',array('user_id'=>$id,'username'=>'fixture-'.$id,'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1,'user_posts'=>$id===9?3:0));}
 mn_insert('fixture_sessions',array('session_id'=>'review-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>0));
 mn_insert('fixture_groups',array('group_id'=>10,'group_name'=>'fixture'));
 mn_insert('fixture_user_group',array('group_id'=>10,'user_id'=>8,'user_pending'=>0));mn_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>3,'auth_mod'=>$actor==='root'?0:1));
 foreach(array(3,4) as $id){mn_insert('fixture_forums',array('forum_id'=>$id,'forum_name'=>'fixture','count_posts'=>$id===3?1:0,'forum_status'=>FORUM_UNLOCKED,'forum_posts'=>$id===3?3:1,'forum_topics'=>$id===3?3:2,'forum_last_post_id'=>$id===3?12:20));}
 foreach(array(100=>array(3,0,10,11),101=>array(3,0,12,12),200=>array(4,100,10,11),201=>array(4,100,20,20),300=>array(3,101,12,12)) as $id=>$v){
  mn_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>$v[0],'topic_title'=>"Grüße O'Reilly 😀",'topic_desc'=>'unchanged','topic_poster'=>9,'topic_status'=>$v[1]?TOPIC_MOVED:TOPIC_UNLOCKED,'topic_moved_id'=>$v[1],'topic_type'=>POST_NORMAL,'topic_first_post_id'=>$v[2],'topic_last_post_id'=>$v[3]));
 }
 foreach(array(10=>array(100,3,9),11=>array(100,3,-1),12=>array(101,3,9),20=>array(201,4,9)) as $id=>$v){mn_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>$v[0],'forum_id'=>$v[1],'poster_id'=>$v[2],'post_username'=>'preserved','post_time'=>123));}
 foreach(array('bookmarks','topics_watch','topic_view') as $s){foreach(array(100,200,201) as $id){mn_insert('fixture_'.$s,array('topic_id'=>$id,'user_id'=>9));}}
 foreach(array('max_topics'=>'999','max_posts'=>'999','board_disable'=>'0','unrelated'=>'preserved') as $k=>$v){mn_insert('fixture_config',array('config_name'=>$k,'config_value'=>$v));}
 mn_sql('COMMIT');$userdata=array('user_id'=>8,'username'=>'fixture-8','user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'review-sid');$client_ip='192.0.2.1';$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'review-sid','confirm'=>'yes');
}
function mn_run($shadow=true){try{return phpbb_move_topics($GLOBALS['db'],3,4,array(101,100),$shadow);}catch(PhpbbTopicMoveException $e){return 'error';}}
function mn_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function mn_revoke($kind){$changes=array('missing'=>"DELETE FROM fixture_sessions WHERE session_id='review-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=9 WHERE session_id='review-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='review-sid'",'case'=>"UPDATE fixture_sessions SET session_id='REVIEW-SID' WHERE session_id='review-sid'",'role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0 WHERE forum_id=3','membership'=>'DELETE FROM fixture_user_group WHERE user_id=8','source'=>'UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3','target'=>'UPDATE fixture_forums SET auth_post=5 WHERE forum_id=4');return $changes[$kind];}
class MoveNativeTemplate {var $vars=array();function assign_vars($vars){$this->vars=$vars;}}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 foreach($mn_tables as $suffix){$name='phpbb_'.$suffix;mn_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);mn_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){mn_sql(str_replace($name,'fixture_'.$suffix,$ddl));}}
 mn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','moderator') as $actor){
  mn_reset($actor);$before=mn_snap();$out=mn_run();mn_check($out===array(100,101),'Complete move '.$actor.' '.json_encode($mn_error));$after=mn_snap();$writes=$mn_write;$boundaries=array_values(array_filter($mn_queries,'mn_boundary'));
  mn_check(count(mn_rows('SELECT * FROM fixture_posts WHERE topic_id IN (100,101) AND forum_id=4'))===3,'All posts moved');
  mn_check(!mn_rows('SELECT * FROM fixture_topics WHERE topic_id=200')&&count(mn_rows('SELECT * FROM fixture_topics WHERE topic_id=201'))===1,'Only empty redirect removed');
  foreach(array('bookmarks','topics_watch','topic_view') as $s){mn_check(count($after[$s])===2,'Only empty redirect preferences removed');}
  mn_check(count(mn_rows('SELECT * FROM fixture_topics WHERE forum_id=3 AND topic_moved_id IN (100,101)'))===2,'Source redirects created/reused exactly once');
  mn_check(count($after['logs'])===2,'Exactly one audit per topic');
  foreach($before['posts'] as $old){$current=mn_rows('SELECT * FROM fixture_posts WHERE post_id='.(int)$old['post_id']);unset($old['forum_id'],$current[0]['forum_id']);mn_check($old===$current[0],'Moving preserves every other canonical post field');}
  foreach(array(100,101) as $id){foreach($before['topics'] as $old){if((int)$old['topic_id']!==$id){continue;}$current=mn_rows('SELECT * FROM fixture_topics WHERE topic_id='.$id);unset($old['forum_id'],$current[0]['forum_id']);mn_check($old===$current[0],'Moving preserves every other canonical topic field');}}
  mn_check((int)mn_rows('SELECT user_posts FROM fixture_users WHERE user_id=9')[0]['user_posts']===0,'Count boundary repairs poster total');
  mn_check(mn_rows("SELECT config_value FROM fixture_config WHERE config_name='max_topics'")[0]['config_value']==='5','Global topic total includes redirects');
  foreach(array('sessions','groups','user_group','auth_access') as $s){mn_check($before[$s]===$after[$s],'Unrelated authority unchanged');}
  for($n=1;$n<=$writes;$n++){mn_reset($actor);$before=mn_snap();$mn_fail=$n;mn_check(mn_run()==='error'&&mn_snap()===$before,'Whole move rollback at write '.$n);$cases++;}
  foreach(array('fail','ack') as $kind){mn_reset($actor);$before=mn_snap();$mn_commit=$kind;mn_check(mn_run()==='error'&&mn_snap()===($kind==='fail'?$before:$after),'Lost/failed COMMIT leaves whole before/after');$mn_commit='';if($kind==='ack'){mn_check(mn_run()==='error'&&mn_snap()===$after,'Uncertain complete move cannot duplicate audit/shadows');}$cases++;}
  foreach(array('missing','foreign','logout','case','inactive',$actor==='root'?'role':'grant') as $kind){mn_reset($actor);mn_sql(mn_revoke($kind));$before=mn_snap();mn_check(mn_run()==='error'&&mn_snap()===$before,'Current entry authority '.$kind);$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   mn_reset($actor);$seen=0;$reached=$blocked=false;$revoked=null;
   $mn_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!mn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['mn_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(mn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();mn_check((int)$e['code']===1205,'Independent authority lock timeout');$blocked=true;}else{$revoked=mn_snap();}};
   $out=mn_run();mn_check($reached,'Every write/commit boundary reached');
   if($blocked){mn_check($out===array(100,101)&&mn_snap()===$after,'Revocation serializes after complete move');mn_sql(mn_revoke($kind));$serialized++;}
   else{mn_check($out==='error'&&mn_snap()===$revoked,'Effective revocation rolls back complete move, retains peer change');}$cases++;
  }}
  echo $actor." native move rollback/current-authority boundaries passed\n";
 }
 mn_reset();mn_check(mn_run(false)===array(100,101),'No-shadow move succeeds');
 mn_check(mn_rows("SELECT config_value FROM fixture_config WHERE config_name='max_topics'")[0]['config_value']==='4'&&mn_rows("SELECT config_value FROM fixture_config WHERE config_name='max_posts'")[0]['config_value']==='4','Global totals include redirect removal and no new shadow');
 mn_reset();$before=mn_snap();mn_check(phpbb_move_topics($db,3,3,array(100),true)===array()&&mn_snap()===$before,'Same-forum no-op has no writes');
 foreach(array('membership','source','target') as $kind){
  mn_reset('moderator');$reached=$blocked=false;$revoked=null;
  $mn_hook=function($sql)use($kind,&$reached,&$blocked,&$revoked){if(strpos($sql,'UPDATE fixture_topics t JOIN')!==0){return;}$GLOBALS['mn_hook']=null;$reached=true;
   if(!$GLOBALS['peer']->sql_query(mn_revoke($kind))){$e=$GLOBALS['peer']->sql_error();mn_check((int)$e['code']===1205,'Real forum/membership lock');$blocked=true;}else{$revoked=mn_snap();}};
  $out=mn_run();mn_check($reached,'Independent forum/membership change reached');mn_check($blocked?$out===array(100,101):$out==='error'&&mn_snap()===$revoked,'Effective permission change aborts, locked forum change serializes');$cases++;
 }
 foreach(array('changed-before-lock','changed-after-lock') as $kind){
  mn_reset();$reached=$blocked=false;$changed=null;$before=mn_snap();
  $mn_hook=function($sql)use($kind,&$reached,&$blocked,&$changed){$prefix=$kind==='changed-before-lock'?'SELECT topic_id, forum_id, topic_type':'UPDATE fixture_topics t JOIN';if(strpos($sql,$prefix)!==0){return;}$GLOBALS['mn_hook']=null;$reached=true;
   if(!$GLOBALS['peer']->sql_query('UPDATE fixture_topics SET forum_id=4 WHERE topic_id=100')){$e=$GLOBALS['peer']->sql_error();mn_check((int)$e['code']===1205,'Real target lock');$blocked=true;}else{$changed=mn_snap();}};
  $out=mn_run();mn_check($reached&&($blocked?$out===array(100,101):$out==='error'&&mn_snap()===$changed),'Changed/locked topic selection never silently overwrites a peer');$cases++;
 }
 foreach($mn_tables as $s){mn_reset();mn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=mn_snap();mn_check(mn_run()==='error'&&mn_snap()===$before,'Reject legacy participant '.$s);mn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 foreach(array('row','table-charset','column-charset') as $kind){
  mn_reset();$ddl=$kind==='row'?'ROW_FORMAT=COMPACT':($kind==='table-charset'?'DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci':'MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
  mn_sql('ALTER TABLE fixture_sessions '.$ddl);$before=mn_snap();mn_check(mn_run()==='error'&&mn_snap()===$before,'Reject alternate storage format '.$kind);
  mn_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
 }
 mn_reset();$before=mn_snap();$killed=false;$mn_hook=function($sql)use(&$killed){if(strpos($sql,'INSERT INTO fixture_topics')!==0){return;}$GLOBALS['mn_hook']=null;$killed=true;mn_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['mn_writer']->db_connect_id));};
 mn_check(mn_run()==='error'&&$killed&&mn_snap()===$before,'Actual connection loss undoes joined move and redirect cleanup');
 mn_reset();$reached=false;$mn_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_topics t JOIN')!==0){return;}$GLOBALS['mn_hook']=null;$other=new attach_mutation_lock($GLOBALS['db'],false);$reached=!$other->acquired;$other->release();};
 mn_check(mn_run()===array(100,101)&&$reached,'Independent competing writer cannot enter');
 foreach(array(false,true) as $failure){mn_reset();$before=mn_snap();$mn_fail=$failure?2:0;$mn_hook=function($sql){if(strpos($sql,'UPDATE fixture_topics t JOIN')!==0){return;}$GLOBALS['mn_hook']=null;mn_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");};
  $out=mn_run();mn_check($failure?$out==='error':$out===array(100,101),'Concurrent availability case outcome');$after=mn_snap();mn_check(mn_rows("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")[0]['config_value']==='1','Peer board disabling is preserved');
  if($failure){foreach($before['config'] as &$row){if($row['config_name']==='board_disable'){$row['config_value']='1';}}unset($row);mn_check($before===$after,'Rollback retains independent config change only');}
 }
 // Actual modcp storage/response branch, not a hand-written controller model.
 $controller=file_get_contents($phpbb_root_path.'modcp.php');$a=strpos($controller,'$old_forum_id = $forum_id;');$b=strpos($controller,'message_die(GENERAL_MESSAGE, $message);',$a);mn_check($a!==false&&$b>$a,'Actual move controller found');$branch=substr($controller,$a,$b-$a).'message_die(GENERAL_MESSAGE, $message);';
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('success','failure','ack','denied') as $kind){
  mn_reset();$before=mn_snap();$template=new MoveNativeTemplate();$forum_id=3;$new_forum_id=4;$topic_id=0;$topic_id_list=array(100,101);$_POST['move_leave_shadow']='1';$message='';
  if($kind==='failure'){$mn_fail=2;}if($kind==='ack'){$mn_commit='ack';}if($kind==='denied'){mn_sql(mn_revoke('missing'));$before=mn_snap();}
  try{eval($branch);}catch(MoveNativeResponse $e){$message=$e->getMessage();}
  mn_check($message!=='','Actual response produced');mn_check((strpos($message,$lang['Topics_Moved'])===0)===($kind==='success'),'Only confirmed storage reports success');
  if($kind==='failure'||$kind==='denied'){mn_check(mn_snap()===$before&&empty($template->vars),'Controller failure leaves no partial data or success redirect');}
  if($kind==='ack'){mn_check(count(mn_rows('SELECT * FROM fixture_logs'))===2&&empty($template->vars),'Lost commit reply keeps complete move but no success redirect');}
 }}
 // CACHE_TREE is opt-in. Exercise only an owned temporary cache, never repo files.
 define('CACHE_TREE',true);$mn_source_root=$phpbb_root_path;$mn_cache=sys_get_temp_dir().'/phpbb-move-cache-'.bin2hex(phpbb_random_bytes(8));mn_check(mkdir($mn_cache,0700)&&mkdir($mn_cache.'/cache',0700),'Owned cache directory');
 try{
  $phpbb_root_path=$mn_cache.'/';foreach(array('success','failure','ack') as $kind){mn_reset();file_put_contents($mn_cache.'/cache/tree.cache','stale');if($kind==='failure'){$mn_fail=2;}if($kind==='ack'){$mn_commit='ack';}$out=mn_run();mn_check(($out===array(100,101))===($kind==='success')&&!file_exists($mn_cache.'/cache/tree.cache'),'Cache invalidated after confirmed/failed/uncertain move');}
 }finally{$phpbb_root_path=$mn_source_root;if(is_file($mn_cache.'/cache/tree.cache')){unlink($mn_cache.'/cache/tree.cache');}rmdir($mn_cache.'/cache');rmdir($mn_cache);}
 echo 'Native topic move: '.$cases.' failure/boundary cases, '.$serialized." serialized changes passed.\n";
}finally{$mn_hook=null;$mn_fail=0;$mn_prefix=$mn_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
