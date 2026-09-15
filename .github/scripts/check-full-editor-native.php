<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_FULL_EDITOR_NATIVE')!=='1'){echo "Native full-editor checks require an explicitly enabled disposable database.\n";return;}
$source=file_get_contents(__DIR__.'/check-post-submit-native.php');$cut=strpos($source,"\$body = <<<'PHP'");if($cut===false){throw new RuntimeException('Canonical fixture boundary missing');}
$setup=substr($source,5,$cut-5);$fixture_dir=var_export(__DIR__,true);$setup=str_replace('file_get_contents(__DIR__','file_get_contents('.$fixture_dir,$setup);$setup=str_replace('var_export(__DIR__','var_export('.$fixture_dir,$setup);
putenv('PHPBB_POST_SUBMIT_NATIVE=1');putenv('PHPBB_POST_SUBMIT_PORT='.(getenv('PHPBB_FULL_EDITOR_PORT')?:'3306'));putenv('PHPBB_POST_SUBMIT_PASSWORD='.(getenv('PHPBB_FULL_EDITOR_PASSWORD')?:''));eval($setup);
$head=str_replace('codex_post_submit_','codex_full_editor_',$head);$head=str_replace("'vote_desc','vote_results');\$ae_columns","'vote_desc','vote_results','logs','vote_voters');\$ae_columns",$head);
function fe_fixture($actor='member',$poll=true)
{
 ps_fixture($actor);ae_sql('ALTER TABLE fixture_logs AUTO_INCREMENT=1');$GLOBALS['client_ip']='2001:db8::42';
 if($poll){ae_insert('fixture_vote_desc',array('vote_id'=>1,'topic_id'=>100,'vote_text'=>'Poll','vote_start'=>1,'vote_length'=>86400));foreach(array(1,2,3) as $id){ae_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>$id,'vote_option_text'=>'Option '.$id));}ae_sql('UPDATE fixture_topics SET topic_vote=1');}
}
function fe_snapshot()
{
 $out=ps_snapshot();foreach($out['logs'] as &$row){$row['log_time']='timestamp';}unset($row);return $out;
}
function fe_submit($options=array())
{
 $post_data=array('first_post'=>false,'last_post'=>true,'poster_post'=>false,'poster_id'=>999,'has_poll'=>true,'edit_poll'=>true);$before=$post_data;
 $message=$meta='';$forum_id=3;$topic_id=100;$post_id=isset($options['post'])?$options['post']:10;$poll_id=isset($options['poll_id'])?$options['poll_id']:1;$topic_type=isset($options['type'])?$options['type']:0;
 $bbcode_on=1;$html_on=$smilies_on=$attach_sig=0;$bbcode_uid=isset($options['uid'])?$options['uid']:'1234567890';$poll_options=isset($options['poll_options'])?$options['poll_options']:array(2=>'Option 2',3=>'Option 3',4=>'Option 4');$poll_length=1;$topic_desc='Description';$news_category=isset($options['news'])?$options['news']:0;
 $subject=isset($options['subject'])?$options['subject']:"Quasarword Grüße O'Reilly 😀";$text=isset($options['text'])?$options['text']:'[b:'.$bbcode_uid.']Quasarword Grüße[/b:'.$bbcode_uid.'] & 😀';$poll_title=isset($options['poll_title'])?$options['poll_title']:'Changed poll';
 try {
  if(!empty($options['controller'])){
   $posting=file_get_contents($GLOBALS['phpbb_root_path'].'posting.php');ae_check(preg_match("/submit_post\\(\\\$mode,[^\\n]+\\);\\s*if \\(\\\$mode == 'editpost'[\\s\\S]+?log_action\\('edit'[^\\n]+\\);\\s*\\}/",$posting,$branch)===1,'Actual full-editor controller and audit branch');
   $mode='editpost';$return_message=$return_meta='';$username='';$subject=addslashes(htmlspecialchars($subject,ENT_COMPAT,'UTF-8'));$message=addslashes($text);$poll_title=addslashes($poll_title);$topic_announce_duration=$post_icon=$topic_calendar_time=$topic_calendar_duration=0;$is_auth=$GLOBALS['is_auth'];$userdata=$GLOBALS['userdata'];eval($branch[0]);
  }else{submit_post('editpost',$post_data,$message,$meta,$forum_id,$topic_id,$post_id,$poll_id,$topic_type,$bbcode_on,$html_on,$smilies_on,$attach_sig,$bbcode_uid,'',addslashes(htmlspecialchars($subject,ENT_COMPAT,'UTF-8')),addslashes($text),addslashes($poll_title),$poll_options,$poll_length,$topic_desc,0,0,isset($options['calendar'])?$options['calendar']:0,0,$news_category);}
  return array('post_data'=>$post_data,'uid'=>$bbcode_uid,'poll_id'=>$poll_id);
 }
 catch(RuntimeException $e){$GLOBALS['fe_exception']=$e->getMessage();ae_check($post_data===$before,'Failure does not mark audit or mutate caller flags');return 'error';}
}
$body= <<<'PHP'
 if(getenv('PHPBB_FULL_EDITOR_TAIL')!=='1'){
 foreach(array('member','moderator','root') as $actor){
  fe_fixture($actor);$before=fe_snapshot();$out=fe_submit();ae_check(is_array($out),'Successful full edit '.$actor.' '.(isset($fe_exception)?$fe_exception:'').' '.json_encode($ae_error));$after=fe_snapshot();$writes=$ae_write;
  ae_check(array_map('intval',array_column(ae_rows('SELECT vote_option_id FROM fixture_vote_results ORDER BY vote_option_id'),'vote_option_id'))===array(2,3,4),'Retained option IDs never collide with new option');
  ae_check(count($after['logs'])===($actor==='member'?0:1)&&$out['post_data']['_edit_audit_completed'],'Atomic moderator audit and caller marker');
  ae_check((int)ae_rows('SELECT post_edit_count FROM fixture_posts WHERE post_id=10')[0]['post_edit_count']===($actor==='member'?1:0),'Correct own non-last edit count');
  ae_check(is_array(fe_submit(array('uid'=>'9876543210')))&&fe_snapshot()===$after,'No-op retry preserves parser UID, counts, options, index and audit');
  for($nth=1;$nth<=$writes;$nth++){fe_fixture($actor);$before=fe_snapshot();$ae_failwrite=$nth;ae_check(fe_submit()==='error'&&fe_snapshot()===$before,'Whole rollback at each body/metadata/poll/index/audit write '.$actor.'/'.$nth);$cases++;}
  foreach(array(false,true) as $ack){fe_fixture($actor);$before=fe_snapshot();$ae_ack=$ack;$ae_fail=$ack?'':'COMMIT';ae_check(fe_submit()==='error'&&fe_snapshot()===($ack?$after:$before),'Failed/lost acknowledgement is atomic');$ae_ack=false;$ae_fail='';if($ack){ae_check(is_array(fe_submit())&&fe_snapshot()===$after,'Lost ACK retry does not repeat edit or audit');}$cases++;}
  foreach(array('session',$actor==='root'?'role':($actor==='moderator'?'grant':'edit')) as $kind){foreach(array('UPDATE fixture_posts SET','INSERT INTO fixture_search_wordmatch','COMMIT') as $boundary){
   fe_fixture($actor);$reached=$blocked=false;$independent=null;$ae_hook=function($sql)use($kind,$boundary,&$reached,&$blocked,&$independent){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ae_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ae_check((int)$e['code']===1205,'Independent authority change blocked only by row lock');$blocked=true;}else{$independent=fe_snapshot();}};
   $out=fe_submit();ae_check($reached,'Actual write/commit authority boundary reached');if($blocked){ae_check(is_array($out)&&fe_snapshot()===$after,'Revocation serializes after full edit');$serialized++;}else{ae_check($out==='error'&&fe_snapshot()===$independent,'Effective revocation rolls back all editor changes');}$cases++;
  }}
  fe_fixture($actor);$before=fe_snapshot();$reached=false;$ae_hook=function($sql)use(&$reached){if(strpos($sql,'DELETE FROM fixture_search_wordmatch')!==0){return;}$GLOBALS['ae_hook']=null;$reached=true;ae_sql('KILL CONNECTION '.(int)mysqli_thread_id($GLOBALS['ae_writer']->db_connect_id));};ae_check(fe_submit()==='error'&&$reached&&fe_snapshot()===$before,'Real disconnect restores full edited state');$cases++;
  echo $actor." native full-editor content/poll/audit and authority checks passed\n";
 }
 foreach(array('case','foreign','logout','inactive','session') as $kind){fe_fixture();ae_sql(ae_revoke($kind));$before=fe_snapshot();ae_check(fe_submit()==='error'&&fe_snapshot()===$before,'Fresh exact actor '.$kind);$cases++;}
 fe_fixture();ae_insert('fixture_vote_results',array('vote_id'=>1,'vote_option_id'=>3,'vote_option_text'=>'Ambiguous legacy option','vote_result'=>5));$before=fe_snapshot();ae_check(fe_submit()==='error'&&fe_snapshot()===$before,'Ambiguous legacy option IDs never silently rewrite historical votes');$cases++;
 fe_fixture();ae_sql('UPDATE fixture_vote_results SET vote_result=4 WHERE vote_option_id=2');$before=fe_snapshot();ae_check(fe_submit()==='error'&&fe_snapshot()===$before,'Member cannot alter voted poll');$cases++;
 fe_fixture('moderator');ae_sql('UPDATE fixture_vote_results SET vote_result=4 WHERE vote_option_id=2');ae_check(is_array(fe_submit())&&(int)ae_rows('SELECT vote_result FROM fixture_vote_results WHERE vote_option_id=2')[0]['vote_result']===4,'Moderator changes keep retained option votes');$cases++;
 foreach($ae_tables as $s){fe_fixture();ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=fe_snapshot();ae_check(fe_submit()==='error'&&fe_snapshot()===$before,'Legacy participant refused '.$s);ae_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 }
 // Additional end-to-end cases can run alone while the failure matrix finishes.
 foreach(array('invalid',0,array(10)) as $invalid){fe_fixture();$before=fe_snapshot();ae_check(fe_submit(array('post'=>$invalid))==='error'&&fe_snapshot()===$before,'Invalid scope keeps every row');ae_check(is_array(fe_submit()),'Invalid scope releases writer immediately for next request');$cases++;}
 fe_fixture();ae_sql('UPDATE fixture_forums SET auth_sticky=1,auth_global_announce=5');$before=fe_snapshot();ae_check(fe_submit(array('type'=>POST_GLOBAL_ANNOUNCE))==='error'&&fe_snapshot()===$before,'Sticky permission never grants global announcement permission');$cases++;
 fe_fixture();ae_sql('UPDATE fixture_forums SET auth_news=5,auth_cal=5,auth_announce=5');ae_sql('UPDATE fixture_topics SET topic_type=2,topic_announce_duration=123,topic_calendar_time=1234567890,topic_calendar_duration=42');$out=fe_submit(array('type'=>POST_ANNOUNCE));$row=ae_rows('SELECT * FROM fixture_topics WHERE topic_id=100')[0];ae_check(is_array($out)&&(int)$row['topic_announce_duration']===123&&(int)$row['topic_calendar_time']===1234567890&&(int)$row['topic_calendar_duration']===42,'Unavailable controls preserve existing restricted metadata during body edit');$cases++;
 fe_fixture();ae_sql('UPDATE fixture_forums SET auth_news=5');ae_sql('UPDATE fixture_topics SET topic_type=4,news_id=7');$out=fe_submit(array('type'=>POST_NEWS));ae_check(is_array($out)&&(int)ae_rows('SELECT news_id FROM fixture_topics WHERE topic_id=100')[0]['news_id']===7,'Hidden news selector never removes existing news category');$cases++;
 fe_fixture();$before=fe_snapshot();$out=fe_submit(array('post'=>20));$after=fe_snapshot();ae_check(is_array($out)&&$before['topics']===$after['topics']&&$before['vote_desc']===$after['vote_desc']&&$before['vote_results']===$after['vote_results'],'Reply editing never changes first-topic metadata or poll');$cases++;
 foreach(array('english','german') as $locale){require $phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';foreach(array('success','failure','ack') as $kind){
  fe_fixture('moderator');$before=fe_snapshot();if($kind==='failure'){$ae_fail='INSERT INTO fixture_logs';}if($kind==='ack'){$ae_ack=true;}$out=fe_submit(array('controller'=>true));ae_check(($kind==='success')===is_array($out),'Actual controller returns truthful localized result '.$locale.'/'.$kind);$after=fe_snapshot();if($kind==='failure'){ae_check($after===$before,'Actual controller includes audit failure rollback');}else{ae_check(count($after['logs'])===1,'Controller must not duplicate worker audit');$ae_ack=false;$ae_fail='';ae_check(is_array(fe_submit(array('controller'=>true,'uid'=>'9876543210')))&&fe_snapshot()===$after,'Actual controller retry/no-op does not create another log or edit');}$cases++;
 }}
 echo 'Native full editor: '.$cases.' failure/authority/storage cases, '.$serialized." serialized changes; content, options, index, counters and audit passed.\n";
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
