<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_TOPIC_REMOVAL_NATIVE')!=='1'){echo "Native topic/forum removal checks require an explicitly enabled disposable database.\n";return;}
date_default_timezone_set('UTC');
$source=file_get_contents(__DIR__.'/check-post-delete-native.php');$cut=strpos($source,"\$body= <<<'PHP'");if($cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
putenv('PHPBB_POST_DELETE_NATIVE=1');putenv('PHPBB_POST_DELETE_PORT='.(getenv('PHPBB_TOPIC_REMOVAL_PORT')?:'3306'));putenv('PHPBB_POST_DELETE_PASSWORD='.(getenv('PHPBB_TOPIC_REMOVAL_PASSWORD')?:''));eval(substr($source,5,$cut-5));
$head=str_replace('codex_post_delete_','codex_topic_removal_',$head);$head=str_replace("'topic_view','privmsgs');\$ae_columns","'topic_view','privmsgs','forum_prune','jr_admin_users','categories');\$ae_columns",$head);
function tr_fixture($policy,$actor='root')
{
 pd_fixture($actor==='moderator'?'moderator':'root');$GLOBALS['br_policy']=$policy;$GLOBALS['br_exception']='';
 ae_sql("UPDATE fixture_forums SET forum_link='',main_type='c',cat_id=1,prune_enable=1,prune_next=0");
 ae_insert('fixture_categories',array('cat_id'=>1,'cat_main_type'=>'c','cat_main'=>0));
 ae_insert('fixture_forums',array('forum_id'=>4,'forum_name'=>'Other forum','forum_link'=>'','count_posts'=>0,'main_type'=>'c','cat_id'=>1));
 foreach(array(200,201,202,203,204,900) as $id){ae_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>$id===900?4:3,'topic_type'=>$id===201?POST_ANNOUNCE:POST_NORMAL,'topic_vote'=>$id===202?1:0));if($id!==204){ae_insert('fixture_posts',array('post_id'=>$id,'topic_id'=>$id,'forum_id'=>$id===900?4:3,'poster_id'=>9,'post_time'=>$id===203?time():1));ae_insert('fixture_posts_text',array('post_id'=>$id,'post_subject'=>'Preserve by scope','post_text'=>'Fixture content'));}foreach(array('topics_watch','bookmarks','topic_view') as $table){ae_insert('fixture_'.$table,array('topic_id'=>$id,'user_id'=>9));}}
 ae_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>202,'vote_text'=>'Protected poll'));ae_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>1,'vote_option_text'=>'Keep','vote_result'=>5));ae_insert('fixture_vote_voters',array('vote_id'=>1,'vote_user_id'=>9));
 ae_insert('fixture_config',array('config_name'=>'prune_enable','config_value'=>'1'));ae_insert('fixture_forum_prune',array('prune_id'=>1,'forum_id'=>3,'prune_days'=>7,'prune_freq'=>1));
 ae_sql('UPDATE fixture_users SET user_posts=(SELECT COUNT(*) FROM fixture_posts p JOIN fixture_forums f ON f.forum_id=p.forum_id WHERE p.poster_id=fixture_users.user_id AND f.count_posts=1) WHERE user_id>0');
 ae_sql('UPDATE fixture_forums SET forum_posts=(SELECT COUNT(*) FROM fixture_posts p WHERE p.forum_id=fixture_forums.forum_id),forum_topics=(SELECT COUNT(*) FROM fixture_topics t WHERE t.forum_id=fixture_forums.forum_id),forum_last_post_id=(SELECT COALESCE(MAX(post_id),0) FROM fixture_posts p WHERE p.forum_id=fixture_forums.forum_id)');
 if($actor==='delegate'){ae_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=8');$module=$policy==='prune_admin'?md5('ForumsPruneadmin_forum_prune.php'):md5('ForumsManageadmin_forums.php');ae_insert('fixture_jr_admin_users',array('user_id'=>8,'user_jr_admin'=>$module));}
 $admin=in_array($policy,array('prune_admin','forum_purge','forum_move'),true);$GLOBALS['userdata']['session_admin']=$admin;ae_sql('UPDATE fixture_sessions SET session_admin='.($admin?1:0));
 $_SERVER['REQUEST_METHOD']=$policy==='prune_auto'?'GET':'POST';$_POST=$policy==='prune_auto'?array():array('sid'=>'edit-sid');
}
function tr_snapshot()
{
 $out=pd_snapshot();foreach($out['forums'] as &$row){if((int)$row['prune_next']>1){$row['prune_next']='scheduled';}}unset($row);return $out;
}
function tr_submit($policy,$controller=false)
{
 global $db,$phpbb_root_path,$phpEx,$userdata;
 $GLOBALS['tr_scheduled']=false;
 try{
  if($policy==='moderator'){
   if($controller){$text=file_get_contents($phpbb_root_path.'modcp.php');$start=strpos($text,'$removed = phpbb_delete_moderated_topics(');$end=strpos($text,"\n\n\t\t\tif ( !empty(\$topic_id)",$start);ae_check($start!==false&&$end>$start,'Actual modcp storage/audit branch');$topics=array(100,200);$forum_id=3;$lang=$GLOBALS['lang'];eval(substr($text,$start,$end-$start));return $removed;}
   return phpbb_delete_moderated_topics($db,3,array(100,200));
  }
  if($policy==='forum_move'||$policy==='forum_purge'){
   if($controller){$text=file_get_contents($phpbb_root_path.'admin/admin_forums.php');$start=strpos($text,"case 'movedelforum':")+strlen("case 'movedelforum':");$end=strpos($text,'// The storage worker has released',$start);ae_check($end>$start,'Actual forum-removal controller');$_POST['from_id']='3';$_POST['to_id']=$policy==='forum_move'?'f4':'-1';eval(substr($text,$start,$end-$start));return $removal_result;}
   return phpbb_remove_forum($db,3,$policy==='forum_move'?4:null);
  }
  return phpbb_prune_forum($db,3,$policy==='prune_auto'?null:100,$GLOBALS['tr_scheduled']);
 }catch(RuntimeException $e){$GLOBALS['br_exception']=$e->getMessage();return 'error';}
}
function tr_revoke($policy,$actor)
{
 if($actor==='delegate'){return 'DELETE FROM fixture_jr_admin_users WHERE user_id=8';}
 return $actor==='moderator'?'DELETE FROM fixture_auth_access WHERE forum_id=3':'UPDATE fixture_users SET user_level=0 WHERE user_id=8';
}
class PruneNativeTemplate {function assign_block_vars($block,$values){} function assign_vars($values){}}
function get_object_lang($id,$field){return 'Fixture forum';}
$body= <<<'PHP'
 require $phpbb_root_path.'attach_mod/includes/functions_attach.php';require $phpbb_root_path.'attach_mod/includes/functions_admin.php';require $phpbb_root_path.'attach_mod/includes/functions_shadow.php';require $phpbb_root_path.'includes/functions_forum_maintenance.php';
 $upload_dir=sys_get_temp_dir().'/phpbb-topic-removal-owned-'.bin2hex(phpbb_random_bytes(8));$attach_config=array('allow_ftp_upload'=>'0');ae_check(mkdir($upload_dir,0700)&&mkdir($upload_dir.'/'.THUMB_DIR,0700),'Owned directories');
 try{
 $matrix=array(array('moderator','root'),array('moderator','moderator'),array('prune_auto','root'),array('prune_auto','moderator'),array('prune_admin','root'),array('prune_admin','delegate'),array('forum_purge','root'),array('forum_purge','delegate'),array('forum_move','root'),array('forum_move','delegate'));
 if(getenv('PHPBB_TOPIC_REMOVAL_TAIL')!=='1' && getenv('PHPBB_TOPIC_REMOVAL_EXTRA')!=='1'){
 foreach($matrix as $entry){list($policy,$actor)=$entry;tr_fixture($policy,$actor);$before=tr_snapshot();$out=tr_submit($policy);ae_check(is_array($out),'Success '.$policy.'/'.$actor.' '.$br_exception.' '.json_encode($ae_error));$after=tr_snapshot();$queries=$ae_queries;
  ae_check(empty($out['cleanup_pending'])&&pd_files()===($policy==='forum_move'?array(true,true):array(false,false)),'Confirmed physical cleanup only after deleting content');
  $expected_topics=$policy==='forum_move'?7:($policy==='forum_purge'?1:5);ae_check(count($after['topics'])===$expected_topics,'Correct topic selection '.$policy);ae_check(count($after['logs'])===($policy==='forum_move'?0:($policy==='forum_purge'?6:2)),'Atomic audit for actual removals');
  if($policy==='forum_move'){foreach(array('posts_text','attachments','attachments_desc','vote_desc','vote_results','vote_voters','topics_watch','bookmarks','topic_view') as $table){ae_check($before[$table]===$after[$table],'Move preserves '.$table);}}
  if(getenv('PHPBB_TOPIC_REMOVAL_SMOKE')==='1'){echo $policy.'/'.$actor." native complete operation passed\n";continue;}
  $commit=array_search('COMMIT',$queries,true);ae_check($commit!==false,'Explicit transaction commit');$writes=0;foreach(array_slice($queries,0,$commit) as $sql){if(preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)){$writes++;}}
  for($nth=1;$nth<=$writes;$nth++){tr_fixture($policy,$actor);$before=tr_snapshot();$ae_failwrite=$nth;ae_check(tr_submit($policy)==='error'&&tr_snapshot()===$before&&pd_files()===array(true,true)&&!$tr_scheduled,'Every core write rolls back selection/counters/schedule/audit '.$policy.'/'.$actor.'/'.$nth);$cases++;}
  foreach(array(false,true) as $ack){tr_fixture($policy,$actor);$before=tr_snapshot();$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';$expected=$ack?$after:$before;if($ack&&$policy!=='forum_move'){$expected['attachments_desc']=$before['attachments_desc'];}ae_check(tr_submit($policy)==='error'&&tr_snapshot()===$expected&&pd_files()===array(true,true)&&!$tr_scheduled,'Failed/lost COMMIT never unlinks files or confirms scheduling');$cases++;}
  $write_boundary=$policy==='forum_move'?'UPDATE fixture_topics t LEFT JOIN':'DELETE FROM fixture_topics';
  foreach(array(ae_revoke('session'),tr_revoke($policy,$actor)) as $revoke){foreach(array($write_boundary,'COMMIT') as $boundary){tr_fixture($policy,$actor);$reached=$blocked=false;$independent=null;$ae_hook=function($sql)use($revoke,$boundary,&$reached,&$blocked,&$independent){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($revoke)){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Independent revoke blocked only by DB row lock');$blocked=true;}else{$independent=tr_snapshot();}};$out=tr_submit($policy);ae_check($reached,'Actual authority boundary');if($blocked){ae_check(is_array($out)&&tr_snapshot()===$after,'Authority change serializes with full transaction');$serialized++;}else{ae_check($out==='error'&&tr_snapshot()===$independent&&pd_files()===array(true,true),'Effective authority loss rolls back every change');}$cases++;}}
  tr_fixture($policy,$actor);$before=tr_snapshot();$reached=false;$kill=$policy==='forum_move'?'DELETE f FROM':'DELETE FROM fixture_search_wordmatch';$ae_hook=function($sql)use($kill,&$reached){if(strpos($sql,$kill)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(tr_submit($policy)==='error'&&$reached&&tr_snapshot()===$before&&pd_files()===array(true,true),'Actual disconnect rolls back content and preserves bytes');$cases++;
  echo $policy.'/'.$actor." native transaction/failure/authority checks passed\n";
 }
 }
 if(getenv('PHPBB_TOPIC_REMOVAL_SMOKE')!=='1' && getenv('PHPBB_TOPIC_REMOVAL_EXTRA')!=='1'){
 foreach(array('moderator','prune_auto','prune_admin','forum_purge','forum_move') as $policy){foreach(array('session','case','foreign','logout','inactive') as $kind){tr_fixture($policy);ae_sql(ae_revoke($kind));$before=tr_snapshot();ae_check(tr_submit($policy)==='error'&&tr_snapshot()===$before&&pd_files()===array(true,true),'Current exact session/account '.$policy.'/'.$kind);$cases++;}}
 foreach(array('prune_admin','forum_purge','forum_move') as $policy){tr_fixture($policy);ae_sql('UPDATE fixture_sessions SET session_admin=0');$before=tr_snapshot();ae_check(tr_submit($policy)==='error'&&tr_snapshot()===$before,'Expired ACP authentication refuses administrative mutation');$cases++;}
 foreach(array('moderator','prune_admin','forum_purge','forum_move') as $policy){tr_fixture($policy);$_POST['sid']='wrong';$before=tr_snapshot();ae_check(tr_submit($policy)==='error'&&tr_snapshot()===$before,'Wrong request session never enters mutation');$cases++;}
 foreach(array('moderator','prune_auto','prune_admin','forum_purge') as $policy){
  tr_fixture($policy);$reached=false;$ae_hook=function($sql)use(&$reached){if($sql!=='COMMIT'){return;}$GLOBALS['ae_hook']=null;$reached=true;$path=$GLOBALS['upload_dir'].'/owned.txt';unlink($path);mkdir($path,0700);};$out=tr_submit($policy);ae_check($reached&&is_array($out)&&!empty($out['cleanup_pending'])&&count(ae_rows('SELECT * FROM fixture_attachments_desc'))===1,'Completed core distinguishes pending file cleanup '.$policy);rmdir($upload_dir.'/owned.txt');file_put_contents($upload_dir.'/owned.txt','owned repaired storage');attach_shadow_cleanup(array(),array(1));ae_check(!ae_rows('SELECT * FROM fixture_attachments_desc')&&pd_files()===array(false,false),'ACP recovery completes retained bulk files');$cases++;
 }
 foreach(array('moderator','prune_admin','forum_purge') as $policy){tr_fixture($policy);ae_insert('fixture_attachments',array('attach_id'=>1,'post_id'=>900));ae_check(is_array(tr_submit($policy))&&pd_files()===array(true,true),'Other forum shared attachment survives');$cases++;}
 foreach(array('moderator','prune_admin','forum_purge') as $policy){tr_fixture($policy);ae_insert('fixture_attachments',array('attach_id'=>1,'privmsgs_id'=>42));ae_check(is_array(tr_submit($policy))&&pd_files()===array(true,true),'Shared PM attachment survives');$cases++;}
 foreach(array('moderator','prune_auto','prune_admin') as $policy){tr_fixture($policy);ae_sql('UPDATE fixture_posts SET forum_id=4 WHERE post_id=10');ae_check(is_array(tr_submit($policy))&&count(ae_rows('SELECT * FROM fixture_topics WHERE topic_id=100'))===1&&count(ae_rows('SELECT * FROM fixture_posts WHERE topic_id=100'))===2,'Malformed cross-forum topic is preserved');$cases++;}
 foreach($ae_tables as $s){tr_fixture('forum_purge');ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=tr_snapshot();ae_check(tr_submit('forum_purge')==='error'&&tr_snapshot()===$before&&pd_files()===array(true,true),'Every storage participant must be canonical '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('moderator','forum_purge','forum_move') as $policy){tr_fixture($policy);$out=tr_submit($policy,true);ae_check(is_array($out)&&count(ae_rows('SELECT * FROM fixture_logs'))===($policy==='moderator'?2:($policy==='forum_purge'?6:0)),'Actual controller successful receipt/audit '.$locale.'/'.$policy);$cases++;}}
 }
 if(getenv('PHPBB_TOPIC_REMOVAL_SMOKE')!=='1'){
  // Independent changes, not edits made on the transaction's own connection.
  foreach(array("UPDATE fixture_config SET config_value='0' WHERE config_name='prune_enable'",'UPDATE fixture_forum_prune SET prune_days=30') as $change){
   foreach(array('DELETE FROM fixture_topics','COMMIT') as $boundary){
    tr_fixture('prune_auto');$reached=$blocked=false;$independent=null;
    $ae_hook=function($sql)use($change,$boundary,&$reached,&$blocked,&$independent){
     if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;
     if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Policy change only blocked by transaction locks');$blocked=true;}
     else{$independent=tr_snapshot();}
    };
    $out=tr_submit('prune_auto');ae_check($reached,'Independent automatic policy boundary reached');
    if($blocked){ae_check(is_array($out)&&$tr_scheduled,'Locked policy serializes with successful prune');$serialized++;}
    else{ae_check($out==='error'&&tr_snapshot()===$independent&&pd_files()===array(true,true)&&!$tr_scheduled,'Changed policy rolls back all content and scheduling');}
    $cases++;
   }
  }
  foreach(array('forum_purge','forum_move') as $policy){
   tr_fixture($policy);$reached=false;$independent=null;
   $ae_hook=function($sql)use(&$reached,&$independent){if(strpos($sql,'DELETE f FROM')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_insert('fixture_categories',array('cat_id'=>2,'cat_main_type'=>'f','cat_main'=>3));$independent=tr_snapshot();};
   ae_check(tr_submit($policy)==='error'&&$reached&&tr_snapshot()===$independent&&pd_files()===array(true,true),'Late child preserves hierarchy and rolls back prior content movement/removal');$cases++;
  }
  require_once $phpbb_root_path.'includes/prune.php';
  $old_log=ini_get('error_log');$owned_log=$upload_dir.'/prune.log';ini_set('error_log',$owned_log);
  try{
   tr_fixture('prune_auto');$GLOBALS['ps_aftercommit']=0;
   $ae_hook=function($sql){if($sql!=='COMMIT'){return;}$GLOBALS['ae_hook']=null;unlink($GLOBALS['upload_dir'].'/owned.txt');mkdir($GLOBALS['upload_dir'].'/owned.txt',0700);};
   $out=auto_prune(3);
   ae_check(!empty($out['cleanup_pending'])&&$GLOBALS['ps_aftercommit']===2&&is_file($owned_log),'Actual automatic wrapper refreshes caches and reports pending cleanup');
   $notice=file_get_contents($owned_log);ae_check(strpos($notice,'detached attachment cleanup remains pending')!==false&&strpos($notice,'Grüße')===false,'Recovery log is actionable without user content');$cases++;
  }finally{ini_set('error_log',$old_log);if(is_file($owned_log)){unlink($owned_log);}}
  tr_fixture('prune_admin');$GLOBALS['ps_aftercommit']=0;$ae_fail='DELETE FROM fixture_topics WHERE topic_id = 900';
  $forum_rows=array(array('forum_id'=>3),array('forum_id'=>4));$prunedate=100;
  $template=new PruneNativeTemplate();$theme=array('td_color1'=>'ffffff','td_color2'=>'eeeeee','td_class1'=>'row1','td_class2'=>'row2');
  $controller=file_get_contents($phpbb_root_path.'admin/admin_forum_prune.php');$a=strpos($controller,'$cleanup_pending = false;');$b=strpos($controller,"\n}\nelse",$a);
  ae_check($a!==false&&$b>$a,'Actual multi-forum prune controller');$failure='';
  try{eval(substr($controller,$a,$b-$a));}catch(RuntimeException $e){$failure=$e->getMessage();}
  ae_check(strpos($failure,sprintf($lang['Prune_batch_interrupted'],1))!==false&&$GLOBALS['ps_aftercommit']===2,'Controller reports prior completed forum and refreshes caches after later failure');
  ae_check(!ae_rows('SELECT * FROM fixture_topics WHERE topic_id IN (100,200)')&&count(ae_rows('SELECT * FROM fixture_topics WHERE topic_id=900'))===1&&pd_files()===array(false,false),'Separate forum commits preserve prior confirmed deletion and roll back only failed forum');$cases++;
 }
 echo 'Native topic/forum removal: '.$cases.' failure/authority/storage/recovery cases, '.$serialized." serialized changes passed.\n";
 }finally{foreach(array($upload_dir.'/owned.txt',$upload_dir.'/'.THUMB_DIR.'/t_owned.txt') as $path){if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}}rmdir($upload_dir.'/'.THUMB_DIR);rmdir($upload_dir);}
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
