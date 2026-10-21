<?php
// Canonical random disposable schema; real authorizer, queries, renderer and
// controller fragment. No production accounts/content, external requests or mail.
$kp_comment_mode=true;
class KcvTemplate {
 var $blocks=array();
 function assign_block_vars($name,$values){$this->blocks[$name][]=$values;}
}
class KcvReads {
 var $connection;var $queries=array();var $fail='';
 function __construct($connection){$this->connection=$connection;}
 function __call($name,$args){return call_user_func_array(array($this->connection,$name),$args);}
 function sql_query($sql,$transaction=false){
  kp_check(preg_match('/^SELECT /D',$sql)===1,'Comment display performs SELECT only');$this->queries[]=$sql;
  if($this->fail!==''&&strpos($sql,$this->fail)!==false){return false;}
  return$this->connection->sql_query($sql,$transaction);
 }
}
function create_date($format,$time,$zone){return 'Synthetic date';}
function kcv_seed($actor,$view,$read){
 global$db,$peer,$userdata,$tree,$board_config,$template;
 kp_reset($actor,true);
 kp_sql('UPDATE fixture_forums SET auth_view='.(int)$view.',auth_read='.(int)$read);
 kp_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_title,topic_first_post_id,topic_last_post_id,topic_replies) VALUES(77,1,'Synthetic discussion',1,2,1)");
 kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip,enable_bbcode,enable_html,enable_smilies) VALUES(1,77,1,4,123,'7f000001',0,0,0),(2,77,1,4,124,'7f000001',0,0,0)");
 kp_sql("INSERT INTO fixture_posts_text(post_id,post_subject,post_text,bbcode_uid) VALUES(1,'Summary','Synthetic first post',''),(2,'Private subject','LOCAL_ONLY_PRIVATE_COMMENT_MARKER Grüße','')");
 // Deliberately stale public tree and ADMIN session role. Only fresh SQL grants
 // and the current account/session are allowed to decide forum access.
 $tree=array('data'=>array(array('forum_id'=>1,'auth_view'=>AUTH_ALL,'auth_read'=>AUTH_ALL)),'keys'=>array('f1'=>0),'type'=>array('f'));
 $board_config=array('default_dateformat'=>'Y','board_timezone'=>0,'allow_html'=>0,'allow_bbcode'=>0,'allow_smilies'=>0);
 $template=new KcvTemplate();$db=new KcvReads($GLOBALS['kp_caller']);
 if($actor==='ordinary'){$userdata['user_level']=ADMIN;}
}
function kcv_guest(){global$userdata;$userdata=array('user_id'=>ANONYMOUS,'user_level'=>USER,'session_logged_in'=>false);}
function kcv_controller($topic_id){
 global$db,$template,$kp_source,$kb_config,$start,$article_id,$lang,$phpbb_root_path,$phpEx;
 $source=file_get_contents($kp_source.'includes/kb_article.php');$a=strpos($source,"\$topic=array();\$comments='';");$b=strpos($source,'//rate',$a);
 kp_check($a!==false&&$b>$a,'Complete actual comment link/count/renderer controller branch');
 $article_id=1;$start=0;eval(substr($source,$a,$b-$a));return array($comments,$topic);
}
function kcv_assert($allowed,$label){
 global$db,$template,$kb_config;
 $before=kp_snapshot();$kb_config['comments']=1;$kb_config['comments_show']=1;$kb_config['comments_pagination']=10;
 $topic=kb_comment_topic(77);kp_check((bool)$topic===$allowed,'Current forum ACL: '.$label);
 $template=new KcvTemplate();get_kb_comments(77,0,10);
 kp_check(isset($template->blocks['postrow'])===$allowed,'Actual renderer visibility: '.$label);
 if($allowed){kp_check(count($template->blocks['postrow'])===1&&strpos($template->blocks['postrow'][0]['MESSAGE'],'LOCAL_ONLY_PRIVATE_COMMENT_MARKER Grüße')!==false,'Authorized reply appears exactly once');}
 $template=new KcvTemplate();$display=kcv_controller(77);
 kp_check(($display[0]!=='')===$allowed&&isset($template->blocks['switch_comments'])===$allowed&&isset($template->blocks['postrow'])===$allowed,'Actual count/link/embedded controller respects forum access: '.$label);
 if(!$allowed){foreach($db->queries as$sql){kp_check(strpos($sql,'pt.post_text')===false,'Denied reader never queries comment bodies');}}
 kp_check(kp_snapshot()===$before,'Comment display preserves every canonical table: '.$label);
}
function kp_comment_suite(){
 global$db,$peer,$kp_source,$userdata,$template,$lang,$kb_config,$tree;
 require$kp_source.'includes/auth.php';require$kp_source.'attach_mod/includes/functions_includes.php';
 $source=file_get_contents($kp_source.'includes/functions.php');
 foreach(array('phpbb_profile_text','generate_pagination')as$name){kp_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$match)===1,'Actual helper '.$name);eval($match[0]);}
 $cases=0;
 foreach(array('english','german')as$locale){require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_kb.php';
  foreach(array(array(AUTH_ALL,AUTH_ALL,true),array(AUTH_ALL,AUTH_REG,false),array(AUTH_REG,AUTH_ALL,false),array(AUTH_ACL,AUTH_ACL,false),array(AUTH_ADMIN,AUTH_ADMIN,false))as$case){kcv_seed('ordinary',$case[0],$case[1]);kcv_guest();kcv_assert($case[2],'guest '.$locale.' '.$case[0].'/'.$case[1]);$cases++;}
  foreach(array(array(AUTH_ALL,AUTH_ALL,true),array(AUTH_REG,AUTH_REG,true),array(AUTH_ACL,AUTH_ACL,false),array(AUTH_MOD,AUTH_MOD,false),array(AUTH_ADMIN,AUTH_ADMIN,false))as$case){kcv_seed('ordinary',$case[0],$case[1]);kcv_assert($case[2],'ordinary with stale ADMIN '.$locale.' '.$case[0]);$cases++;}
  foreach(array(AUTH_ACL,AUTH_MOD,AUTH_ADMIN)as$level){kcv_seed('root',$level,$level);kcv_assert(true,'current admin '.$locale.' '.$level);$cases++;}
  foreach(array('grant','pending','revoked','view-only','read-only','moderator')as$grant){
   kcv_seed('ordinary',AUTH_ACL,AUTH_ACL);$pending=$grant==='pending'?1:0;
   kp_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,4,'.$pending.')');
   kp_sql('INSERT INTO fixture_auth_access(group_id,forum_id,auth_view,auth_read,auth_mod) VALUES(10,1,'.($grant==='read-only'?0:1).','.($grant==='view-only'?0:1).','.($grant==='moderator'?1:0).')');
   if($grant==='revoked'){kp_sql('DELETE FROM fixture_auth_access');}
   kcv_assert(in_array($grant,array('grant','moderator'),true),'current group '.$locale.' '.$grant);$cases++;
  }
  foreach(array('demoted','inactive','missing','expired','wrong-user','logged-out','case-session')as$state){
   kcv_seed('root',AUTH_ADMIN,AUTH_ADMIN);
   if($state==='demoted'){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
   elseif($state==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
   elseif($state==='missing'){kp_sql('DELETE FROM fixture_users WHERE user_id=1');}
   elseif($state==='expired'){kp_sql('DELETE FROM fixture_sessions');}
   elseif($state==='wrong-user'){kp_sql('UPDATE fixture_sessions SET session_user_id=4');}
   elseif($state==='logged-out'){kp_sql('UPDATE fixture_sessions SET session_logged_in=0');}
   else{$userdata['session_id']=strtoupper($userdata['session_id']);}
   kcv_assert(false,'stale admin '.$locale.' '.$state);$cases++;
  }
  foreach(array('missing-topic','missing-forum','moved-id','moved-status')as$state){
   kcv_seed('root',AUTH_ALL,AUTH_ALL);
   if($state==='missing-topic'){kp_sql('DELETE FROM fixture_topics');}elseif($state==='missing-forum'){kp_sql('DELETE FROM fixture_forums');}elseif($state==='moved-id'){kp_sql('UPDATE fixture_topics SET topic_moved_id=999');}else{kp_sql('UPDATE fixture_topics SET topic_status='.TOPIC_MOVED);}
   kcv_assert(false,'unavailable relation '.$locale.' '.$state);$cases++;
  }
  foreach(array('empty','beyond-end','foreign-post-forum')as$state){
   kcv_seed('ordinary',AUTH_ALL,AUTH_ALL);kcv_guest();
   if($state==='empty'){kp_sql('DELETE FROM fixture_posts_text');kp_sql('DELETE FROM fixture_posts');}elseif($state==='foreign-post-forum'){kp_sql('UPDATE fixture_posts SET forum_id=2 WHERE post_id=2');}
   $before=kp_snapshot();$template=new KcvTemplate();get_kb_comments(77,$state==='beyond-end'?9999:0,10);
   kp_check(isset($template->blocks['postrow'])===($state==='beyond-end')&&kp_snapshot()===$before,'Empty/normalized-last/foreign reply GET stays read-only '.$state);$cases++;
  }
  kcv_seed('ordinary',AUTH_ALL,AUTH_ALL);kcv_guest();$kb_config['comments']=0;$kb_config['comments_show']=1;$before=kp_snapshot();$template=new KcvTemplate();$db->queries=array();$display=kcv_controller(77);
  kp_check($display[0]===''&&!$db->queries&&!$template->blocks&&kp_snapshot()===$before,'Comments disabled never queries or exposes a discussion');$cases++;
 }
 foreach(array(0,-1,'1e2','77foo',array(77),true,77.5,'16777216')as$bad){$db->queries=array();kp_check(kb_comment_topic($bad)===false&&!$db->queries,'Malformed references do not query a different discussion');$cases++;}
 foreach(array('FROM fixture_topics','FROM fixture_users','FROM fixture_auth_access','pt.post_text')as$fault){
  kcv_seed('root',AUTH_ACL,AUTH_ACL);$before=kp_snapshot();$db->fail=$fault;$failed=false;
  try{get_kb_comments(77,0,10);}catch(KpMessageExit$exit){$failed=true;}
  kp_check($failed&&!$template->blocks&&kp_snapshot()===$before,'Actual SQL fault cannot render unauthorized/partial comments or mutate tables');$cases++;
 }
 echo 'KB comment visibility native: '.$cases." actual guest/current group/current admin/revocation/session/cache/relation/empty-page/fault/controller/locale cases passed; SELECT only.\n";
}
require __DIR__.'/check-kb-publication-native.php';
