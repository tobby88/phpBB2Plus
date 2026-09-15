<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_POST_SUBMIT_NATIVE') !== '1') { echo "Native posting checks require an explicitly enabled disposable database.\n"; return; }
// Reuse only the canonical-schema / owning-connection fixture prelude. No AJAX
// operation is substituted for submit_post(), which is exercised below.
$fixture = file_get_contents(__DIR__ . '/check-ajax-edit-native.php');
$cut = strpos($fixture, " foreach(array('member','moderator','root') as \$actor){");
if ($cut === false) { throw new RuntimeException('Canonical fixture boundary missing'); }
$head = substr($fixture, 5, $cut - 5);
$head = str_replace('__DIR__', var_export(__DIR__, true), $head);
$head = str_replace('codex_ajax_atomic_', 'codex_post_submit_', $head);
$head = str_replace("'search_wordmatch','config');\$ae_columns", "'search_wordmatch','config','vote_desc','vote_results');\$ae_columns", $head);
putenv('PHPBB_AJAX_EDIT_NATIVE=1');
putenv('PHPBB_AJAX_EDIT_PORT=' . (getenv('PHPBB_POST_SUBMIT_PORT') ?: '3306'));
putenv('PHPBB_AJAX_EDIT_PASSWORD=' . (getenv('PHPBB_POST_SUBMIT_PASSWORD') ?: ''));
function board_stats() { $GLOBALS['ps_aftercommit']++; }
function cache_tree($force = false) { $GLOBALS['ps_aftercommit']++; }
function append_sid($url) { return $url; }
function ps_fixture($actor = 'member')
{
 ae_fixture($actor); global $userdata, $board_config, $user_ip, $ctracker_config, $is_auth;
 foreach(array('posts'=>21,'topics'=>101,'vote_desc'=>1) as $suffix=>$next){ae_sql('ALTER TABLE fixture_'.$suffix.' AUTO_INCREMENT='.$next);}
 ae_sql('UPDATE fixture_forums SET count_posts=1, forum_posts=2, forum_topics=1, forum_last_post_id=20, auth_post=1, auth_reply=1, auth_pollcreate=1');
 ae_sql('UPDATE fixture_topics SET topic_replies=1, topic_first_post_id=10, topic_last_post_id=20');
 ae_sql('UPDATE fixture_users SET user_posts=2 WHERE user_id=8');
 if($actor==='guest'){
  ae_insert('fixture_users',array('user_id'=>ANONYMOUS,'user_posts'=>77));
  ae_sql('UPDATE fixture_sessions SET session_user_id=-1, session_logged_in=0');
  ae_sql('UPDATE fixture_forums SET auth_view=0, auth_read=0, auth_post=0, auth_reply=0, auth_pollcreate=0');
  $userdata['user_id']=ANONYMOUS;$userdata['session_logged_in']=false;
 }
 if($actor==='root'){ae_sql('UPDATE fixture_forums SET forum_status=1');}
 $board_config['flood_interval']=0;$is_auth=array('auth_mod'=>true);$user_ip='7f000001';
 $ctracker_config=new stdClass();$ctracker_config->settings=array('spammer_blockmode'=>0,'spam_attack_boost'=>0);
 $GLOBALS['ps_aftercommit']=0;$GLOBALS['ps_exception']='';
}
function ps_snapshot()
{
 $out=ae_snapshot();foreach(array('posts'=>'post_time','topics'=>'topic_time','vote_desc'=>'vote_start') as $table=>$column){foreach($out[$table] as &$row){if((int)$row[$column]>1){$row[$column]='timestamp';}}unset($row);}return $out;
}
function ps_submit($mode, $options = array())
{
 $post_data=array('first_post'=>false,'last_post'=>false,'poster_post'=>true,'poster_id'=>8,'has_poll'=>false,'edit_poll'=>false);
 if(isset($options['post_data'])){$post_data=array_merge($post_data,$options['post_data']);}
 $before_data=$post_data;
 $message=$meta='';$forum_id=3;$topic_id=$mode==='reply'?100:0;$post_id=$poll_id=0;$topic_type=isset($options['type'])?$options['type']:POST_NORMAL;
 $bbcode_on=1;$html_on=$smilies_on=$attach_sig=0;$bbcode_uid='1234567890';$poll_options=!empty($options['poll'])?array(1=>'Grüße 😀',2=>"O'Reilly"):array();$poll_length=1;$topic_desc='Description';$news_category=isset($options['news'])?$options['news']:0;
 try {
  submit_post($mode,$post_data,$message,$meta,$forum_id,$topic_id,$post_id,$poll_id,$topic_type,$bbcode_on,$html_on,$smilies_on,$attach_sig,$bbcode_uid,'',isset($options['subject'])?$options['subject']:addslashes("Quasarword Grüße O'Reilly 😀"),addslashes("[b:1234567890]Quasarword Grüße[/b:1234567890] \\ & 😀"),$poll_options?'Poll':'',$poll_options,$poll_length,$topic_desc,0,0,!empty($options['calendar'])?1234567890:0,0,$news_category);
  return array('post_id'=>$post_id,'topic_id'=>$topic_id,'poll_id'=>$poll_id,'post_data'=>$post_data);
 } catch (RuntimeException $e) {
  $GLOBALS['ps_exception']=$e->getMessage();
  ae_check($post_id===0&&$topic_id===($mode==='reply'?100:0)&&$poll_id===0&&$post_data===$before_data,'Failure restores request IDs and statistics sentinel');
  return 'error';
 }
}
$body = <<<'PHP'
 if(getenv('PHPBB_POST_SUBMIT_TAIL')!=='1'){
 foreach(array('member','moderator','root','guest') as $actor){foreach(array('reply','newtopic') as $mode){
  $options=array('poll'=>$mode==='newtopic');ps_fixture($actor);$before=ps_snapshot();$out=ps_submit($mode,$options);
  ae_check(is_array($out),'Successful actual submission '.$actor.'/'.$mode.' '.$ps_exception.' '.json_encode($ae_error));$after=ps_snapshot();$writes=$ae_write;
  ae_check(count($after['posts'])===3&&count($after['posts_text'])===3&&count($after['topics'])===($mode==='newtopic'?2:1),'Whole parent/text publication');
  $stored=ae_rows('SELECT * FROM fixture_posts_text WHERE post_id='.(int)$out['post_id'])[0];
  ae_check($stored['post_subject']==="Quasarword Grüße O'Reilly 😀"&&strpos($stored['post_text'],'Grüße')!==false&&strpos($stored['post_text'],'\\')!==false,'Prepared UTF8/quotes/slashes stored exactly once');
  ae_check((int)ae_rows('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')[0]['forum_posts']===3&&$ps_aftercommit===2,'Counters and after-commit metadata completed once');
  ae_check((int)ae_rows('SELECT user_posts FROM fixture_users WHERE user_id='.(int)$userdata['user_id'])[0]['user_posts']===($actor==='guest'?77:3),'Member and guest counts');
  ae_check(count(ae_rows('SELECT * FROM fixture_search_wordmatch WHERE post_id='.(int)$out['post_id']))>0,'New post indexed');
  if($mode==='newtopic'){ae_check(count($after['vote_desc'])===1&&count($after['vote_results'])===2,'Whole poll publication');ae_check((int)ae_rows('SELECT topic_type FROM fixture_topics WHERE topic_id='.(int)$out['topic_id'])[0]['topic_type']===POST_NORMAL,'Zero news category does not turn normal topic into news');}
  for($nth=1;$nth<=$writes;$nth++){
   ps_fixture($actor);$before=ps_snapshot();$ae_failwrite=$nth;ae_check(ps_submit($mode,$options)==='error'&&ps_snapshot()===$before&&$ps_aftercommit===0,'Whole rollback at every write '.$actor.'/'.$mode.'/'.$nth);$cases++;
  }
  foreach(array(false,true) as $ack){ps_fixture($actor);$before=ps_snapshot();$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';ae_check(ps_submit($mode,$options)==='error'&&ps_snapshot()===($ack?$after:$before)&&$ps_aftercommit===0,'Failed or lost commit acknowledgement is truthful and atomic');$cases++;}
  ps_fixture($actor);$before=ps_snapshot();$reached=false;$ae_hook=function($sql)use(&$reached){if(strpos($sql,'INSERT INTO fixture_posts_text')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(ps_submit($mode,$options)==='error'&&$reached&&ps_snapshot()===$before,'Real connection loss leaves no orphan post');$cases++;
  foreach(array('session',$actor==='root'?'role':($actor==='moderator'?'grant':'session')) as $kind){foreach(array('INSERT INTO fixture_posts (','COMMIT') as $boundary){
   ps_fixture($actor);$blocked=$reached=false;$independent=null;$ae_hook=function($sql)use($kind,$boundary,&$blocked,&$reached,&$independent){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ae_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Independent change blocked only by owned locks');$blocked=true;}else{$independent=ps_snapshot();}};
   $out=ps_submit($mode,$options);ae_check($reached,'Reached actual publication boundary');if($blocked){ae_check(is_array($out)&&ps_snapshot()===$after,'Revocation serializes with complete publication');$serialized++;}else{ae_check($out==='error'&&ps_snapshot()===$independent,'Effective revocation rolls back complete publication');}$cases++;
  }}
  echo $actor.'/'.$mode." native publication, failure and authority checks passed\n";
 }}
 foreach(array('case','foreign','logout','inactive','session') as $kind){ps_fixture();ae_sql(ae_revoke($kind));$before=ps_snapshot();ae_check(ps_submit('reply')==='error'&&ps_snapshot()===$before,'Exact current session and active account '.$kind);$cases++;}
 foreach(array('auth_post','auth_read','auth_view','auth_sticky','auth_announce','auth_global_announce','auth_news','auth_cal','auth_pollcreate') as $permission){
  ps_fixture();ae_sql('UPDATE fixture_forums SET '.$permission.'=5');$before=ps_snapshot();$types=array('auth_sticky'=>POST_STICKY,'auth_announce'=>POST_ANNOUNCE,'auth_global_announce'=>POST_GLOBAL_ANNOUNCE,'auth_news'=>POST_NEWS);
  $options=array('type'=>isset($types[$permission])?$types[$permission]:POST_NORMAL,'calendar'=>$permission==='auth_cal','poll'=>$permission==='auth_pollcreate');
  ae_check(ps_submit('newtopic',$options)==='error'&&ps_snapshot()===$before,'Exact granular publication permission '.$permission);$cases++;
 }
 foreach($ae_tables as $s){ps_fixture();ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=ps_snapshot();ae_check(ps_submit('newtopic',array('poll'=>true))==='error'&&ps_snapshot()===$before,'Old-format participant refuses publication '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 }
 // Focused tail also runs after source changes that do not affect the already
 // running failure matrix. Default CI always includes both sections.
 foreach(array(str_repeat('&',20),str_repeat('ä',59).'😀extra',"O'Reilly \\ &quot; 😀",str_repeat('"',20)) as $plain){
  ps_fixture();$out=ps_submit('newtopic',array('subject'=>addslashes(htmlspecialchars($plain,ENT_QUOTES,'UTF-8'))));ae_check(is_array($out),'Strict SQL accepts complete prepared title');
  $expected=phpbb_storage_subject($plain);$row=ae_rows('SELECT pt.post_subject,t.topic_title FROM fixture_posts_text pt JOIN fixture_posts p ON p.post_id=pt.post_id JOIN fixture_topics t ON t.topic_id=p.topic_id WHERE p.post_id='.(int)$out['post_id'])[0];
  ae_check($row['post_subject']===$expected&&$row['topic_title']===$expected,'Whole-character/entity title and first-topic title agree');$cases++;
 }
 ps_fixture();$out=ps_submit('newtopic',array('poll'=>true,'post_data'=>array('has_poll'=>true,'_stats_completed'=>'newtopic:21')));ae_check(is_array($out)&&count(ae_rows('SELECT * FROM fixture_vote_desc'))===1&&(int)ae_rows('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')[0]['forum_posts']===3,'Stale private poll/statistics flags cannot suppress new publication');$cases++;
 ps_fixture();ae_sql('UPDATE fixture_forums SET count_posts=0');$out=ps_submit('reply');ae_check(is_array($out)&&(int)ae_rows('SELECT user_posts FROM fixture_users WHERE user_id=8')[0]['user_posts']===2&&(int)ae_rows('SELECT forum_posts FROM fixture_forums WHERE forum_id=3')[0]['forum_posts']===3,'Excluded forum changes public count but not personal count');$cases++;
 echo 'Native posting: '.$cases.' failure/session/permission/storage cases, '.$serialized." serialized changes; no partial content, polls, search or counters.\n";
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head . $body);
