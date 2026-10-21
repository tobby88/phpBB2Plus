<?php
$kp_write_mode = true;
function kw_input() { return array('title'=>"Synthetic Größe 😀 O'Reilly",'description'=>'Exact UTF8 description','body'=>'Synthetic searchable article body','username'=>'Guest identity','category'=>'2','type'=>'1','html_on'=>false,'bbcode_on'=>false,'smilies_on'=>false); }
function kw_reset($actor='root',$comments=false,$notify='0') {
 kp_reset($actor,$comments);
 foreach(array('allow_new'=>'1','allow_edit'=>'1','allow_anon'=>'0','approve_new'=>'1','approve_edit'=>'1','bump_post'=>'1','notify'=>$notify)as$key=>$value){kp_sql("INSERT INTO fixture_kb_config VALUES('".$key."','".$value."')");}
 if($actor==='ordinary'){kp_sql('UPDATE fixture_kb_articles SET article_author_id=4');}
}
function kw_run($mode='add',$input=null,$id=1) {
 try{return kb_write_article($GLOBALS['db'],$mode,$id,$input===null?kw_input():$input);}catch(PhpbbKbPublicationException $error){return $error;}
}
function kw_ok($out,$message){kp_check(is_array($out),$message.': '.($out instanceof Exception?$out->getMessage():'unexpected'));return $out;}
function kw_controller($mode,$valid_sid=true,$method='POST') {
 global $db,$userdata,$kb_config,$lang,$phpbb_root_path,$phpEx;
 $source=file_get_contents($GLOBALS['kp_source'].'includes/kb_'.$mode.'.php');
 $start=strpos($source,$mode==='add'?'// Submit a new article through':'// Save the existing article through');kp_check($start!==false,'Actual new action boundary');
 $body=str_replace('dirname(__FILE__)',var_export($GLOBALS['kp_source'].'includes',true),substr($source,$start));$body=preg_replace('/\?>\s*$/D','',$body);
 $input=kw_input();$article_submit=true;$html_on=$bbcode_on=$smilies_on=false;
 $_GET=array('k'=>'1','cat'=>'2');$_POST=array('article_submit'=>'1','article_name'=>$input['title'],'article_desc'=>$input['description'],'message'=>$input['body'],'type_id'=>'1','category_id'=>'2','cat'=>'2','sid'=>$valid_sid?$userdata['session_id']:'wrong');$_SERVER['REQUEST_METHOD']=$method;
 try{eval($body);}catch(KpMessageExit $error){return $error;}throw new RuntimeException('Actual write controller did not report completion');
}
function kw_existing_fixture($scenario) {
 global $userdata;
 kw_reset('root',$scenario!=='move',$scenario==='review'?'1':'0');kw_ok(kw_run('edit'),'Seed approved existing article/discussion for '.$scenario);
 if($scenario==='review'){$userdata['user_id']=4;kp_sql('UPDATE fixture_sessions SET session_user_id=4');}
 if($scenario==='quiet'){kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='bump_post'");}
 $input=kw_input();$input['title']='Current Changedword';$input['description']='New Butterflytoken';
 if($scenario==='move'){kp_sql("INSERT INTO fixture_kb_categories(category_id,category_name,category_details,number_articles,parent,cat_order) VALUES(3,'Destination','Preserve',0,1,2)");$input['category']='3';}
 return $input;
}
function kw_edit_entry($admin,$allowed,$method='GET',$preview=false) {
 global$db,$userdata,$kb_config,$lang,$phpbb_root_path,$phpEx;
 static$body=null;if($body===null){$source=file_get_contents($GLOBALS['kp_source'].'includes/kb_edit.php');$a=strpos($source,'$article_id =');$b=strpos($source,'// main / preview',$a);kp_check($a!==false&&$b>$a,'Complete actual editor entry and permission/token gates');$body=substr($source,$a,$b-$a);}
 $is_admin=$admin;$kb_config['allow_edit']=$allowed;$_GET=array('k'=>'1');$_POST=array('sid'=>$userdata['session_id']);if($preview){$_POST['preview']='1';}$_SERVER['REQUEST_METHOD']=$method;
 try{eval($body);}catch(KpMessageExit$error){return$error;}return true;
}
function kw_edit_policy_suite() {
 global$kp_source,$kp_hook,$userdata,$kb_config;
 $cases=0;
 foreach(array(false,true)as$comments){foreach(array('root','moderator','ordinary','junior')as$actor){foreach(array('0','1')as$allowed){
  kw_reset($actor,$comments);if($actor==='junior'){kp_sql('UPDATE fixture_kb_articles SET article_author_id=2');}kp_sql("UPDATE fixture_kb_config SET config_value='".$allowed."' WHERE config_name='allow_edit'");
  $before=kp_snapshot();$out=kw_run('edit');$accept=$allowed==='1'||in_array($actor,array('root','moderator'),true);
  if($accept){kw_ok($out,'Current editing policy retains existing moderator exception');}else{kp_check($out instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Disabled editing denies even actual owner or delegated article admin, with no effects');}$cases++;
 }}}
 foreach(array('2','invalid','missing')as$policy){kw_reset();if($policy==='missing'){kp_sql("DELETE FROM fixture_kb_config WHERE config_name='allow_edit'");}else{kp_sql("UPDATE fixture_kb_config SET config_value='".$policy."' WHERE config_name='allow_edit'");}$before=kp_snapshot();kp_check(kw_run('edit') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Missing/malformed stored edit policy never permits writes');$cases++;}
 foreach(array('GET','POST')as$method){foreach(array(false,true)as$preview){foreach(array('0','1')as$allowed){kw_reset('ordinary');$before=kp_snapshot();$out=kw_edit_entry(false,$allowed,$method,$preview);$accept=$allowed==='1'&&(!$preview||$method==='POST');kp_check(($accept?$out===true:$out instanceof KpMessageExit)&&kp_snapshot()===$before,'Actual editor GET/preview entry honors edit policy and POST token without writes');$cases++;}}}
 foreach(array('root','moderator')as$actor){kw_reset($actor);$before=kp_snapshot();kp_check(kw_edit_entry(true,'0')===true&&kp_snapshot()===$before,'Actual editor entry retains existing KB administrator/moderator policy exception');$cases++;}
 require$kp_source.'includes/functions_kb_config_storage.php';
 foreach(array(false,true)as$comments){
  kw_reset('ordinary',$comments);$seed=file_get_contents($kp_source.'install/schemas/mysql_basic.sql');preg_match_all('/^INSERT INTO phpbb_kb_config\b[^\r\n]*;/m',$seed,$matches);kp_check(count($matches[0])===26,'Canonical settings for actual config-policy change');foreach($matches[0]as$sql){kp_sql(str_replace(array('INSERT INTO phpbb_kb_config'),array('INSERT IGNORE INTO fixture_kb_config'),$sql));}
  $before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use(&$reached){
   if($event!=='before'||strpos($sql,'SELECT GET_LOCK(')!==0){return;}$GLOBALS['kp_hook']=null;$reached=true;$old=$GLOBALS['userdata'];
   try{$GLOBALS['userdata']['user_id']=1;kp_sql('UPDATE fixture_sessions SET session_user_id=1');$result=kb_save_config($GLOBALS['kp_caller'],array('allow_edit'=>'0'));kp_check(is_array($result)&&$result['allow_edit']==='0','Actual independent configuration owner disables editing before editor owns its transaction');}
   finally{$GLOBALS['userdata']=$old;kp_sql('UPDATE fixture_sessions SET session_user_id=4');}
  };
  $kb_config['allow_edit']='1';$out=kw_controller('edit');$kp_hook=null;$after=kp_snapshot();unset($before[KB_CONFIG_TABLE],$after[KB_CONFIG_TABLE]);
  kp_check($reached&&$out->level===GENERAL_ERROR&&$after===$before&&kp_rows("SELECT config_value FROM fixture_kb_config WHERE config_name='allow_edit'")===array(array('config_value'=>'0')),'Actual stale cached editor cannot bypass separately committed current config change; article/counters/index/PM/discussion remain unchanged');$cases++;
 }
 return$cases;
}
function kp_write_suite() {
 global $kp_source,$kp_hook,$userdata,$lang;require $kp_source.'includes/functions_kb_write_storage.php';$cases=0;
 foreach(array('root','moderator','ordinary','junior')as$actor){foreach(array(false,true)as$comments){
  kw_reset($actor,$comments);$before_config=$GLOBALS['kb_config'];$before_db=$GLOBALS['db'];$out=kw_ok(kw_run(),'Actual add '.$actor);
  $approved=in_array($actor,array('root','moderator'),true)?1:0;
  kp_check((int)$out['approved']===$approved&&kp_counts($approved),'Add approval/current authority category policy');
  $rows=kp_rows('SELECT article_title,article_body,username,article_author_id,topic_id FROM fixture_kb_articles WHERE article_id='.$out['article_id']);
  kp_check(count($rows)===1&&$rows[0]['article_title']===kw_input()['title']&&$rows[0]['article_body']===kw_input()['body'],'Exact quote/Unicode body persistence');
  kp_check(count(kp_rows('SELECT * FROM fixture_topics'))===($approved&&$comments?1:0),'Only approved configured additions create discussions');
  if($approved&&$comments){kp_check((int)$rows[0]['topic_id']>0,'Created discussion relation saved');}
  kp_check($GLOBALS['db']===$before_db&&$GLOBALS['kb_config']===$before_config,'Caller connection/config restored');$cases++;
 }}
 foreach(array('root','moderator','ordinary')as$actor){foreach(array(false,true)as$comments){
  kw_reset($actor,$comments);$out=kw_ok(kw_run('edit'),'Actual edit '.$actor);$approved=$actor==='ordinary'?2:1;
  kp_check((int)$out['approved']===$approved&&kp_counts($approved===1?1:0),'Edit review policy/category counters');$cases++;
 }}
 kw_reset('ordinary',false,'1');$out=kw_ok(kw_run(),'Pending article with atomic PM');
 $pm=kp_rows('SELECT privmsgs_type,privmsgs_subject FROM fixture_privmsgs');$text=kp_rows('SELECT privmsgs_text FROM fixture_privmsgs_text');
 kp_check(count($pm)===1&&(int)$pm[0]['privmsgs_type']===PRIVMSGS_NEW_MAIL&&count($text)===1&&strpos($text[0]['privmsgs_text'],kw_input()['title'])!==false,'Pending notification uses this exact article, not a latest unrelated edited article');
 kp_check(kp_rows('SELECT user_new_privmsg FROM fixture_users WHERE user_id=1')===array(array('user_new_privmsg'=>'1')),'PM count matches published current new messages');$cases++;
 kw_reset('ordinary',false,'1');$input=kw_input();$input['title']='[img]https://pixel.example.invalid/owned.png[/img]';$input['description']='[url=https://link.example.invalid/]Unreviewed label[/url]';kw_ok(kw_run('add',$input),'Pending metadata notification');
 $text=kp_rows('SELECT privmsgs_text FROM fixture_privmsgs_text');kp_check(count($text)===1&&strpos($text[0]['privmsgs_text'],'[img:')===false&&strpos($text[0]['privmsgs_text'],'&#91;img&#93;')!==false&&strpos($text[0]['privmsgs_text'],'&#91;url=')!==false,'Unreviewed title/description are literal notification metadata, not active remote images or user-defined BBCode links');$cases++;
 kw_reset('ordinary',false,'2');$out=kw_ok(kw_run(),'Deferred email outcome');kp_check($out['notify_email']&&!kp_rows('SELECT * FROM fixture_privmsgs'),'Email outcome performs no transport before/inside transaction');$cases++;
 foreach(array('add','edit')as$mode){
  kw_reset();$before=kp_snapshot();kp_sql("CREATE TRIGGER kw_fail BEFORE ".($mode==='add'?'INSERT':'UPDATE')." ON fixture_kb_articles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic article failure'");
  kp_check(kw_run($mode) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Actual server article mutation failure preserves all data/counters');kp_sql('DROP TRIGGER kw_fail');$cases++;
 }
 foreach(array(array('root',true,'0','add'),array('root',true,'0','edit'),array('ordinary',false,'1','add'))as$scenario){
  kw_reset($scenario[0],$scenario[1],$scenario[2]);$mutations=0;$kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};
  kw_ok(kw_run($scenario[3]),'Collect exact write mutation inventory');$kp_hook=null;$total=$mutations;kp_check($total>0,'Actual write mutations observed');
  for($fail=1;$fail<=$total;$fail++){
   kw_reset($scenario[0],$scenario[1],$scenario[2]);$before=kp_snapshot();$seen=0;$kp_hook=function($event,$sql)use(&$seen,$fail){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return ++$seen===$fail?false:null;}};
   $out=kw_run($scenario[3]);$kp_hook=null;kp_check($out instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every actual add/edit/index/discussion/PM mutation failure rolls back the complete owned state');$cases++;
  }
 }
 foreach(array('before','after')as$boundary){
  kw_reset('root',true);$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($boundary,&$reached){if($sql==='COMMIT'&&$event===$boundary){$reached=true;return false;}};
  $out=kw_run();$kp_hook=null;kp_check($reached&&$out instanceof PhpbbKbPublicationException,'Missing commit acknowledgement is not reported successful');
  kp_check($boundary==='before'?kp_snapshot()===$before:(kp_counts(1)&&count(kp_rows('SELECT * FROM fixture_topics'))===1),'Only actual commit determines durable state, not the transport acknowledgement');$cases++;
 }
 foreach(array(array('root','UPDATE fixture_users SET user_active=0 WHERE user_id=1'),array('moderator','DELETE FROM fixture_user_group WHERE user_id=3'),array('ordinary','DELETE FROM fixture_sessions'))as$actor){
  kw_reset($actor[0]);$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($actor,&$reached){if($event==='before'&&preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql)){$GLOBALS['kp_hook']=null;$reached=true;kp_sql($actor[1]);}};
  $out=kw_run();$kp_hook=null;kp_check($reached&&$out instanceof PhpbbKbPublicationException,'Revoked current account/group/session cannot publish using stale approval authority');$after=kp_snapshot();unset($before[USERS_TABLE],$before[USER_GROUP_TABLE],$before[SESSIONS_TABLE],$after[USERS_TABLE],$after[USER_GROUP_TABLE],$after[SESSIONS_TABLE]);kp_check($before===$after,'Revocation rollback keeps only independent revocation');$cases++;
 }
 kw_reset('root',true);$out=kw_ok(kw_run('edit'),'Create missing discussion during edit');$first=kp_rows('SELECT topic_id FROM fixture_kb_articles WHERE article_id=1');
 kp_check((int)$first[0]['topic_id']>0,'Edit saves newly created discussion ID');
 $input=kw_input();$input['title']='Changed Zebraword';$input['description']='Changed Butterflytoken';
 kw_ok(kw_run('edit',$input),'Repeat edit uses stored discussion');kp_check(count(kp_rows('SELECT * FROM fixture_topics'))===1&&kp_rows('SELECT topic_id FROM fixture_kb_articles WHERE article_id=1')===$first,'Repeat edit cannot orphan/duplicate its discussion');$cases++;
 kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name='bump_post'");$posts_before=count(kp_rows('SELECT * FROM fixture_posts'));$input['title']='Quietedit Searchneedle';kw_ok(kw_run('edit',$input),'No-bump edit');
 $matches=kp_rows("SELECT m.title_match FROM fixture_search_wordmatch m INNER JOIN fixture_search_wordlist w ON w.word_id=m.word_id INNER JOIN fixture_topics t ON t.topic_first_post_id=m.post_id WHERE w.word_text='searchneedle' ORDER BY m.title_match");
 kp_check(count(kp_rows('SELECT * FROM fixture_posts'))===$posts_before&&$matches===array(array('title_match'=>'0'),array('title_match'=>'1')),'No-bump edit indexes changed title AND quoted body of the first post without a new reply: '.json_encode($matches));$cases++;
 kw_reset('ordinary');kp_sql('UPDATE fixture_users SET user_level=2 WHERE user_id=4');kp_check((int)kw_ok(kw_run(),'Forum moderator add policy')['approved']===1,'Existing forum-MOD add autoapproval remains separate from KB edit authority');$cases++;
 kw_reset('ordinary');kp_sql('UPDATE fixture_kb_articles SET article_author_id=1');$before=kp_snapshot();kp_check(kw_run('edit') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Ordinary user cannot edit another author');$cases++;
 kw_reset();$userdata=array('user_id'=>ANONYMOUS,'session_id'=>str_repeat('b',32),'session_logged_in'=>false);kp_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_admin) VALUES('".$userdata['session_id']."',-1,0,0)");$_POST['sid']=$userdata['session_id'];
 $before=kp_snapshot();kp_check(kw_run() instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Disabled anonymous additions fail closed');kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='allow_anon'");
 $out=kw_ok(kw_run(),'Explicitly permitted guest add');$rows=kp_rows('SELECT article_author_id,username FROM fixture_kb_articles WHERE article_id='.$out['article_id']);kp_check($rows===array(array('article_author_id'=>'0','username'=>'Guest identity'))&&$out['approved']===0,'Guest retains author0 and current moderation requirement');$cases++;
 kw_reset('root',true);kp_sql("UPDATE fixture_kb_config SET config_value='0' WHERE config_name IN ('allow_anon','approve_new')");kp_sql("UPDATE fixture_kb_config SET config_value='1' WHERE config_name='allow_anon'");
 $userdata=array('user_id'=>ANONYMOUS,'session_id'=>str_repeat('b',32),'session_logged_in'=>false);kp_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_admin) VALUES('".$userdata['session_id']."',-1,0,0)");$_POST['sid']=$userdata['session_id'];
 $out=kw_ok(kw_run(),'Explicit autoapproved guest discussion');kp_check($out['approved']===1&&kp_counts(1)&&kp_rows('SELECT poster_id,post_username FROM fixture_posts')===array(array('poster_id'=>'-1','post_username'=>'Guest identity')),'Guest article and discussion keep their distinct canonical guest author IDs');$cases++;
 foreach(array('add','edit')as$mode){foreach(array(array(false,'POST'),array(true,'GET'),array(true,'POST'))as$request){
  kw_reset();$before=kp_snapshot();$out=kw_controller($mode,$request[0],$request[1]);
  if($request[0]&&$request[1]==='POST'){kp_check($out->level===GENERAL_MESSAGE&&kp_counts(1),'Actual new controller confirms saved article');}else{kp_check($out->level===GENERAL_ERROR&&kp_snapshot()===$before,'Actual new controller refuses wrong token/method without effects');}$cases++;
 }}
 foreach(array('before-first-write','before-actor-lock','before-commit')as$boundary){
  kw_reset();kp_sql('UPDATE fixture_kb_articles SET article_author_id=1');$before=kp_snapshot();$reached=$blocked=false;
  $kp_hook=function($event,$sql)use($boundary,&$reached,&$blocked){
   $match=$boundary==='before-first-write'?preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql):($boundary==='before-commit'?$sql==='COMMIT':strpos($sql,'SELECT session_id FROM fixture_sessions ')===0&&substr($sql,-18)==='LOCK IN SHARE MODE');
   if($event!=='before'||!$match){return;}$GLOBALS['kp_hook']=null;$reached=true;kp_sql('SET SESSION innodb_lock_wait_timeout=0');$out=$GLOBALS['peer']->sql_query('UPDATE fixture_users SET user_level=0 WHERE user_id=1');if(!$out){$error=$GLOBALS['peer']->sql_error();kp_check((int)$error['code']===1205,'Only real authority locking serializes role revocation');$blocked=true;}kp_sql('SET SESSION innodb_lock_wait_timeout=1');
  };
  $out=kw_run('edit');$kp_hook=null;kp_check($reached,'Owner-author approval authority race reached');
  if($blocked){kp_check(is_array($out)&&kp_counts(1),'Pinned approval privilege serializes final commit before revocation');}else{$after=kp_snapshot();unset($before[USERS_TABLE],$after[USERS_TABLE]);kp_check($out instanceof PhpbbKbPublicationException&&$after===$before,'Author who loses KB privilege cannot retain stale immediate approval even while retaining ordinary edit permission');}$cases++;
 }
 foreach(array('shared','redirect','foreign-first-post')as$relation){
  kw_reset('root',true);kw_ok(kw_run('edit'),'Seed bound discussion');$topic=kp_rows('SELECT topic_id FROM fixture_kb_articles WHERE article_id=1');$topic=(int)$topic[0]['topic_id'];
  if($relation==='shared'){kp_sql("INSERT INTO fixture_kb_articles(article_id,article_category_id,article_title,article_body,topic_id) VALUES(999,2,'Other','Preserve',".$topic.')');}elseif($relation==='redirect'){kp_sql('UPDATE fixture_topics SET topic_moved_id=999 WHERE topic_id='.$topic);}else{kp_sql('UPDATE fixture_posts SET forum_id=999 WHERE topic_id='.$topic);}
  $before=kp_snapshot();kp_check(kw_run('edit') instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Ambiguous stored topic refuses all article/discussion/index effects');$cases++;
 }
 foreach(array(array('category','2 trailing'),array('type',array('1')),array('body',"bad\xff"),array('title',true),array('html_on','1'))as$bad){kw_reset();$input=kw_input();$input[$bad[0]]=$bad[1];$before=kp_snapshot();kp_check(kw_run('add',$input) instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Complete typed UTF8 input validation, no partial SQL identity parsing');$cases++;}
 foreach(array('missing','borrow')as$factory){kw_reset();$GLOBALS['kp_factory']=$factory;$before=kp_snapshot();kp_check(kw_run() instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Broken dedicated connection factory cannot write/commit/close caller');$cases++;}
 kw_reset();kp_sql('ALTER TABLE fixture_privmsgs ENGINE=MyISAM');$before=kp_snapshot();kp_check(kw_run() instanceof PhpbbKbPublicationException&&kp_snapshot()===$before,'Nontransactional PM participant refuses all publication, even when current notifications disabled');kp_sql('ALTER TABLE fixture_privmsgs ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;
 kw_reset();kp_sql('CREATE TABLE fixture_write_caller(marker INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 kp_check($GLOBALS['db']->sql_query('START TRANSACTION')&&$GLOBALS['db']->sql_query('INSERT INTO fixture_write_caller VALUES(7)'),'Independent caller work');kw_ok(kw_run(),'Owned add preserves caller transaction');kp_check(!kp_rows('SELECT * FROM fixture_write_caller'),'Article commit never commits caller pending work');kp_check($GLOBALS['db']->sql_query('ROLLBACK')&&kp_counts(1),'Caller rollback cannot undo durable article');kp_sql('DROP TABLE fixture_write_caller');$cases++;
 foreach(array('bump','quiet','review','move')as$scenario){
  $input=kw_existing_fixture($scenario);$mutations=0;$kp_hook=function($event,$sql)use(&$mutations){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){$mutations++;}};
  kw_ok(kw_run('edit',$input),'Existing article update '.$scenario);$kp_hook=null;$total=$mutations;kp_check($total>0,'Existing update DML inventory');
  if($scenario==='move'){kp_check(kp_rows('SELECT category_id,number_articles FROM fixture_kb_categories ORDER BY category_id')===array(array('category_id'=>'1','number_articles'=>'1'),array('category_id'=>'2','number_articles'=>'0'),array('category_id'=>'3','number_articles'=>'1')),'Approved move transfers exactly one count and preserves shared ancestor');}
  if($scenario==='review'){kp_check(kp_counts(0)&&!kp_rows('SELECT * FROM fixture_kb_wordmatch')&&count(kp_rows('SELECT * FROM fixture_privmsgs'))===1&&count(kp_rows('SELECT * FROM fixture_topics'))===1,'Pending edit removes public KB index/count but preserves existing discussion and publishes exact notification');}
  $cases++;
  for($fail=1;$fail<=$total;$fail++){
   $input=kw_existing_fixture($scenario);$before=kp_snapshot();$seen=0;$kp_hook=function($event,$sql)use(&$seen,$fail){if($event==='before'&&preg_match('/^\s*(UPDATE|INSERT|DELETE)\b/i',$sql)){return ++$seen===$fail?false:null;}};
   $out=kw_run('edit',$input);$kp_hook=null;kp_check($out instanceof PhpbbKbPublicationException&&$seen===$fail&&kp_snapshot()===$before,'Every existing '.$scenario.' write/index/PM/count failure fully restores approved old data');$cases++;
  }
 }
 $input=kw_existing_fixture('move');$reached=false;$kp_hook=function($event,$sql)use(&$reached,$input){if($event==='before'&&strpos($sql,'SELECT GET_LOCK(')===0){$GLOBALS['kp_hook']=null;$reached=true;kw_ok(kw_run('edit',$input),'First interleaved actual editor');}};
 $out=kw_run('edit');$kp_hook=null;kw_ok($out,'Second editor after independent commit');kp_check($reached&&kp_rows('SELECT category_id,number_articles FROM fixture_kb_categories ORDER BY category_id')===array(array('category_id'=>'1','number_articles'=>'1'),array('category_id'=>'2','number_articles'=>'1'),array('category_id'=>'3','number_articles'=>'0')),'Second edit reloads committed current category, not a cached row, and cannot double-count');$cases++;
 foreach(array(false,true)as$bbcode){
  kw_reset();$input=kw_input();$input['bbcode_on']=$bbcode;$input['body']="[b]Größe 😀 O'Reilly[/b] <script>not executable</script>";$out=kw_ok(kw_run('edit',$input),'UTF8/BBCode content');$rows=kp_rows('SELECT article_body,bbcode_uid FROM fixture_kb_articles WHERE article_id=1');
  kp_check(strpos($rows[0]['article_body'],'Größe 😀 O\'Reilly')!==false&&strpos($rows[0]['article_body'],'<script>')===false,'UTF8/quotes preserved, disallowed HTML escaped');
  kp_check($bbcode?(strlen($rows[0]['bbcode_uid'])===BBCODE_UID_LEN&&strpos($rows[0]['article_body'],'[b:'.$rows[0]['bbcode_uid'].']')!==false):($rows[0]['bbcode_uid']===''&&strpos($rows[0]['article_body'],'[b]')!==false),'BBCode parsed once only under current selected formatting');$cases++;
 }
 $original_lang=$lang;
 foreach(array('english','german')as$locale){
  require $kp_source.'language/lang_'.$locale.'/lang_kb.php';kw_reset();$input=kw_input();$input['type']='invalid';$before=kp_snapshot();$out=kw_run('add',$input);
  kp_check($out instanceof PhpbbKbPublicationException&&$out->getMessage()===$lang['KB_write_invalid']&&preg_match('//u',$out->getMessage())===1&&kp_snapshot()===$before,'Available UTF8-localized input refusal in '.$locale);
  kp_check(kw_controller('add')->level===GENERAL_MESSAGE,'Actual successful localized controller in '.$locale);$cases++;
 }
 $lang=$original_lang;
 $cases+=kw_edit_policy_suite();
 echo 'KB add/edit native: '.$cases." actual authority/fault/PM/discussion/index/guest/controller/locale/edit-policy cases passed.\n";
}
require __DIR__.'/check-kb-publication-native.php';
