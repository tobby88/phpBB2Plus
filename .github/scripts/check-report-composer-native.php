<?php
// Actual posting form fragment, real current auth/driver and canonical owned
// tables. No HTTP session/account and no real mail or live data are used.
if(PHP_SAPI!=='cli'||getenv('PHPBB_REPORT_COMPOSER_NATIVE')!=='1'){echo "Report composers require explicitly enabled disposable storage.\n";return;}
putenv('PHPBB_REPORT_NATIVE=1');putenv('PHPBB_REPORT_PORT='.(getenv('PHPBB_REPORT_COMPOSER_PORT')?:'3306'));putenv('PHPBB_REPORT_PASSWORD='.(getenv('PHPBB_REPORT_COMPOSER_PASSWORD')?:''));
$rc_file=__DIR__.'/check-report-native.php';$rc_source=file_get_contents($rc_file);$rc_cut=strpos($rc_source,"\ntry{\n set_error_handler");
if($rc_cut===false){throw new RuntimeException('Native report setup boundary');}
$rc_head='';foreach(token_get_all(substr($rc_source,0,$rc_cut))as$t){$rc_head.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($rc_head,5));
require $ats_source.'includes/auth.php';ats_load_function($ats_source.'attach_mod/includes/functions_includes.php','attach_setup_basic_auth');
$rc_posting=file_get_contents($ats_source.'posting.php');$rc_start=strpos($rc_posting,"if (\$postreport)\n{");$rc_end=strpos($rc_posting,"\n\tif ( \$mode == 'newtopic' )",$rc_start);
ats_check($rc_start!==false&&$rc_end>$rc_start,'Actual full report form subject dispatch');$rc_body=substr($rc_posting,$rc_start,$rc_end-$rc_start);
$rc_tables=array('users','sessions','sessions_keys','banlist','config','groups','user_group','auth_access','forums','posts','topics','posts_text');
class ReportComposerReadDatabase {
 var $db;var $queries=array();var $failure;
 function __construct($db,$failure){$this->db=$db;$this->failure=$failure;}
 function __call($method,$args){return call_user_func_array(array($this->db,$method),$args);}
 function sql_query($sql,$transaction=false){$this->queries[]=$sql;ats_check(preg_match('/^SELECT\b/i',ltrim($sql))===1,'Composer must never issue writes or transaction commands');if(count($this->queries)===$this->failure){return false;}return $this->db->sql_query($sql,$transaction);}
}
function rc_reset($actor='admin'){
 card_report_seed(function()use($actor){rc_reset_rows($actor);});
}
function rc_reset_rows($actor='admin'){
 // Only the owned fixture peer seeds data in a transaction. The real form
 // reader never owns, commits, rolls back or changes any transaction.
 rs_reset_rows($actor==='member'?'moderator':$actor);if($actor==='member'){lp_query('UPDATE fixture_users SET user_level=0 WHERE user_id=1');lp_query('DELETE FROM fixture_user_group');}lp_query('UPDATE fixture_forums SET auth_bluecard='.($actor==='guest'?AUTH_ALL:AUTH_REG).' WHERE forum_id=20');lp_insert('fixture_forums',array('forum_id'=>21,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_ALL,'auth_post'=>AUTH_ALL));
 $GLOBALS['tree']=array('keys'=>array('f20'=>0),'data'=>array(array('forum_id'=>20,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_ALL,'auth_bluecard'=>AUTH_ALL)));
}
function rc_snapshot(){ $out=array();foreach($GLOBALS['rc_tables']as$table){$rows=card_rows('SELECT * FROM fixture_'.$table);usort($rows,function($a,$b){return strcmp(serialize($a),serialize($b));});$out[$table]=$rows;}return$out; }
function rc_run($id=30,$failure=0){
 global $rc_body,$db,$main,$userdata,$phpbb_root_path,$phpEx,$lang,$subject,$lock_subject,$postreport;
 $postreport=$id;$subject=$lock_subject=null;$reader=new ReportComposerReadDatabase($main,$failure);$db=$reader;
 try{eval($rc_body);$result=array('ok'=>true,'subject'=>$subject,'locked'=>$lock_subject);}catch(AttachSettingsExit$e){$result=array('ok'=>false,'message'=>$e->getMessage());}finally{$db=$main;}
 ats_check($GLOBALS['lp_open']===0,'Read-only composer opens no mutation owner');foreach($reader->queries as$sql){ats_check(preg_match('/^SELECT\b/i',ltrim($sql))===1,'Every actual reader/auth query is read-only');}$result['queries']=$reader->queries;return$result;
}
$rc_cases=$rc_failure_cases=0;
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach($rc_tables as$table){ats_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical report composer participant');lp_query(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 foreach(array('english','german')as$language){$lang=array();require $ats_source.'language/lang_'.$language.'/lang_main.php';foreach(array('','STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES')as$mode){$main->sql_query("SET SESSION sql_mode='$mode'");lp_query("SET SESSION sql_mode='$mode'");
  foreach(array('admin','moderator','member','guest')as$actor){rc_reset($actor);$before=rc_snapshot();$result=rc_run();ats_check($result['ok']&&$result['subject']==="(30)Synthetic Grüße ' 😀"&&$result['locked']===30&&rc_snapshot()===$before,'Ordinary source '.$language.'/'.$mode.'/'.$actor.' and exact unchanged data');$rc_cases++;}
  foreach(array('canonical','deleted-erased','deleted-retained')as$kind){
   rc_reset();lp_query("UPDATE fixture_topics SET topic_first_post_id=31,topic_title='Remaining Grüße 😀' WHERE topic_id=40");lp_query('UPDATE fixture_posts SET post_time=1 WHERE post_id=30');
   lp_insert('fixture_posts',array('post_id'=>31,'poster_id'=>$kind==='canonical'?2:-1,'forum_id'=>20,'topic_id'=>40,'post_time'=>99,'post_deleted'=>$kind==='canonical'?0:1));lp_insert('fixture_posts_text',array('post_id'=>31,'post_subject'=>$kind==='canonical'?'Canonical Grüße &amp; 😀':($kind==='deleted-erased'?'':'DELETED_SUBJECT_MUST_NOT_LEAK'),'post_text'=>$kind==='deleted-retained'?'DELETED_BODY_MUST_NOT_LEAK':''));
   $before=rc_snapshot();$result=rc_run();ats_check($result['ok']&&$result['subject']==='(30)'.($kind==='canonical'?'Canonical Grüße &amp; 😀':'Remaining Grüße 😀')&&rc_snapshot()===$before,'Canonical first post/remaining title, not time order or deleted contents '.$kind);$rc_cases++;
  }
  foreach(array('deleted-erased','deleted-retained','post','topic','forum','first','text','topic-forum','first-topic','first-forum','shadow')as$kind){
   rc_reset();
   if(strpos($kind,'deleted-')===0){lp_query('UPDATE fixture_posts SET post_deleted=1,poster_id=-1 WHERE post_id=30');if($kind==='deleted-erased'){lp_query("UPDATE fixture_posts_text SET post_subject='',post_text='' WHERE post_id=30");}}
   elseif($kind==='post'){lp_query('DELETE FROM fixture_posts WHERE post_id=30');}elseif($kind==='topic'){lp_query('DELETE FROM fixture_topics WHERE topic_id=40');}elseif($kind==='forum'){lp_query('DELETE FROM fixture_forums WHERE forum_id=20');}elseif($kind==='first'){lp_query('UPDATE fixture_topics SET topic_first_post_id=99 WHERE topic_id=40');}elseif($kind==='text'){lp_query('DELETE FROM fixture_posts_text WHERE post_id=30');}elseif($kind==='topic-forum'){lp_query('UPDATE fixture_topics SET forum_id=21 WHERE topic_id=40');}elseif($kind==='shadow'){lp_query('UPDATE fixture_topics SET topic_moved_id=99 WHERE topic_id=40');}
   else{lp_query('UPDATE fixture_topics SET topic_first_post_id=31 WHERE topic_id=40');lp_insert('fixture_posts',array('post_id'=>31,'poster_id'=>2,'topic_id'=>$kind==='first-topic'?41:40,'forum_id'=>$kind==='first-forum'?21:20));lp_insert('fixture_posts_text',array('post_id'=>31,'post_subject'=>'FOREIGN_SUBJECT_MUST_NOT_LEAK'));}
   $before=rc_snapshot();$result=rc_run();ats_check(!$result['ok']&&strpos($result['message'],$lang['No_such_post'])===0&&rc_snapshot()===$before,'Missing/deleted/inconsistent source is controlled and unchanged '.$kind);$rc_cases++;
  }
  foreach(array('view','read','bluecard','role','membership','pending','session','session-case','session-foreign','logout','inactive','blocked','missing-actor')as$kind){
   rc_reset('moderator');
   if(in_array($kind,array('view','read','bluecard'),true)){lp_query('UPDATE fixture_forums SET auth_'.$kind.'='.AUTH_ADMIN.' WHERE forum_id=20');}
   elseif($kind==='role'){lp_query('UPDATE fixture_users SET user_level=0 WHERE user_id=1');lp_query('UPDATE fixture_forums SET auth_read='.AUTH_ADMIN.' WHERE forum_id=20');}
   elseif(in_array($kind,array('membership','pending'),true)){lp_query('UPDATE fixture_forums SET auth_bluecard='.AUTH_MOD.' WHERE forum_id=20');lp_query($kind==='membership'?'DELETE FROM fixture_user_group':'UPDATE fixture_user_group SET user_pending=1');}
   elseif($kind==='session'){lp_query("DELETE FROM fixture_sessions WHERE session_id='fixture-actor'");}elseif($kind==='session-case'){lp_query("UPDATE fixture_sessions SET session_id='FIXTURE-actor' WHERE session_id='fixture-actor'");}elseif($kind==='session-foreign'){lp_query("UPDATE fixture_sessions SET session_user_id=3 WHERE session_id='fixture-actor'");}elseif($kind==='logout'){lp_query("UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-actor'");}elseif($kind==='inactive'){lp_query('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}elseif($kind==='blocked'){lp_query('UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=1');}else{lp_query('DELETE FROM fixture_users WHERE user_id=1');}
   // A real current destination grant never permits the denied source title.
   $destination_actor=array('user_id'=>1,'user_level'=>0,'session_logged_in'=>1);$destination=auth(AUTH_ALL,21,$destination_actor,'',$main);ats_check(!empty($destination['auth_post']),'Destination independently permits composing');
   $before=rc_snapshot();$result=rc_run();ats_check(!$result['ok']&&strpos($result['message'],'Synthetic')===false&&rc_snapshot()===$before,'Fresh source/session authority beats cached root/tree and allowed destination '.$kind);$rc_cases++;
  }
  rc_reset();$reference=rc_run();ats_check($reference['ok'],'Reference full native composer');$query_count=count($reference['queries']);
  for($failure=1;$failure<=$query_count;$failure++){$before=rc_snapshot();$result=rc_run(30,$failure);ats_check(!$result['ok']&&rc_snapshot()===$before&&strpos($result['message'],'SELECT ')===false,'Every actual reader/auth SQL failure is controlled without data changes');$rc_failure_cases++;}
 }}
 echo 'Report composer native: '.$rc_cases.' cases, '.$rc_failure_cases." SQL failures PASS; actual posting fragment/current auth, EN/DE, canonical InnoDB, all SQL modes, independent source access, current sessions, deleted/missing/canonical first posts, unchanged data and read-only queries.\n";
 require_once __DIR__.'/../fixtures/card-report-seed-checks.php';
 card_report_seed_checks('report composer',array('admin','moderator','member','guest'),$rc_tables,'rc_reset_rows','rc_reset','INSERT INTO fixture_posts_text (');
}finally{$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
