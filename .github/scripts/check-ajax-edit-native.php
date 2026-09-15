<?php
// Canonical owned InnoDB tables, actual driver/parser/index and independent peer.
if(PHP_SAPI!=='cli'||getenv('PHPBB_AJAX_EDIT_NATIVE')!=='1'){echo "Native AJAX edit checks require an explicitly enabled disposable database.\n";return;}
define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require $phpbb_root_path.'includes/constants.php';require $phpbb_root_path.'attach_mod/includes/constants.php';require $phpbb_root_path.'includes/php_compat.php';require $phpbb_root_path.'db/mysqli.php';
require $phpbb_root_path.'attach_mod/includes/functions_includes.php';require $phpbb_root_path.'attach_mod/includes/functions_mutation.php';require $phpbb_root_path.'attach_mod/includes/functions_delete.php';require $phpbb_root_path.'includes/auth.php';
require $phpbb_root_path.'includes/functions_post.php';require $phpbb_root_path.'includes/bbcode.php';require $phpbb_root_path.'includes/functions_search.php';require $phpbb_root_path.'includes/functions_ajax_storage.php';
function ae_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($level,$message){throw new RuntimeException($message);}
class AjaxNativeResponse extends RuntimeException {var $value;function __construct($value){$this->value=$value;parent::__construct('AJAX response');}}
function AJAX_message_die($value){throw new AjaxNativeResponse($value);}
function obtain_word_list(&$words,&$replacements){$words=$replacements=array();}
function create_date($format,$time,$tz){return 'fixture date';}
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
$port=getenv('PHPBB_AJAX_EDIT_PORT')?:'3306';ae_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$password=getenv('PHPBB_AJAX_EDIT_PASSWORD')?:'';$host='127.0.0.1:'.$port;$schema='codex_ajax_atomic_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ae_check($control->sql_query('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
$peer=new sql_db($host,'root',$password,$schema,false);$ae_hook=null;$ae_queries=array();$ae_fail='';$ae_write=$ae_failwrite=0;$ae_ack=false;
class AjaxNativeConnection {
 var $inner;var $db_connect_id;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->inner,$m),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['ae_queries'][]=$sql;if(is_callable($GLOBALS['ae_hook'])){call_user_func($GLOBALS['ae_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['ae_write']===$GLOBALS['ae_failwrite']){return false;}
  if($GLOBALS['ae_fail']!==''&&strpos($sql,$GLOBALS['ae_fail'])===0){return false;}
  $r=$this->inner->sql_query($sql,$transaction);if(!$r){$GLOBALS['ae_error']=$this->inner->sql_error();}
  return $sql==='COMMIT'&&$GLOBALS['ae_ack']?false:$r;
 }
}
class AjaxNativeDatabase extends sql_db {function sql_dedicated_connection(){$c=new AjaxNativeConnection(parent::sql_dedicated_connection());$GLOBALS['ae_writer']=$c;return $c;}}
$db=new AjaxNativeDatabase($host,'root',$password,$schema,false);
function ae_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ae_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function ae_rows($sql){$r=ae_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function ae_insert($table,$values){foreach($GLOBALS['ae_columns'][$table] as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}ae_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');}
$ae_tables=array('users','sessions','forums','topics','posts','posts_text','user_group','auth_access','search_wordlist','search_wordmatch','config');$ae_columns=array();
function ae_snapshot(){
 $out=array();foreach($GLOBALS['ae_tables'] as $s){$rows=ae_rows('SELECT * FROM fixture_'.$s);foreach($rows as &$row){if($s==='posts'&&(int)$row['post_edit_time']>0){$row['post_edit_time']='timestamp';}if($s==='posts_text'&&$row['bbcode_uid']!==''){$row['post_text']=str_replace(':'.$row['bbcode_uid'],':parseruid',$row['post_text']);$row['bbcode_uid']='parseruid';}}unset($row);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
function ae_fixture($actor='member',$common=false){
 global $ae_hook,$ae_queries,$ae_fail,$ae_ack,$ae_write,$ae_failwrite,$userdata,$board_config;
 $ae_hook=null;$ae_queries=array();$ae_fail='';$ae_ack=false;$ae_write=$ae_failwrite=0;$GLOBALS['ae_error']=null;
 ae_sql('START TRANSACTION');foreach($GLOBALS['ae_tables'] as $s){ae_sql('DELETE FROM fixture_'.$s);}ae_sql('COMMIT');ae_sql('ALTER TABLE fixture_search_wordlist AUTO_INCREMENT=3');ae_sql('START TRANSACTION');
 foreach(array(8,9) as $id){ae_insert('fixture_users',array('user_id'=>$id,'username'=>"Grüße O'Reilly 😀",'user_level'=>$id===8&&$actor==='root'?ADMIN:USER,'user_active'=>1));}
 ae_insert('fixture_sessions',array('session_id'=>'edit-sid','session_user_id'=>8,'session_logged_in'=>1,'session_admin'=>0));
 ae_insert('fixture_forums',array('forum_id'=>3,'forum_name'=>'fixture','auth_view'=>AUTH_REG,'auth_read'=>AUTH_REG,'auth_edit'=>AUTH_REG,'forum_status'=>$actor==='moderator'?FORUM_LOCKED:FORUM_UNLOCKED));
 ae_insert('fixture_topics',array('topic_id'=>100,'forum_id'=>3,'topic_title'=>'Old title','topic_status'=>$actor==='moderator'?TOPIC_LOCKED:TOPIC_UNLOCKED,'topic_first_post_id'=>20,'topic_last_post_id'=>10));
 foreach(array(10,20) as $id){ae_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>100,'forum_id'=>3,'poster_id'=>$actor==='member'?8:9,'enable_bbcode'=>1));ae_insert('fixture_posts_text',array('post_id'=>$id,'post_subject'=>'Old title','post_text'=>'oldbody','bbcode_uid'=>'1234567890'));}
 ae_insert('fixture_search_wordlist',array('word_id'=>1,'word_text'=>'oldbody'));ae_insert('fixture_search_wordlist',array('word_id'=>2,'word_text'=>'oldtitle'));
 foreach(array(10,20) as $id){foreach(array(1,2) as $word){ae_insert('fixture_search_wordmatch',array('post_id'=>$id,'word_id'=>$word,'title_match'=>$word===2?1:0));}}
 ae_insert('fixture_config',array('config_name'=>'board_disable','config_value'=>'0'));ae_insert('fixture_config',array('config_name'=>'dbmtnc_rebuild_job','config_value'=>''));
 if($actor==='moderator'){ae_insert('fixture_user_group',array('user_id'=>8,'group_id'=>7,'user_pending'=>0));ae_insert('fixture_auth_access',array('forum_id'=>3,'group_id'=>7,'auth_mod'=>1));}
 if($common){ae_insert('fixture_search_wordlist',array('word_id'=>3,'word_text'=>'sharedword'));for($id=1000;$id<1100;$id++){ae_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>200,'forum_id'=>4,'poster_id'=>9));ae_insert('fixture_search_wordmatch',array('post_id'=>$id,'word_id'=>3,'title_match'=>0));}}
 ae_sql('COMMIT');$userdata=array('user_id'=>8,'user_level'=>ADMIN,'session_logged_in'=>true,'session_id'=>'edit-sid','user_allowhtml'=>false);
 $board_config=array('default_lang'=>'english','allow_html'=>false,'allow_bbcode'=>true,'allow_smilies'=>false,'default_dateformat'=>'Y-m-d','board_timezone'=>0);
}
function ae_value($field){return $field==='subject'?"Quasarword Grüße O'Reilly 😀":"[b]Quasarword Grüße[/b] O'Reilly \\ & 😀 sharedword";}
function ae_edit($field='subject',$post=10,$value=null){try{return phpbb_ajax_edit_post($GLOBALS['db'],$post,$field,$value===null?ae_value($field):$value);}catch(PhpbbAjaxStorageException $e){$GLOBALS['ae_exception']=$e->getMessage();return 'error';}}
function ae_boundary($sql){return preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function ae_revoke($kind){$map=array('session'=>"DELETE FROM fixture_sessions WHERE session_id='edit-sid'",'case'=>"UPDATE fixture_sessions SET session_id='EDIT-SID'",'foreign'=>'UPDATE fixture_sessions SET session_user_id=9','logout'=>'UPDATE fixture_sessions SET session_logged_in=0','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=8','role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=8','grant'=>'UPDATE fixture_auth_access SET auth_mod=0','membership'=>'DELETE FROM fixture_user_group','pending'=>'UPDATE fixture_user_group SET user_pending=1','edit'=>'UPDATE fixture_forums SET auth_edit=5','read'=>'UPDATE fixture_forums SET auth_read=5');return $map[$kind];}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $canonical=file_get_contents($phpbb_root_path.'install/schemas/mysql_schema.sql');require dirname(dirname(__DIR__)).'/update/innodb_migration.php';$migrated=plus_storage_tables($canonical,'fixture_');
 foreach($ae_tables as $suffix){$name='phpbb_'.$suffix;ae_check(in_array('fixture_'.$suffix,$migrated,true),'Updater covers '.$suffix);ae_check(preg_match('/CREATE TABLE '.$name.'\s*\([\s\S]*?;/',$canonical,$match)===1,'Canonical table '.$suffix);ae_sql(str_replace($name,'fixture_'.$suffix,$match[0]));preg_match_all('/ALTER TABLE '.$name.'\s+[\s\S]*?;/',$canonical,$extra);foreach($extra[0] as $ddl){ae_sql(str_replace($name,'fixture_'.$suffix,$ddl));}$ae_columns['fixture_'.$suffix]=ae_rows('SHOW COLUMNS FROM fixture_'.$suffix);}
 ae_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('member','moderator','root') as $actor){foreach(array('subject','text') as $field){
  ae_fixture($actor);$before=ae_snapshot();$out=ae_edit($field);ae_check(is_array($out),'Successful edit '.$actor.'/'.$field.' '.json_encode(array($ae_error,isset($ae_exception)?$ae_exception:null,array_slice($ae_queries,-4))));$after=ae_snapshot();$writes=$ae_write;$boundaries=array_values(array_filter($ae_queries,'ae_boundary'));
  foreach(array('users','sessions','forums','user_group','auth_access','config') as $s){ae_check($before[$s]===$after[$s],'Unrelated data preserved '.$s);}
  $stored=ae_rows('SELECT * FROM fixture_posts_text WHERE post_id=10')[0];ae_check($stored['post_'.$field]===$out['value']&&$stored['bbcode_uid']===$out['bbcode_uid'],'Returned content matches committed bytes');
  ae_check(ae_rows('SELECT * FROM fixture_posts_text WHERE post_id=20')[0]['post_text']==='oldbody','Other post content intact');
  ae_check(count(ae_rows('SELECT * FROM fixture_search_wordmatch WHERE post_id=20'))===2&&count(ae_rows('SELECT * FROM fixture_search_wordmatch WHERE post_id=10 AND title_match='.($field==='text'?1:0)))===1,'Other post and unedited field index preserved');
  ae_check((int)ae_rows('SELECT post_edit_count FROM fixture_posts WHERE post_id=10')[0]['post_edit_count']===($actor==='member'?1:0),'Own non-last edit counter only');
  if($field==='subject'){ae_check(ae_rows('SELECT topic_title FROM fixture_topics WHERE topic_id=100')[0]['topic_title']===$out['value'],'Actual first-post title despite stale cached bounds');}
  ae_check(is_array(ae_edit($field))&&ae_snapshot()===$after,'No-op retry preserves complete state, parser UID and edit count');
  for($nth=1;$nth<=$writes;$nth++){ae_fixture($actor);$before=ae_snapshot();$ae_failwrite=$nth;ae_check(ae_edit($field)==='error'&&ae_snapshot()===$before,'Rollback every content/index write '.$nth);$cases++;}
  foreach(array(false,true) as $ack){ae_fixture($actor);$before=ae_snapshot();$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';ae_check(ae_edit($field)==='error'&&ae_snapshot()===($ack?$after:$before),'Failed/lost COMMIT all-before or all-after');$ae_ack=false;$ae_fail='';if($ack){ae_check(is_array(ae_edit($field))&&ae_snapshot()===$after,'Lost acknowledgement retry does not count/reindex');}$cases++;}
  foreach(array('session',$actor==='root'?'role':($actor==='moderator'?'grant':'edit')) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   ae_fixture($actor);$seen=0;$reached=$blocked=false;$revoked=null;$ae_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!ae_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ae_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Independent authority lock timeout');$blocked=true;}else{$revoked=ae_snapshot();}};
   $out=ae_edit($field);ae_check($reached,'Every write/commit authority boundary reached');if($blocked){ae_check(is_array($out)&&ae_snapshot()===$after,'Authority revocation serializes after complete edit');ae_sql(ae_revoke($kind));$serialized++;}else{ae_check($out==='error'&&ae_snapshot()===$revoked,'Effective revocation leaves only independent change');}$cases++;
  }}
  ae_fixture($actor);$before=ae_snapshot();$reached=false;$ae_hook=function($sql)use(&$reached){if(strpos($sql,'DELETE FROM fixture_search_wordmatch')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(ae_edit($field)==='error'&&$reached&&ae_snapshot()===$before,'Real disconnect restores content/title/count/index');
  echo $actor.'/'.$field." native atomic edit and authority checks passed\n";
 }}
 foreach(array('session','case','foreign','logout','inactive','edit','read') as $kind){ae_fixture();ae_sql(ae_revoke($kind));$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Current entry session/account/rights '.$kind);$cases++;}
 foreach(array('grant','membership','pending') as $kind){ae_fixture('moderator');ae_sql(ae_revoke($kind));$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Current moderator grant '.$kind);$cases++;}
 foreach(array('UPDATE fixture_forums SET forum_status=1','UPDATE fixture_topics SET topic_status=1','UPDATE fixture_topics SET topic_moved_id=99','UPDATE fixture_posts SET poster_id=9','DELETE FROM fixture_posts_text WHERE post_id=10','DELETE FROM fixture_topics') as $change){ae_fixture();ae_sql($change);$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Stale target/ownership refuses edit');$cases++;}
 ae_fixture();$before=ae_snapshot();ae_check(is_array(ae_edit('subject',20,''))&&ae_rows('SELECT topic_title FROM fixture_topics WHERE topic_id=100')[0]['topic_title']==='Old title'&&(int)ae_rows('SELECT post_edit_count FROM fixture_posts WHERE post_id=20')[0]['post_edit_count']===0,'Empty reply subject and actual last-post counter preserved');
 ae_fixture();ae_sql("UPDATE fixture_topics SET topic_title='Divergent title'");ae_check(is_array(ae_edit('subject',10,'Old title'))&&ae_rows('SELECT topic_title FROM fixture_topics WHERE topic_id=100')[0]['topic_title']==='Old title'&&(int)ae_rows('SELECT post_edit_count FROM fixture_posts WHERE post_id=10')[0]['post_edit_count']===0,'Repair divergent first-topic title without extra post edit');
 foreach(array('subject','text') as $field){
  ae_fixture('member',true);$before=ae_snapshot();ae_check(is_array(ae_edit($field,10,'sharedword quasarword')),'Common-word index update');$writes=$ae_write;ae_check((int)ae_rows("SELECT word_common FROM fixture_search_wordlist WHERE word_text='sharedword'")[0]['word_common']===1&&!ae_rows('SELECT * FROM fixture_search_wordmatch WHERE word_id=3'),'Common words pruned across index');
  for($nth=1;$nth<=$writes;$nth++){ae_fixture('member',true);$before=ae_snapshot();$ae_failwrite=$nth;ae_check(ae_edit($field,10,'sharedword quasarword')==='error'&&ae_snapshot()===$before,'Common-word transition whole rollback '.$nth);$cases++;}
 }
 ae_fixture('member',true);ae_sql("UPDATE fixture_config SET config_value='{}' WHERE config_name='dbmtnc_rebuild_job'");ae_check(is_array(ae_edit('text',10,'sharedword quasarword'))&&(int)ae_rows("SELECT word_common FROM fixture_search_wordlist WHERE word_text='sharedword'")[0]['word_common']===0,'Active rebuild preserves partial index');
 foreach($ae_tables as $s){ae_fixture();ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Legacy participant refused '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('ROW_FORMAT=COMPACT','DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci','MODIFY session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL') as $ddl){ae_fixture();ae_sql('ALTER TABLE fixture_sessions '.$ddl);$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Alternate storage refused');ae_sql('ALTER TABLE fixture_sessions ROW_FORMAT=DYNAMIC, DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, MODIFY session_id CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');$cases++;}
 // BEGIN search-collation variants (also runnable as a focused native check).
 foreach(array('utf8mb4_unicode_ci','utf8mb4_bin') as $collation){ae_fixture();ae_sql('ALTER TABLE fixture_search_wordlist MODIFY word_text VARCHAR(50) CHARACTER SET utf8mb4 COLLATE '.$collation." NOT NULL DEFAULT ''");ae_check(is_array(ae_edit('text')),'Both canonical and migrated UTF8 word collations supported');$cases++;}
 foreach(array('ascii COLLATE ascii_bin','latin1 COLLATE latin1_bin','utf8mb4 COLLATE utf8mb4_general_ci') as $definition){ae_fixture();ae_sql('ALTER TABLE fixture_search_wordlist MODIFY word_text VARCHAR(50) CHARACTER SET '.$definition." NOT NULL DEFAULT ''");$before=ae_snapshot();ae_check(ae_edit()==='error'&&ae_snapshot()===$before,'Other word charset/collation is not silently permitted');ae_sql("ALTER TABLE fixture_search_wordlist MODIFY word_text VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''");$cases++;}
 // END search-collation variants.
 // Real request decoding, branches, response draft and rendering; only censor
 // lookup and displayed date are deterministic presentation fixtures.
 $ajax=file_get_contents($phpbb_root_path.'ajax.php');$a=strpos($ajax,'function ajax_scalar_value(');$b=strpos($ajax,'// Get SID and check it',$a);ae_check($a!==false&&$b>$a,'Actual request helpers');eval(substr($ajax,$a,$b-$a));
 $functions=file_get_contents($phpbb_root_path.'includes/functions.php');foreach(array('phpbb_profile_text','utf8_rawurldecode','unhtmlspecialchars') as $name){$a=strpos($functions,'function '.$name.'(');$b=strpos($functions,"\n}",$a);ae_check($a!==false&&$b>$a,'Actual text conversion '.$name);eval(substr($functions,$a,$b+2-$a));}
 $a=strpos($ajax,"if (\$mode == 'edit_post_subject')");$b=strpos($ajax,'// Voting/Viewing of polls',$a);ae_check($a!==false&&$b>$a,'Actual edit branches');$branches=substr($ajax,$a,$b-$a);
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('subject','text') as $field){foreach(array('success','failure','ack','denied') as $kind){
  ae_fixture();$board_config['allow_bbcode']=false;$before=ae_snapshot();if($kind==='failure'){$ae_fail='DELETE FROM fixture_search_wordmatch';}if($kind==='ack'){$ae_ack=true;}if($kind==='denied'){ae_sql(ae_revoke('session'));$before=ae_snapshot();}
  $draft=$field==='subject'?"Grüße O'Reilly & 😀":"[b]Grüße[/b] O'Reilly \\ & 😀";$mode='edit_post_'.$field;$HTTP_POST_VARS=array('p'=>10,'subject'=>addslashes($draft),'message'=>addslashes($draft));$HTTP_GET_VARS=array();$response=null;
  try{eval($branches);}catch(AjaxNativeResponse $e){$response=$e->value;}ae_check(is_array($response)&&(($response['result']!==AJAX_ERROR)===($kind==='success')),'Truthful AJAX response '.$locale.'/'.$field.'/'.$kind);
  if($kind==='success'){ae_check($response[$field==='text'?'rawmessage':'rawsubject']===$draft,'Response draft retains Unicode/quotes/slashes/entities/BBCode');}
  if($kind==='failure'||$kind==='denied'){ae_check(ae_snapshot()===$before,'Actual controller rollback');}
  if($kind==='ack'||$kind==='success'){$after=ae_snapshot();$ae_ack=false;$ae_fail='';ae_check(is_array(ae_edit($field,10,$draft))&&ae_snapshot()===$after,'Actual controller retry does not duplicate edit');}
 }}}
 echo 'Native AJAX editing: '.$cases.' failure/authority/storage cases, '.$serialized." serialized changes; title/body/index/parser/retry and actual responses passed.\n";
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
