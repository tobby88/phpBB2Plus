<?php
// Canonical installer tables and actual compose/parser/download code. Only the
// final file/header delivery uses controlled sinks from the native PM fixture.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_ENTRY_NATIVE')!=='1'){echo "PM entry checks require explicitly enabled disposable storage.\n";return;}
$pe_mode=isset($argv[1])?$argv[1]:'';if(!in_array($pe_mode,array('compose','download'),true)){throw new RuntimeException('Explicit PM entry mode required');}
putenv('PHPBB_PM_NOTIFY_NATIVE=1');putenv('PHPBB_PM_NOTIFY_PORT='.(getenv('PHPBB_PM_ENTRY_PORT')?:'3306'));putenv('PHPBB_PM_NOTIFY_PASSWORD='.(getenv('PHPBB_PM_ENTRY_PASSWORD')?:''));
function pe_prepare($verb='GET',$role=0,$locale='english'){
 global$userdata,$HTTP_POST_VARS,$HTTP_GET_VARS,$HTTP_POST_FILES,$attach_config,$submit,$preview,$refresh,$error,$error_msg,$template,$privmsg_id,$post_id,$is_auth;
 pd_prepare($locale);pd_login(4,$role);kp_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id=4');
 $_SERVER['REQUEST_METHOD']=$verb;$_POST=$verb==='POST'?array('sid'=>$userdata['session_id']):array();$_GET=array();$HTTP_POST_VARS=&$_POST;$HTTP_GET_VARS=&$_GET;$HTTP_POST_FILES=array();
 $attach_config=array('allow_pm_attach'=>1,'disable_mod'=>0,'max_attachments_pm'=>5,'display_order'=>0,'allow_ftp_upload'=>0);$submit=$preview=$refresh=$error=false;$error_msg='';$is_auth=array();$privmsg_id=$GLOBALS['pn_id'];$post_id=0;
}
function pe_source($mode='edit',$id=null,$database=null){
 if($id===null){$id=$GLOBALS['pn_id'];}if($database===null){$database=$GLOBALS['db'];}
 try{return array('allowed'=>true,'id'=>phpbb_pm_compose_attachment_source($database,$mode,$id),'error'=>'');}
 catch(Exception$error){return array('allowed'=>false,'id'=>null,'error'=>$error->getMessage());}
}
function pe_revoke($kind){
 if($kind==='block'){kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=4');}
 elseif($kind==='allow'){kp_sql('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=4');}
 elseif($kind==='pending'){kp_sql("UPDATE fixture_privmsgs SET privmsgs_write_payload='pending'");}
 elseif($kind==='parent'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_type=0');}
 elseif($kind==='foreign-parent'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_from_userid=3');}
 elseif($kind==='deleted-parent'){kp_sql('DELETE FROM fixture_privmsgs');}
 elseif($kind==='cached'){ $GLOBALS['userdata']['session_id']=str_repeat('e',32);$_POST['sid']=$GLOBALS['userdata']['session_id']; }
 else{pd_revoke($kind);}
}
function pe_compose_suite(){
 global$db,$userdata,$pn_id,$pn_open,$pd_factory,$pd_busy,$kp_hook,$kp_source,$privmsg_id,$template,$pn_queries;
 $cases=$failures=0;
 foreach(array(0,2,1)as$role){foreach(array('post','reply','quote','edit')as$mode){foreach(array('clear','expired','negative','current')as$n=>$kind){
  pe_prepare($n%2?'POST':'GET',$role,$n%2?'german':'english');$stamp=$kind==='current'?time()+3600:($kind==='expired'?time()-3600:($kind==='negative'?-1:0));kp_sql('UPDATE fixture_users SET user_blocktime='.$stamp.' WHERE user_id=4');$before=kp_snapshot();$result=pe_source($mode);
  kp_check($result['allowed']===($kind!=='current')&&(!$result['allowed']||$result['id']===($mode==='edit'?$pn_id:0))&&$pn_open===0,'Current compose account state and exact source: '.$role.'/'.$mode.'/'.$kind);
  kp_check(kp_snapshot()===$before,'Compose entry changes no persistent data');$cases++;
 }}}
 foreach(array('missing','foreign','logout','case','replacement','cached','inactive','allow','block','parent','foreign-parent','deleted-parent','pending')as$n=>$kind){
  pe_prepare($n%2?'POST':'GET');pe_revoke($kind);$before=kp_snapshot();$result=pe_source();
  kp_check(!$result['allowed']&&$result['error']===$GLOBALS['lang'][$kind==='pending'?'PM_write_pending':'Not_Authorised']&&kp_snapshot()===$before&&$pn_open===0,'Current compose revocation: '.$kind);$cases++;
 }
 foreach(array('missing','case','inactive','allow','block','parent','foreign-parent','deleted-parent','pending')as$kind){
  pe_prepare();kp_check($db->sql_query('START TRANSACTION'),'Own caller transaction');
  foreach(array('users','sessions','privmsgs')as$table){$r=$db->sql_query('SELECT * FROM fixture_'.$table);$db->sql_freeresult($r);}
  kp_check($db->sql_query("INSERT INTO fixture_config(config_name,config_value) VALUES('entry-caller-marker','pending')"),'Own pending caller marker');
  try{pe_revoke($kind);$before=kp_snapshot();$result=pe_source();$r=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='entry-caller-marker'");$pending=$db->sql_fetchrow($r);$db->sql_freeresult($r);
   kp_check(!$result['allowed']&&kp_snapshot()===$before&&$pending['config_value']==='pending'&&!kp_rows("SELECT * FROM fixture_config WHERE config_name='entry-caller-marker'")&&$db->db_connect_id&&$pn_open===0,'Fresh compose context ignores old caller snapshot without committing it: '.$kind);
  }finally{$db->sql_query('ROLLBACK');}$cases++;
 }
 foreach(array('alias','throw','busy','busy-autocommit')as$factory){
  pe_prepare();$busy=in_array($factory,array('busy','busy-autocommit'),true);
  if($busy){$pd_busy=$db->sql_dedicated_connection();$pd_busy->sql_query($factory==='busy'?'START TRANSACTION':'SET autocommit=0');$pd_busy->sql_query("UPDATE fixture_users SET username='Pending entry fixture' WHERE user_id=2");}
  $pd_factory=$factory;$before=kp_snapshot();
  try{$result=pe_source();kp_check(!$result['allowed']&&$result['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$db->db_connect_id,'Invalid compose factory fails safely without data or private error details: '.$factory);
   if($busy){$r=$pd_busy->sql_query('SELECT username FROM fixture_users WHERE user_id=2');$row=$pd_busy->sql_fetchrow($r);$pd_busy->sql_freeresult($r);kp_check($row['username']==='Pending entry fixture'&&kp_rows('SELECT username FROM fixture_users WHERE user_id=2')[0]['username']!=='Pending entry fixture','Borrowed transaction remains open, uncommitted and private');}
  }finally{$pd_factory='normal';if($busy){$pd_busy->sql_query('ROLLBACK');$pd_busy->sql_close();}}kp_check($pn_open===0,'Factory proof releases only its owned connections');$cases++;
 }
 foreach(array('SELECT @@SESSION.autocommit','SET TRANSACTION READ ONLY','SELECT u.user_id')as$needle){foreach(array('before','after')as$phase){
  pe_prepare();$reached=false;$before=kp_snapshot();$kp_hook=function($event,$sql)use($needle,$phase,&$reached){if($event===$phase&&strpos($sql,$needle)===0){$reached=true;return false;}};
  try{$result=pe_source();kp_check($reached&&!$result['allowed']&&$result['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$pn_open===0,'Compose SQL fault fails closed: '.$needle.'/'.$phase);}finally{$kp_hook=null;}$failures++;
 }}
 foreach(array('mode','id','user','session-array','session-long','post-token')as$kind){
  pe_prepare('POST');$mode='edit';$id=$pn_id;
  if($kind==='mode'){$mode=array('edit');}elseif($kind==='id'){$id=array(1);}elseif($kind==='user'){$userdata['user_id']=-1;}elseif($kind==='session-array'){$userdata['session_id']=array();}elseif($kind==='session-long'){$userdata['session_id']=str_repeat('a',33);}else{$_POST['sid']='wrong';}
  $result=pe_source($mode,$id,new stdClass());kp_check(!$result['allowed']&&$result['error']===$GLOBALS['lang'][$kind==='post-token'?'Session_invalid':'Not_Authorised']&&$pn_open===0,'Malformed request denied before connection allocation: '.$kind);$cases++;
 }
 require_once$kp_source.'attach_mod/includes/functions_attach.php';require_once$kp_source.'attach_mod/posting_attachments.php';require_once$kp_source.'attach_mod/pm_attachments.php';
 class PeTemplate{function assign_vars($values){}function assign_block_vars($name,$values){}}
 foreach(array(0,2,1)as$role){foreach(array('GET','POST')as$verb){
  pe_prepare($verb,$role);$template=new PeTemplate();$pn_queries=array();$original=$db;$pm=new attach_pm();$before=kp_snapshot();$pm->handle_attachments('edit');
  $locks=0;foreach($pn_queries as$sql){if(strpos($sql,'SELECT GET_LOCK(')===0){$locks++;}}
  kp_check($locks===1&&$pn_open===0&&$db===$original&&$pm->compose_database===null&&$pm->attachment_list===array('synthetic.bin')&&kp_snapshot()===$before,'Actual parser uses one existing mutex and restores caller for '.$verb.'/'.$role);$cases++;
 }}
 foreach(array('before','after')as$phase){foreach(array('block','pending','parent')as$kind){
  pe_prepare();$template=new PeTemplate();$pm=new attach_pm();$reached=false;$state=null;$original=$db;
  $kp_hook=function($event,$sql)use($phase,$kind,&$reached,&$state){if($event===$phase&&strpos($sql,'SELECT u.user_id')===0){$GLOBALS['kp_hook']=null;$reached=true;pe_revoke($kind);$state=kp_snapshot();}};
  $denied=false;try{$pm->handle_attachments('edit');}catch(PhpbbAclException$error){$denied=true;}finally{$kp_hook=null;}
  kp_check($reached&&$denied&&$pn_open===0&&$db===$original&&$pm->compose_database===null&&kp_snapshot()===$state,'Actual parser revalidates source/account around lookup: '.$phase.'/'.$kind);$cases++;
 }}
	foreach(array(0,2,1)as$role){foreach(array('GET','POST')as$verb){
	 pe_prepare($verb,$role);$before=kp_snapshot();$lock=attach_require_mutation_lock($db);$denied=false;
	 try{$writer=new PhpbbPmComposeDatabase($lock->connection,'edit',$pn_id);try{$writer->sql_query('UPDATE '.ATTACHMENTS_DESC_TABLE." SET comment='".$writer->sql_escape("Aktualisiert ' Grüße 😀")."' WHERE attach_id=1");}catch(PhpbbAclException$error){$denied=$error->getMessage()===$GLOBALS['lang']['Session_invalid'];if(!$denied){throw$error;}}}
	 finally{$lock->release();}
	 $expected=$before;if($verb==='POST'){$expected[ATTACHMENTS_DESC_TABLE][0]['comment']="Aktualisiert ' Grüße 😀";}
	 kp_check($denied===($verb==='GET')&&kp_snapshot()===$expected&&$pn_open===0,'Fresh standalone read mode never makes the existing parser write connection read-only: '.$verb.'/'.$role);$cases++;
	}}
 echo 'Native PM compose entry: '.$cases.' cases, '.$failures." SQL faults PASS; canonical current session/account/parent, isolated snapshots, safe factories, actual parser and single mutex.\n";
}
function pe_download_suite(){
 global$db,$userdata,$pd_after_context,$pn_open;$cases=0;
 foreach(array('english','german')as$locale){foreach(array(0,2,1)as$role){foreach(array('clear','expired','negative','current')as$kind){
  pe_prepare('GET',$role,$locale);$stamp=$kind==='current'?time()+3600:($kind==='expired'?time()-3600:($kind==='negative'?-1:0));kp_sql('UPDATE fixture_users SET user_blocktime='.$stamp.' WHERE user_id=4');$allow=$kind!=='current';
  kp_check(phpbb_pm_attachment_access($db,$GLOBALS['pn_id'],$userdata,true)===$allow,'Current parent-only attachment API account block');
  kp_check((bool)phpbb_pm_download_attachment($db,$GLOBALS['pn_id'],$userdata,true,1)===$allow,'Current descriptor API account block');pd_check(pd_route(),$allow,'Current block '.$locale.'/'.$role.'/'.$kind);
  kp_check(kp_rows('SELECT download_count FROM fixture_attachments_desc WHERE attach_id=1')[0]['download_count']===($allow?'1':'0'),'Only actual authorized delivery increments counter');$cases++;
 }}}
 foreach(array(0,2,1)as$role){pe_prepare('GET',$role);$db->sql_query('START TRANSACTION');$r=$db->sql_query('SELECT * FROM fixture_users');$db->sql_freeresult($r);
  try{pe_revoke('block');$before=kp_snapshot();pd_check(pd_route(),false,'Current block ignores caller RR');kp_check(kp_snapshot()===$before&&$db->db_connect_id&&$pn_open===0,'Denied stream preserves caller connection and all rows');}finally{$db->sql_query('ROLLBACK');}$cases++;
 }
 foreach(array(0,2,1)as$role){pe_prepare('GET',$role);$pd_after_context=function(){pe_revoke('block');};pd_check(pd_route(),false,'Late block before transport');$cases++;}
 pe_prepare();pd_login(1,ADMIN);kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=1');pd_check(pd_route(false),false,'Blocked current administrator cannot bypass global feature switch');$cases++;
 pe_prepare();kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=3');pd_check(pd_route(),true,'Other participant block does not disable authorized sender download');$cases++;
 echo 'Native PM download account block: '.$cases." cases PASS; current/past blocks, every role, actual parent/descriptor/controller, old snapshots, late revocation and preserved participant semantics.\n";
}
function pe_suite(){
 global$kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$pd_body,$pd_after_context,$pd_factory,$pd_root_fault,$pd_root_queries,$pe_mode;
 require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';require$kp_source.'includes/functions_post_attachment_access.php';
 $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();$pd_factory='normal';$pd_root_fault='';$pd_root_queries=array();$pd_after_context=null;
 if(getenv('PHPBB_PM_ENTRY_MYSQL')==='8.4'){$rows=kp_rows('SELECT VERSION() version');kp_check(preg_match('/^8\.4\./',$rows[0]['version'])&&stripos($rows[0]['version'],'MariaDB')===false,'Actual MySQL8.4 required');}
 $source=str_replace("\r\n","\n",file_get_contents($kp_source.'download.php'));$start=strpos($source,"\$sql = 'SELECT *\n\tFROM ' . ATTACHMENTS_DESC_TABLE");kp_check($start!==false,'Actual complete download controller');$pd_body=substr($source,$start);putenv('SERVER_SOFTWARE=fixture-only');
 if($pe_mode==='compose'){pe_compose_suite();}else{pe_download_suite();}
}
$pe_source=file_get_contents(__DIR__.'/check-pm-download-native.php');if(substr_count($pe_source,'pd_suite();return;')!==1){throw new RuntimeException('Exact canonical fixture entry');}$pe_source=str_replace('pd_suite();return;','pe_suite();return;',$pe_source);
$pe_eval='';foreach(token_get_all($pe_source)as$t){if(is_array($t)&&$t[0]===T_OPEN_TAG){continue;}$pe_eval.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval($pe_eval);
