<?php
// Actual mysqli driver, canonical owned tables and an independent peer.
if(PHP_SAPI!=='cli'||getenv('PHPBB_SPLIT_NATIVE')!=='1'){echo "Native topic split checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';
require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';require $phpbb_root_path.'includes/functions_topic_split.php';
function sp_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class SplitNativeResponse extends RuntimeException {}
function message_die($level,$message){throw new SplitNativeResponse($message);}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_SPLIT_PORT')?:'3306';sp_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_SPLIT_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_split_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);sp_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);
$sp_hook=null;$sp_queries=array();$sp_write=$sp_fail=0;$sp_prefix=$sp_commit='';
class SplitNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['sp_queries'][]=$sql;if(is_callable($GLOBALS['sp_hook'])){call_user_func($GLOBALS['sp_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['sp_write']===$GLOBALS['sp_fail']){return false;}
  if($GLOBALS['sp_prefix']!==''&&strpos($sql,$GLOBALS['sp_prefix'])===0){return false;}
  if($sql==='COMMIT'&&$GLOBALS['sp_commit']==='fail'){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['sp_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['sp_commit']==='ack'?false:$r;
 }
}
class SplitNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new SplitNativeConnection(parent::sql_dedicated_connection());$GLOBALS['sp_writer']=$c;return $c;}}
$db=new SplitNativeDatabase($host,'root',$password,$schema,false);
function sp_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);sp_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function sp_rows($sql){$r=sp_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function sp_insert($table,$values){
 foreach(sp_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}sp_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}

require $phpbb_root_path.'attach_mod/includes/functions_attach.php';require $phpbb_root_path.'includes/sessions.php';
$sp_participants=array('users','sessions','forums','topics','posts','user_group','auth_access','attachments','topics_watch','logs','config');
$sp_tables=array_merge($sp_participants,array('posts_text','search_wordmatch','vote_desc','vote_results','vote_voters','attachments_desc','bookmarks','topic_view'));
function sp_snap(){
 $out=array();foreach($GLOBALS['sp_tables'] as $s){$rows=sp_rows('SELECT * FROM fixture_'.$s);if($s==='logs'){foreach($rows as &$row){sp_check((int)$row['log_time']>0,'Real audit timestamp');$row['log_time']='timestamp';}unset($row);}
  usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function sp_reset($actor='root'){
 global $sp_hook,$sp_queries,$sp_write,$sp_fail,$sp_prefix,$sp_commit,$userdata,$client_ip;
 $sp_hook=null;$sp_queries=array();$sp_write=$sp_fail=0;$sp_prefix=$sp_commit='';$GLOBALS['sp_error']=null;
 sp_sql('START TRANSACTION');foreach($GLOBALS['sp_tables'] as $s){sp_sql('DELETE FROM fixture_'.$s);}sp_sql('COMMIT');sp_sql('ALTER TABLE fixture_topics AUTO_INCREMENT=301');sp_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');
 sp_sql('START TRANSACTION');
 foreach(array(8,9) as $id){sp_insert('fixture_users',array('user_id'=>$id,'username'=>"Grüße O'Reilly 😀",'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1,'user_posts'=>$id===9?3:1));}
 sp_insert('fixture_sessions',array('session_id'=>'review-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>0));
 sp_insert('fixture_user_group',array('group_id'=>10,'user_id'=>8,'user_pending'=>0));sp_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>3,'auth_mod'=>$actor==='root'?0:1));
 foreach(array(3,4) as $id){sp_insert('fixture_forums',array('forum_id'=>$id,'forum_name'=>'fixture','count_posts'=>$id===3?1:0,'forum_status'=>FORUM_UNLOCKED,'forum_posts'=>$id===3?5:1,'forum_topics'=>1,'forum_last_post_id'=>$id===3?14:20));}
 foreach(array(100=>3,200=>4) as $id=>$forum){sp_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>$forum,'topic_title'=>"Grüße O'Reilly 😀",'topic_desc'=>'unchanged','topic_poster'=>9,'topic_status'=>TOPIC_UNLOCKED,'topic_type'=>POST_NORMAL,'topic_vote'=>$id===100?1:0,'topic_first_post_id'=>$id===100?10:20,'topic_last_post_id'=>$id===100?14:20,'topic_replies'=>$id===100?4:0,'topic_attachment'=>$id===100?1:0));}
 foreach(array(10=>array(100,3,9,0),11=>array(100,3,-1,1),12=>array(100,3,9,2),13=>array(100,3,8,2),14=>array(100,3,9,3),20=>array(200,4,9,4)) as $id=>$v){
  sp_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>$v[0],'forum_id'=>$v[1],'poster_id'=>$v[2],'post_username'=>'preserved','post_time'=>$v[3],'post_attachment'=>in_array($id,array(10,12),true)?1:0));
  sp_insert('fixture_posts_text',array('post_id'=>$id,'post_text'=>"Grüße O'Reilly \\ 😀",'post_subject'=>'unchanged','bbcode_uid'=>'fixture'));
  sp_insert('fixture_search_wordmatch',array('post_id'=>$id,'word_id'=>1,'title_match'=>0));
 }
 foreach(array(10,12) as $id){sp_insert('fixture_attachments',array('attach_id'=>$id,'post_id'=>$id,'user_id_1'=>9));sp_insert('fixture_attachments_desc',array('attach_id'=>$id,'physical_filename'=>'fixture-'.$id.'.bin','real_filename'=>'preserved.bin'));}
 sp_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>100,'vote_text'=>'Preserved poll'));
 sp_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>1,'vote_option_text'=>'preserved','vote_result'=>1));
 sp_insert('fixture_vote_voters',array('vote_id'=>1,'vote_user_id'=>9));
 foreach(array(8,9,-1) as $id){sp_insert('fixture_topics_watch',array('topic_id'=>100,'user_id'=>$id,'notify_status'=>1));}
 foreach(array('bookmarks','topic_view') as $s){sp_insert('fixture_'.$s,array('topic_id'=>100,'user_id'=>9));}
 foreach(array('max_topics'=>'2','max_posts'=>'6','board_disable'=>'0','unrelated'=>'preserved') as $k=>$v){sp_insert('fixture_config',array('config_name'=>$k,'config_value'=>$v));}
 sp_sql('COMMIT');$userdata=array('user_id'=>8,'username'=>"Grüße O'Reilly 😀",'user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'review-sid');$client_ip='2001:db8::42';
}
function sp_run($mode='selected',$target=4){try{return phpbb_split_topic($GLOBALS['db'],3,100,$target,$mode==='after'?array(13):array(12,14),$mode,"Split Grüße O'Reilly \\ 😀");}catch(PhpbbTopicSplitException $e){return 'error';}}
function sp_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function sp_revoke($kind){$changes=array('missing'=>"DELETE FROM fixture_sessions WHERE session_id='review-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=9 WHERE session_id='review-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='review-sid'",'case'=>"UPDATE fixture_sessions SET session_id='REVIEW-SID' WHERE session_id='review-sid'",'role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0 WHERE forum_id=3','membership'=>'DELETE FROM fixture_user_group WHERE user_id=8','read'=>'UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3','target'=>'UPDATE fixture_forums SET auth_post=5 WHERE forum_id=4');return $changes[$kind];}
class SplitNativeTemplate {var $vars=array();function assign_vars($vars){$this->vars=$vars;}}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 require dirname(dirname(__DIR__)).'/update/innodb_migration.php';$migrated=plus_storage_tables($canonical,'fixture_');
 foreach($sp_tables as $suffix){$name='phpbb_'.$suffix;sp_check(in_array('fixture_'.$suffix,$migrated,true),'Normal migration covers '.$suffix);sp_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);sp_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){sp_sql(str_replace($name,'fixture_'.$suffix,$ddl));}}
 sp_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','moderator') as $actor){foreach(array('selected','after') as $action){
  sp_reset($actor);$before=sp_snap();$out=sp_run($action);sp_check(is_array($out)&&$out['topic_id']===301,'Complete split '.$actor.'/'.$action.' '.json_encode($sp_error));$after=sp_snap();$writes=$sp_write;$boundaries=array_values(array_filter($sp_queries,'sp_boundary'));
  $selected=$action==='after'?array(13,14):array(12,14);sp_check($out['post_ids']===$selected,'Exact selected/timestamp-tied post set');
  foreach($before['posts'] as $old){$current=sp_rows('SELECT * FROM fixture_posts WHERE post_id='.(int)$old['post_id']);if(in_array((int)$old['post_id'],$selected,true)){$old['forum_id']='4';$old['topic_id']='301';}sp_check($old===$current[0],'All other canonical post fields preserved');}
  foreach(array('posts_text','search_wordmatch','vote_desc','vote_results','vote_voters','attachments','attachments_desc','bookmarks','topic_view','sessions','user_group','auth_access') as $s){sp_check($before[$s]===$after[$s],'Preserve unrelated/reference data '.$s);}
  foreach(array(100,200) as $id){foreach($before['topics'] as $old){if((int)$old['topic_id']!==$id){continue;}$current=sp_rows('SELECT * FROM fixture_topics WHERE topic_id='.$id);foreach(array('topic_replies','topic_first_post_id','topic_last_post_id','topic_attachment') as $key){unset($old[$key],$current[0][$key]);}sp_check($old===$current[0],'Preserve existing topic metadata including poll/type');}}
  sp_check(count($after['logs'])===2,'Source and destination audited');
  $new=sp_rows('SELECT * FROM fixture_topics WHERE topic_id=301')[0];sp_check($new['topic_title']==="Split Grüße O'Reilly \\ 😀"&&(int)$new['topic_vote']===0&&(int)$new['topic_poster']===($action==='after'?8:9),'New title/author/poll correct');
  sp_check((int)$new['topic_first_post_id']===$selected[0]&&(int)$new['topic_last_post_id']===14&&(int)$new['topic_replies']===1,'New topic counters correct');
  sp_check((int)$new['topic_attachment']===($action==='after'?0:1),'Attachment flag follows exact new posts');
  sp_check(count(sp_rows('SELECT * FROM fixture_topics_watch WHERE topic_id=301'))===($action==='after'?2:1),'Only subscribed moved authors copied');
  sp_check(sp_rows("SELECT config_value FROM fixture_config WHERE config_name='max_topics'")[0]['config_value']==='3'&&sp_rows("SELECT config_value FROM fixture_config WHERE config_name='max_posts'")[0]['config_value']==='6','Global topic/post totals atomic');
  sp_check((int)sp_rows('SELECT user_posts FROM fixture_users WHERE user_id=9')[0]['user_posts']===($action==='after'?2:1),'Count boundary recount exact poster');
  sp_check(sp_run($action)==='error'&&sp_snap()===$after,'Replay cannot create another topic or duplicate logs');
  for($n=1;$n<=$writes;$n++){sp_reset($actor);$before=sp_snap();$sp_fail=$n;sp_check(sp_run($action)==='error'&&sp_snap()===$before,'Whole split rollback at write '.$n.'/'.$action);$cases++;}
  foreach(array('fail','ack') as $kind){sp_reset($actor);$before=sp_snap();$sp_commit=$kind;sp_check(sp_run($action)==='error'&&sp_snap()===($kind==='fail'?$before:$after),'Lost/failed COMMIT leaves all-before/all-after');$sp_commit='';if($kind==='ack'){sp_check(sp_run($action)==='error'&&sp_snap()===$after,'Uncertain complete split does not duplicate topic');}$cases++;}
  if($action==='selected'){foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   sp_reset($actor);$seen=0;$reached=$blocked=false;$revoked=null;
   $sp_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!sp_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['sp_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(sp_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sp_check((int)$e['code']===1205,'Independent authority lock timeout');$blocked=true;}else{$revoked=sp_snap();}};
   $out=sp_run($action);sp_check($reached,'Every write/commit boundary reached');
   if($blocked){sp_check(is_array($out)&&$out['topic_id']===301&&sp_snap()===$after,'Revocation serializes after complete split');sp_sql(sp_revoke($kind));$serialized++;}
   else{sp_check($out==='error'&&sp_snap()===$revoked,'Effective revocation undoes entire split but keeps peer change');}$cases++;
  }}}
  echo $actor.'/'.$action." native split rollback/current-authority boundaries passed\n";
 }}
 foreach(array('missing','foreign','logout','case','inactive','role') as $kind){sp_reset();sp_sql(sp_revoke($kind));$before=sp_snap();sp_check(sp_run()==='error'&&sp_snap()===$before,'Exact current entry authority '.$kind);$cases++;}
 foreach(array('membership','read','target') as $kind){sp_reset('moderator');$blocked=false;$revoked=null;$sp_hook=function($sql)use($kind,&$blocked,&$revoked){if(strpos($sql,'UPDATE fixture_posts p JOIN')!==0){return;}$GLOBALS['sp_hook']=null;if(!$GLOBALS['peer']->sql_query(sp_revoke($kind))){$e=$GLOBALS['peer']->sql_error();sp_check((int)$e['code']===1205,'Permission row lock');$blocked=true;}else{$revoked=sp_snap();}};$out=sp_run();sp_check($blocked?is_array($out):$out==='error'&&sp_snap()===$revoked,'Changed permission rollback or serialization');$cases++;}
 foreach($sp_participants as $s){sp_reset();sp_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=sp_snap();sp_check(sp_run()==='error'&&sp_snap()===$before,'Reject legacy participant '.$s);sp_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('row','table-charset','column-charset') as $kind){sp_reset();$ddl=$kind==='row'?'ROW_FORMAT=COMPACT':($kind==='table-charset'?'DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci':'MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');sp_sql('ALTER TABLE fixture_sessions '.$ddl);$before=sp_snap();sp_check(sp_run()==='error'&&sp_snap()===$before,'Reject alternate storage '.$kind);sp_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');$cases++;}
 sp_reset();$before=sp_snap();$killed=false;$sp_hook=function($sql)use(&$killed){if(strpos($sql,'INSERT INTO fixture_topics_watch')!==0){return;}$GLOBALS['sp_hook']=null;$killed=true;sp_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['sp_writer']->db_connect_id));};sp_check(sp_run()==='error'&&$killed&&sp_snap()===$before,'Real disconnect undoes new topic/posts/counters/flags');
 sp_reset();$reached=false;$sp_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_posts p JOIN')!==0){return;}$GLOBALS['sp_hook']=null;$other=new attach_mutation_lock($GLOBALS['db'],false);$reached=!$other->acquired;$other->release();};sp_check(is_array(sp_run())&&$reached,'Competing writer excluded');
 sp_reset('moderator');$out=sp_run('after',3);sp_check(is_array($out)&&count(sp_rows('SELECT * FROM fixture_posts WHERE forum_id=3'))===5,'Same-forum split retains post totals');
 foreach(array(false,true) as $failure){sp_reset();$before=sp_snap();$sp_fail=$failure?3:0;$sp_hook=function($sql){if(strpos($sql,'INSERT INTO fixture_topics')!==0){return;}$GLOBALS['sp_hook']=null;sp_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");};$out=sp_run();$after=sp_snap();sp_check(($out==='error')===$failure&&sp_rows("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")[0]['config_value']==='1','Independent board disabling retained');if($failure){foreach($before['config'] as &$row){if($row['config_name']==='board_disable'){$row['config_value']='1';}}unset($row);sp_check($before===$after,'Rollback retains only independent availability change');}}
 // Real modcp split branch, not a replacement implementation.
 $controller=file_get_contents($phpbb_root_path.'modcp.php');$a=strpos($controller,"if (isset(\$_POST['split_type_all']) || isset(\$_POST['split_type_beyond']))");$b=strpos($controller,'message_die(GENERAL_MESSAGE, $message);',$a);sp_check($a!==false&&$b>$a,'Actual split controller found');$branch=substr($controller,$a,$b-$a).'message_die(GENERAL_MESSAGE, $message); }';
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('selected','after') as $action){foreach(array('success','failure','ack','denied') as $kind){
  sp_reset('moderator');$before=sp_snap();$template=new SplitNativeTemplate();$forum_id=3;$topic_id=100;$SID='sid=review-sid';$_POST=array($action==='after'?'split_type_beyond':'split_type_all'=>1,'new_forum_id'=>'f4','post_id_list'=>$action==='after'?array(13):array(12,14),'subject'=>addslashes("Split Grüße O'Reilly \\ 😀"));
  if($kind==='failure'){$sp_fail=3;}if($kind==='ack'){$sp_commit='ack';}if($kind==='denied'){sp_sql(sp_revoke('missing'));$before=sp_snap();}
  $message='';try{eval($branch);}catch(SplitNativeResponse $e){$message=$e->getMessage();}sp_check($message!==''&&((strpos($message,$lang['Topic_split'])===0)===($kind==='success')),'Only confirmed split reports success');sp_check((!empty($template->vars))===($kind==='success'),'No success redirect on failed or uncertain split');
  if($kind==='failure'||$kind==='denied'){sp_check(sp_snap()===$before,'Controller rollback leaves no partial state');}if($kind==='ack'){sp_check(count(sp_rows('SELECT * FROM fixture_logs'))===2,'Uncertain controller split has complete audit');}
 }}}
 define('CACHE_TREE',true);$sp_source_root=$phpbb_root_path;$sp_cache=sys_get_temp_dir().'/phpbb-split-cache-'.bin2hex(phpbb_random_bytes(8));sp_check(mkdir($sp_cache,0700)&&mkdir($sp_cache.'/cache',0700),'Owned cache directory');
 try{$phpbb_root_path=$sp_cache.'/';foreach(array('success','failure','ack') as $kind){sp_reset();file_put_contents($sp_cache.'/cache/tree.cache','stale');if($kind==='failure'){$sp_fail=3;}if($kind==='ack'){$sp_commit='ack';}$out=sp_run();sp_check(is_array($out)===($kind==='success')&&!file_exists($sp_cache.'/cache/tree.cache'),'Cache invalidated after confirmed/failed/uncertain split');}}
 finally{$phpbb_root_path=$sp_source_root;if(is_file($sp_cache.'/cache/tree.cache')){unlink($sp_cache.'/cache/tree.cache');}rmdir($sp_cache.'/cache');rmdir($sp_cache);}
 echo 'Native topic split: '.$cases.' failure/boundary cases, '.$serialized." serialized changes; both modes and actual controllers passed.\n";
}finally{$sp_hook=null;$sp_fail=0;$sp_prefix=$sp_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
