<?php
// Actual controller and canonical tables. Only final file/header output uses a
// controlled sink; no real config, mail, users, uploads or file bytes are used.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_NOTIFY_NATIVE')!=='1'){return;}
function pud_prepare($locale,$actor='member'){
 global$kp_source,$lang,$userdata,$tree,$pd_after_context,$pd_root_fault,$pd_factory,$kp_factory,$kp_hook;
 $pd_after_context=null;$pd_root_fault='';$pd_factory='normal';$kp_factory='normal';$kp_hook=null;kp_reset('ordinary');
 $lang=array();require$kp_source.'language/lang_'.$locale.'/lang_main.php';require$kp_source.'language/lang_'.$locale.'/lang_main_attach.php';
 $userdata['user_level']=0;pd_login($actor==='admin'?1:4);
 if($actor==='guest'){$userdata['session_logged_in']=false;$userdata['user_id']=ANONYMOUS;$userdata['user_level']=ADMIN;}
 kp_sql("INSERT INTO fixture_topics(topic_id,forum_id,topic_title,topic_poster,topic_time,topic_first_post_id,topic_last_post_id) VALUES(1,1,'Synthetic',4,123,1,1)");
 kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip) VALUES(1,1,1,4,123,'7f000001')");
 kp_sql("INSERT INTO fixture_attachments_config VALUES('disable_mod','0'),('allow_pm_attach','1')");
 kp_sql("INSERT INTO fixture_extension_groups(group_id,group_name,cat_id,allow_group,download_mode) VALUES(1,'Synthetic',0,1,".INLINE_LINK.')');
 kp_sql("INSERT INTO fixture_extensions(ext_id,group_id,extension,comment) VALUES(1,1,'bin','Synthetic')");
 kp_sql("INSERT INTO fixture_attachments_desc(attach_id,physical_filename,real_filename,extension,mimetype,filesize) VALUES(1,'synthetic.bin','Grüße 😀.bin','bin','application/octet-stream',1)");
 kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES(1,1,0,4,0)');
 $rows=kp_rows('SELECT * FROM fixture_forums WHERE forum_id=1');$tree=array('keys'=>array('f1'=>0),'data'=>array($rows[0]),'type'=>array('f'));
}
function pud_snapshot($rows){foreach($rows[ATTACHMENTS_DESC_TABLE]as&$row){unset($row['download_count']);}unset($row);return$rows;}
function pud_check($allowed,$label,$thumb=false,$cached_disabled=false){
 $before=kp_snapshot();$result=pd_route(true,$thumb,$cached_disabled);pd_check($result,$allowed,$label);
 kp_check(pud_snapshot(kp_snapshot())===pud_snapshot($before),'Public download changes only counter: '.$label);
 $count=kp_rows('SELECT download_count FROM fixture_attachments_desc WHERE attach_id=1');
 if($count){$old=$before[ATTACHMENTS_DESC_TABLE][0]['download_count'];kp_check((int)$count[0]['download_count']===(int)$old+($allowed&&!$thumb?1:0),'Only delivered full attachments increment counter: '.$label);}
 return$result;
}
function pud_revoke($kind){
 if($kind==='deleted'){kp_sql('UPDATE fixture_posts SET post_deleted=1');}
 elseif($kind==='post'){kp_sql('DELETE FROM fixture_posts');}
 elseif($kind==='topic'){kp_sql('DELETE FROM fixture_topics');}
 elseif($kind==='forum'){kp_sql('DELETE FROM fixture_forums');}
 elseif($kind==='topic-forum'){kp_sql('UPDATE fixture_topics SET forum_id=2');}
 elseif($kind==='post-topic'){kp_sql('UPDATE fixture_posts SET topic_id=2');}
 elseif($kind==='link'){kp_sql('DELETE FROM fixture_attachments');}
 elseif($kind==='link-target'){kp_sql('UPDATE fixture_attachments SET post_id=2');}
 elseif($kind==='mixed-link'){kp_sql('UPDATE fixture_attachments SET privmsgs_id=1');}
 elseif($kind==='descriptor'){kp_sql('DELETE FROM fixture_attachments_desc');}
 elseif($kind==='acl'){kp_sql('UPDATE fixture_forums SET auth_read='.AUTH_ADMIN);}
 elseif($kind==='group'){kp_sql('DELETE FROM fixture_user_group WHERE user_id=4');}
 elseif($kind==='grant'){kp_sql('DELETE FROM fixture_auth_access');}
 elseif($kind==='pending-group'){kp_sql('UPDATE fixture_user_group SET user_pending=1 WHERE user_id=4');}
 elseif($kind==='missing'){kp_sql('DELETE FROM fixture_sessions');}
 elseif($kind==='foreign'){kp_sql('UPDATE fixture_sessions SET session_user_id=2');}
 elseif($kind==='logout'){kp_sql('UPDATE fixture_sessions SET session_logged_in=0');}
 elseif($kind==='case'){kp_sql('UPDATE fixture_sessions SET session_id=UPPER(session_id)');}
 elseif($kind==='inactive'){kp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=4');}
 elseif($kind==='user'){kp_sql('DELETE FROM fixture_users WHERE user_id=4');}
 elseif($kind==='demoted'){kp_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
 elseif($kind==='disabled'){kp_sql("UPDATE fixture_attachments_config SET config_value='1' WHERE config_name='disable_mod'");}
 elseif($kind==='filename'){kp_sql("UPDATE fixture_attachments_desc SET physical_filename='../fresh.bin',real_filename='Fresh Grüße.bin'");}
 elseif($kind==='extension'){kp_sql("UPDATE fixture_attachments_desc SET extension='blocked'");}
 elseif($kind==='extension-group'){kp_sql('UPDATE fixture_extension_groups SET allow_group=0');}
 elseif($kind==='extension-missing'){kp_sql('DELETE FROM fixture_extensions');}
 elseif($kind==='physical-mode'){kp_sql('UPDATE fixture_extension_groups SET download_mode='.PHYSICAL_LINK);}
}
function pud_group(){
 kp_sql('UPDATE fixture_forums SET auth_view='.AUTH_ACL.',auth_read='.AUTH_ACL.',auth_download='.AUTH_ACL);
 kp_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(10,4,0)');
 kp_sql('INSERT INTO fixture_auth_access(group_id,forum_id,auth_view,auth_read,auth_download,auth_mod) VALUES(10,1,1,1,1,0)');
}
function pud_suite(){
 global$kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$pd_body,$userdata,$pd_after_context,$kp_hook,$db,$pd_factory,$kp_factory,$pd_busy,$pd_root_fault;
 require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';require$kp_source.'includes/functions_post_attachment_access.php';
 require_once$kp_source.'attach_mod/includes/functions_includes.php';require_once$kp_source.'includes/auth.php';
 $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();
 kp_check(in_array(AUTH_ACCESS_TABLE,$GLOBALS['kp_tables'],true),'Canonical ACL table participates in setup and cleanup');
 $source=str_replace("\r\n","\n",file_get_contents($kp_source.'download.php'));$start=strpos($source,"\$sql = 'SELECT *\n\tFROM ' . ATTACHMENTS_DESC_TABLE");kp_check($start!==false,'Actual complete download body');$pd_body=substr($source,$start);putenv('SERVER_SOFTWARE=fixture-only');$cases=$failures=0;
 if(getenv('PHPBB_PM_DOWNLOAD_MYSQL')==='8.4'){$server=kp_rows('SELECT VERSION() version,@@GLOBAL.innodb_flush_log_at_trx_commit commit_flush');kp_check(preg_match('/^8\.4\./',$server[0]['version'])&&stripos($server[0]['version'],'mariadb')===false&&(int)$server[0]['commit_flush']===1,'Actual durable MySQL required');echo "Post download MySQL 8.4: canonical server confirmed.\n";}
 foreach(array('english','german')as$locale){
  foreach(array('guest','member','admin')as$actor){foreach(array('auth_view','auth_read','auth_download')as$field){foreach(array(AUTH_ALL,AUTH_REG,AUTH_ACL,AUTH_MOD,AUTH_ADMIN,7)as$rule){
   pud_prepare($locale,$actor);kp_sql('UPDATE fixture_forums SET '.$field.'='.(int)$rule);
   $viewer=$userdata;$r=kp_rows('SELECT user_level FROM fixture_users WHERE user_id='.(int)$viewer['user_id']);$viewer['user_level']=$r?(int)$r[0]['user_level']:0;
   $rights=auth(AUTH_ALL,1,$viewer,'',$GLOBALS['peer']);$allowed=!empty($rights['auth_view'])&&!empty($rights['auth_read'])&&!empty($rights['auth_download']);
   pud_check($allowed,'Legacy/current ACL '.$locale.'/'.$actor.'/'.$field.'/'.$rule);$cases++;
  }}}
  foreach(array('grant','moderator','pending','removed','missing-grant')as$kind){pud_prepare($locale);pud_group();if($kind==='moderator'){kp_sql('UPDATE fixture_auth_access SET auth_view=0,auth_read=0,auth_download=0,auth_mod=1');kp_sql('UPDATE fixture_forums SET auth_view='.AUTH_MOD.',auth_read='.AUTH_MOD.',auth_download='.AUTH_MOD);}elseif($kind==='pending'){pud_revoke('pending-group');}elseif($kind==='removed'){pud_revoke('group');}elseif($kind==='missing-grant'){pud_revoke('grant');}pud_check(in_array($kind,array('grant','moderator'),true),'Current group membership/grants '.$kind);$cases++;}
  foreach(array('deleted','post','topic','forum','topic-forum','post-topic','link','link-target','mixed-link','descriptor','missing','foreign','logout','case','inactive','user','disabled','extension')as$kind){pud_prepare($locale);pud_revoke($kind);pud_check(false,'Missing/revoked public context '.$kind);$cases++;}
  foreach(array('deleted','acl','link','missing','foreign','logout','inactive','disabled','filename','extension','group','grant','pending-group','demoted','extension-group','extension-missing','physical-mode')as$kind){
   pud_prepare($locale,$kind==='demoted'?'admin':'member');if(in_array($kind,array('group','grant','pending-group'),true)){pud_group();}if($kind==='demoted'){kp_sql('UPDATE fixture_forums SET auth_read='.AUTH_ADMIN);}
   if($kind==='physical-mode'){kp_sql("UPDATE fixture_attachments_desc SET mimetype='image/png'");}
   $reached=false;$expected=null;$pd_after_context=function()use($kind,&$reached,&$expected){$reached=true;pud_revoke($kind);$expected=kp_snapshot();};$result=pd_route();$allowed=in_array($kind,array('filename','physical-mode'),true);pd_check($result,$allowed,'Late public change '.$kind);kp_check($reached&&pud_snapshot(kp_snapshot())===pud_snapshot($expected),'Late change persists without other data changes');if($kind==='filename'){kp_check($result->value['physical_filename']==='fresh.bin'&&$result->value['real_filename']==='Fresh Grüße.bin','Late descriptor replaces stale path/name');}if($kind==='physical-mode'){kp_check($result->kind==='redirect','Late extension mode replaces initial transport policy');}$cases++;
  }
  pud_prepare($locale);$userdata['user_level']=ADMIN;pud_revoke('extension');pud_check(false,'Cached admin never bypasses disabled extension');$cases++;
  pud_prepare($locale,'admin');pud_revoke('extension');pud_check(true,'Current admin retains disabled-extension access');$cases++;
  pud_prepare($locale,'admin');pud_revoke('disabled');pud_check(true,'Current admin retains disabled-feature access');$cases++;
  pud_prepare($locale,'admin');pud_revoke('deleted');pud_check(false,'Deleted public post is unavailable even to admin');$cases++;
  pud_prepare($locale,'guest');pud_revoke('extension');pud_check(false,'Guest never inherits cached admin extension bypass');$cases++;
  pud_prepare($locale);$context=phpbb_post_attachment_context($db,1,$userdata,1);kp_check(array_key_exists('post_download_mode',$context)&&$context['post_download_mode']===$context['attachment_download_mode'],'Previous controller mode key remains available during provider-first rollout');pud_check(true,'Current enabled feature overrides obsolete disabled cache',false,true);$cases++;
  pud_prepare($locale);kp_sql("UPDATE fixture_attachments_desc SET extension=' BiN '");pud_check(true,'Public descriptor extension normalization matches current group');$cases++;
  pud_prepare($locale);pud_group();kp_sql('INSERT INTO fixture_auth_access(group_id,forum_id,auth_view,auth_read,auth_download,auth_mod) VALUES(11,1,0,1,1,0)');kp_sql('INSERT INTO fixture_user_group(group_id,user_id,user_pending) VALUES(11,4,0)');kp_sql('UPDATE fixture_auth_access SET auth_read=0,auth_download=0 WHERE group_id=10');pud_check(true,'Different current groups may grant different required fields');$cases++;
  pud_prepare($locale);pud_revoke('deleted');kp_sql("INSERT INTO fixture_posts(post_id,topic_id,forum_id,poster_id,post_time,poster_ip) VALUES(2,1,1,4,123,'7f000001')");kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES(1,2,0,4,0)');pud_check(true,'Another surviving public parent may still own shared file');$cases++;
  pud_prepare($locale);pud_revoke('deleted');kp_sql("INSERT INTO fixture_privmsgs(privmsgs_id,privmsgs_type,privmsgs_subject,privmsgs_from_userid,privmsgs_to_userid,privmsgs_date,privmsgs_ip) VALUES(10,0,'Synthetic',3,4,123,'7f000001')");kp_sql('INSERT INTO fixture_attachments(attach_id,post_id,privmsgs_id,user_id_1,user_id_2) VALUES(1,0,10,4,3)');pud_check(true,'Authorized private copy remains a valid separate parent');$cases++;
  foreach(array('missing','borrow','alias','throw')as$factory){pud_prepare($locale);$pd_factory=$factory;$kp_factory=in_array($factory,array('missing','borrow'),true)?$factory:'normal';$before=kp_snapshot();try{pd_check(pd_route(),false,'Public invalid reader '.$factory);kp_check(kp_snapshot()===$before&&$db->db_connect_id,'Invalid factory leaves original data and connection intact');}finally{$pd_factory='normal';$kp_factory='normal';}$cases++;}
  foreach(array(false,true)as$thumb){pud_prepare($locale,'guest');$result=pud_check(true,'Guest full/thumbnail',$thumb);kp_check($result->value['physical_filename']===($thumb?THUMB_DIR.'/t_synthetic.bin':'synthetic.bin'),'Thumbnail path remains bound');$cases++;}
  pud_prepare($locale);kp_sql('UPDATE fixture_extension_groups SET download_mode='.PHYSICAL_LINK);kp_sql("UPDATE fixture_attachments_desc SET extension='bin',mimetype='image/png'");$result=pud_check(true,'Physical redirect');kp_check($result->kind==='redirect'&&strpos($result->value,'synthetic.bin')!==false,'Actual redirect uses current descriptor');$cases++;
  foreach(array('missing','inactive','acl','link')as$kind){pud_prepare($locale);$db->sql_query('START TRANSACTION');$view=pd_caller_view();pud_revoke($kind);$before=kp_snapshot();try{pd_check(pd_route(true,true),false,'Old caller snapshot '.$kind);kp_check(pd_caller_view()===$view&&kp_snapshot()===$before&&$db->db_connect_id,'Caller snapshot/transaction and current data preserved');}finally{$db->sql_query('ROLLBACK');}$cases++;}
  foreach(array('SELECT @@SESSION.autocommit','SET TRANSACTION READ ONLY','SELECT d.*,u.user_level')as$needle){foreach(array('before','after')as$phase){pud_prepare($locale);$before=kp_snapshot();$reached=false;$kp_hook=function($event,$sql)use($needle,$phase,&$reached){if($event===$phase&&strpos($sql,$needle)===0){$reached=true;return false;}};try{pd_check(pd_route(),false,'Public reader SQL failure');kp_check($reached&&kp_snapshot()===$before,'SQL failure never delivers or mutates');}finally{$kp_hook=null;}$failures++;}}
  foreach(array("SELECT *\n\tFROM ".ATTACHMENTS_DESC_TABLE."\n","SELECT *\n\tFROM ".ATTACHMENTS_TABLE."\n",'UPDATE '.ATTACHMENTS_DESC_TABLE)as$needle){pud_prepare($locale);$pd_root_fault=$needle;$before=kp_snapshot();pd_check(pd_route(),false,'Public controller SQL failure');kp_check(kp_snapshot()===$before,'Controller failure preserves data/counter');$pd_root_fault='';$failures++;}
  pud_prepare($locale);foreach(array(null,false,0,-1,'1 OR 1=1',array(1),'999999999999999999999')as$bad){kp_check(!phpbb_post_attachment_context($db,$bad,$userdata,1)&&!phpbb_post_attachment_context($db,1,$userdata,$bad),'Malformed public ids refused');$cases++;}
 }
 echo 'Post download native: '.$cases.' controller/ACL/guest/parent/metadata cases, '.$failures." SQL failures PASS; canonical storage, current groups and sessions, late changes, caller snapshots, counters and controlled transport.\n";
}
$file=__DIR__.'/check-pm-download-native.php';$source=file_get_contents($file);if(substr_count($source,'pd_suite();return;')!==1){throw new RuntimeException('Actual canonical fixture entry');}$source=str_replace('pd_suite();return;','pud_suite();return;',$source);
$code='';foreach(token_get_all($source)as$t){$code.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval(substr($code,5));
