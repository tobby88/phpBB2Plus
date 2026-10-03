<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CT_USERS_NATIVE') !== '1') { echo "CrackerTracker user checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1');
putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_CT_USERS_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_CT_USERS_PASSWORD') ?: ''));
$source = file_get_contents(__DIR__ . '/check-style-data-native.php');
$cut = strpos($source, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($cut === false) { throw new RuntimeException('Shared native fixture boundary missing'); }
$head = str_replace('__DIR__', var_export(__DIR__, true), substr($source, 5, $cut - 5));
$head = str_replace('codex_style_data_', 'codex_ct_users_', $head);
$head = str_replace('INSERT INTO fixture_users VALUES', 'INSERT INTO fixture_users (user_id,user_level,user_active) VALUES', $head);
$body = <<<'PHP'
 define('MOD',2); define('ANONYMOUS',-1); define('CTRACKER_ACP',true);
 define('PHPBB_LEGACY_REQUEST_ESCAPED',getenv('PHPBB_CT_USERS_RAW')!=='1');
 require $sd_source.'language/lang_english/lang_admin.php';
 require $sd_source.'language/lang_english/lang_cback_ctracker.php';
 $loader=file_get_contents(__DIR__.'/check-attachment-settings-storage.php');
 $a=strpos($loader,'function ats_load_function('); $b=strpos($loader,'ats_load_function($ats_source',$a);
 sd_check($a!==false&&$b>$a,'Production function loader'); eval(substr($loader,$a,$b-$a));
 function ats_check($ok,$message){sd_check($ok,$message);}
 foreach(array('phpbb_rtrim','phpbb_clean_username','get_userdata')as$name){ats_load_function($sd_source.'includes/functions.php',$name);}
 sd_sql("ALTER TABLE fixture_users ADD username VARCHAR(25) NOT NULL DEFAULT 'Actor', ADD ct_miserable_user TINYINT NOT NULL DEFAULT 0");
 class CtUserTemplate {
  var $blocks=array();
  function set_filenames($files){}
  function assign_vars($vars){}
  function assign_block_vars($name,$value){$this->blocks[$name][]=$value;}
  function pparse($name){throw new StyleDataExit('rendered');}
 }
 function ctu_reset($actor='root',$flag=0){
  sd_reset($actor==='root'?'root':'none');
  sd_sql("INSERT INTO fixture_users VALUES(2,0,1,'A&amp;B',".(int)$flag.")");
  if($actor!=='root'){
   $route=$actor==='wrong'?'admin_cracker_tracker.php?modu=9':'admin_cracker_tracker.php?modu=8';
   $hash=array_search($route,jr_admin_authorization_routes(),true);sd_check($hash!==false,'Actual exact tracker module grant');
   sd_sql("INSERT INTO fixture_jr VALUES(1,'".$hash."')");
  }
 }
 function ctu_request($action){return $action==='mark'?array('submit'=>'1','username'=>'A&B','sid'=>'fixture-admin'):array('mode'=>'unmis','userid'=>'2','sid'=>'fixture-admin');}
 function ctu_run($request){
  global $db,$userdata,$phpbb_root_path,$phpEx,$lang,$template,$HTTP_POST_VARS,$HTTP_GET_VARS;
  $_POST=$HTTP_POST_VARS=$request;$_GET=$HTTP_GET_VARS=array();$template=new CtUserTemplate();
  try{include $GLOBALS['sd_source'].'ctracker/admin/acp_module_miserableuser.php';throw new RuntimeException('Controller must render');}
  catch(StyleDataExit $e){return $e->getMessage();}
 }
 function ctu_success($action,$out){return $out==='rendered'&&isset($GLOBALS['template']->blocks['infobox'][0])&&$GLOBALS['template']->blocks['infobox'][0]['L_MESSAGE_TEXT']===$GLOBALS['lang'][$action==='mark'?'ctracker_mu_success':'ctracker_mu_deleted'];}
 function ctu_snap(){return phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_users ORDER BY user_id');}
 if(getenv('PHPBB_CT_USERS_PROBE')==='1'){
  ctu_reset();sd_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');
  $out=ctu_run(ctu_request('mark'));
  sd_check(ctu_success('mark',$out)&&(int)ctu_snap()[1]['ct_miserable_user']===1,'Actual stale-admin mutation reproduced');
  echo "Reproduced: CrackerTracker marks a member after the actor lost admin rights.\n";
 }else{
  $cases=$serialized=0;
  foreach(array('root','delegated')as$actor){foreach(array('mark','unmark')as$action){
   ctu_reset($actor,$action==='unmark'?1:0);$before=ctu_snap();sd_check(ctu_success($action,ctu_run(ctu_request($action))),'Actual authorized controller');$expected=ctu_snap();
   sd_check((int)$expected[1]['ct_miserable_user']===($action==='mark'?1:0)&&$expected[0]===$before[0],'Only selected flag changed');sd_unlocked();$cases++;
   foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$change){
    ctu_reset($actor,$action==='unmark'?1:0);sd_sql(sd_revoke($change));$before=ctu_snap();
    sd_check(!ctu_success($action,ctu_run(ctu_request($action)))&&ctu_snap()===$before,'Current actor/session refusal '.$change);sd_unlocked();$cases++;
   }
   foreach(array('missing',$actor==='root'?'role':'grant')as$change){foreach(array('before-lock','write','commit')as$boundary){
    ctu_reset($actor,$action==='unmark'?1:0);$before=ctu_snap();$seen=$blocked=false;$revoked=null;
    $sd_hook=function($sql)use($change,$boundary,&$seen,&$blocked,&$revoked){
     $match=$boundary==='before-lock'?$sql==='SELECT * FROM fixture_users LIMIT 0':($boundary==='write'?strpos($sql,'UPDATE fixture_users SET ct_miserable_user=')===0:$sql==='COMMIT');if(!$match){return;}
     $GLOBALS['sd_hook']=null;$seen=true;sd_sql('SET SESSION innodb_lock_wait_timeout=0');
     try{if(!$GLOBALS['peer']->sql_query(sd_revoke($change))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Native authority lock');$blocked=true;}else{$revoked=ctu_snap();}}finally{sd_sql('SET SESSION innodb_lock_wait_timeout=1');}
    };
    $out=ctu_run(ctu_request($action));sd_check($seen,'Revocation boundary reached');
    if($blocked){sd_check(ctu_success($action,$out)&&ctu_snap()===$expected,'Complete authorized write before revocation');$serialized++;}
    else{sd_check(!ctu_success($action,$out)&&ctu_snap()===$revoked,'Earlier revocation refuses whole write');}
    sd_unlocked();$cases++;
   }}
   foreach(array('UPDATE fixture_users SET ct_miserable_user=','COMMIT','lost-ack')as$failure){ctu_reset($actor,$action==='unmark'?1:0);$before=ctu_snap();$sd_failure=$failure;sd_check(!ctu_success($action,ctu_run(ctu_request($action)))&&ctu_snap()===($failure==='lost-ack'?$expected:$before),'Failure/uncertain commit has no confirmed success');sd_unlocked();$cases++;}
  }}
  foreach(array('mark','unmark')as$action){ctu_reset('wrong',1);$before=ctu_snap();sd_check(!ctu_success($action,ctu_run(ctu_request($action)))&&ctu_snap()===$before,'Different tracker module is not an authorization');sd_unlocked();$cases++;}
  foreach(array('mark','unmark')as$action){
   ctu_reset('root',$action==='mark'?1:0);$before=ctu_snap();sd_check(ctu_success($action,ctu_run(ctu_request($action)))&&ctu_snap()===$before,'Already matching flag is a verified idempotent outcome');sd_unlocked();$cases++;
   foreach(array('get','bad-sid','array-sid')as$bad){ctu_reset('root',1);$r=ctu_request($action);if($bad==='get'){$_SERVER['REQUEST_METHOD']='GET';}else{$r['sid']=$bad==='array-sid'?array('fixture-admin'):'wrong';}$before=ctu_snap();sd_check(!ctu_success($action,ctu_run($r))&&ctu_snap()===$before,'Exact POST session required');sd_unlocked();$cases++;}
  }
  foreach(array(1,2)as$role){ctu_reset();sd_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id=2');$before=ctu_snap();sd_check(!ctu_success('mark',ctu_run(ctu_request('mark')))&&ctu_snap()===$before,'Protected current role cannot be marked');sd_unlocked();$cases++;}
  foreach(array(1,2)as$role){ctu_reset('root',1);sd_sql('UPDATE fixture_users SET user_level='.$role.' WHERE user_id=2');sd_check(ctu_success('unmark',ctu_run(ctu_request('unmark')))&&(int)ctu_snap()[1]['ct_miserable_user']===0,'Existing protected-role flag can be cleared');sd_unlocked();$cases++;}
  foreach(array('UPDATE fixture_users SET user_level=1 WHERE user_id=2','DELETE FROM fixture_users WHERE user_id=2',"UPDATE fixture_users SET username='Renamed' WHERE user_id=2")as$change){
   foreach(array('before-lock','write','commit')as$boundary){ctu_reset();$seen=$blocked=false;$after=null;
    $sd_hook=function($sql)use($change,$boundary,&$seen,&$blocked,&$after){$match=$boundary==='before-lock'?$sql==='SELECT * FROM fixture_users LIMIT 0':($boundary==='write'?strpos($sql,'UPDATE fixture_users SET ct_miserable_user=')===0:$sql==='COMMIT');if(!$match){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_sql('SET SESSION innodb_lock_wait_timeout=0');try{if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Native target lock');$blocked=true;}else{$after=ctu_snap();}}finally{sd_sql('SET SESSION innodb_lock_wait_timeout=1');}};
    $out=ctu_run(ctu_request('mark'));sd_check($seen,'Target change boundary reached');if($blocked){sd_check(ctu_success('mark',$out)&&(int)ctu_snap()[1]['ct_miserable_user']===1,'Target change serializes after valid marking');$serialized++;}else{sd_check(!ctu_success('mark',$out)&&ctu_snap()===$after,'Earlier role/identity/deletion change refuses marking');}sd_unlocked();$cases++;
   }
  }
  foreach(array('0','-1','2junk','2e0','8388608','999',array('2'),null,true)as$id){ctu_reset('root',1);$r=ctu_request('unmark');$r['userid']=$id;$before=ctu_snap();sd_check(!ctu_success('unmark',ctu_run($r))&&ctu_snap()===$before,'Malformed/missing target refuses removal');sd_unlocked();$cases++;}
  foreach(array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1')as$ddl){ctu_reset();sd_sql('ALTER TABLE '.$table.' '.$ddl);$before=ctu_snap();sd_check(!ctu_success('mark',ctu_run(ctu_request('mark')))&&ctu_snap()===$before,'Canonical migrated participant required');sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');sd_unlocked();$cases++;}}
  foreach(array('mark','unmark')as$action){ctu_reset('root',$action==='unmark'?1:0);$seen=false;$sd_hook=function($sql,$connection)use(&$seen){if(strpos($sql,'UPDATE fixture_users SET ct_miserable_user=')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));};$before=ctu_snap();sd_check(!ctu_success($action,ctu_run(ctu_request($action)))&&$seen&&ctu_snap()===$before,'Lost dedicated owner cannot continue');sd_unlocked();$cases++;}
  ctu_reset();sd_check($db->sql_query('START TRANSACTION'),'Caller transaction');sd_check($db->sql_query("UPDATE fixture_themes SET tr_color1='202020' WHERE themes_id=1"),'Caller pending unrelated write');$caller=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');sd_check(ctu_success('mark',ctu_run(ctu_request('mark'))),'Dedicated user flag owner');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$caller&&phpbb_acl_rows($peer,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='aaaaaa','Caller transaction remains private');sd_check($db->sql_query('ROLLBACK'),'Caller owns rollback');sd_unlocked();$cases++;
  ctu_reset();$seen=$blocked=false;sd_sql('SET SESSION lock_wait_timeout=0');$sd_hook=function($sql)use(&$seen,&$blocked){if(strpos($sql,'UPDATE fixture_users SET ct_miserable_user=')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_users ENGINE=MyISAM')){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Native metadata lock only');$blocked=true;}};sd_check(ctu_success('mark',ctu_run(ctu_request('mark')))&&$seen&&$blocked,'Storage definition held through publication');sd_sql('SET SESSION lock_wait_timeout=1');sd_unlocked();$cases++;
  ctu_reset();sd_check($db->sql_query('START TRANSACTION'),'Conflicting caller transaction');sd_check($db->sql_query('UPDATE fixture_users SET user_level=1 WHERE user_id=2'),'Caller uncommitted target promotion');$before=ctu_snap();$caller=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');$sd_hook=function($sql,$connection){if(strpos($sql,'SET SESSION sql_mode ')!==0){return;}$GLOBALS['sd_hook']=null;sd_check($connection->inner->sql_query('SET SESSION innodb_lock_wait_timeout=0'),'Bounded conflicting owner lock');};sd_check(!ctu_success('mark',ctu_run(ctu_request('mark')))&&ctu_snap()===$before,'Conflicting caller row refuses whole change');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$caller&&(int)phpbb_acl_rows($db,'SELECT user_level FROM fixture_users WHERE user_id=2')[0]['user_level']===1,'Refusal preserves caller pending target change');sd_check($db->sql_query('ROLLBACK'),'Caller alone controls conflicting rollback');sd_unlocked();$cases++;
  class CtUserReuseDatabase extends StyleDataDatabase {function sql_dedicated_connection(){return $this;}}
  ctu_reset();$original=$db;$db=new CtUserReuseDatabase($original->inner);$before=ctu_snap();sd_check(!ctu_success('mark',ctu_run(ctu_request('mark')))&&ctu_snap()===$before&&$db->db_connect_id instanceof mysqli,'Factory reuse is refused without closing source');$db=$original;sd_unlocked();$cases++;
  require_once dirname(rtrim($sd_source,'/')).'/update/innodb_migration.php';$covered=plus_storage_tables($schema,'fixture_');foreach(array('users','sessions','jr_admin_users')as$kind){sd_check(in_array('fixture_'.$kind,$covered,true),'Current migration covers authority/target participant');}
  echo 'CrackerTracker user flags: '.$cases.' cases; '.$serialized." concurrent changes serialized.\n";
 }
PHP;
$body = str_replace('__DIR__', var_export(__DIR__,true), $body);
$tail = <<<'PHP'
}finally{
 $sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
