<?php
$kp_delete_mode = true;
function kd_run($context='acp',$id=1) {
 try{return kb_delete_article($GLOBALS['db'],$id,$context);}
 catch(PhpbbKbPublicationException $error){return $error;}
}
function kd_files($create=false){
 $dir=$GLOBALS['upload_dir'];kp_check(strpos($dir,sys_get_temp_dir().'/phpbb-kb-delete-owned-')===0,'Only own temporary file directory');
 $paths=array($dir.'/owned.txt',$dir.'/'.THUMB_DIR.'/t_owned.txt');
 if($create){foreach($paths as$path){if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}kp_check(file_put_contents($path,'owned bytes')!==false,'Own synthetic file');}}
 clearstatcache();return array(is_file($paths[0]),is_file($paths[1]));
}
function kd_file_fixture(){
 kp_reset('root',true);kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='del_topic'");kp_check(is_array(kp_run(1)),'Seed actual discussion for file case');kd_files(true);
 $posts=kp_rows('SELECT post_id FROM fixture_posts ORDER BY post_id');kp_check(count($posts)===1,'Exactly owned discussion post');$post=(int)$posts[0]['post_id'];
 kp_sql("INSERT INTO fixture_attachments_desc(attach_id,physical_filename,real_filename,filesize,filetime,thumbnail) VALUES(1,'owned.txt','owned.txt',11,1,1)");
 kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,user_id_1,user_id_2) VALUES(1,'.$post.',1,0)');kp_sql('UPDATE fixture_posts SET post_attachment=1');kp_sql('UPDATE fixture_topics SET topic_attachment=1');
}
function kd_relational_fixture($actor='root',$context='acp'){
 kp_reset($actor,true);kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='del_topic'");kp_check(is_array(kp_run(1,$context)),'Seed actual discussion for relational case');
 $rows=kp_rows('SELECT topic_id FROM fixture_kb_articles WHERE article_id=1');$topic=(int)$rows[0]['topic_id'];
 kp_sql("INSERT INTO fixture_forums(forum_id,cat_id,forum_name,forum_desc,forum_order,count_posts,forum_posts,forum_topics,forum_last_post_id) VALUES(2,1,'Unrelated','Preserve',1,'0',1,1,9999)");
 kp_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_title,topic_poster,topic_first_post_id,topic_last_post_id) VALUES(9999,2,'Unrelated',4,9999,9999)");
 kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,poster_ip) VALUES(9999,9999,2,4,'7f000001'),(9998,".$topic.",1,4,'7f000001')");
 kp_sql("INSERT INTO fixture_posts_text(post_id,post_subject,post_text,bbcode_uid) VALUES(9999,'Preserve','Unrelated bytes',''),(9998,'Reply','Owned discussion reply','')");
 foreach(array($topic,9999)as$id){foreach(array('topics_watch','bookmarks')as$table){kp_sql('INSERT INTO fixture_'.$table.'(topic_id,user_id) VALUES('.$id.',4)');}kp_sql('INSERT INTO fixture_topic_view(topic_id,user_id,view_time,view_count) VALUES('.$id.',4,1,1)');}
 kp_sql("INSERT INTO fixture_vote_desc(vote_id,topic_id,vote_text) VALUES(1,".$topic.",'Owned poll'),(2,9999,'Preserve poll')");
 kp_sql("INSERT INTO fixture_vote_results(vote_id,vote_option_id,vote_option_text,vote_result) VALUES(1,1,'Owned',1),(2,1,'Preserve',2)");
 kp_sql("INSERT INTO fixture_vote_voters(vote_id,vote_user_id,vote_user_ip) VALUES(1,4,'7f000001'),(2,4,'7f000001')");
 kp_sql('UPDATE fixture_users u SET user_posts=(SELECT COUNT(*) FROM fixture_posts p INNER JOIN fixture_forums f ON f.forum_id=p.forum_id AND f.count_posts<>0 WHERE p.poster_id=u.user_id) WHERE u.user_id>0');
 return $topic;
}
function kp_delete_suite() {
 global $kp_source,$kp_hook,$userdata;
 require $kp_source.'includes/functions_kb_delete_storage.php';$GLOBALS['client_ip']='127.0.0.1';$cases=0;
 foreach(array(array('root','acp'),array('junior','acp'),array('moderator','moderator'))as$actor){
  foreach(array(false,true)as$comments){foreach(array(false,true)as$remove_topic){
   kp_reset($actor[0],$comments);kp_sql("UPDATE fixture_kb_config SET config_value='".($remove_topic?'1':'0')."' WHERE config_name='del_topic'");
   kp_check(is_array(kp_run(1,$actor[1])),'Seed actual approved article and optional discussion');
   kp_sql("INSERT INTO fixture_kb_votes VALUES('127.0.0.1','1',1),('127.0.0.1','1',2)");
   $last_sql='';$kp_hook=function($event,$sql)use(&$last_sql){if($event==='before'&&$sql!=='ROLLBACK'&&strpos($sql,'SELECT CONNECTION_ID()')!==0){$last_sql=$sql;}};
   $result=kd_run($actor[1]);$kp_hook=null;kp_check(is_array($result),'Authorized complete KB deletion '.$actor[0].'/'.$actor[1].'/'.(int)$comments.'/'.(int)$remove_topic.': '.($result instanceof Exception?$result->getMessage():'unexpected').' last fixture SQL '.$last_sql);
   kp_check(kp_counts(0)&&!kp_rows('SELECT * FROM fixture_kb_articles')&&!kp_rows('SELECT * FROM fixture_kb_wordmatch')&&count(kp_rows('SELECT * FROM fixture_kb_votes'))===1,'Article/counters/index/owned votes deleted together');
   $remaining=$comments&&!$remove_topic?1:0;kp_check(count(kp_rows('SELECT * FROM fixture_topics'))===$remaining&&count(kp_rows('SELECT * FROM fixture_posts'))===$remaining,'Only configured stored discussion is removed');
   kp_check(empty($result['cleanup_pending']),'No-file deletion confirms complete cleanup');$cases++;
  }}
 }
 kp_reset();kp_check(is_array(kp_run(1)),'Seed native rollback test');$before=kp_snapshot();
 kp_sql("CREATE TRIGGER kd_failure BEFORE DELETE ON fixture_kb_articles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic delete failure'");
 $result=kd_run();kp_check($result instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Real article DELETE failure rolls back all preceding decrements/index/votes');kp_sql('DROP TRIGGER kd_failure');$cases++;
 foreach(array(array('root','acp','UPDATE fixture_users SET user_level=0 WHERE user_id=1'),array('moderator','moderator','DELETE FROM fixture_user_group WHERE user_id=3'))as$actor){kp_reset($actor[0]);kp_check(is_array(kp_run(1,$actor[1])),'Seed stale authority case');kp_sql($actor[2]);$before=kp_snapshot();kp_check(kd_run($actor[1]) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Current actor revocation denies deletion before writes');$cases++;}
 kp_reset('root',true);kp_sql('UPDATE fixture_forums SET count_posts=0 WHERE forum_id=1');kp_check(is_array(kp_run(1)),'Publish discussion in non-counting forum');
 kp_check(kp_rows('SELECT user_posts FROM fixture_users WHERE user_id=1')===array(array('user_posts'=>'0')),'KB discussion must respect the configured non-counting forum policy');
 kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='del_topic'");kp_check(is_array(kd_run())&&kp_rows('SELECT user_posts FROM fixture_users WHERE user_id=1')===array(array('user_posts'=>'0')),'Delete cannot leave a user counter for a non-counting discussion');$cases++;
 $topic=kd_relational_fixture();$mutations=0;$kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};
 $out=kd_run();$kp_hook=null;kp_check(is_array($out)&&$mutations>0,'Collect actual complete deletion mutation inventory');$total=$mutations;
 kp_check(kp_rows('SELECT topic_id FROM fixture_topics')===array(array('topic_id'=>'9999'))&&kp_rows('SELECT vote_id FROM fixture_vote_desc')===array(array('vote_id'=>'2')),'Only bound discussion/poll removed');
 foreach(array('topics_watch','bookmarks','topic_view')as$table){kp_check(kp_rows('SELECT topic_id FROM fixture_'.$table)===array(array('topic_id'=>'9999')),'Unrelated topic preference preserved');}$cases++;
 for($fail=1;$fail<=$total;$fail++){
  kd_relational_fixture();$before=kp_snapshot();$seen=0;$kp_hook=function($event,$sql)use(&$seen,$fail){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return ++$seen===$fail?false:null;}};
  $out=kd_run();$kp_hook=null;kp_check($out instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every deletion mutation failure rolls back article/topics/posts/polls/preferences/audit/index/votes/counters');$cases++;
 }
 foreach(array(array('root','acp'),array('junior','acp'),array('moderator','moderator'))as$actor){
  kp_reset($actor[0]);kp_check(is_array(kp_run(1,$actor[1])),'Seed same-article deletion race');kp_load_controllers();$reached=false;$independent=null;
  $kp_hook=function($event,$sql)use(&$reached,&$independent,$actor){if($event!=='before'||strpos($sql,'SELECT GET_LOCK(')!==0){return;}$GLOBALS['kp_hook']=null;$reached=true;kp_check(kp_controller_run($actor[1],'delete')->level===GENERAL_MESSAGE,'First real controller deletes after second request cached its article');$independent=kp_snapshot();};
  $out=kp_controller_run($actor[1],'delete');$kp_hook=null;kp_check($reached&&$out->level===GENERAL_ERROR&&kp_snapshot()===$independent&&kp_counts(0),'Stale concurrent controller cannot double-delete/decrement');$cases++;
 }
 foreach(array(array('root','acp','UPDATE fixture_users SET user_level=0 WHERE user_id=1','fixture_users'),array('junior','acp',"UPDATE fixture_jr_admin_users SET user_jr_admin='' WHERE user_id=2",'fixture_jr_admin_users'),array('moderator','moderator','DELETE FROM fixture_user_group WHERE user_id=3','fixture_user_group'))as$actor){
  foreach(array('before-first-write','before-authority-lock','before-commit')as$boundary){
   kp_reset($actor[0]);kp_check(is_array(kp_run(1,$actor[1])),'Seed deletion authority race');$before=kp_snapshot();$reached=$blocked=false;
   $kp_hook=function($event,$sql)use($boundary,$actor,&$reached,&$blocked){
    $match=$boundary==='before-first-write'?preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql):($boundary==='before-commit'?$sql==='COMMIT':strpos($sql,'SELECT session_id FROM fixture_sessions ')===0&&substr($sql,-18)==='LOCK IN SHARE MODE');
    if($event!=='before'||!$match){return;}$GLOBALS['kp_hook']=null;$reached=true;kp_sql('SET SESSION innodb_lock_wait_timeout=0');$r=$GLOBALS['peer']->sql_query($actor[2]);if(!$r){$error=$GLOBALS['peer']->sql_error();kp_check((int)$error['code']===1205,'Only native current-authority lock establishes serialized revocation');$blocked=true;}kp_sql('SET SESSION innodb_lock_wait_timeout=1');
   };
   $out=kd_run($actor[1]);$kp_hook=null;kp_check($reached,'Deletion authority boundary reached');
   if($blocked){kp_check(is_array($out)&&kp_counts(0),'Pinned authority commits before waiting revocation');}else{$after=kp_snapshot();unset($before[$actor[3]],$after[$actor[3]]);kp_check($out instanceof PhpbbKbPublicationException&&$after===$before,'Earlier authority revocation rolls back all deletion effects');}$cases++;
  }
 }
 foreach(array('shared','redirect','foreign-child','unknown-count-flag')as$relation){
  $topic=kd_relational_fixture();if($relation==='shared'){kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_body,topic_id) VALUES(2,2,'Other article','Keep',".$topic.')');}elseif($relation==='redirect'){kp_sql('UPDATE fixture_topics SET topic_moved_id=9999 WHERE topic_id='.$topic);}elseif($relation==='foreign-child'){kp_sql('UPDATE fixture_posts SET forum_id=2 WHERE topic_id='.$topic);}else{kp_sql("UPDATE fixture_forums SET count_posts='Y' WHERE forum_id=1");}
  $before=kp_snapshot();kp_check(kd_run() instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Ambiguous stored discussion relations refuse all deletion effects');$cases++;
 }
 kd_relational_fixture();$_POST['topic']='9999';$_POST['forum_id']='2';kp_check(is_array(kd_run())&&kp_rows('SELECT topic_id FROM fixture_topics')===array(array('topic_id'=>'9999')),'Posted topic/forum fields cannot broaden stored relation');$cases++;
 kp_reset();kp_check(is_array(kp_run(1)),'Seed standalone article');kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='del_topic'");kp_sql('DELETE FROM fixture_forums');kp_check(is_array(kd_run())&&kp_counts(0),'Standalone KB deletion never invents a forum requirement');$cases++;
 require $kp_source.'attach_mod/includes/functions_admin.php';require $kp_source.'attach_mod/includes/functions_shadow.php';
 $GLOBALS['upload_dir']=sys_get_temp_dir().'/phpbb-kb-delete-owned-'.bin2hex(phpbb_random_bytes(8));$GLOBALS['attach_config']=array('allow_ftp_upload'=>'0');
 kp_check(mkdir($GLOBALS['upload_dir'],0700)&&mkdir($GLOBALS['upload_dir'].'/'.THUMB_DIR,0700),'Own file-test directories');
 try{
  kd_file_fixture();$before_commit=$after_commit=false;
  $kp_hook=function($event,$sql)use(&$before_commit,&$after_commit){if($sql==='COMMIT'){if($event==='before'){$before_commit=kd_files()===array(true,true);}if($event==='after'){$after_commit=kd_files()===array(true,true);}}};
  $out=kd_run();$kp_hook=null;kp_check(is_array($out)&&empty($out['cleanup_pending'])&&$before_commit&&$after_commit&&kd_files()===array(false,false)&&!kp_rows('SELECT * FROM fixture_attachments_desc'),'Real files are unlinked only after confirmed core+KB commit');$cases++;
  kd_file_fixture();$before=kp_snapshot();kp_sql("CREATE TRIGGER kd_file_failure BEFORE DELETE ON fixture_kb_articles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic file rollback'");
  kp_check(kd_run() instanceof PhpbbKbPublicationException&&kp_snapshot()===$before&&kd_files()===array(true,true),'Native late SQL failure restores topic/post/attachment references and never unlinks files');kp_sql('DROP TRIGGER kd_file_failure');$cases++;
  kd_file_fixture();unlink($GLOBALS['upload_dir'].'/owned.txt');kp_check(mkdir($GLOBALS['upload_dir'].'/owned.txt',0700),'Own deliberate storage conflict');
  $out=kd_run();kp_check(is_array($out)&&$out['cleanup_pending']&&is_dir($GLOBALS['upload_dir'].'/owned.txt')&&count(kp_rows('SELECT * FROM fixture_attachments_desc'))===1&&!kp_rows('SELECT * FROM fixture_attachments'),'Confirmed deletion retains description when physical cleanup fails');
  rmdir($GLOBALS['upload_dir'].'/owned.txt');file_put_contents($GLOBALS['upload_dir'].'/owned.txt','owned bytes');attach_shadow_cleanup(array(),array('1'));
  kp_check(kd_files()===array(false,false)&&!kp_rows('SELECT * FROM fixture_attachments_desc'),'Existing ACP shadow cleanup recovers retained description/file');$cases++;
  kd_file_fixture();$reached=false;$kp_hook=function($event,$sql)use(&$reached){if($event==='before'&&strpos($sql,'DELETE FROM fixture_attachments_desc WHERE ')===0){$reached=true;return false;}};
  $out=kd_run();$kp_hook=null;kp_check(is_array($out)&&$out['cleanup_pending']&&$reached&&kd_files()===array(false,false)&&count(kp_rows('SELECT * FROM fixture_attachments_desc'))===1,'Postcommit metadata failure reserves filename without claiming full cleanup');
  attach_shadow_cleanup(array(),array('1'));kp_check(!kp_rows('SELECT * FROM fixture_attachments_desc'),'Shadow retry can clear a description after already-removed bytes');$cases++;
  foreach(array('before','after')as$boundary){
   kd_file_fixture();$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($boundary,&$reached){if($sql==='COMMIT'&&$event===$boundary){$reached=true;return false;}};
   $out=kd_run();$kp_hook=null;kp_check($reached&&$out instanceof PhpbbKbPublicationException&&kd_files()===array(true,true),'Refused/lost COMMIT never reports success or unlinks files');
   if($boundary==='before'){kp_check(kp_snapshot()===$before,'Pre-COMMIT failure preserves all old SQL state');}
   else{kp_check(!kp_rows('SELECT * FROM fixture_kb_articles')&&!kp_rows('SELECT * FROM fixture_attachments')&&count(kp_rows('SELECT * FROM fixture_attachments_desc'))===1,'Unacknowledged committed deletion retains reserved cleanup metadata');attach_shadow_cleanup(array(),array('1'));kp_check(kd_files()===array(false,false),'Shadow recovery completes unacknowledged committed deletion');}$cases++;
  }
  foreach(array('same-id','shared-name')as$sharing){
   kd_file_fixture();if($sharing==='shared-name'){kp_sql("INSERT INTO fixture_attachments_desc(attach_id,physical_filename,real_filename,filesize,filetime,thumbnail) VALUES(2,'owned.txt','shared.txt',11,1,1)");}$id=$sharing==='same-id'?1:2;
   kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES('.$id.',0,9,4,2)');$out=kd_run();
   kp_check(is_array($out)&&empty($out['cleanup_pending'])&&kd_files()===array(true,true)&&kp_rows('SELECT attach_id,privmsgs_id FROM fixture_attachments')===array(array('attach_id'=>(string)$id,'privmsgs_id'=>'9')),'Foreign PM reference/shared physical name preserves real bytes and foreign link');
   kp_check(count(kp_rows('SELECT * FROM fixture_attachments_desc'))===1,'Only target-owned unreferenced description may be removed');$cases++;
  }
  kd_file_fixture();$reached=false;$kp_hook=function($event,$sql)use(&$reached){if($sql==='COMMIT'&&$event==='after'){$GLOBALS['kp_hook']=null;$reached=true;kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}};
  $out=kd_run();$kp_hook=null;kp_check(is_array($out)&&$out['cleanup_pending']&&$reached&&kd_files()===array(true,true)&&!kp_rows('SELECT * FROM fixture_kb_articles'),'Rights revoked after confirmed commit cannot authorize physical cleanup');
  kp_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=1');attach_shadow_cleanup(array(),array('1'));kp_check(kd_files()===array(false,false),'Authorized later shadow cleanup recovers after postcommit revocation');$cases++;
  kd_file_fixture();kp_sql('CREATE TABLE fixture_delete_caller(marker INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
  kp_check($GLOBALS['db']->sql_query('START TRANSACTION')&&$GLOBALS['db']->sql_query('INSERT INTO fixture_delete_caller VALUES(7)'),'Caller pending work');$out=kd_run();kp_check(is_array($out)&&kp_rows('SELECT * FROM fixture_delete_caller')===array(),'Owned deletion never commits unrelated caller work');
  $r=$GLOBALS['db']->sql_query('SELECT marker FROM fixture_delete_caller');$row=$GLOBALS['db']->sql_fetchrow($r);$GLOBALS['db']->sql_freeresult($r);kp_check((int)$row['marker']===7&&$GLOBALS['db']->sql_query('ROLLBACK')&&!kp_rows('SELECT * FROM fixture_kb_articles'),'Caller can roll back independently of confirmed KB deletion');kp_sql('DROP TABLE fixture_delete_caller');$cases++;
  $saved_cwd=getcwd();$saved_root=$GLOBALS['phpbb_root_path'];$saved_upload=$GLOBALS['upload_dir'];$saved_config=$GLOBALS['attach_config'];$mock=$saved_upload.'/relative-forum';
  kp_check(mkdir($mock,0700)&&mkdir($mock.'/admin',0700)&&mkdir($mock.'/files',0700)&&mkdir($mock.'/files/'.THUMB_DIR,0700),'Own relative-path forum mock');
  try{
   foreach(array('admin-raw','admin-prefixed','frontend-raw')as$path_mode){
    kd_file_fixture();kp_check(rename($saved_upload.'/owned.txt',$mock.'/files/owned.txt')&&rename($saved_upload.'/'.THUMB_DIR.'/t_owned.txt',$mock.'/files/'.THUMB_DIR.'/t_owned.txt'),'Move own synthetic files into mock forum');
    $GLOBALS['attach_config']=array('allow_ftp_upload'=>'0','upload_dir'=>'files');$GLOBALS['upload_dir']=$path_mode==='admin-prefixed'?'../files':'files';$GLOBALS['phpbb_root_path']=$path_mode==='frontend-raw'?'./':'../';
    kp_check(chdir($path_mode==='frontend-raw'?$mock:$mock.'/admin'),'Own working directory');$out=kd_run();
    clearstatcache();kp_check(is_array($out)&&empty($out['cleanup_pending'])&&!is_file($mock.'/files/owned.txt')&&!is_file($mock.'/files/'.THUMB_DIR.'/t_owned.txt'),'Actual relative attachment root works without double-prefixing: '.$path_mode);
    chdir($saved_cwd);$GLOBALS['phpbb_root_path']=$saved_root;$GLOBALS['upload_dir']=$saved_upload;$GLOBALS['attach_config']=$saved_config;$cases++;
   }
  }finally{
   chdir($saved_cwd);$GLOBALS['phpbb_root_path']=$saved_root;$GLOBALS['upload_dir']=$saved_upload;$GLOBALS['attach_config']=$saved_config;
   foreach(array($mock.'/files/owned.txt',$mock.'/files/'.THUMB_DIR.'/t_owned.txt')as$path){if(is_file($path)){unlink($path);}}
   rmdir($mock.'/files/'.THUMB_DIR);rmdir($mock.'/files');rmdir($mock.'/admin');rmdir($mock);
  }
 }finally{
  $kp_hook=null;foreach(array($GLOBALS['upload_dir'].'/owned.txt',$GLOBALS['upload_dir'].'/'.THUMB_DIR.'/t_owned.txt')as$path){if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}}
  rmdir($GLOBALS['upload_dir'].'/'.THUMB_DIR);rmdir($GLOBALS['upload_dir']);
 }
 echo 'KB atomic deletion native: '.$cases." authority/race/fault/policy/relation/file/recovery cases passed.\n";
}
require __DIR__.'/check-kb-publication-native.php';
