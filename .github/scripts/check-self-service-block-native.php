<?php
// Actual profile/signature controllers and the canonical PM lifecycle fixture.
if(PHP_SAPI!=='cli'||getenv('PHPBB_SELF_BLOCK_NATIVE')!=='1'){echo "Self-service block checks require an explicitly enabled disposable database.\n";return;}
$self_mode=isset($argv[1])?$argv[1]:'';
if(!in_array($self_mode,array('profile','signature','pm'),true)){throw new RuntimeException('Explicit self-service mode required');}
foreach(array('PUBLIC_PROFILE','SIGNATURE','PM_NOTIFY')as$key){putenv('PHPBB_'.$key.'_NATIVE=1');putenv('PHPBB_'.$key.'_PORT='.(getenv('PHPBB_SELF_BLOCK_PORT')?:'3306'));putenv('PHPBB_'.$key.'_PASSWORD='.(getenv('PHPBB_SELF_BLOCK_PASSWORD')?:''));}
require_once __DIR__.'/native-fixture-locks.php';
function self_block_code($source){$out='';foreach(token_get_all($source)as$t){if(is_array($t)&&$t[0]===T_OPEN_TAG){continue;}$out.=is_array($t)?($t[0]===T_DIR?var_export(__DIR__,true):$t[1]):$t;}return$out;}
function self_block_server($rows){if(getenv('PHPBB_SELF_BLOCK_MYSQL')==='8.4'&&(!preg_match('/^8\.4\./',$rows[0]['version'])||stripos($rows[0]['version'],'MariaDB')!==false)){throw new RuntimeException('Actual MySQL8.4 required');}}
function self_block_profile_reset($mode,$scenario,$role){
 if($mode==='signature'){sig_reset();}else{pp_reset($scenario);}
 pp_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id=2');$GLOBALS['userdata']['user_level']=$role;
}
function self_block_profile_run($mode,$scenario){return $mode==='signature'?sig_run():pp_run($scenario)===true;}
function self_block_profile_suite($mode){
 global$userdata,$peer,$pp_hook,$pp_files,$pp_cookies;
 self_block_server(pp_rows('SELECT VERSION() AS version'));$cases=$serialized=0;
 $scenarios=$mode==='signature'?array('signature'):array('edit','password','rename','avatar','reactivate','reactivate-password');
 foreach(array(0,2,1)as$role){foreach($scenarios as$scenario){
  foreach(array('current','clear','expired','negative')as$kind){
   self_block_profile_reset($mode,$scenario,$role);$stamp=$kind==='current'?time()+3600:($kind==='expired'?time()-3600:($kind==='negative'?-1:0));
   pp_sql('UPDATE fixture_users SET user_blocktime='.$stamp.' WHERE user_id=2');$before=pp_snap();$accepted=self_block_profile_run($mode,$scenario);
   ats_check($accepted===($kind!=='current'),'Current account block applies to '.$mode.'/'.$scenario.'/'.$role.'/'.$kind);
   if($kind==='current'){ats_check(pp_snap()===$before&&!$pp_cookies&&file_get_contents($pp_files.'/avatars/before.png')==='before','Blocked edit preserves all canonical data, old avatar and cookies');}
   $cases++;
  }
  foreach(array('before-row','first-write')as$phase){
   self_block_profile_reset($mode,$scenario,$role);$reached=$blocked=false;$state=null;
   $pp_hook=function($sql)use($phase,&$reached,&$blocked,&$state){
    if(($phase==='before-row'&&strpos($sql,'SELECT * FROM fixture_users WHERE user_id=2 FOR UPDATE')!==0)||($phase==='first-write'&&!preg_match('/^(INSERT|UPDATE|DELETE) /',$sql))){return;}
    $GLOBALS['pp_hook']=null;$reached=true;
    list($result,$error)=native_fixture_conflict_query($GLOBALS['peer'],'UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=2');
    if(!$result){ats_check((int)$error['code']===1205,'Only actual account row locks serialize block changes');$blocked=true;}
    $state=pp_snap();
   };
   try{$accepted=self_block_profile_run($mode,$scenario);}finally{$pp_hook=null;}
   ats_check($reached&&$blocked===($phase==='first-write')&&$accepted===$blocked,'Fresh block is denied or genuinely serialized: '.$mode.'/'.$scenario.'/'.$role.'/'.$phase);
   if(!$blocked){ats_check(pp_snap()===$state&&!$pp_cookies&&file_get_contents($pp_files.'/avatars/before.png')==='before','A newly blocked edit adds no writes or cookie publication');}
   else{pp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=2');$serialized++;}
   $cases++;
  }
 }}
 ats_check(pp_rows('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')[0]['seconds']==='1','Profile fixture wait restored after every conflict');
 echo 'Native self-service account blocks '.$mode.': '.$cases.' cases, '.$serialized." serialized changes PASS; actual controllers, canonical tables, all roles, current/past blocks, row locks and denied data/cookies.\n";
}
function self_block_pm_suite(){
 global$kp_source,$pn_open,$pn_claim_pending,$pn_after_claim,$pn_queries,$kp_hook,$userdata,$db;
 require_once$kp_source.'attach_mod/includes/functions_delete.php';require$kp_source.'includes/functions_pm_controller.php';
 $pn_open=0;$pn_claim_pending=false;$pn_after_claim=null;$pn_queries=array();self_block_server(kp_rows('SELECT VERSION() AS version'));
 $cases=$boundaries=$serialized=0;
 foreach(array('send','edit','retry','read','visit','save','delete','attachments','resume','claim')as$action){
  foreach(array(0,2,1)as$role){foreach(array('current','clear','expired','negative')as$kind){
   pc_prepare($action);$actor=(int)$userdata['user_id'];$stamp=$kind==='current'?time()+3600:($kind==='expired'?time()-3600:($kind==='negative'?-1:0));
   kp_sql('UPDATE fixture_users SET user_level='.$role.',user_blocktime='.$stamp.' WHERE user_id='.$actor);$userdata['user_level']=$role;$before=kp_snapshot();$denied=false;
   try{pc_action($action);}catch(Exception$error){$denied=$error->getMessage()===$GLOBALS['lang']['Not_Authorised'];if(!$denied){throw$error;}}
   kp_check($denied===($kind==='current')&&$pn_open===0,'Current PM account block and released owner: '.$action.'/'.$role.'/'.$kind);
   if($denied){kp_check(kp_snapshot()===$before,'Blocked PM entry changes no persistent data');}$cases++;
  }}
  pc_prepare($action);$pn_queries=array();pc_action($action);$reference=$pn_queries;$position=0;$positions=array();
  foreach($reference as$sql){$position++;if(preg_match('/^(INSERT INTO|UPDATE|DELETE FROM) /',$sql)||(strpos($sql,'SELECT ')===0&&strpos($sql,'fixture_privmsgs')!==false&&strpos($sql,'SELECT 1 AS allowed WHERE')!==0)){$positions[]=$position;}}
  foreach($positions as$position){
   pc_prepare($action);$actor=(int)$userdata['user_id'];$seen=0;$state=null;$blocked=false;
   $kp_hook=function($phase,$sql,$owner)use($position,$actor,&$seen,&$state,&$blocked){
    if($phase!=='before'||++$seen!==$position){return;}$GLOBALS['kp_hook']=null;
    list($result,$error)=native_fixture_conflict_query($GLOBALS['peer'],'UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id='.$actor);
    if(!$result){kp_check((int)$error['code']===1205,'Only actual PM locks serialize account changes');$blocked=true;}$state=kp_snapshot();
   };
   $denied=false;try{pc_action($action);}catch(Exception$error){$denied=$error->getMessage()===$GLOBALS['lang']['Not_Authorised'];if(!$denied){throw$error;}}finally{$kp_hook=null;}
   kp_check($state!==null&&$pn_open===0&&$denied!==$blocked,'Every actual PM SQL boundary sees the current block or holds its row');
   if(!$blocked){kp_check(kp_snapshot()===$state,'No private result or further writes after account block');}else{$serialized++;}
   kp_sql('UPDATE fixture_users SET user_blocktime=0 WHERE user_id='.$actor);
   pc_login(in_array($action,array('read','visit','save','delete','attachments','resume'),true)?3:4,str_repeat('f',32));
   if(in_array($action,array('send','edit','retry','read','visit','save','claim'),true)){
    pc_action($action);kp_check($pn_open===0,'Unblocked login resumes durable PM action');$messages=kp_rows('SELECT COUNT(*) n FROM fixture_privmsgs');
    kp_check((int)$messages[0]['n']===(in_array($action,array('send','retry','read'),true)?2:1),'Unblocked resume never duplicates messages/copies');
   }$boundaries++;
  }
 }
 foreach(array('send','read','delete')as$action){
  pc_prepare($action);$actor=(int)$userdata['user_id'];kp_check($db->sql_query('START TRANSACTION'),'Owned caller snapshot');$r=$db->sql_query('SELECT user_blocktime FROM fixture_users');$db->sql_freeresult($r);
  try{kp_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id='.$actor);$before=kp_snapshot();pc_expect_denied($action);kp_check(kp_snapshot()===$before,'Old caller snapshot cannot authorize blocked account');}
  finally{$db->sql_query('ROLLBACK');}$cases++;
 }
 echo 'Native self-service account blocks pm: '.$cases.' entry/snapshot cases, '.$boundaries.' SQL boundaries, '.$serialized." serialized changes PASS; canonical send/edit/retry/read/save/delete/attachments/claim, unchanged denied data and resumable journals.\n";
}
if($self_mode==='profile'){
 $source=file_get_contents(__DIR__.'/check-public-profile-native.php');$cut=strpos($source," foreach(array('edit','password','rename','avatar','reactivate','reactivate-password') as \$scenario)");$cleanup=strrpos($source,'} finally {');
 if($cut===false||$cleanup===false){throw new RuntimeException('Exact profile fixture boundaries');}
 $source=substr($source,0,$cut)." self_block_profile_suite('profile');\n".substr($source,$cleanup);
 eval(self_block_code(str_replace('codex_public_profile_','codex_self_profile_block_',$source)));
}elseif($self_mode==='signature'){
 $source=file_get_contents(__DIR__.'/check-signature-save-native.php');$cut=strpos($source,' foreach(array(0,1) as $html_on)');$cleanup=strrpos($source,'}finally{');
 if($cut===false||$cleanup===false){throw new RuntimeException('Exact signature fixture boundaries');}
 $source=substr($source,0,$cut)." self_block_profile_suite('signature');\n".substr($source,$cleanup);
 eval(self_block_code(str_replace('codex_signature_save_','codex_self_signature_block_',$source)));
}else{
 $source=file_get_contents(__DIR__.'/check-pm-current-session-native.php');
 if(substr_count($source,'pc_suite();return;')!==1){throw new RuntimeException('Exact canonical PM fixture boundary');}
 eval(self_block_code(str_replace('pc_suite();return;','self_block_pm_suite();return;',$source)));
}
