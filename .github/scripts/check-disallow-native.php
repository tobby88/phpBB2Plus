<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_DISALLOW_NATIVE')!=='1'){echo "Native disallow checks require an explicitly enabled disposable database.\n";return;}
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_DISALLOW_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_DISALLOW_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Shared native fixture boundary missing');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_disallow_',$head);
$head=str_replace('INSERT INTO fixture_users VALUES','INSERT INTO fixture_users (user_id,user_level,user_active) VALUES',$head);
$body= <<<'PHP'
 define('DISALLOW_TABLE','fixture_disallow');define('WORDS_TABLE','fixture_words');define('GROUPS_TABLE','fixture_groups');
 define('PHPBB_LEGACY_REQUEST_ESCAPED',getenv('PHPBB_DISALLOW_RAW')!=='1');
 require $sd_source.'language/lang_english/lang_main.php';require $sd_source.'language/lang_english/lang_admin.php';require_once $sd_source.'includes/functions_content_admin.php';
 foreach(array('disallow','words','groups')as$kind){sd_check(preg_match('/CREATE TABLE phpbb_'.$kind.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical policy table');sd_sql(str_replace('phpbb_'.$kind,'fixture_'.$kind,$m[0]));}
 sd_sql("ALTER TABLE fixture_users ADD username VARCHAR(25) NOT NULL DEFAULT 'existing'");
 require_once dirname(rtrim($sd_source,'/')).'/update/innodb_migration.php';$covered=plus_storage_tables($schema,'fixture_');
 foreach(array('disallow','words','groups','users','sessions','jr_admin_users')as$kind){sd_check(in_array('fixture_'.$kind,$covered,true),'Updater covers every validation participant');}
 function da_reset($actor='root'){
  sd_reset($actor==='root'?'root':'none');
  foreach(array('disallow','words','groups')as$table){sd_sql('DELETE FROM fixture_'.$table);sd_sql('ALTER TABLE fixture_'.$table.' AUTO_INCREMENT=1');}
  sd_sql("INSERT INTO fixture_disallow VALUES (1,'reserved*')");
  if($actor!=='root'){$hash=array_search($actor==='wrong'?'admin_words.php':'admin_disallow.php',jr_admin_authorization_routes(),true);sd_check($hash!==false,'Exact disallow module grant');sd_sql("INSERT INTO fixture_jr VALUES(1,'".$hash."')");}
 }
 function da_snapshot(){return phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_disallow ORDER BY disallow_id');}
 function da_request($mode){$value="Grüße ' \\ 😀";return $mode==='add'?array('add_name'=>'1','disallowed_user'=>PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($value):$value,'sid'=>'fixture-admin'):array('delete_name'=>'1','disallowed_id'=>'1','sid'=>'fixture-admin');}
 function da_run($request){global $db,$userdata,$phpbb_root_path,$phpEx,$lang,$template;$_POST=$request;$_GET=array();try{include $GLOBALS['sd_source'].'admin/admin_disallow.php';throw new RuntimeException('Actual controller must terminate');}catch(StyleDataExit $error){return $error->getMessage();}}
 function da_success($mode,$out){return strpos($out,$GLOBALS['lang'][$mode==='add'?'Disallow_successful':'Disallowed_deleted'])===0;}
 $cases=$serialized=0;
 foreach(array('root','delegated')as$actor){foreach(array('add','delete')as$mode){
  da_reset($actor);$request=da_request($mode);$before=da_snapshot();sd_check(da_success($mode,da_run($request)),'Actual authorized disallow '.$actor.'/'.$mode);$expected=da_snapshot();
  if($mode==='add'){sd_check($expected[1]['disallow_username']===phpbb_request_raw_value($request['disallowed_user']),'Unicode, quote/backslash raw value stored once');}else{sd_check(!$expected,'Exact restriction deleted');}sd_unlocked();$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$change){da_reset($actor);sd_sql(sd_revoke($change));$before=da_snapshot();sd_check(!da_success($mode,da_run($request))&&da_snapshot()===$before,'Entry authority refusal '.$change);sd_check(!array_filter($sd_queries,function($sql){return preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql);}), 'No mutation before authority');sd_unlocked();$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant')as$change){foreach(array('before-lock','write','commit')as$boundary){
   da_reset($actor);$before=da_snapshot();$seen=$blocked=false;
   $sd_hook=function($sql)use($boundary,$change,&$seen,&$blocked){$match=$boundary==='before-lock'?$sql==='SELECT * FROM fixture_disallow LIMIT 0':($boundary==='commit'?$sql==='COMMIT':preg_match('/^(INSERT INTO|DELETE FROM) fixture_disallow\b/',$sql));if(!$match){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_sql('SET SESSION innodb_lock_wait_timeout=0');try{if(!$GLOBALS['peer']->sql_query(sd_revoke($change))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Real native authority lock');$blocked=true;}}finally{sd_sql('SET SESSION innodb_lock_wait_timeout=1');}};
   $out=da_run($request);sd_check($seen,'Native revocation boundary reached');if($blocked){sd_check(da_success($mode,$out)&&da_snapshot()===$expected,'Authorized complete commit precedes peer revocation');sd_sql(sd_revoke($change));$serialized++;}else{sd_check(!da_success($mode,$out)&&da_snapshot()===$before,'Earlier revocation prevents mutation');}sd_check(!da_success($mode,da_run($request)),'Following revoked request denied');sd_unlocked();$cases++;
  }}
  $write=($mode==='add'?'INSERT INTO ':'DELETE FROM ').'fixture_disallow ';
  foreach(array($write,'COMMIT','lost-ack')as$failure){da_reset($actor);$before=da_snapshot();$sd_failure=$failure;sd_check(!da_success($mode,da_run($request))&&da_snapshot()===($failure==='lost-ack'?$expected:$before),'Failure rollback/uncertain acknowledgement');sd_unlocked();$cases++;}
  da_reset($actor);$before=da_snapshot();$seen=false;$sd_hook=function($sql,$connection)use($write,&$seen){if(strpos($sql,$write)!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));};sd_check(!da_success($mode,da_run($request))&&$seen&&da_snapshot()===$before,'Lost owner cannot continue without lock/transaction');sd_unlocked();$cases++;
 }}
 foreach(array('add','delete')as$mode){da_reset('wrong');$before=da_snapshot();sd_check(!da_success($mode,da_run(da_request($mode)))&&da_snapshot()===$before,'Unrelated delegated route refused');sd_unlocked();$cases++;}
 foreach(array(array('bad'),null,true,1,"\xc3",PHPBB_LEGACY_REQUEST_ESCAPED?addslashes("a\0b"):"a\0b",'',str_repeat('😀',26))as$bad){da_reset();$before=da_snapshot();$request=da_request('add');$request['disallowed_user']=$bad;sd_check(!da_success('add',da_run($request))&&da_snapshot()===$before,'Invalid or oversized raw pattern refused before writes');sd_unlocked();$cases++;}
 foreach(array('0','-1','1junk','1e0','16777216','999',array('1'),null,true)as$bad){da_reset();$before=da_snapshot();$request=da_request('delete');$request['disallowed_id']=$bad;sd_check(!da_success('delete',da_run($request))&&da_snapshot()===$before,'Malformed/missing selection cannot remove other restrictions');sd_unlocked();$cases++;}
 foreach(array('wrong',array('bad'))as$sid){da_reset();$before=da_snapshot();$request=da_request('add');$request['sid']=$sid;sd_check(!da_success('add',da_run($request))&&da_snapshot()===$before,'Exact valid POST session required');sd_unlocked();$cases++;}
 foreach(array('existing','group','reserved','censored','unicode','exact-backslash')as$rule){
  da_reset();$request=da_request('add');$value='reserved-name';
  if($rule==='existing'){$value='EXISTING';}
  if($rule==='group'){sd_sql("INSERT INTO fixture_groups(group_name,group_description) VALUES ('groupname','')");$value='GROUPNAME';}
  if($rule==='censored'){sd_sql("INSERT INTO fixture_words(word,replacement) VALUES ('censor*','x')");$value='censored';}
  if($rule==='unicode'){sd_sql("INSERT INTO fixture_disallow(disallow_username) VALUES ('Ärger')");$value='ärger';}
  if($rule==='exact-backslash'){$value='C:\\notes';sd_sql("INSERT INTO fixture_disallow(disallow_username) VALUES ('".$peer->sql_escape($value)."')");}
  $request['disallowed_user']=PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($value):$value;$before=da_snapshot();sd_check(strpos(da_run($request),$lang['Disallowed_already'])===0&&da_snapshot()===$before,'Existing current name/rule refused without duplicate: '.$rule);sd_unlocked();$cases++;
 }
 foreach(array(str_repeat('😀',25),'*','new*pattern',"', user_level=1 --")as$value){da_reset();$request=da_request('add');$request['disallowed_user']=PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($value):$value;sd_check(da_success('add',da_run($request))&&da_snapshot()[1]['disallow_username']===$value,'Full schema character capacity, wildcard and SQL literals preserved');sd_check((int)phpbb_acl_rows($peer,'SELECT user_level FROM fixture_users WHERE user_id=1')[0]['user_level']===1,'Pattern cannot alter privilege columns');sd_unlocked();$cases++;}
 foreach(array(DISALLOW_TABLE,WORDS_TABLE,GROUPS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1')as$ddl){da_reset();sd_sql('ALTER TABLE '.$table.' '.$ddl);$before=da_snapshot();sd_check(!da_success('add',da_run(da_request('add')))&&da_snapshot()===$before,'Noncanonical validation participant refused');sd_unlocked();sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}}
 da_reset();$seen=$blocked=false;sd_sql('SET SESSION lock_wait_timeout=0');$sd_hook=function($sql)use(&$seen,&$blocked){if(strpos($sql,'INSERT INTO fixture_disallow ')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_disallow ENGINE=MyISAM')){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Native metadata lock only');$blocked=true;}};sd_check(da_success('add',da_run(da_request('add')))&&$seen&&$blocked,'DDL cannot replace transactional storage before commit');sd_sql('SET SESSION lock_wait_timeout=1');sd_unlocked();$cases++;
 // The caller's uncommitted changes remain private and uncommitted.
 da_reset();sd_check($db->sql_query('START TRANSACTION'),'Caller begin');sd_check($db->sql_query("UPDATE fixture_themes SET tr_color1='202020' WHERE themes_id=1"),'Caller unrelated pending write');$caller=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');sd_check(da_success('add',da_run(da_request('add'))),'Dedicated disallow owner');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$caller&&phpbb_acl_rows($peer,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='aaaaaa','Caller state and unrelated transaction untouched');sd_check($db->sql_query('ROLLBACK'),'Caller rollback only');sd_unlocked();$cases++;
 da_reset();sd_sql("INSERT INTO fixture_users VALUES(2,0,1,'other')");sd_check($db->sql_query('START TRANSACTION'),'Conflicting caller begin');sd_check($db->sql_query('UPDATE fixture_users SET user_active=0 WHERE user_id=2'),'Caller conflicting pending write');$before=da_snapshot();$caller=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');
 $sd_hook=function($sql,$connection){if(strpos($sql,'SET SESSION sql_mode ')!==0){return;}$GLOBALS['sd_hook']=null;sd_check($connection->inner->sql_query('SET SESSION innodb_lock_wait_timeout=0'),'Bounded native conflicting lock');};
 sd_check(!da_success('add',da_run(da_request('add')))&&da_snapshot()===$before,'Conflicting caller lock refuses whole change, not implicit caller commit/rollback');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$caller&&(int)phpbb_acl_rows($db,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===0&&(int)phpbb_acl_rows($peer,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===1,'Refused owner leaves caller pending row private');sd_check($db->sql_query('ROLLBACK'),'Conflicting caller rollback only');sd_unlocked();$cases++;
 class DisallowRenderTemplate{var $vars=array();function set_filenames($files){}function assign_vars($vars){$this->vars=$vars;}function pparse($name){}}
 $template=new DisallowRenderTemplate();$footer=$sd_root.'/admin/page_footer_admin.php';file_put_contents($footer,'<?php throw new StyleDataExit("rendered");');$sd_files[]=$footer;
 da_reset();sd_sql('DELETE FROM fixture_disallow');$_SERVER['REQUEST_METHOD']='GET';sd_check(da_run(array())==='rendered'&&strpos($template->vars['S_DISALLOW_SELECT'],$lang['No_disallowed'])!==false,'Empty list renders the actual localized language key without warnings');$cases++;
 echo 'Disallow native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
PHP;
$tail= <<<'PHP'
}finally{
 $sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
