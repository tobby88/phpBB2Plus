<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_REPORT_NOTIFY_NATIVE')!=='1'){echo "Report mail checks require explicitly enabled disposable storage.\n";return;}
putenv('PHPBB_REPORT_NATIVE=1');putenv('PHPBB_REPORT_PORT='.(getenv('PHPBB_REPORT_NOTIFY_PORT')?:'3306'));putenv('PHPBB_REPORT_PASSWORD='.(getenv('PHPBB_REPORT_NOTIFY_PASSWORD')?:''));
$rn_file=__DIR__.'/check-report-native.php';$rn_source=file_get_contents($rn_file);$rn_cut=strpos($rn_source,"\ntry{\n set_error_handler");if($rn_cut===false){throw new RuntimeException('Actual native report setup');}
$rn_head='';foreach(token_get_all(substr($rn_source,0,$rn_cut))as$t){$rn_head.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($rn_head,5));
require_once __DIR__.'/../fixtures/card-report-seed-checks.php';require $ats_source.'includes/functions_report_notifications.php';
$rn_app=$ats_source;$rn_sink=__DIR__.'/../fixtures/report-notifications-native/';$rn_tables=array('users','sessions','sessions_keys','banlist','config','groups','user_group','auth_access','forums','posts','topics','posts_text');
$rn_deliveries=array();$rn_transport=true;$rn_after_transport=null;$rn_cases=$rn_failures=0;
function rn_seed($language='english',$second=false){
 rs_reset();lp_query("UPDATE fixture_users SET user_email='recipient@example.invalid',user_lang='".$language."' WHERE user_id=3");lp_insert('fixture_user_group',array('user_id'=>3,'group_id'=>10,'user_pending'=>0));
 if($second){lp_insert('fixture_users',array('user_id'=>4,'username'=>'Other moderator','user_active'=>1,'user_level'=>0,'user_email'=>'other@example.invalid','user_lang'=>$language));lp_insert('fixture_user_group',array('user_id'=>4,'group_id'=>10,'user_pending'=>0));}
 $GLOBALS['rn_transport']=true;$GLOBALS['rn_after_transport']=null;
}
function rn_prepare($language='english',$second=false){
 rn_seed($language,$second);profile_fixture_request(array('report_x'=>'1','sid'=>'fixture-actor','post_id'=>'30'));$_SERVER['REQUEST_METHOD']='POST';$outcome=phpbb_report_post($GLOBALS['main'],$_POST,$GLOBALS['user_ip']);ats_check($GLOBALS['lp_committed']&&$GLOBALS['lp_open']===0&&$outcome['notify']&&count($outcome['recipients'])===($second?2:1)&&rs_count()===4,'Actual committed report before mail');return$outcome;
}
function rn_dispatch($outcome,$failure=0){
 global$phpbb_root_path,$rn_app,$rn_sink,$rn_deliveries,$lp_owner_queries,$lp_query_count,$lp_failure;
 $rn_deliveries=$lp_owner_queries=array();$lp_query_count=0;$lp_failure=$failure;$phpbb_root_path=$rn_sink;
 try{$ok=phpbb_report_notify($outcome);}finally{$phpbb_root_path=$rn_app;$lp_failure=0;}
 ats_check($GLOBALS['lp_open']===0,'All readiness owners released even after failures');
 foreach($lp_owner_queries as$sql){ats_check(!preg_match('/^\s*(INSERT|UPDATE|DELETE|COMMIT)\b/i',$sql),'Readiness never writes or commits data');}
 return array('ok'=>$ok,'mail'=>$rn_deliveries,'sql'=>$lp_owner_queries);
}
function rn_snapshot(){return card_report_seed_snapshot($GLOBALS['rn_tables']);}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($rn_app.'install/schemas/mysql_schema.sql');foreach($rn_tables as$table){ats_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical report mail participant');lp_query(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 foreach(array('english','german')as$language){$lang=array();require $rn_app.'language/lang_'.$language.'/lang_main.php';
  foreach(array('ordinary','membership','group','pending','moderator','view','read','inactive','blocked','recipient-missing','admin','user-ban','email-ban','address','invalid-address','language','deleted','erased','missing','missing-first-text','shadow','inconsistent','moved','moved-hidden','reset','reviewed-cycle','subject','first-deleted','locked')as$kind){
   $outcome=rn_prepare($language);
   switch($kind){
    case'membership':lp_query('DELETE FROM fixture_user_group WHERE user_id=3');break;
    case'group':lp_query('DELETE FROM fixture_groups WHERE group_id=10');break;
    case'pending':lp_query('UPDATE fixture_user_group SET user_pending=1 WHERE user_id=3');break;
    case'moderator':lp_query('UPDATE fixture_auth_access SET auth_mod=0');break;
    case'view':case'read':lp_query('UPDATE fixture_forums SET auth_'.$kind.'='.AUTH_ADMIN.' WHERE forum_id=20');break;
    case'inactive':lp_query('UPDATE fixture_users SET user_active=0 WHERE user_id=3');break;
    case'blocked':lp_query('UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=3');break;
    case'recipient-missing':lp_query('DELETE FROM fixture_users WHERE user_id=3');break;
    case'admin':lp_query('UPDATE fixture_users SET user_level='.ADMIN.' WHERE user_id=3');lp_query('DELETE FROM fixture_user_group WHERE user_id=3');lp_query('UPDATE fixture_forums SET auth_read='.AUTH_ADMIN.' WHERE forum_id=20');break;
    case'user-ban':lp_insert('fixture_banlist',array('ban_userid'=>3));break;
    case'email-ban':lp_insert('fixture_banlist',array('ban_email'=>'*@example.invalid'));break;
    case'address':lp_query("UPDATE fixture_users SET user_email='current@example.invalid' WHERE user_id=3");break;
    case'invalid-address':lp_query("UPDATE fixture_users SET user_email='invalid' WHERE user_id=3");break;
    case'language':lp_query("UPDATE fixture_users SET user_lang='".($language==='english'?'german':'english')."' WHERE user_id=3");break;
    case'deleted':case'erased':lp_query("UPDATE fixture_posts SET post_deleted=1,poster_id=-1,post_bluecard=0,post_username='' WHERE post_id=30");if($kind==='erased'){lp_query("UPDATE fixture_posts_text SET post_subject='',post_text='',bbcode_uid='' WHERE post_id=30");}break;
    case'missing':lp_query('DELETE FROM fixture_posts WHERE post_id=30');break;
    case'missing-first-text':lp_query('DELETE FROM fixture_posts_text WHERE post_id=30');break;
    case'shadow':lp_query('UPDATE fixture_topics SET topic_moved_id=99 WHERE topic_id=40');break;
    case'inconsistent':lp_query('UPDATE fixture_topics SET forum_id=21 WHERE topic_id=40');break;
    case'moved':case'moved-hidden':lp_insert('fixture_forums',array('forum_id'=>21,'forum_name'=>'Current forum','auth_view'=>$kind==='moved'?AUTH_ALL:AUTH_ADMIN,'auth_read'=>$kind==='moved'?AUTH_ALL:AUTH_ADMIN));lp_query('UPDATE fixture_posts SET forum_id=21 WHERE post_id=30');lp_query('UPDATE fixture_topics SET forum_id=21 WHERE topic_id=40');lp_insert('fixture_auth_access',array('group_id'=>10,'forum_id'=>21,'auth_mod'=>1));break;
    case'reset':case'reviewed-cycle':lp_query('UPDATE fixture_posts SET post_bluecard='.($kind==='reset'?0:1).' WHERE post_id=30');break;
    case'subject':lp_query("UPDATE fixture_posts_text SET post_subject='Current Grüße 😀' WHERE post_id=30");break;
    case'first-deleted':lp_query("UPDATE fixture_topics SET topic_first_post_id=31,topic_title='Remaining Grüße 😀' WHERE topic_id=40");lp_insert('fixture_posts',array('post_id'=>31,'poster_id'=>-1,'forum_id'=>20,'topic_id'=>40,'post_deleted'=>1));lp_insert('fixture_posts_text',array('post_id'=>31,'post_subject'=>'DELETED_SUBJECT_MUST_NOT_LEAK','post_text'=>'DELETED_BODY_MUST_NOT_LEAK'));break;
    case'locked':lp_query('UPDATE fixture_topics SET topic_status=1 WHERE topic_id=40');break;
   }
   $allowed=in_array($kind,array('ordinary','admin','address','language','moved','subject','first-deleted','locked'),true);$before=rn_snapshot();$result=rn_dispatch($outcome);
   ats_check($result['ok']===($kind!=='invalid-address')&&count($result['mail'])===($allowed?1:0)&&rn_snapshot()===$before,'Current report mail '.$language.'/'.$kind.' with unchanged canonical data');
   if($allowed){$mail=$result['mail'][0];$expected_language=$kind==='language'?($language==='english'?'german':'english'):$language;ats_check(strpos($mail['subject'],$expected_language==='german'?'Beanstandung':'report')!==false,'Actual current EN/DE mail template subject');ats_check($mail['to']===($kind==='address'?'current@example.invalid':'recipient@example.invalid'),'Current recipient address');ats_check(strpos($mail['vars']['POST_URL'],'viewtopic.php?p=30#30')!==false,'Actual board URL');if($kind==='subject'||$kind==='first-deleted'){ats_check($mail['vars']['POST_SUBJECT']===($kind==='subject'?'Current Grüße 😀':'Remaining Grüße 😀'),'Current canonical source subject');}if($kind==='moved'){ats_check($mail['vars']['FORUM_NAME']==='Current forum','Current source forum name');}ats_check(strpos($mail['body'],'MUST_NOT_LEAK')===false,'No retained deleted content in actual mail body');}$rn_cases++;
  }
  $outcome=rn_prepare($language);$rn_transport=false;$before=rn_snapshot();$result=rn_dispatch($outcome);ats_check(!$result['ok']&&count($result['mail'])===1&&rs_count()===4&&rn_snapshot()===$before,'Transport failure preserves committed report and data');$rn_cases++;
  foreach(array('revoke','address')as$change){$outcome=rn_prepare($language,true);$after=null;$rn_after_transport=function()use($change,&$after){$GLOBALS['rn_after_transport']=null;lp_query($change==='revoke'?'DELETE FROM fixture_user_group WHERE user_id=4':"UPDATE fixture_users SET user_email='changed-other@example.invalid' WHERE user_id=4");$after=rn_snapshot();};$result=rn_dispatch($outcome);ats_check($result['ok']&&count($result['mail'])===($change==='revoke'?1:2)&&rn_snapshot()===$after,'Each recipient rechecked after preceding actual transport');if($change==='address'){ats_check($result['mail'][1]['to']==='changed-other@example.invalid','Second current recipient address');}$rn_cases++;}
  $outcome=rn_prepare($language);$main->sql_query('START TRANSACTION');$r=$main->sql_query('SELECT user_email FROM fixture_users WHERE user_id=3');$main->sql_fetchrow($r);$main->sql_freeresult($r);lp_query("UPDATE fixture_users SET user_email='fresh@example.invalid' WHERE user_id=3");
  try{$before=rn_snapshot();$result=rn_dispatch($outcome);$r=$main->sql_query('SELECT user_email FROM fixture_users WHERE user_id=3');$old=$main->sql_fetchrow($r);$main->sql_freeresult($r);ats_check($result['ok']&&$result['mail'][0]['to']==='fresh@example.invalid'&&$old['user_email']==='recipient@example.invalid'&&rn_snapshot()===$before,'Fresh mail owner ignores caller snapshot and leaves it open');$rn_cases++;}finally{$main->sql_query('ROLLBACK');}
  foreach(array('ordinary','delete-after-commit')as$kind){rn_seed($language);$seen=false;$lp_after_commit=function()use($kind,&$seen){$seen=true;if($kind==='delete-after-commit'){lp_query("UPDATE fixture_posts SET post_deleted=1,poster_id=-1,post_bluecard=0,post_username='' WHERE post_id=30");lp_query("UPDATE fixture_posts_text SET post_subject='',post_text='',bbcode_uid='' WHERE post_id=30");}};$rn_deliveries=array();$phpbb_root_path=$rn_sink;try{$message=rs_run('report');}finally{$phpbb_root_path=$rn_app;$lp_after_commit=null;}ats_check($seen&&rs_success($message)&&count($rn_deliveries)===($kind==='ordinary'?1:0),'Complete actual card.php report dispatch after confirmed commit');$rn_cases++;}
  $outcome=rn_prepare($language);$reference=rn_dispatch($outcome);$positions=array();$index=0;foreach($reference['sql']as$sql){if($sql==='ROLLBACK'){continue;}$index++;if(($index===2)||preg_match('/^SELECT\b/i',$sql)&&stripos($sql,'information_schema.')===false&&stripos($sql,'LIMIT 0')===false&&stripos($sql,'GET_LOCK(')===false&&stripos($sql,'RELEASE_LOCK(')===false){$positions[]=$index;}}
  foreach($positions as$position){$before=rn_snapshot();$result=rn_dispatch($outcome,$position);ats_check(!$result['ok']&&count($result['mail'])===0&&rn_snapshot()===$before&&rs_count()===4,'Readiness SQL failure closes owner and preserves committed report');$rn_failures++;}
 }
 echo 'Report notifications native: '.$rn_cases.' cases, '.$rn_failures." SQL failures PASS; actual committed report/controller, real EN/DE mailer/template sinks, current recipients/context, caller isolation, released locks and durable counters.\n";
}finally{$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
