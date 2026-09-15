<?php
// Real mysqli, canonical owned tables and independent authority/failure peers.
if(PHP_SAPI!=='cli'||getenv('PHPBB_VOTE_NATIVE')!=='1'){echo "Native voting checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';
require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';
require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';require $phpbb_root_path.'includes/functions_poll_storage.php';
function pv_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class PollNativeHtmlResponse extends RuntimeException {}
function message_die($level,$message){throw new PollNativeHtmlResponse($message);}
class PollNativeResponse extends RuntimeException {var $value;function __construct($value){$this->value=$value;parent::__construct('AJAX response');}}
function AJAX_message_die($value){throw new PollNativeResponse($value);}
function append_sid($url){return $url;}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_VOTE_PORT')?:'3306';pv_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_VOTE_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_vote_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);pv_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);$pv_hook=null;$pv_queries=array();$pv_fail='';$pv_ack=false;
class PollNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['pv_queries'][]=$sql;if(is_callable($GLOBALS['pv_hook'])){call_user_func($GLOBALS['pv_hook'],$sql);}
  if($GLOBALS['pv_fail']!==''&&strpos($sql,$GLOBALS['pv_fail'])===0){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['pv_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['pv_ack']?false:$r;
 }
}
class PollNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new PollNativeConnection(parent::sql_dedicated_connection());$GLOBALS['pv_writer']=$c;return $c;}}
$db=new PollNativeDatabase($host,'root',$password,$schema,false);
function pv_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);pv_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function pv_rows($sql){$r=pv_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function pv_insert($table,$values){
 foreach($GLOBALS['pv_columns'][$table] as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pv_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}
$pv_tables=array('users','sessions','forums','topics','user_group','auth_access','vote_desc','vote_results','vote_voters');$pv_columns=array();
function pv_snapshot(){$out=array();foreach($GLOBALS['pv_tables'] as $s){$rows=pv_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;}
function pv_fixture($actor='member'){
 global $pv_hook,$pv_queries,$pv_fail,$pv_ack,$userdata,$user_ip;
 $pv_hook=null;$pv_queries=array();$pv_fail='';$pv_ack=false;$GLOBALS['pv_error']=null;
 pv_sql('START TRANSACTION');foreach($GLOBALS['pv_tables'] as $s){pv_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1,8,9) as $id){pv_insert('fixture_users',array('user_id'=>$id,'username'=>'Grüße 😀','user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1));}
 $id=$actor==='guest'?ANONYMOUS:8;$logged=$actor!=='guest';
 pv_insert('fixture_sessions',array('session_id'=>'vote-sid','session_user_id'=>$id,'session_logged_in'=>(int)$logged,'session_admin'=>0));
 pv_insert('fixture_forums',array('forum_id'=>3,'forum_name'=>'fixture','auth_view'=>0,'auth_read'=>0,'auth_vote'=>$actor==='root'?AUTH_ADMIN:($actor==='guest'?AUTH_ALL:AUTH_REG),'forum_status'=>FORUM_UNLOCKED));
 pv_insert('fixture_topics',array('topic_id'=>100,'forum_id'=>3,'topic_title'=>'Grüße 😀','topic_status'=>TOPIC_UNLOCKED,'topic_vote'=>1));
 pv_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>100,'vote_text'=>'Grüße 😀'));
 foreach(array(1,2) as $choice){pv_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>$choice,'vote_option_text'=>'Option '.$choice,'vote_result'=>$choice===1?1:0));}
 pv_insert('fixture_vote_voters',array('vote_id'=>1,'vote_user_id'=>9,'vote_user_ip'=>'7f000001'));
 if($actor==='moderator'){pv_insert('fixture_user_group',array('user_id'=>8,'group_id'=>7,'user_pending'=>0));pv_insert('fixture_auth_access',array('forum_id'=>3,'group_id'=>7,'auth_mod'=>1));pv_sql('UPDATE fixture_forums SET forum_status=1');pv_sql('UPDATE fixture_topics SET topic_status=1');}
 pv_sql('COMMIT');$userdata=array('user_id'=>$id,'user_level'=>ADMIN,'session_logged_in'=>$logged,'session_id'=>'vote-sid');$user_ip='7f000001';
}
function pv_vote($option=1){try{return phpbb_cast_poll_vote($GLOBALS['db'],100,$option);}catch(PhpbbPollStorageException $e){return 'error';}}
function pv_boundary($sql){return preg_match('/^(INSERT|UPDATE)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function pv_revoke($kind){$map=array('session'=>"DELETE FROM fixture_sessions WHERE session_id='vote-sid'",'case'=>"UPDATE fixture_sessions SET session_id='VOTE-SID'",'foreign'=>'UPDATE fixture_sessions SET session_user_id=9','logout'=>'UPDATE fixture_sessions SET session_logged_in=0','guestlogin'=>'UPDATE fixture_sessions SET session_logged_in=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0','membership'=>'DELETE FROM fixture_user_group','pending'=>'UPDATE fixture_user_group SET user_pending=1','vote'=>'UPDATE fixture_forums SET auth_vote=5','read'=>'UPDATE fixture_forums SET auth_read=5');return $map[$kind];}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');require dirname(dirname(__DIR__)).'/update/innodb_migration.php';$migrated=plus_storage_tables($canonical,'fixture_');
 foreach($pv_tables as $suffix){$name='phpbb_'.$suffix;pv_check(in_array('fixture_'.$suffix,$migrated,true),'Updater covers '.$suffix);pv_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);pv_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){pv_sql(str_replace($name,'fixture_'.$suffix,$ddl));}$pv_columns['fixture_'.$suffix]=pv_rows('SHOW COLUMNS FROM fixture_'.$suffix);}
 pv_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('guest','member','moderator','root') as $actor){
  pv_fixture($actor);$before=pv_snapshot();pv_check(pv_vote()==='Vote_cast','Successful vote '.$actor.' '.json_encode($pv_error));$after=pv_snapshot();$boundaries=array_values(array_filter($pv_queries,'pv_boundary'));
  foreach($pv_tables as $s){if(!in_array($s,array('vote_results','vote_voters'),true)){pv_check($before[$s]===$after[$s],'Unrelated table preserved '.$s);}}
  pv_check(count($after['vote_voters'])===2&&(int)pv_rows('SELECT vote_result FROM fixture_vote_results WHERE vote_option_id=1')[0]['vote_result']===2,'Exactly one voter and increment');
  pv_check(pv_vote(2)==='Already_voted'&&pv_snapshot()===$after,'Retry/other choice cannot count twice');
  foreach(array('START TRANSACTION','SELECT ENGINE,','SELECT vd.','SELECT a.forum_id','INSERT INTO fixture_vote_voters','UPDATE fixture_vote_results','COMMIT') as $failure){pv_fixture($actor);$before=pv_snapshot();$pv_fail=$failure;pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Whole vote rollback '.$failure);$cases++;}
  pv_fixture($actor);$pv_ack=true;pv_check(pv_vote()==='error'&&pv_snapshot()===$after,'Lost acknowledgement leaves a whole vote');$pv_ack=false;pv_check(pv_vote(2)==='Already_voted'&&pv_snapshot()===$after,'Lost acknowledgement retry safe');
  foreach(array('session',$actor==='root'?'role':($actor==='moderator'?'grant':'read')) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   pv_fixture($actor);$before=pv_snapshot();$seen=0;$reached=$blocked=false;$revoked=null;
   $pv_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!pv_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['pv_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(pv_revoke($kind))){$e=$GLOBALS['peer']->sql_error();pv_check((int)$e['code']===1205,'Independent lock timeout only');$blocked=true;}else{$revoked=pv_snapshot();}};
   $out=pv_vote();pv_check($reached,'Every publication/authority boundary reached');
   if($blocked){pv_check($out==='Vote_cast'&&pv_snapshot()===$after,'Authority change serializes after complete vote');pv_sql(pv_revoke($kind));$serialized++;}
   else{pv_check($out==='error'&&pv_snapshot()===$revoked,'Effective revocation leaves only peer change');}$cases++;
  }}
  foreach(array('session','case','foreign',$actor==='guest'?'guestlogin':'logout') as $kind){pv_fixture($actor);pv_sql(pv_revoke($kind));$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Exact entry session '.$actor.'/'.$kind);$cases++;}
  pv_fixture($actor);$before=pv_snapshot();$reached=false;$pv_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_vote_results')!==0){return;}$GLOBALS['pv_hook']=null;$reached=true;pv_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['pv_writer']->db_connect_id));};pv_check(pv_vote()==='error'&&$reached&&pv_snapshot()===$before,'Real connection loss leaves no uncounted voter');
  echo $actor." native atomic vote and current authority checks passed\n";
 }
 foreach(array('inactive','vote','read') as $kind){pv_fixture();pv_sql(pv_revoke($kind));$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Current member authority '.$kind);$cases++;}
 foreach(array('grant','membership','pending') as $kind){pv_fixture('moderator');pv_sql(pv_revoke($kind));$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Current moderator lock exception '.$kind);$cases++;}
 foreach(array('UPDATE fixture_forums SET forum_status=1','UPDATE fixture_topics SET topic_status=1','UPDATE fixture_topics SET topic_moved_id=55','DELETE FROM fixture_topics','DELETE FROM fixture_forums','DELETE FROM fixture_vote_desc','DELETE FROM fixture_vote_results WHERE vote_option_id=1','UPDATE fixture_vote_desc SET vote_start=1,vote_length=1','UPDATE fixture_vote_results SET vote_result=2147483647 WHERE vote_option_id=1') as $change){pv_fixture();pv_sql($change);$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Stale/expired/overflow target');$cases++;}
 pv_fixture();$reached=false;$pv_hook=function($sql)use(&$reached){if(strpos($sql,'UPDATE fixture_vote_results')!==0){return;}$GLOBALS['pv_hook']=null;$other=new attach_mutation_lock($GLOBALS['db'],false);$reached=!$other->acquired;$other->release();};pv_check(pv_vote()==='Vote_cast'&&$reached,'Competing writer excluded');
 pv_fixture('guest');pv_check(pv_vote()==='Vote_cast','First guest');$user_ip='7f000002';pv_check(pv_vote(2)==='Vote_cast','Different guest IP allowed');
 pv_fixture();pv_check(pv_vote()==='Vote_cast','First member');$user_ip='7f000002';pv_check(pv_vote(2)==='Already_voted','Member identity ignores changed IP');
 foreach($pv_tables as $s){pv_fixture();pv_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Legacy participant refused '.$s);pv_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci','MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL') as $ddl){pv_fixture();pv_sql('ALTER TABLE fixture_sessions '.$ddl);$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Alternate storage refused');pv_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');$cases++;}
 // Ambiguous old option rows must never let only one of two copies count.
 foreach(array(0,2147483647) as $count){pv_fixture();pv_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>1,'vote_option_text'=>'Duplicate','vote_result'=>$count));$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Duplicate option refused before publication');$cases++;}
 pv_fixture();pv_insert('fixture_vote_desc',array('vote_id'=>2,'topic_id'=>100,'vote_text'=>'Duplicate owner'));$before=pv_snapshot();pv_check(pv_vote()==='error'&&pv_snapshot()===$before,'Duplicate topic poll refused');$cases++;
 foreach(array('DELETE FROM fixture_vote_results WHERE vote_option_id=1','DELETE FROM fixture_vote_desc','UPDATE fixture_topics SET forum_id=4') as $change){
  pv_fixture();$before=pv_snapshot();$reached=false;$pv_hook=function($sql)use($change,&$reached){if(strpos($sql,'UPDATE fixture_vote_results')!==0){return;}$GLOBALS['pv_hook']=null;$reached=true;pv_check(!$GLOBALS['peer']->sql_query($change),'Current poll target is locked');$e=$GLOBALS['peer']->sql_error();pv_check((int)$e['code']===1205,'Target writer must serialize');};
  pv_check(pv_vote()==='Vote_cast'&&$reached,'Complete vote before independent target edit');$cases++;
 }
 pv_fixture();pv_sql('UPDATE fixture_vote_desc SET vote_start='.(time()+10).',vote_length=1');$before=pv_snapshot();$reached=false;
 $pv_hook=function($sql)use(&$reached){if(strpos($sql,'SELECT session_id')!==0||strpos($sql,'LOCK IN SHARE MODE')===false){return;}$GLOBALS['pv_hook']=null;$reached=true;pv_sql('DO SLEEP(12)');};
 pv_check(pv_vote()==='error'&&$reached&&pv_snapshot()===$before,'Expiry before commit rolls back both voter and counter');$cases++;
 // Execute the real HTML/AJAX submission handoffs and transport catches.
 $ajax=file_get_contents($phpbb_root_path.'ajax.php');$a=strpos($ajax,'function ajax_scalar_value(');$b=strpos($ajax,'// Get SID and check it',$a);pv_check($a!==false&&$b>$a,'Actual AJAX request helpers');eval(substr($ajax,$a,$b-$a));
 $a=strpos($ajax,"\t// Get topic_id",strpos($ajax,'// Voting/Viewing of polls'));$b=strpos($ajax,"\t// Display vote information",$a);pv_check($a!==false&&$b>$a,'AJAX handoff');$ajax_branch=substr($ajax,$a,$b-$a);
 $posting=file_get_contents($phpbb_root_path.'posting.php');$a=strpos($posting,"\t\trequire_once(",strpos($posting,"else if ( \$mode == 'vote' )"));$b=strpos($posting,"\t\t\$template->assign_vars",$a);pv_check($a!==false&&$b>$a,'HTML handoff');$post_branch=substr($posting,$a,$b-$a);
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('html','ajax') as $transport){foreach(array('guest','member') as $actor){foreach(array('success','failure','ack','denied') as $kind){
  pv_fixture($actor);if($kind==='failure'){$pv_fail='UPDATE fixture_vote_results';}if($kind==='ack'){$pv_ack=true;}if($kind==='denied'){pv_sql(pv_revoke('session'));}$before=pv_snapshot();
  $mode='vote_poll';$HTTP_POST_VARS=array('t'=>100,'vote_option_id'=>1);$HTTP_GET_VARS=array();$topic_id=100;$_POST=array('vote_id'=>1);$message='';$response=null;
  if($transport==='html'){$caught=false;try{eval($post_branch);}catch(PollNativeHtmlResponse $e){$caught=true;$message=$e->getMessage();}pv_check($caught===($kind!=='success')&&(($message===$lang['Vote_cast'])===($kind==='success')),'Truthful HTML result');}
  else{try{eval($ajax_branch);}catch(PollNativeResponse $e){$response=$e->value;}pv_check(($response===null)===($kind==='success'),'Truthful AJAX result');if($kind==='success'){pv_check(!$can_vote,'AJAX displays results');}else{pv_check($response['result']===AJAX_ERROR,'XML error transport');}}
  if($kind==='failure'||$kind==='denied'){pv_check(pv_snapshot()===$before,'Controller leaves no partial vote');}
  $pv_ack=false;$pv_fail='';if($kind==='ack'||$kind==='success'){pv_check(pv_vote(2)==='Already_voted','Controller replay safe');}
 }}}}
 echo 'Native poll voting: '.$cases.' failure/authority/storage cases, '.$serialized." serialized changes; guest/member/moderator/root and actual controllers passed.\n";
}finally{$pv_hook=null;$pv_fail='';$pv_ack=false;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
