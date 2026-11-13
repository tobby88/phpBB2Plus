<?php
// Actual PN controllers and installer tables on disposable MySQL/MariaDB.
// Existing native setup owns and removes the random database; mail is disabled.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PM_FORM_NATIVE')!=='1'){echo "PM form reads require explicitly enabled disposable storage.\n";return;}
putenv('PHPBB_PM_NOTIFY_NATIVE=1');putenv('PHPBB_PM_NOTIFY_PORT='.(getenv('PHPBB_PM_FORM_PORT')?:'3306'));putenv('PHPBB_PM_NOTIFY_PASSWORD='.(getenv('PHPBB_PM_FORM_PASSWORD')?:''));
function pf_prepare($pending=false,$role=0,$locale='english'){
 global$kp_hook,$userdata,$pn_id;
 pd_prepare($locale);kp_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id=4');pd_login(4,$role);
 if($pending){
  $kp_hook=function($phase,$sql){if($phase==='before'&&strpos($sql,'INSERT INTO fixture_privmsgs_text ')===0){return false;}};$failed=false;
  try{phpbb_pm_write_message(str_repeat('c',32),0,'',pn_data(),0);}catch(Exception$error){$failed=true;}finally{$kp_hook=null;}
  kp_check($failed&&count(kp_rows('SELECT privmsgs_id FROM fixture_privmsgs WHERE privmsgs_write_payload IS NOT NULL'))===1,'Actual interrupted durable PN');
 }
 $_SERVER['REQUEST_METHOD']='GET';$_POST=array();
}
function pf_read($kind){
 try{return array('ok'=>true,'value'=>$kind==='revision'?phpbb_pm_form_revision($GLOBALS['pn_id']):phpbb_pm_pending_writes_html(),'error'=>'');}
 catch(Exception$error){return array('ok'=>false,'value'=>null,'error'=>$error->getMessage());}
}
function pf_change($kind){
 if($kind==='block'){kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=4');}
 elseif($kind==='allow'){kp_sql('UPDATE fixture_users SET user_allow_pm=0 WHERE user_id=4');}
 elseif($kind==='cached'){$GLOBALS['userdata']['session_id']=str_repeat('e',32);}
 elseif($kind==='foreign-parent'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_from_userid=2');}
 elseif($kind==='read-parent'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_type=0');}
 else{pd_revoke($kind);}
}
function pf_suite(){
 global$kp_source,$db,$userdata,$pn_id,$pn_nonce,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$pd_factory,$pd_busy,$kp_hook,$pd_root_queries,$pd_root_fault;
 require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';
 $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();$cases=$faults=0;
 $source=file_get_contents($kp_source.'includes/functions.php');kp_check(preg_match('/^function phpbb_profile_text\(.*?^\}/ms',$source,$m)===1,'Actual HTML helper');eval($m[0]);
 if(!function_exists('append_sid')){function append_sid($url){return$url;}}
 if(getenv('PHPBB_PM_FORM_MYSQL')==='8.4'){$rows=kp_rows('SELECT VERSION() version');kp_check(preg_match('/^8\.4\./',$rows[0]['version'])&&stripos($rows[0]['version'],'MariaDB')===false,'Actual MySQL8.4 required');}
 foreach(array('english','german')as$locale){foreach(array(0,2,1)as$role){foreach(array(0,-1,time()-3600,time()+3600)as$block){foreach(array('revision','pending')as$kind){
  pf_prepare($kind==='pending',$role,$locale);kp_sql('UPDATE fixture_users SET user_blocktime='.$block.' WHERE user_id=4');$before=kp_snapshot();$r=pf_read($kind);$allowed=$block<time();
  kp_check($r['ok']===$allowed&&($allowed||$r['error']===$GLOBALS['lang']['Not_Authorised']),'Current block state every role/locale: '.$kind);
  if($allowed){kp_check($kind==='revision'?$r['value']===$pn_nonce:strpos($r['value'],'value="'.str_repeat('c',32).'"')!==false,'Current revision or own actual retry form');}
  kp_check(kp_snapshot()===$before&&$pn_open===0,'Form reads never mutate persistent data and release owner');$cases++;
 }}}}
 foreach(array('revision','pending')as$kind){foreach(array('missing','foreign','logout','case','replacement','cached','inactive','deleted-user','allow')as$change){
  pf_prepare($kind==='pending');$db->sql_query('START TRANSACTION');foreach(array('users','sessions','privmsgs')as$table){$r=$db->sql_query('SELECT * FROM fixture_'.$table);$db->sql_freeresult($r);}
  kp_check($db->sql_query("INSERT INTO fixture_config(config_name,config_value) VALUES('form-caller-marker','pending')"),'Uncommitted caller marker');
  try{pf_change($change);$before=kp_snapshot();$r=pf_read($kind);$hidden=$kind==='pending'&&$change==='allow';kp_check($hidden?($r['ok']&&$r['value']===''):(!$r['ok']&&$r['error']===$GLOBALS['lang']['Not_Authorised']),'Current authority ignores old caller snapshot: '.$kind.'/'.$change);
   $q=$db->sql_query("SELECT config_value FROM fixture_config WHERE config_name='form-caller-marker'");$row=$db->sql_fetchrow($q);$db->sql_freeresult($q);
   kp_check($row['config_value']==='pending'&&!kp_rows("SELECT * FROM fixture_config WHERE config_name='form-caller-marker'")&&$db->db_connect_id&&$pn_open===0&&kp_snapshot()===$before,'Caller transaction remains private, open and unchanged');
  }finally{$db->sql_query('ROLLBACK');}$cases++;
 }}
 foreach(array('revision','pending')as$kind){
  pf_prepare($kind==='pending');$db->sql_query('START TRANSACTION');foreach(array('users','sessions','privmsgs')as$table){$q=$db->sql_query('SELECT * FROM fixture_'.$table);$db->sql_freeresult($q);}
  try{$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>$userdata['session_id']);
   if($kind==='revision'){$data=pn_data();$data['subject']='Actual later edit Grüße 😀';phpbb_pm_write_message(str_repeat('e',32),$pn_id,$pn_nonce,$data,0);}else{phpbb_pm_retry_write(str_repeat('c',32),0);}
   $_SERVER['REQUEST_METHOD']='GET';$_POST=array();$before=kp_snapshot();$r=pf_read($kind);kp_check($r['ok']&&$r['value']===($kind==='revision'?str_repeat('e',32):'')&&kp_snapshot()===$before&&$pn_open===0,'Current real edit/completed retry ignores old caller snapshot');
  }finally{$db->sql_query('ROLLBACK');}$cases++;
 }
 foreach(array('foreign-parent','read-parent','parent','pending')as$change){pf_prepare();pf_change($change);$before=kp_snapshot();$r=pf_read('revision');kp_check(!$r['ok']&&$r['error']===$GLOBALS['lang'][$change==='pending'?'PM_write_pending':'Not_Authorised']&&kp_snapshot()===$before&&$pn_open===0,'Revision never reveals another/complete/absent/pending parent: '.$change);$cases++;}
 foreach(array('revision','pending')as$kind){foreach(array('alias','throw','busy','busy-autocommit')as$factory){
  pf_prepare($kind==='pending');$busy=in_array($factory,array('busy','busy-autocommit'),true);
  if($busy){$pd_busy=$db->sql_dedicated_connection();$pd_busy->sql_query($factory==='busy'?'START TRANSACTION':'SET autocommit=0');$pd_busy->sql_query("UPDATE fixture_users SET username='Pending form fixture' WHERE user_id=2");}
  $pd_factory=$factory;$before=kp_snapshot();try{$r=pf_read($kind);kp_check(!$r['ok']&&$r['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$db->db_connect_id,'Unsafe form reader factory fails closed: '.$kind.'/'.$factory);
   if($busy){$q=$pd_busy->sql_query('SELECT username FROM fixture_users WHERE user_id=2');$row=$pd_busy->sql_fetchrow($q);$pd_busy->sql_freeresult($q);kp_check($row['username']==='Pending form fixture'&&kp_rows('SELECT username FROM fixture_users WHERE user_id=2')[0]['username']!=='Pending form fixture','Borrowed transaction remains open and private');}
  }finally{$pd_factory='normal';if($busy){$pd_busy->sql_query('ROLLBACK');$pd_busy->sql_close();}}kp_check($pn_open===0,'Only owned connections released');$cases++;
 }}
 foreach(array('revision'=>'SELECT u.user_id','pending'=>'SELECT p.privmsgs_id,p.privmsgs_write_token')as$kind=>$select){foreach(array('SELECT @@SESSION.autocommit','SET TRANSACTION READ ONLY',$select)as$needle){foreach(array('before','after')as$phase){
  pf_prepare($kind==='pending');$reached=false;$before=kp_snapshot();$kp_hook=function($event,$sql)use($needle,$phase,&$reached){if($event===$phase&&strpos($sql,$needle)===0){$reached=true;return false;}};
  try{$r=pf_read($kind);kp_check($reached&&!$r['ok']&&$r['error']===$GLOBALS['lang']['PM_cleanup_failed']&&kp_snapshot()===$before&&$pn_open===0,'Form SQL fault sanitized and released: '.$kind.'/'.$phase.'/'.$needle);}finally{$kp_hook=null;}$faults++;
 }}}
 foreach(array('revision','pending')as$kind){foreach(array('anonymous','id','sid-array','sid-empty','sid-long')as$bad){
  pf_prepare($kind==='pending');if($bad==='anonymous'){$userdata['session_logged_in']=false;}elseif($bad==='id'){$userdata['user_id']=array(4);}elseif($bad==='sid-array'){$userdata['session_id']=array('a');}elseif($bad==='sid-empty'){$userdata['session_id']='';}else{$userdata['session_id']=str_repeat('a',33);}
  $pd_factory='throw';try{$r=pf_read($kind);kp_check(!$r['ok']&&$r['error']===$GLOBALS['lang']['Not_Authorised']&&$pn_open===0,'Invalid form identity rejected before factory: '.$kind.'/'.$bad);}finally{$pd_factory='normal';}$cases++;
 }}
 pf_prepare();kp_sql("UPDATE fixture_privmsgs SET privmsgs_write_token='' WHERE privmsgs_id=".$pn_id);kp_check(phpbb_pm_form_revision($pn_id)==='','Legacy empty revision preserved');$cases++;
 pf_prepare();kp_sql("UPDATE fixture_privmsgs SET privmsgs_write_token='malformed' WHERE privmsgs_id=".$pn_id);$r=pf_read('revision');kp_check(!$r['ok']&&$pn_open===0,'Malformed revision rejected after owner cleanup');$cases++;
 foreach(array('none','foreign','bad-token')as$kind){pf_prepare($kind!=='none');if($kind==='foreign'){kp_sql('UPDATE fixture_privmsgs SET privmsgs_from_userid=2');}elseif($kind==='bad-token'){kp_sql("UPDATE fixture_privmsgs SET privmsgs_write_token='bad' WHERE privmsgs_write_payload IS NOT NULL");}$before=kp_snapshot();kp_check(phpbb_pm_pending_writes_html()===''&&kp_snapshot()===$before&&$pn_open===0,'No empty wrapper or foreign pending form: '.$kind);$cases++;}
 pf_prepare();$seed=kp_rows('SELECT * FROM fixture_privmsgs WHERE privmsgs_id='.$pn_id)[0];unset($seed['privmsgs_id']);$seed['privmsgs_type']=6;$seed['privmsgs_write_payload']='pending';$values=array();for($i=0;$i<103;$i++){$seed['privmsgs_write_token']=sprintf('%032x',$i+1);$fields=array();foreach($seed as$value){$fields[]=$value===null?'NULL':"'".$GLOBALS['peer']->sql_escape($value)."'";}$values[]='('.implode(',',$fields).')';}kp_sql('INSERT INTO fixture_privmsgs('.implode(',',array_keys($seed)).') VALUES'.implode(',',$values));$before=kp_snapshot();$html=phpbb_pm_pending_writes_html();preg_match_all('/name="pm_write_nonce" value="([a-f0-9]{32})"/',$html,$tokens);preg_match_all('/<p class="genmed">#([0-9]+)/',$html,$ids);$sorted=$ids[1];sort($sorted,SORT_NUMERIC);kp_check(count($tokens[1])===100&&count(array_unique($tokens[1]))===100&&$ids[1]===$sorted&&$tokens[1][0]===sprintf('%032x',1)&&$tokens[1][99]===sprintf('%032x',100)&&kp_snapshot()===$before&&$pn_open===0,'Retry forms remain bounded, own-only and deterministically ordered');$cases++;
 foreach(array('revision','pending')as$kind){pf_prepare($kind==='pending');$pd_root_queries=array();$pd_root_fault='SELECT ';$before=kp_snapshot();try{$r=pf_read($kind);kp_check($r['ok']&&$pd_root_queries===array()&&kp_snapshot()===$before&&$pn_open===0,'No data query ever uses caller connection: '.$kind);}finally{$pd_root_fault='';}$cases++;}
 echo 'Native PM form reads: '.$cases.' cases, '.$faults." SQL faults PASS; current revision and pending forms, real edits/recovery, isolated snapshots, safe factories, bounded own retries and EN/DE roles.\n";
}
$pf_source=file_get_contents(__DIR__.'/check-pm-download-native.php');if(substr_count($pf_source,'pd_suite();return;')!==1){throw new RuntimeException('Exact canonical fixture boundary');}$pf_source=str_replace('pd_suite();return;','pf_suite();return;',$pf_source);$pf_eval='';foreach(token_get_all($pf_source)as$t){if(is_array($t)&&$t[0]===T_OPEN_TAG){continue;}$pf_eval.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}eval($pf_eval);
