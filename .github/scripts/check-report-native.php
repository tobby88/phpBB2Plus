<?php
// Actual complete report/reset POST dispatch AFTER shared cached bootstrap,
// canonical native storage, real owning driver. Not HTTP and never live data.
if(PHP_SAPI!=='cli'||getenv('PHPBB_REPORT_NATIVE')!=='1'){echo "Native reports require explicitly enabled disposable storage.\n";return;}
error_reporting(E_ALL);
putenv('PHPBB_CARD_NATIVE=1');putenv('PHPBB_CARD_PORT='.(getenv('PHPBB_REPORT_PORT')?:'3306'));putenv('PHPBB_CARD_PASSWORD='.(getenv('PHPBB_REPORT_PASSWORD')?:''));
$rs_file=__DIR__.'/check-card-native.php';$rs_source=file_get_contents($rs_file);$rs_cut=strpos($rs_source,"\ntry{\n set_error_handler");
if($rs_cut===false){throw new RuntimeException('Card fixture setup boundary');}
$rs_head='';foreach(token_get_all(substr($rs_source,0,$rs_cut))as$t){$rs_head.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($rs_head,5));
define('POSTS_TEXT_TABLE','fixture_posts_text');
require $ats_source.'includes/functions_report_storage.php';
$report_body=substr($controller,$start,$bootstrap-$start).substr($controller,$after+strlen('init_userprefs($userdata);'));
function rs_reset($actor='admin'){
 global $board_config,$userdata;
 card_reset($actor);lp_query('DELETE FROM fixture_posts_text');
 lp_insert('fixture_posts_text',array('post_id'=>30,'post_subject'=>"Synthetic Grüße ' 😀",'post_text'=>'Synthetic post'));
 lp_query('UPDATE fixture_topics SET topic_first_post_id=30 WHERE topic_id=40');lp_query('UPDATE fixture_posts SET post_bluecard=3 WHERE post_id=30');lp_query('UPDATE fixture_forums SET auth_bluecard='.AUTH_MOD.' WHERE forum_id=20');
 foreach(array('bluecard_limit'=>3,'bluecard_limit_2'=>1,'report_forum'=>0)as$key=>$value){lp_insert('fixture_config',array('config_name'=>$key,'config_value'=>$value));$board_config[$key]=$value;}
 if($actor==='guest'){lp_query('UPDATE fixture_users SET user_active=0 WHERE user_id=-1');lp_query("UPDATE fixture_sessions SET session_user_id=-1,session_logged_in=0 WHERE session_id='fixture-actor'");$userdata=array('user_id'=>-1,'session_id'=>'fixture-actor','session_logged_in'=>0,'user_level'=>0);}
}
function rs_run($action,$options=array()){
 global $report_body,$db,$userdata,$user_ip,$lang,$phpbb_root_path,$phpEx,$board_config,$tree;
 $tree=array();$request=array($action.'_x'=>'1','sid'=>'fixture-actor','post_id'=>'30');if(isset($options['request'])){$request=array_merge($request,$options['request']);}
 profile_fixture_request($request);$_SERVER['REQUEST_METHOD']=isset($options['method'])?$options['method']:'POST';
 try{eval($report_body);throw new RuntimeException('Report must terminate');}catch(AttachSettingsExit $e){ats_check($GLOBALS['lp_open']===0&&$db===$GLOBALS['main'],'Report response after owner release, caller unchanged');return $e->getMessage();}
}
function rs_success($message){return strpos($message,$GLOBALS['lang']['Report_saved'])===0||strpos($message,$GLOBALS['lang']['Post_reset'])===0;}
function rs_count(){return (int)lp_rows('SELECT post_bluecard FROM fixture_posts WHERE post_id=30')[0]['post_bluecard'];}
function rs_snapshot(){return array(card_snapshot(),card_rows('SELECT * FROM fixture_posts ORDER BY post_id'),card_rows('SELECT * FROM fixture_posts_text ORDER BY post_id'));}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','sessions_keys','banlist','config','groups','user_group','auth_access','forums','posts','topics','posts_text')as$table){ats_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical report participant');lp_query(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 foreach(array('','STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES')as$mode){$main->sql_query("SET SESSION sql_mode='$mode'");lp_query("SET SESSION sql_mode='$mode'");foreach(array('admin','moderator')as$actor){foreach(array('report','report_reset')as$action){rs_reset($actor);ats_check(rs_success(rs_run($action))&&rs_count()===($action==='report'?4:0),'Complete report/reset '.$mode.'/'.$actor.'/'.$action);$cases++;}}}
 foreach(array(AUTH_ALL,AUTH_REG,AUTH_ACL,AUTH_MOD,AUTH_ADMIN)as$permission){foreach(array('guest','member','moderator','admin')as$actor){
  rs_reset($actor==='member'?'moderator':$actor);if($actor==='member'){lp_query('UPDATE fixture_users SET user_level=0 WHERE user_id=1');lp_query('DELETE FROM fixture_user_group');}
  lp_query('UPDATE fixture_forums SET auth_bluecard='.$permission.' WHERE forum_id=20');$before=rs_snapshot();$allowed=$permission===AUTH_ALL||($actor!=='guest'&&($permission===AUTH_REG||$actor==='admin'||($actor==='moderator'&&$permission!==AUTH_ADMIN)));
  ats_check(rs_success(rs_run('report'))===$allowed,'Current guest/member/group/root report policy');if(!$allowed){ats_check(rs_snapshot()===$before,'Denied report unchanged');}$cases++;
 }}
 foreach(array('report','report_reset')as$action){foreach(array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','missing'=>'DELETE FROM fixture_users WHERE user_id=1','session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-actor'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-actor'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=3 WHERE session_id='fixture-actor'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-actor' WHERE session_id='fixture-actor'",'board'=>"UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'",'post'=>'DELETE FROM fixture_posts WHERE post_id=30','text'=>'DELETE FROM fixture_posts_text WHERE post_id=30','topic'=>'DELETE FROM fixture_topics WHERE topic_id=40','move'=>'UPDATE fixture_topics SET forum_id=21 WHERE topic_id=40','shadow'=>'UPDATE fixture_topics SET topic_moved_id=99 WHERE topic_id=40','read'=>'UPDATE fixture_forums SET auth_read=99 WHERE forum_id=20','view'=>'UPDATE fixture_forums SET auth_view=99 WHERE forum_id=20','ban'=>"INSERT INTO fixture_banlist(ban_userid,ban_ip,ban_email)VALUES(1,'','')",'block'=>'UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=1')as$kind=>$sql){
  rs_reset();lp_query('UPDATE fixture_forums SET auth_bluecard='.AUTH_ADMIN.' WHERE forum_id=20');lp_query('DELETE FROM fixture_user_group');lp_query($sql);$before=rs_snapshot();ats_check(!rs_success(rs_run($action))&&rs_snapshot()===$before,'Revoked current authority/context '.$action.'/'.$kind);$cases++;
 }}
 foreach(array('group'=>'DELETE FROM fixture_groups WHERE group_id=10','member'=>'DELETE FROM fixture_user_group','pending'=>'UPDATE fixture_user_group SET user_pending=1','null-pending'=>'UPDATE fixture_user_group SET user_pending=NULL','acl'=>'DELETE FROM fixture_auth_access')as$kind=>$sql){foreach(array('report','report_reset')as$action){rs_reset('moderator');lp_query($sql);$before=rs_snapshot();ats_check(!rs_success(rs_run($action))&&rs_snapshot()===$before,'Current real membership '.$kind);$cases++;}}
 foreach(array(null,-1,0,126,127)as$count){rs_reset();lp_query('UPDATE fixture_posts SET post_bluecard='.($count===null?'NULL':$count).' WHERE post_id=30');ats_check(rs_success(rs_run('report'))&&rs_count()===min(127,max(0,(int)$count)+1),'Nullable/tinyint bounded increment');ats_check(rs_success(rs_run('report_reset'))&&rs_count()===0,'Bounded reset');$cases+=2;}
 lp_query('ALTER TABLE fixture_posts MODIFY post_bluecard BIGINT UNSIGNED NULL');
 foreach(array('128','32767','2147483647','18446744073709551615')as$count){rs_reset();lp_query('UPDATE fixture_posts SET post_bluecard='.$count.' WHERE post_id=30');ats_check(rs_success(rs_run('report'))&&(string)lp_rows('SELECT post_bluecard FROM fixture_posts WHERE post_id=30')[0]['post_bluecard']===$count,'Custom-widened counters are retained byte-exact, not reduced to bundled limit');ats_check(rs_success(rs_run('report_reset'))&&rs_count()===0,'Custom-widened count can be deliberately reset');$cases+=2;}
 lp_query('ALTER TABLE fixture_posts MODIFY post_bluecard TINYINT NULL');
 foreach(array('report','report_reset')as$action){foreach(array(array('method'=>'GET'),array('request'=>array('sid'=>'other')),array('request'=>array('post_id'=>'0','u'=>'2')),array('request'=>array('post_id'=>array('30'))),array('request'=>array('post_id'=>'16777216')),array('request'=>array('post_id'=>'30x')),array('request'=>array('warn_x'=>'1')),array('request'=>array($action.'_x'=>array('1'))))as$options){rs_reset();$before=rs_snapshot();ats_check(!rs_success(rs_run($action,$options))&&rs_snapshot()===$before,'Invalid report request has no effects');$cases++;}}
 foreach(array('report','report_reset')as$action){
  rs_reset();ats_check(rs_success(rs_run($action)),'Reference report publication');$queries=$lp_query_count;
  for($n=1;$n<=$queries;$n++){rs_reset();$before=rs_snapshot();$lp_failure=$n;ats_check(!rs_success(rs_run($action))&&rs_snapshot()===$before,'Report rollback at every owner query '.$n);$cases++;}
  foreach(array('before','ack')as$failure){rs_reset();$lp_commit_failure=$failure;$before=rs_snapshot();ats_check(!rs_success(rs_run($action)),'Unconfirmed report commit never success');if($failure==='before'){ats_check(rs_snapshot()===$before,'Failed commit rollback');}else{ats_check(rs_count()===($action==='report'?4:0),'Lost acknowledgment preserves actual durable outcome');}$cases++;}
 }
 foreach(array('report','report_reset')as$action){foreach(array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-actor'",'group'=>'DELETE FROM fixture_groups WHERE group_id=10','pending'=>'UPDATE fixture_user_group SET user_pending=1','acl'=>'DELETE FROM fixture_auth_access','policy'=>"UPDATE fixture_config SET config_value='1' WHERE config_name='board_disable'",'post'=>'UPDATE fixture_posts SET post_bluecard=99 WHERE post_id=30','topic'=>'UPDATE fixture_topics SET forum_id=21 WHERE topic_id=40','forum'=>'UPDATE fixture_forums SET auth_read=99 WHERE forum_id=20','ddl'=>'ALTER TABLE fixture_posts ENGINE=MyISAM')as$kind=>$sql){
  rs_reset('moderator');$seen=$blocked=false;$lp_hook=function($query)use($sql,&$seen,&$blocked){if($query!=='COMMIT'||$seen){return;}$seen=true;$GLOBALS['lp_hook']=null;lp_query('SET SESSION innodb_lock_wait_timeout=0');lp_query('SET SESSION lock_wait_timeout=0');try{$r=$GLOBALS['peer']->sql_query($sql);$e=$GLOBALS['peer']->sql_error();ats_check(!$r&&(int)$e['code']===1205,'Real report peer contention');$blocked=true;}finally{lp_query('SET SESSION innodb_lock_wait_timeout=1');lp_query('SET SESSION lock_wait_timeout=1');}};
  ats_check(rs_success(rs_run($action))&&$seen&&$blocked,'Serialized report change '.$kind);$cases++;$serialized++;
 }}
 foreach(array('users','sessions','sessions_keys','banlist','config','groups','user_group','auth_access','forums','posts','topics','posts_text')as$table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1')as$legacy){rs_reset();lp_query('SET SESSION innodb_strict_mode=OFF');try{lp_query('ALTER TABLE fixture_'.$table.' '.$legacy);}finally{lp_query('SET SESSION innodb_strict_mode=ON');}$before=rs_snapshot();ats_check(!rs_success(rs_run('report'))&&rs_snapshot()===$before,'Unmigrated participant refused');lp_query('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}}
 foreach(array('0','1','2147483647')as$value){rs_reset();lp_query("UPDATE fixture_config SET config_value='$value' WHERE config_name IN('bluecard_limit','bluecard_limit_2')");ats_check(rs_success(rs_run('report'))&&rs_count()===4,'Zero and maximum configured notification intervals remain valid');$cases++;}
 foreach(array('bluecard_limit','bluecard_limit_2','report_forum')as$key){foreach(array(null,'-1','bad','2147483648')as$value){rs_reset();if($value===null){lp_query("DELETE FROM fixture_config WHERE config_name='$key'");}else{lp_query("UPDATE fixture_config SET config_value='$value' WHERE config_name='$key'");}$before=rs_snapshot();$message=rs_run('report');ats_check(strpos($message,$lang['Report_storage_failed'])===0&&rs_snapshot()===$before,'Malformed or missing current report policy refuses publication with report-specific reload/update guidance');$cases++;}}
 // A subject collision in a different forum is not an existing report.
 foreach(array('allowed','hidden','foreign','shadow','reply-denied','locked')as$kind){
  rs_reset('moderator');lp_query("UPDATE fixture_config SET config_value='21' WHERE config_name='report_forum'");
  lp_insert('fixture_forums',array('forum_id'=>21,'auth_view'=>AUTH_ALL,'auth_read'=>$kind==='hidden'?AUTH_ADMIN:AUTH_ALL,'auth_post'=>AUTH_REG,'auth_reply'=>$kind==='reply-denied'?AUTH_ADMIN:AUTH_REG));
  lp_insert('fixture_topics',array('topic_id'=>41,'forum_id'=>$kind==='foreign'?22:21,'topic_first_post_id'=>31,'topic_moved_id'=>$kind==='shadow'?40:0,'topic_status'=>$kind==='locked'?1:0));
  lp_insert('fixture_posts',array('post_id'=>31,'poster_id'=>1,'forum_id'=>$kind==='foreign'?22:21,'topic_id'=>41));lp_insert('fixture_posts_text',array('post_id'=>31,'post_subject'=>"(30)Synthetic Grüße ' 😀",'post_text'=>'Synthetic report'));
  $message=rs_run('report');ats_check(rs_success($message)&&rs_count()===4,'Destination does not control source report save');
  if($kind==='hidden'){ats_check(strpos($message,'posting.php?mode=')===false,'Inaccessible report forum has no compose link');}
  else{ats_check(strpos($message,$kind==='allowed'?'reply&t=41':'newtopic&f=21')!==false,'Only a consistent accessible unlocked destination topic can be linked');}$cases++;
 }
 foreach(array('allowed','hidden','read-hidden','banned','email-banned','blocked','inactive','guest-source')as$kind){
  rs_reset($kind==='guest-source'?'guest':'admin');
  lp_query("UPDATE fixture_users SET user_email='recipient@example.invalid' WHERE user_id=3");lp_insert('fixture_user_group',array('user_id'=>3,'group_id'=>10,'user_pending'=>0));
  if($kind==='hidden'){lp_query('UPDATE fixture_forums SET auth_view='.AUTH_ADMIN.' WHERE forum_id=20');}
  if($kind==='read-hidden'){lp_query('UPDATE fixture_forums SET auth_read='.AUTH_ADMIN.' WHERE forum_id=20');}
  if($kind==='banned'){lp_insert('fixture_banlist',array('ban_userid'=>3));}
  if($kind==='email-banned'){lp_insert('fixture_banlist',array('ban_email'=>'*@example.invalid'));}
  if($kind==='blocked'){lp_query('UPDATE fixture_users SET user_blocktime=2147483647 WHERE user_id=3');}
  if($kind==='inactive'){lp_query('UPDATE fixture_users SET user_active=0 WHERE user_id=3');}
  if($kind==='guest-source'){lp_query('UPDATE fixture_forums SET auth_bluecard='.AUTH_ALL.' WHERE forum_id=20');}
  profile_fixture_request(array('report_x'=>'1','sid'=>'fixture-actor','post_id'=>'30'));$_SERVER['REQUEST_METHOD']='POST';$outcome=phpbb_report_post($db,$_POST,$user_ip);
  $ids=array_map(function($row){return (int)$row['user_id'];},$outcome['recipients']);
  ats_check($ids===(in_array($kind,array('allowed','guest-source'),true)?array(3):array()),'Captured recipient has current source access and account authority '.$kind);$cases++;
 }
 // Mail is deliberately disabled, so actual optional transport must fail only
 // after durable publication. Duplicate memberships still select one recipient.
 rs_reset();lp_query("UPDATE fixture_users SET user_email='moderator@example.invalid',user_lang='../../invalid' WHERE user_id=1");
 lp_insert('fixture_groups',array('group_id'=>11));lp_insert('fixture_user_group',array('user_id'=>1,'group_id'=>11,'user_pending'=>0));lp_insert('fixture_auth_access',array('group_id'=>11,'forum_id'=>20,'auth_mod'=>1));
 $seen=false;$lp_after_commit=function()use(&$seen){$seen=true;ats_check(!class_exists('emailer',false),'Mailer not loaded before confirmed report commit');};
 $message=rs_run('report');ats_check($seen&&rs_success($message)&&rs_count()===4&&strpos($message,$lang['Report_notification_failed'])!==false,'Actual disabled transport failure follows durable report');$cases++;
 foreach(array('invalid-address','saturated','below-start','pending-recipient')as$kind){rs_reset();lp_query("UPDATE fixture_users SET user_email='".($kind==='invalid-address'?'invalid':'moderator@example.invalid')."' WHERE user_id=1");if($kind==='saturated'){lp_query('UPDATE fixture_posts SET post_bluecard=127 WHERE post_id=30');}if($kind==='below-start'){lp_query("UPDATE fixture_config SET config_value='10' WHERE config_name='bluecard_limit_2'");}if($kind==='pending-recipient'){lp_query('UPDATE fixture_user_group SET user_pending=1');}
  $message=rs_run('report');ats_check(rs_success($message)&&((strpos($message,$lang['Report_notification_failed'])!==false)===($kind==='invalid-address')),'Notification eligibility/invalid-address handling '.$kind);$cases++;
 }
 lp_query('CREATE TABLE fixture_caller(marker INT PRIMARY KEY)ENGINE=InnoDB ROW_FORMAT=DYNAMIC');rs_reset();$main->sql_query('START TRANSACTION');$lp_fixture_write=true;$main->sql_query('INSERT INTO fixture_caller VALUES(1)');$lp_fixture_write=false;
 ats_check(rs_success(rs_run('report'))&&lp_rows('SELECT * FROM fixture_caller')===array(),'Report owner cannot commit caller transaction');$main->sql_query('ROLLBACK');$cases++;
 rs_reset();$lp_reuse=true;$before=rs_snapshot();ats_check(!rs_success(rs_run('report'))&&rs_snapshot()===$before&&$main->db_connect_id!==null,'Reused factory cannot commit or close caller');$cases++;
 echo 'Native complete report/reset: '.$cases.' boundary/failure cases and '.$serialized." serialized changes passed.\n";
}finally{$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
