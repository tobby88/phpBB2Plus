<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_POST_DELETE_NATIVE')!=='1'){echo "Native post deletion checks require an explicitly enabled disposable database.\n";return;}
$source=file_get_contents(__DIR__.'/check-post-submit-native.php');$cut=strpos($source,"\$body = <<<'PHP'");if($cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
$setup=substr($source,5,$cut-5);$fixture_dir=var_export(__DIR__,true);$setup=str_replace('file_get_contents(__DIR__','file_get_contents('.$fixture_dir,$setup);$setup=str_replace('var_export(__DIR__','var_export('.$fixture_dir,$setup);
putenv('PHPBB_POST_SUBMIT_NATIVE=1');putenv('PHPBB_POST_SUBMIT_PORT='.(getenv('PHPBB_POST_DELETE_PORT')?:'3306'));putenv('PHPBB_POST_SUBMIT_PASSWORD='.(getenv('PHPBB_POST_DELETE_PASSWORD')?:''));eval($setup);
$head=str_replace('codex_post_submit_','codex_post_delete_',$head);$head=str_replace("'vote_desc','vote_results');\$ae_columns","'vote_desc','vote_results','logs','vote_voters','attachments','attachments_desc','topics_watch','bookmarks','topic_view','privmsgs');\$ae_columns",$head);
function pd_files($reset=false)
{
 $dir=$GLOBALS['upload_dir'];$paths=array($dir.'/owned.txt',$dir.'/'.THUMB_DIR.'/t_owned.txt');
 if($reset){foreach($paths as $path){if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}ae_check(file_put_contents($path,'owned bytes')!==false,'Owned file setup');}}
 clearstatcache();return array(is_file($paths[0]),is_file($paths[1]));
}
function pd_fixture($actor='member',$kind='reply')
{
 ps_fixture($actor);pd_files(true);ae_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');$GLOBALS['client_ip']='2001:db8::42';
 ae_sql('UPDATE fixture_forums SET auth_delete=1');
 if($kind==='single'){ae_sql('DELETE FROM fixture_posts WHERE post_id=20');ae_sql('DELETE FROM fixture_posts_text WHERE post_id=20');ae_sql('DELETE FROM fixture_search_wordmatch WHERE post_id=20');ae_sql('UPDATE fixture_forums SET forum_posts=1,forum_last_post_id=10');ae_sql('UPDATE fixture_topics SET topic_replies=0,topic_last_post_id=10');}
 $post=$kind==='reply'?20:10;
 ae_insert('fixture_attachments_desc',array('attach_id'=>1,'physical_filename'=>'owned.txt','real_filename'=>'owned.txt','thumbnail'=>1));ae_insert('fixture_attachments',array('attach_id'=>1,'post_id'=>$post));ae_sql('UPDATE fixture_posts SET post_attachment=1 WHERE post_id='.$post);ae_sql('UPDATE fixture_topics SET topic_attachment=1');
 foreach(array('topics_watch','bookmarks','topic_view') as $table){ae_insert('fixture_'.$table,array('topic_id'=>100,'user_id'=>8));}
 if($kind!=='reply'){ae_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>100,'vote_text'=>'Poll'));foreach(array(1,2) as $id){ae_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>$id,'vote_option_text'=>'Option '.$id));}ae_sql('UPDATE fixture_topics SET topic_vote=1');}
 return $post;
}
function pd_snapshot(){ $out=ps_snapshot();foreach($out['logs'] as &$row){$row['log_time']='timestamp';}unset($row);return $out; }
function pd_redirect_fixture()
{
 pd_fixture('root','single');ae_insert('fixture_forums',array('forum_id'=>4,'forum_name'=>'Other forum','forum_topics'=>2,'forum_posts'=>1,'forum_last_post_id'=>30));
 foreach(array(200,201) as $id){ae_insert('fixture_topics',array('topic_id'=>$id,'forum_id'=>4,'topic_moved_id'=>100));foreach(array('topics_watch','bookmarks','topic_view') as $table){ae_insert('fixture_'.$table,array('topic_id'=>$id,'user_id'=>9));}}
 ae_insert('fixture_posts',array('post_id'=>30,'topic_id'=>201,'forum_id'=>4,'poster_id'=>9));ae_insert('fixture_posts_text',array('post_id'=>30,'post_subject'=>'Keep','post_text'=>'Keep'));
 ae_insert('fixture_vote_desc',array('vote_id'=>2,'topic_id'=>100,'vote_text'=>'Legacy second poll'));ae_insert('fixture_vote_results',array('vote_id'=>2,'vote_option_id'=>1,'vote_option_text'=>'Legacy option','vote_result'=>2));ae_insert('fixture_vote_voters',array('vote_id'=>2,'vote_user_id'=>9));
 ae_insert('fixture_vote_desc',array('vote_id'=>3,'topic_id'=>201,'vote_text'=>'Unrelated poll'));ae_insert('fixture_vote_results',array('vote_id'=>3,'vote_option_id'=>1,'vote_option_text'=>'Keep'));
}
function pd_submit($kind='reply',$options=array())
{
 $mode=$kind==='poll'?'poll_delete':'delete';$forum_id=3;$topic_id=100;$post_id=isset($options['post'])?$options['post']:($kind==='reply'?20:10);$poll_id=isset($options['poll'])?$options['poll']:1;
 $post_data=array('first_post'=>false,'last_post'=>false,'poster_id'=>999,'_stats_completed'=>'delete:20','_delete_cleanup_pending'=>true);$before=$post_data;$message=$meta='';
 try{
  if(!empty($options['controller'])){
   $posting=file_get_contents($GLOBALS['phpbb_root_path'].'posting.php');ae_check(preg_match("/delete_post\\(\\\$mode,[^\\n]+\\);\\s*if \\(\\\$mode == 'delete'[\\s\\S]+?log_action\\('delete'[^\\n]+\\);\\s*\\}/",$posting,$branch)===1,'Actual delete controller/audit branch');
   $return_message=$return_meta='';$is_auth=$GLOBALS['is_auth'];$userdata=$GLOBALS['userdata'];eval($branch[0]);$message=$return_message;$meta=$return_meta;
  }else{delete_post($mode,$post_data,$message,$meta,$forum_id,$topic_id,$post_id,$poll_id);}
  return array('data'=>$post_data,'message'=>$message,'meta'=>$meta);
 }catch(RuntimeException $e){$GLOBALS['pd_exception']=$e->getMessage();ae_check($post_data===$before&&$message===''&&$meta==='','Unconfirmed deletion never mutates caller state');return 'error';}
}
$body= <<<'PHP'
 require $phpbb_root_path.'attach_mod/includes/functions_attach.php';
 require $phpbb_root_path.'attach_mod/includes/functions_admin.php';
 require $phpbb_root_path.'attach_mod/includes/functions_shadow.php';
 $upload_dir=sys_get_temp_dir().'/phpbb-delete-owned-'.bin2hex(phpbb_random_bytes(8));$attach_config=array('allow_ftp_upload'=>'0');
 ae_check(mkdir($upload_dir,0700)&&mkdir($upload_dir.'/'.THUMB_DIR,0700),'Owned file directories');
 try{
 if(getenv('PHPBB_POST_DELETE_TAIL')!=='1'&&getenv('PHPBB_POST_DELETE_EXTRA_ONLY')!=='1'){
 foreach(array('member','moderator','root') as $actor){foreach(array('reply','single','poll') as $kind){
  pd_fixture($actor,$kind);$before=pd_snapshot();$out=pd_submit($kind);ae_check(is_array($out),'Successful delete '.$actor.'/'.$kind.' '.(isset($pd_exception)?$pd_exception:'').' '.json_encode($ae_error));$after=pd_snapshot();$queries=$ae_queries;
  ae_check(!$out['data']['_delete_cleanup_pending']&&$out['data']['_delete_audit_completed'],'Confirmed cleanup and audit marker');
  ae_check(pd_files()===($kind==='poll'?array(true,true):array(false,false)),'Physical bytes removed only for deleted posts');
  ae_check(count($after['logs'])===($actor==='member'?0:1),'One current moderator audit');
  ae_check(count($after['posts'])===($kind==='poll'?2:($kind==='single'?0:1)),'Exactly selected parent removed');
  ae_check(count($after['vote_desc'])===0&&count($after['vote_results'])===0,'Selected poll records removed');
  ae_check(count($after['topics_watch'])===($kind==='single'?0:1),'Preferences outlive replies/polls');
  $commit=array_search('COMMIT',$queries,true);ae_check($commit!==false,'Explicit commit boundary');$writes=0;foreach(array_slice($queries,0,$commit) as $sql){if(preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)){$writes++;}}
  for($nth=1;$nth<=$writes;$nth++){pd_fixture($actor,$kind);$before=pd_snapshot();$ae_failwrite=$nth;ae_check(pd_submit($kind)==='error'&&pd_snapshot()===$before&&pd_files()===array(true,true)&&$ps_aftercommit===0,'Rollback before physical removal at each core write '.$actor.'/'.$kind.'/'.$nth);$cases++;}
  foreach(array(false,true) as $ack){pd_fixture($actor,$kind);$before=pd_snapshot();$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';$out=pd_submit($kind);$actual=pd_snapshot();$expected=$ack?$after:$before;if($ack&&$kind!=='poll'){$expected['attachments_desc']=$before['attachments_desc'];}ae_check($out==='error'&&$actual===$expected&&pd_files()===array(true,true),'Failed/lost COMMIT keeps files and truthful result');$cases++;}
  if($kind!=='poll'){
   pd_fixture($actor,$kind);$before=pd_snapshot();$reached=false;$ae_hook=function($sql)use(&$reached){if(strpos($sql,'DELETE FROM fixture_search_wordmatch')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(pd_submit($kind)==='error'&&$reached&&pd_snapshot()===$before&&pd_files()===array(true,true),'Real connection loss rolls back complete deletion without touching bytes');$cases++;
  }
  foreach(array('session',$actor==='root'?'role':($actor==='moderator'?'grant':'inactive')) as $change){foreach(array($kind==='poll'?'DELETE FROM fixture_vote_results':'DELETE FROM fixture_posts ','COMMIT') as $boundary){
   pd_fixture($actor,$kind);$reached=$blocked=false;$independent=null;$ae_hook=function($sql)use($change,$boundary,&$reached,&$blocked,&$independent){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ae_revoke($change))){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Authority change blocks only on row locks');$blocked=true;}else{$independent=pd_snapshot();}};
   $out=pd_submit($kind);ae_check($reached,'Actual authority boundary reached');if($blocked){ae_check(is_array($out)&&pd_snapshot()===$after,'Revocation serializes after full deletion');$serialized++;}else{ae_check($out==='error'&&pd_snapshot()===$independent&&pd_files()===array(true,true),'Effective revocation rolls back every core change');}$cases++;
  }}
  echo $actor.'/'.$kind." native deletion/failure/authority checks passed\n";
 }}
 }
 foreach(array(0,'invalid',array(20)) as $id){pd_fixture();$before=pd_snapshot();ae_check(pd_submit('reply',array('post'=>$id))==='error'&&pd_snapshot()===$before,'Invalid post scope refuses all writes');ae_check(is_array(pd_submit()),'Invalid post scope releases writer for immediate next request');$cases++;}
 foreach(array(0,'invalid',array(1),2) as $id){pd_fixture('member','poll');$before=pd_snapshot();ae_check(pd_submit('poll',array('poll'=>$id))==='error'&&pd_snapshot()===$before,'Invalid or stale poll scope preserves all rows');$cases++;}
 pd_redirect_fixture();ae_check(is_array(pd_submit('single')),'Delete selected whole topic with legacy extra poll and redirects');$after=pd_snapshot();$queries=$ae_queries;$commit=array_search('COMMIT',$queries,true);$writes=0;foreach(array_slice($queries,0,$commit) as $sql){if(preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql)){$writes++;}}
 ae_check(count($after['topics'])===1&&(int)$after['topics'][0]['topic_id']===201&&count($after['topics_watch'])===1&&(int)$after['topics_watch'][0]['topic_id']===201,'Only empty redirect removed; nonempty redirected topic and preferences survive');
 ae_check(count($after['vote_desc'])===1&&(int)$after['vote_desc'][0]['vote_id']===3&&count($after['vote_voters'])===0,'All selected legacy polls removed without touching another topic');
 ae_check((int)ae_rows('SELECT forum_topics FROM fixture_forums WHERE forum_id=4')[0]['forum_topics']===1,'Dependent redirect forum counter repaired inside transaction');
 for($nth=1;$nth<=$writes;$nth++){pd_redirect_fixture();$before=pd_snapshot();$ae_failwrite=$nth;ae_check(pd_submit('single')==='error'&&pd_snapshot()===$before&&pd_files()===array(true,true),'Redirect/preference/legacy-poll failure rolls back whole deletion '.$nth);$cases++;}
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';pd_fixture();$ae_fail='COMMIT';ae_check(pd_submit()==='error'&&$pd_exception===$lang['Posting_delete_unconfirmed']&&pd_files()===array(true,true),'Deletion-specific uncertainty message '.$locale);$cases++;}
 if(getenv('PHPBB_POST_DELETE_EXTRA_ONLY')!=='1'){
 foreach(array('session','case','foreign','logout','inactive','read') as $change){pd_fixture();ae_sql(ae_revoke($change));$before=pd_snapshot();ae_check(pd_submit()==='error'&&pd_snapshot()===$before&&pd_files()===array(true,true),'Current actor required '.$change);$cases++;}
 foreach(array('UPDATE fixture_forums SET auth_delete=5','UPDATE fixture_forums SET forum_status=1','UPDATE fixture_topics SET topic_status=1','UPDATE fixture_posts SET poster_id=9','UPDATE fixture_topics SET forum_id=4') as $sql){pd_fixture();ae_sql($sql);$before=pd_snapshot();ae_check(pd_submit()==='error'&&pd_snapshot()===$before,'Current permission/target required');$cases++;}
 pd_fixture('member','first');$before=pd_snapshot();ae_check(pd_submit('first')==='error'&&pd_snapshot()===$before,'Ordinary member cannot delete first post with replies');$cases++;
 pd_fixture('moderator','first');ae_check(is_array(pd_submit('first'))&&(int)ae_rows('SELECT topic_first_post_id FROM fixture_topics')[0]['topic_first_post_id']===20&&count(ae_rows('SELECT * FROM fixture_vote_desc'))===1,'Moderator first-post deletion preserves topic poll and repairs boundary');$cases++;
 foreach(array('single','poll') as $kind){foreach(array('UPDATE fixture_vote_results SET vote_result=1','INSERT INTO fixture_vote_voters (vote_id,vote_user_id,vote_user_ip) VALUES (1,9,\'7f000001\')') as $sql){pd_fixture('member',$kind);ae_sql($sql);$before=pd_snapshot();ae_check(pd_submit($kind)==='error'&&pd_snapshot()===$before,'Recorded vote/voter prevents ordinary poll removal');$cases++;}}
 foreach(array('post','pm','duplicate') as $shared){pd_fixture('root');if($shared==='duplicate'){ae_insert('fixture_attachments_desc',array('attach_id'=>2,'physical_filename'=>'owned.txt','real_filename'=>'shared.txt','thumbnail'=>1));}else{ae_insert('fixture_attachments',array('attach_id'=>1,'post_id'=>$shared==='post'?10:0,'privmsgs_id'=>$shared==='pm'?42:0));}ae_check(is_array(pd_submit())&&pd_files()===array(true,true),'Shared post/PM/file reference preserves bytes '.$shared);$cases++;}
 foreach(array('thumbnail','main','description','session') as $failure){
  pd_fixture('root');$reached=false;$ae_hook=function($sql)use($failure,&$reached){if(strpos($sql,'DELETE FROM fixture_attachments_desc')!==0&&strpos($sql,'UPDATE fixture_attachments_desc SET thumbnail')!==0){if($sql!=='COMMIT'){return;}}else{if($failure!=='description'){return;}}$GLOBALS['ae_hook']=null;$reached=true;
   if($failure==='description'){$GLOBALS['ae_fail']='UPDATE fixture_attachments_desc SET thumbnail';}
   elseif($failure==='session'){$GLOBALS['pd_revoke_after_commit']=true;}
   else{$path=$GLOBALS['upload_dir'].'/'.($failure==='thumbnail'?THUMB_DIR.'/t_':'').'owned.txt';unlink($path);mkdir($path,0700);}
  };
  // Post-commit authority loss is injected on the first cleanup read, after
  // the commit locks have really been released, not inside COMMIT itself.
  if($failure==='session'){$ae_hook=function($sql)use(&$reached){if(strpos($sql,'SELECT privmsgs_id FROM fixture_privmsgs WHERE privmsgs_write_payload')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql(ae_revoke('session'));};}
  $out=pd_submit();ae_check($reached&&is_array($out)&&$out['data']['_delete_cleanup_pending']&&$out['meta']===''&&count(ae_rows('SELECT * FROM fixture_posts'))===1&&count(ae_rows('SELECT * FROM fixture_attachments_desc'))===1,'Committed deletion reports recoverable cleanup failure '.$failure);
  $ae_hook=null;$ae_fail='';if($failure==='session'){ae_insert('fixture_sessions',array('session_id'=>'edit-sid','session_user_id'=>8,'session_logged_in'=>1));}
  foreach(array($upload_dir.'/owned.txt',$upload_dir.'/'.THUMB_DIR.'/t_owned.txt') as $path){if(is_dir($path)){rmdir($path);ae_check(file_put_contents($path,'owned bytes')!==false,'Repair owned fixture failure');}}
  // Execute the actual ACP orphan recovery on the retained description.
  attach_shadow_cleanup(array(),array(1));ae_check(!ae_rows('SELECT * FROM fixture_attachments_desc')&&pd_files()===array(false,false),'Existing ACP can finish detached cleanup '.$failure);$cases++;
 }
 foreach($ae_tables as $s){pd_fixture();ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=pd_snapshot();ae_check(pd_submit()==='error'&&pd_snapshot()===$before&&pd_files()===array(true,true),'Every old-format participant refuses deletion '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';pd_fixture('moderator');$out=pd_submit('reply',array('controller'=>true));ae_check(is_array($out)&&count(ae_rows('SELECT * FROM fixture_logs'))===1,'Actual controller avoids duplicate audit '.$locale);$cases++;}
 }
 echo 'Native post deletion: '.$cases.' failure/authority/storage/recovery cases, '.$serialized." serialized changes passed.\n";
 }finally{foreach(array($upload_dir.'/owned.txt',$upload_dir.'/'.THUMB_DIR.'/t_owned.txt') as $path){if(is_file($path)){unlink($path);}elseif(is_dir($path)){rmdir($path);}}rmdir($upload_dir.'/'.THUMB_DIR);rmdir($upload_dir);}
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
