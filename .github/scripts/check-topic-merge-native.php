<?php
// Actual mysqli driver, canonical owned tables and an independent peer.
if(PHP_SAPI!=='cli'||getenv('PHPBB_MERGE_NATIVE')!=='1'){echo "Native topic merge checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';
require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';require $phpbb_root_path.'includes/functions_topic_merge_storage.php';
function mg_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class MergeNativeResponse extends RuntimeException {}
function message_die($level,$message){throw new MergeNativeResponse($message);}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_MERGE_PORT')?:'3306';mg_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_MERGE_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_merge_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);mg_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);
$mg_hook=null;$mg_queries=array();$mg_write=$mg_fail=0;$mg_prefix=$mg_commit='';
class MergeNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['mg_queries'][]=$sql;if(is_callable($GLOBALS['mg_hook'])){call_user_func($GLOBALS['mg_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['mg_write']===$GLOBALS['mg_fail']){return false;}
  if($GLOBALS['mg_prefix']!==''&&strpos($sql,$GLOBALS['mg_prefix'])===0){return false;}
  if($sql==='COMMIT'&&$GLOBALS['mg_commit']==='fail'){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['mg_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['mg_commit']==='ack'?false:$r;
 }
}
class MergeNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new MergeNativeConnection(parent::sql_dedicated_connection());$GLOBALS['mg_writer']=$c;return $c;}}
$db=new MergeNativeDatabase($host,'root',$password,$schema,false);
function mg_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);mg_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function mg_rows($sql){$r=mg_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function mg_insert($table,$values){
 foreach(mg_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}mg_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}

require $phpbb_root_path.'attach_mod/includes/functions_attach.php';require $phpbb_root_path.'includes/sessions.php';
$mg_participants=array('users','sessions','forums','topics','posts','user_group','auth_access','attachments','topics_watch','logs','config','bookmarks','topic_view','vote_desc','vote_results','vote_voters');
$mg_tables=array_merge($mg_participants,array('posts_text','search_wordmatch','attachments_desc'));
function mg_snap(){
 $out=array();foreach($GLOBALS['mg_tables'] as $s){$rows=mg_rows('SELECT * FROM fixture_'.$s);if($s==='logs'){foreach($rows as &$row){mg_check((int)$row['log_time']>0,'Real audit timestamp');$row['log_time']='timestamp';}unset($row);}
  usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function mg_reset($actor='root'){
 global $mg_hook,$mg_queries,$mg_write,$mg_fail,$mg_prefix,$mg_commit,$userdata,$client_ip;
 $mg_hook=null;$mg_queries=array();$mg_write=$mg_fail=0;$mg_prefix=$mg_commit='';$GLOBALS['mg_error']=null;
 mg_sql('START TRANSACTION');foreach($GLOBALS['mg_tables'] as $s){mg_sql('DELETE FROM fixture_'.$s);}mg_sql('COMMIT');mg_sql('ALTER TABLE fixture_topics AUTO_INCREMENT=301');mg_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');
 mg_sql('START TRANSACTION');
 foreach(array(8,9) as $id){mg_insert('fixture_users',array('user_id'=>$id,'username'=>"Grüße O'Reilly 😀",'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1,'user_posts'=>$id===9?3:1));}
 mg_insert('fixture_sessions',array('session_id'=>'review-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>0));
 mg_insert('fixture_user_group',array('group_id'=>10,'user_id'=>8,'user_pending'=>0));mg_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>3,'auth_mod'=>$actor==='root'?0:1));
 foreach(array(3,4) as $id){mg_insert('fixture_forums',array('forum_id'=>$id,'forum_name'=>'fixture','count_posts'=>$id===3?1:0,'forum_status'=>FORUM_UNLOCKED,'forum_posts'=>$id===3?5:1,'forum_topics'=>1,'forum_last_post_id'=>$id===3?14:20));}
 foreach(array(100=>3,200=>4) as $id=>$forum){mg_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>$forum,'topic_title'=>"Grüße O'Reilly 😀",'topic_desc'=>'unchanged','topic_poster'=>9,'topic_status'=>TOPIC_UNLOCKED,'topic_type'=>POST_NORMAL,'topic_vote'=>$id===100?1:0,'topic_first_post_id'=>$id===100?10:20,'topic_last_post_id'=>$id===100?14:20,'topic_replies'=>$id===100?4:0,'topic_attachment'=>$id===100?1:0));}
 foreach(array(10=>array(100,3,9,0),11=>array(100,3,-1,1),12=>array(100,3,9,2),13=>array(100,3,8,2),14=>array(100,3,9,3),20=>array(200,4,9,4)) as $id=>$v){
  mg_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>$v[0],'forum_id'=>$v[1],'poster_id'=>$v[2],'post_username'=>'preserved','post_time'=>$v[3],'post_attachment'=>in_array($id,array(10,12),true)?1:0));
  mg_insert('fixture_posts_text',array('post_id'=>$id,'post_text'=>"Grüße O'Reilly \\ 😀",'post_subject'=>'unchanged','bbcode_uid'=>'fixture'));
  mg_insert('fixture_search_wordmatch',array('post_id'=>$id,'word_id'=>1,'title_match'=>0));
 }
 foreach(array(10,12) as $id){mg_insert('fixture_attachments',array('attach_id'=>$id,'post_id'=>$id,'user_id_1'=>9));mg_insert('fixture_attachments_desc',array('attach_id'=>$id,'physical_filename'=>'fixture-'.$id.'.bin','real_filename'=>'preserved.bin'));}
 mg_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>100,'vote_text'=>'Preserved poll'));
 mg_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>1,'vote_option_text'=>'preserved','vote_result'=>1));
 mg_insert('fixture_vote_voters',array('vote_id'=>1,'vote_user_id'=>9));
 foreach(array(8,9,-1) as $id){mg_insert('fixture_topics_watch',array('topic_id'=>100,'user_id'=>$id,'notify_status'=>1));}
 foreach(array('bookmarks','topic_view') as $s){mg_insert('fixture_'.$s,array('topic_id'=>100,'user_id'=>9));}
 foreach(array('max_topics'=>'2','max_posts'=>'6','board_disable'=>'0','unrelated'=>'preserved') as $k=>$v){mg_insert('fixture_config',array('config_name'=>$k,'config_value'=>$v));}
 mg_sql('COMMIT');$userdata=array('user_id'=>8,'username'=>"Grüße O'Reilly 😀",'user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'review-sid');$client_ip='2001:db8::42';
}

// Load the same module translations as the actual endpoint.
require $phpbb_root_path.'language/lang_english/lang_extend_merge.php';
function mg_fixture($actor='root',$polls='both'){
 mg_reset($actor);mg_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>4,'auth_mod'=>$actor==='root'?0:1));
 mg_sql('UPDATE fixture_topics SET topic_views=CASE WHEN topic_id=100 THEN 7 ELSE 5 END,topic_type=CASE WHEN topic_id=100 THEN 1 ELSE 2 END');
 mg_insert('fixture_topic_view',array('topic_id'=>100,'user_id'=>9,'view_time'=>9,'view_count'=>7));
 foreach(array(2,3) as $count){mg_insert('fixture_topic_view',array('topic_id'=>200,'user_id'=>9,'view_time'=>1,'view_count'=>$count));}
 mg_insert('fixture_topics_watch',array('topic_id'=>100,'user_id'=>8,'notify_status'=>1,'notify_claim'=>'old'));
 mg_insert('fixture_topics_watch',array('topic_id'=>200,'user_id'=>9,'notify_status'=>1,'notify_claim'=>'targetclaim'));
 mg_insert('fixture_bookmarks',array('topic_id'=>100,'user_id'=>8));mg_insert('fixture_bookmarks',array('topic_id'=>200,'user_id'=>9));
 if($polls==='none'||$polls==='target'){foreach(array('vote_voters','vote_results','vote_desc') as $s){mg_sql('DELETE FROM fixture_'.$s.' WHERE vote_id=1');}}
 if($polls==='target'||$polls==='both'){
  mg_insert('fixture_vote_desc',array('vote_id'=>2,'topic_id'=>200,'vote_text'=>'Target poll'));
  mg_insert('fixture_vote_results',array('vote_id'=>2,'vote_option_id'=>1,'vote_option_text'=>'Target choice','vote_result'=>3));
  mg_insert('fixture_vote_voters',array('vote_id'=>2,'vote_user_id'=>8));
 }
}
function mg_token($shadow=false,$subject=''){return phpbb_prepare_topic_merge($GLOBALS['db'],100,200,$subject,$shadow)['token'];}
function mg_run($shadow=false,$token=null,$subject=''){try{if($token===null){$token=mg_token($shadow,$subject);}return phpbb_merge_topics($GLOBALS['db'],100,200,$subject,$shadow,$token);}catch(PhpbbTopicMergeException $e){return 'error';}}
function mg_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function mg_revoke($kind){$changes=array('missing'=>"DELETE FROM fixture_sessions WHERE session_id='review-sid'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=9 WHERE session_id='review-sid'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='review-sid'",'case'=>"UPDATE fixture_sessions SET session_id='REVIEW-SID' WHERE session_id='review-sid'",'role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0 WHERE forum_id=3','targetgrant'=>'UPDATE fixture_auth_access SET auth_mod=0 WHERE forum_id=4','membership'=>'DELETE FROM fixture_user_group WHERE user_id=8','read'=>'UPDATE fixture_forums SET auth_read=5 WHERE forum_id=3');return $changes[$kind];}
class MergeNativeTemplate {var $vars=array();function assign_vars($vars){$this->vars=$vars;}}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');
 require dirname(dirname(__DIR__)).'/update/innodb_migration.php';$migrated=plus_storage_tables($canonical,'fixture_');
 foreach($mg_tables as $suffix){$name='phpbb_'.$suffix;mg_check(in_array('fixture_'.$suffix,$migrated,true),'Normal migration covers '.$suffix);mg_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);mg_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){mg_sql(str_replace($name,'fixture_'.$suffix,$ddl));}}
 mg_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','moderator') as $actor){foreach(array('none','source','target','both') as $polls){foreach(array(false,true) as $shadow){
  mg_fixture($actor,$polls);$before=mg_snap();$token=mg_token($shadow);mg_check(mg_snap()===$before,'Preview never changes data');
  mg_check(mg_run($shadow,$token)===200,'Complete merge '.$actor.'/'.$polls.'/'.(int)$shadow.' '.json_encode($mg_error));$after=mg_snap();$writes=$mg_write;$boundaries=array_values(array_filter($mg_queries,'mg_boundary'));
  foreach($before['posts'] as $old){$current=mg_rows('SELECT * FROM fixture_posts WHERE post_id='.(int)$old['post_id']);$old['forum_id']='4';$old['topic_id']='200';mg_check($old===$current[0],'All other canonical post fields preserved');}
  foreach(array('posts_text','search_wordmatch','attachments','attachments_desc','sessions','user_group','auth_access') as $s){mg_check($before[$s]===$after[$s],'Preserve post content/references/authority '.$s);}
  foreach(array('vote_desc','vote_results','vote_voters') as $s){$expected=$before[$s];if($polls==='both'){$expected=array_values(array_filter($expected,function($r){return (int)$r['vote_id']!==1;}));}elseif($polls==='source'&&$s==='vote_desc'){foreach($expected as &$row){$row['topic_id']='200';}unset($row);}mg_check($expected===$after[$s],'Only confirmed source poll conflict is deleted; other ballots preserved '.$s);}
  $target=mg_rows('SELECT * FROM fixture_topics WHERE topic_id=200')[0];mg_check((int)$target['topic_vote']===($polls==='none'?0:1)&&(int)$target['topic_type']===2,'Actual poll topology and target type retained');
  mg_check((int)$target['topic_first_post_id']===10&&(int)$target['topic_last_post_id']===20&&(int)$target['topic_replies']===5&&(int)$target['topic_views']===12&&(int)$target['topic_attachment']===1,'Merged topic pointers/counters/attachment exact');
  $source=mg_rows('SELECT * FROM fixture_topics WHERE topic_id=100');mg_check($shadow?count($source)===1&&(int)$source[0]['topic_moved_id']===200&&(int)$source[0]['topic_first_post_id']===10:!$source,'Chosen source shadow/deletion');
  mg_check(count(mg_rows('SELECT * FROM fixture_topics_watch WHERE topic_id=200'))===2&&mg_rows('SELECT notify_claim FROM fixture_topics_watch WHERE topic_id=200 AND user_id=9')[0]['notify_claim']==='targetclaim','Target watch claim retained; no source duplicate multiplication');
  mg_check(mg_rows('SELECT notify_claim FROM fixture_topics_watch WHERE topic_id=200 AND user_id=8')[0]['notify_claim']==='','Copied author watch starts unclaimed');
  mg_check((int)mg_rows('SELECT SUM(view_count) AS total FROM fixture_topic_view WHERE topic_id=200 AND user_id=9')[0]['total']===12&&count(mg_rows('SELECT * FROM fixture_topic_view WHERE topic_id=200 AND user_id=9'))===2,'Duplicate target view totals increased exactly once');
  mg_check(count(mg_rows('SELECT * FROM fixture_bookmarks WHERE topic_id=200'))===2,'Bookmarks merged without overlap duplication');
  mg_check(count($after['logs'])===2&&mg_rows("SELECT config_value FROM fixture_config WHERE config_name='max_topics'")[0]['config_value']===($shadow?'2':'1')&&mg_rows("SELECT config_value FROM fixture_config WHERE config_name='max_posts'")[0]['config_value']==='6','Logs and global totals atomic');
  mg_check((int)mg_rows('SELECT SUM(user_posts) AS total FROM fixture_users')[0]['total']===0,'Cross-count boundary poster totals exact');
  mg_check(mg_run($shadow,$token)==='error'&&mg_snap()===$after,'Confirmation replay cannot duplicate merge');
  if(($polls==='source'&&!$shadow)||($polls==='both'&&$shadow)){
   for($n=1;$n<=$writes;$n++){mg_fixture($actor,$polls);$before=mg_snap();$mg_fail=$n;mg_check(mg_run($shadow)==='error'&&mg_snap()===$before,'Whole merge rollback at write '.$n.'/'.$polls);$cases++;}
  }
  foreach(array('fail','ack') as $kind){mg_fixture($actor,$polls);$before=mg_snap();$mg_commit=$kind;mg_check(mg_run($shadow)==='error'&&mg_snap()===($kind==='fail'?$before:$after),'Failed/lost COMMIT leaves all-before or all-after');$mg_commit='';if($kind==='ack'){mg_check(mg_run($shadow,$token)==='error'&&mg_snap()===$after,'Uncertain committed merge replay harmless');}$cases++;}
  if($polls==='both'&&$shadow){foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   mg_fixture($actor,$polls);$seen=0;$reached=$blocked=false;$revoked=null;
   $mg_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!mg_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['mg_hook']=null;$reached=true;
    if(!$GLOBALS['peer']->sql_query(mg_revoke($kind))){$e=$GLOBALS['peer']->sql_error();mg_check((int)$e['code']===1205,'Independent authority lock timeout');$blocked=true;}else{$revoked=mg_snap();}};
   $out=mg_run($shadow);mg_check($reached,'Every write/commit boundary reached');
   if($blocked){mg_check($out===200&&mg_snap()===$after,'Revocation serializes after whole merge');mg_sql(mg_revoke($kind));$serialized++;}
   else{mg_check($out==='error'&&mg_snap()===$revoked,'Effective revocation restores all merge data and retains peer change');}$cases++;
  }}}
 }echo $actor.'/'.$polls." native merge topology/failure/authority checks passed\n";}}
 foreach(array('missing','foreign','logout','case','inactive','role') as $kind){mg_fixture();$token=mg_token();mg_sql(mg_revoke($kind));$before=mg_snap();mg_check(mg_run(false,$token)==='error'&&mg_snap()===$before,'Exact current entry authority '.$kind);$denied=false;try{mg_token();}catch(PhpbbTopicMergeException $e){$denied=true;}mg_check($denied,'Preview also refuses revoked current session/authority');$cases++;}
 foreach(array('targetgrant','membership','read') as $kind){mg_fixture('moderator');$blocked=false;$revoked=null;$mg_hook=function($sql)use($kind,&$blocked,&$revoked){if(strpos($sql,'INSERT INTO fixture_bookmarks')!==0){return;}$GLOBALS['mg_hook']=null;if(!$GLOBALS['peer']->sql_query(mg_revoke($kind))){$e=$GLOBALS['peer']->sql_error();mg_check((int)$e['code']===1205,'Permission row lock');$blocked=true;}else{$revoked=mg_snap();}};$out=mg_run();mg_check($blocked?$out===200:$out==='error'&&mg_snap()===$revoked,'Both-forum/membership authority after post move');$cases++;}
 foreach(array("UPDATE fixture_vote_desc SET vote_text='changed' WHERE vote_id=1","UPDATE fixture_vote_results SET vote_option_text='changed' WHERE vote_id=2","DELETE FROM fixture_vote_desc WHERE vote_id=2","UPDATE fixture_topics SET topic_title='changed' WHERE topic_id=200") as $change){mg_fixture();$token=mg_token();mg_sql($change);$before=mg_snap();mg_check(mg_run(false,$token)==='error'&&mg_snap()===$before,'Stale confirmation cannot delete changed polls/topics');$cases++;}
 foreach($mg_participants as $s){mg_fixture();mg_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=mg_snap();mg_check(mg_run()==='error'&&mg_snap()===$before,'Reject legacy participant '.$s);mg_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('row','table-charset','column-charset') as $kind){mg_fixture();$ddl=$kind==='row'?'ROW_FORMAT=COMPACT':($kind==='table-charset'?'DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci':'MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');mg_sql('ALTER TABLE fixture_sessions '.$ddl);$before=mg_snap();mg_check(mg_run()==='error'&&mg_snap()===$before,'Reject alternate storage '.$kind);mg_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');$cases++;}
 mg_fixture();$before=mg_snap();$killed=false;$mg_hook=function($sql)use(&$killed){if(strpos($sql,'DELETE FROM fixture_vote_voters')!==0){return;}$GLOBALS['mg_hook']=null;$killed=true;mg_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['mg_writer']->db_connect_id));};mg_check(mg_run()==='error'&&$killed&&mg_snap()===$before,'Real disconnect restores topics/posts/preferences/ballots');
 mg_fixture();$reached=false;$mg_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_posts p JOIN')!==0){return;}$GLOBALS['mg_hook']=null;$other=new attach_mutation_lock($GLOBALS['db'],false);$reached=!$other->acquired;$other->release();};mg_check(mg_run()===200&&$reached,'Competing writer excluded');
 mg_fixture('moderator');mg_sql('UPDATE fixture_topics SET forum_id=3 WHERE topic_id=200');mg_sql('UPDATE fixture_posts SET forum_id=3 WHERE topic_id=200');mg_check(mg_run()===200&&count(mg_rows('SELECT * FROM fixture_posts WHERE forum_id=3'))===6,'Same-forum merge retains all posts');
 foreach(array(false,true) as $failure){mg_fixture();$before=mg_snap();$mg_fail=$failure?3:0;$mg_hook=function($sql){if(strpos($sql,'UPDATE fixture_posts p JOIN')!==0){return;}$GLOBALS['mg_hook']=null;mg_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'");};$out=mg_run();$after=mg_snap();mg_check(($out==='error')===$failure&&mg_rows("SELECT config_value FROM fixture_config WHERE config_name='board_disable'")[0]['config_value']==='1','Independent board disabling retained');if($failure){foreach($before['config'] as &$row){if($row['config_name']==='board_disable'){$row['config_value']='1';}}unset($row);mg_check($before===$after,'Rollback retains only independent availability change');}}
 // Real endpoint preparation/confirmation branch, before template rendering.
 $controller=file_get_contents($phpbb_root_path.'merge.php');$a=strpos($controller,'// submission:');$b=strpos($controller,'// The confirmation is tied',$a);mg_check($a!==false&&$b>$a,'Actual merge controller found');$branch=substr($controller,$a,$b-$a).'}';
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require $phpbb_root_path.'language/lang_'.$locale.'/lang_extend_merge.php';foreach(array(false,true) as $shadow){foreach(array('success','failure','ack','denied') as $kind){
  mg_fixture('moderator');$before=mg_snap();$template=new MergeNativeTemplate();$from_topic_id=100;$to_topic_id=200;$topic_title="Merge Grüße O'Reilly \\ & 😀";$SID='sid=review-sid';$sid=$userdata['session_id'];$submit=true;$confirm=false;$_SERVER['REQUEST_METHOD']='POST';$_POST=array();
  eval($branch);mg_check(isset($merge_context['token'])&&mg_snap()===$before,'Actual preparation is read-only');$_POST['merge_token']=$merge_context['token'];$confirm=true;
  if($kind==='failure'){$mg_fail=3;}if($kind==='ack'){$mg_commit='ack';}if($kind==='denied'){mg_sql(mg_revoke('missing'));$before=mg_snap();}
  $message='';try{eval($branch);}catch(MergeNativeResponse $e){$message=$e->getMessage();}mg_check($message!==''&&((strpos($message,$lang['Merge_topic_done'])===0)===($kind==='success')),'Only confirmed merge reports success');mg_check((!empty($template->vars))===($kind==='success'),'No success redirect on failed or uncertain merge');
  if($kind==='failure'||$kind==='denied'){mg_check(mg_snap()===$before,'Controller rollback leaves no partial state');}if($kind==='ack'){mg_check(count(mg_rows('SELECT * FROM fixture_logs'))===2,'Uncertain controller merge has complete audit');}
 }}}
 define('CACHE_TREE',true);$mg_source_root=$phpbb_root_path;$mg_cache=sys_get_temp_dir().'/phpbb-merge-cache-'.bin2hex(phpbb_random_bytes(8));mg_check(mkdir($mg_cache,0700)&&mkdir($mg_cache.'/cache',0700),'Owned cache directory');
 try{$phpbb_root_path=$mg_cache.'/';foreach(array('success','failure','ack') as $kind){mg_fixture();file_put_contents($mg_cache.'/cache/tree.cache','stale');if($kind==='failure'){$mg_fail=3;}if($kind==='ack'){$mg_commit='ack';}$out=mg_run();mg_check(($out===200)===($kind==='success')&&!file_exists($mg_cache.'/cache/tree.cache'),'Cache invalidated after confirmed/failed/uncertain merge');}}
 finally{$phpbb_root_path=$mg_source_root;if(is_file($mg_cache.'/cache/tree.cache')){unlink($mg_cache.'/cache/tree.cache');}rmdir($mg_cache.'/cache');rmdir($mg_cache);}
 echo 'Native topic merge: '.$cases.' failure/boundary cases, '.$serialized." serialized changes; four poll topologies, shadow choices and actual controllers passed.\n";
}finally{$mg_hook=null;$mg_fail=0;$mg_prefix=$mg_commit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
