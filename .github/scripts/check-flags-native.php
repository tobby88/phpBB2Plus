<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_FLAGS_NATIVE') !== '1') { echo "Native flags checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1');putenv('PHPBB_STYLE_DATA_PORT='.(getenv('PHPBB_FLAGS_PORT')?:'3306'));putenv('PHPBB_STYLE_DATA_PASSWORD='.(getenv('PHPBB_FLAGS_PASSWORD')?:''));
$source=file_get_contents(__DIR__.'/check-style-data-native.php');$cut=strpos($source," if (getenv('PHPBB_STYLE_DATA_PROBE')");
if($cut===false){throw new RuntimeException('Shared native own-schema fixture required');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_style_data_','codex_flags_',$head);
$body= <<<'PHP'
 define('FLAG_TABLE','fixture_flags');define('PHPBB_LEGACY_REQUEST_ESCAPED',getenv('PHPBB_FLAGS_RAW')!=='1');
 require $sd_source.'language/lang_english/lang_main.php';require $sd_source.'language/lang_english/lang_admin.php';
 require_once $sd_source.'includes/functions_flags_storage.php';
 sd_check(preg_match('/CREATE TABLE phpbb_flags\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical flags');sd_sql(str_replace('phpbb_flags',FLAG_TABLE,$m[0]));
 sd_sql("ALTER TABLE fixture_users ADD user_from_flag VARCHAR(25) NULL");
 require_once dirname(rtrim($sd_source,'/')).'/update/innodb_migration.php';$covered=plus_storage_tables($schema,'fixture_');
 foreach(array('flags','users','sessions','jr_admin_users')as$suffix){sd_check(in_array('fixture_'.$suffix,$covered,true),'Existing updater covers flag participant');}
 $cases=$faults=$serialized=0;$fl_html='';$theme=array('template_name'=>'fisubsilversh','td_color1'=>'ffffff','td_color2'=>'eeeeee','td_class1'=>'row1','td_class2'=>'row2');
 $board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'xs_add_comments'=>0,'default_lang'=>'english');
 function fl_reset($actor='root'){
  sd_reset($actor==='root'?'root':'none');sd_sql('DELETE FROM fixture_flags');
  $next=phpbb_acl_rows($GLOBALS['peer'],"SELECT AUTO_INCREMENT n,VERSION() server_version FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fixture_flags'");
  if(count($next)!==1){throw new RuntimeException('Owned fixture identity metadata required');}
  // MySQL may cache information_schema statistics. Only known MariaDB exposes
  // the current allocated counter here; other servers retain the original DDL.
  $fresh=preg_match('/(?:^|-)([0-9]+\.[0-9]+\.[0-9]+)-MariaDB(?:-|$)/i',$next[0]['server_version'],$v)===1&&version_compare($v[1],'10.3.0','>=');
  if(!$fresh||(int)$next[0]['n']>3){sd_sql('ALTER TABLE fixture_flags AUTO_INCREMENT=1');}
  sd_sql("INSERT INTO fixture_flags(flag_id,flag_name,flag_image) VALUES(1,'Original','de.gif'),(2,'Other','fr.gif')");
  sd_sql("UPDATE fixture_users SET user_from_flag='de.gif' WHERE user_id=1");
  sd_sql("INSERT INTO fixture_users(user_id,user_level,user_active,user_from_flag) VALUES(2,0,1,'de.gif'),(3,0,1,'DE.gif'),(4,0,1,'fr.gif'),(5,0,1,''),(6,0,1,NULL)");
  if($actor!=='root'){$route=$actor==='wrong'?'admin_words.php':'admin_flags.php';$hash=array_search($route,jr_admin_authorization_routes(),true);sd_check($hash!==false,'Actual registered flag module');sd_sql("INSERT INTO fixture_jr VALUES(1,'".$hash."')");}
 }
 function fl_snapshot(){return array(phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_flags ORDER BY flag_id'),phpbb_acl_rows($GLOBALS['peer'],'SELECT user_id,user_from_flag FROM fixture_users ORDER BY user_id'));}
 function fl_request($mode){$r=array('mode'=>$mode==='delete'?'do_delete':'save','sid'=>'fixture-admin');if($mode!=='add'){$r['id']='1';}if($mode==='delete'){$r['confirm']='1';}else{$v="Größe ' \\ 😀 &amp;";$r['title']=PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($v):$v;$r['flag_image']='gb.png';}return$r;}
 function fl_run($request,$get=array()){
  global $db,$userdata,$phpEx,$lang,$template,$phpbb_root_path,$theme,$board_config,$fl_html;
  $_POST=$request;$_GET=$get;ob_start();
  try{include $GLOBALS['sd_source'].'admin/admin_flags.php';throw new RuntimeException('Actual flag controller must terminate');}
  catch(StyleDataExit $e){return$e->getMessage();}
  finally{$fl_html=ob_get_clean();}
 }
 function fl_success($mode,$message){$key=$mode==='delete'?'Flag_removed':($mode==='add'?'Flag_added':'Flag_updated');return strpos($message,$GLOBALS['lang'][$key])===0;}
 foreach(array('root','delegated')as$actor){foreach(array('add','edit','delete')as$mode){
  fl_reset($actor);$request=fl_request($mode);$before=fl_snapshot();sd_check(fl_success($mode,fl_run($request)),'Actual authorized flag controller');$expected=fl_snapshot();$queries=$sd_queries;
  if($mode==='add'){sd_check(count($expected[0])===3&&$expected[1]===$before[1],'Add leaves all profiles unchanged');}
  else{sd_check($expected[1][0]['user_from_flag']===($mode==='delete'?'blank.gif':'gb.png')&&$expected[1][1]['user_from_flag']===($mode==='delete'?'blank.gif':'gb.png'),'Delete or image change updates exact current references');sd_check(array_slice($expected[1],2)===array_slice($before[1],2),'Case-different, other, empty and NULL selections unchanged');}
  if($mode!=='delete'){$row=$expected[0][$mode==='add'?2:0];sd_check($row['flag_name']===phpbb_request_raw_value($request['title'])&&$row['flag_image']==='gb.png','Unicode/entities/quotes/backslashes preserved exactly once');}sd_unlocked();$cases++;
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant')as$change){fl_reset($actor);sd_sql(sd_revoke($change));$before=fl_snapshot();sd_check(!fl_success($mode,fl_run($request))&&fl_snapshot()===$before,'Fresh authority rejects obsolete cached rights');sd_unlocked();$cases++;}
  $write=($mode==='delete'?'DELETE FROM ':($mode==='add'?'INSERT INTO ':'UPDATE ')).FLAG_TABLE.' ';
  foreach(array('SELECT * FROM '.FLAG_TABLE.' LIMIT 0',$write,'COMMIT')as$boundary){
   fl_reset($actor);$before=fl_snapshot();$reached=$blocked=false;$change=$actor==='root'?'role':'grant';
   $sd_hook=function($sql)use($boundary,$change,&$reached,&$blocked){if(strpos($sql,$boundary)!==0){return;}$GLOBALS['sd_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(sd_revoke($change))){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Only actual row lock serializes revocation');$blocked=true;}};
   $out=fl_run($request);sd_check($reached,'Actual selected boundary reached');
   if($blocked){sd_check(fl_success($mode,$out)&&fl_snapshot()===$expected,'Commit precedes serialized revocation: '.$actor.'/'.$mode.'/'.$boundary.' '.$out);sd_sql(sd_revoke($change));$serialized++;}else{sd_check(!fl_success($mode,$out)&&fl_snapshot()===$before,'Earlier revocation rolls back all flag/profile changes');}
   sd_check(!fl_success($mode,fl_run($request)),'Next request observes revoked authority');sd_unlocked();$cases++;
  }
  if($actor==='root'){
   // Every observed SQL operation can fail, including the readback after a
   // persisted flag change and the later profile reset. None leaves a half save.
   foreach(array_unique($queries)as$sql){fl_reset();$before=fl_snapshot();$sd_failure=$sql;sd_check(!fl_success($mode,fl_run($request))&&fl_snapshot()===$before,'Every SQL failure preserves complete prior state: '.$sql);sd_unlocked();$faults++;}
  }
  fl_reset($actor);$sd_failure='lost-ack';sd_check(!fl_success($mode,fl_run($request))&&fl_snapshot()===$expected,'Unacknowledged COMMIT reports uncertainty, not fictitious rollback');sd_unlocked();$cases++;
  fl_reset($actor);$before=fl_snapshot();$seen=false;$sd_hook=function($sql,$connection)use($write,&$seen){if(strpos($sql,$write)!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));};sd_check(!fl_success($mode,fl_run($request))&&$seen&&fl_snapshot()===$before,'Lost owner cannot continue without its transaction');sd_unlocked();$cases++;
 }}
 foreach(array('edit','delete')as$mode){foreach(array('duplicate','case-only','no-image')as$kind){
  fl_reset();if($kind==='duplicate'){sd_sql("INSERT INTO fixture_flags(flag_id,flag_name,flag_image) VALUES(3,'Duplicate','de.gif')");}
  if($kind==='case-only'){sd_sql("INSERT INTO fixture_flags(flag_id,flag_name,flag_image) VALUES(3,'Case','DE.gif')");}
  if($kind==='no-image'){sd_sql("UPDATE fixture_flags SET flag_image='' WHERE flag_id=1");}
  $before=fl_snapshot();sd_check(fl_success($mode,fl_run(fl_request($mode))),'Shared filename controller');$after=fl_snapshot();
  sd_check($kind==='case-only'?$after[1][0]['user_from_flag']===($mode==='delete'?'blank.gif':'gb.png'):$after[1]===$before[1],'Shared exact names survive, case aliases do not count, unset profiles never swept');sd_unlocked();$cases++;
 }}
 foreach(array('add','edit','delete')as$mode){fl_reset('wrong');$before=fl_snapshot();sd_check(!fl_success($mode,fl_run(fl_request($mode)))&&fl_snapshot()===$before,'Unrelated delegated module refused');sd_unlocked();$cases++;}
 foreach(array('title'=>array('',str_repeat('😀',26),"bad\0name","\xc3",array('bad'),null,true),'flag_image'=>array('../de.gif','dir/de.gif','dir\\de.gif','a.php','https://a/de.gif',str_repeat('a',22).'.gif',array('bad'),null,true))as$field=>$values){foreach($values as$value){fl_reset();$before=fl_snapshot();$request=fl_request('edit');$request[$field]=PHPBB_LEGACY_REQUEST_ESCAPED&&is_string($value)?addslashes($value):$value;sd_check(!fl_success('edit',fl_run($request))&&fl_snapshot()===$before,'Malformed or oversized fields cannot partially change data');sd_unlocked();$cases++;}}
 foreach(array('add','edit')as$mode){foreach(array('',str_repeat('a',21).'.gif')as$image){fl_reset();$r=fl_request($mode);$r['title']=str_repeat('😀',25);$r['flag_image']=$image;sd_check(fl_success($mode,fl_run($r)),'Full 25-character name/image capacity and optional empty image');$after=fl_snapshot();sd_check($after[0][$mode==='add'?2:0]['flag_name']===str_repeat('😀',25),'Complete Unicode character capacity');sd_unlocked();$cases++;}}
 foreach(array('0','-1','1junk','1e0','2147483648','999',array('1'),null,true)as$id){fl_reset();$r=fl_request('delete');$r['id']=$id;$before=fl_snapshot();sd_check(!fl_success('delete',fl_run($r))&&fl_snapshot()===$before,'Invalid or missing selected flag cannot reset profiles');sd_unlocked();$cases++;}
 foreach(array('wrong',array('bad'))as$sid){fl_reset();$r=fl_request('edit');$r['sid']=$sid;$before=fl_snapshot();sd_check(!fl_success('edit',fl_run($r))&&fl_snapshot()===$before,'Exact signed POST required');sd_unlocked();$cases++;}
 foreach(array(FLAG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE)as$table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1')as$ddl){fl_reset();sd_sql('ALTER TABLE '.$table.' '.$ddl);$before=fl_snapshot();sd_check(!fl_success('delete',fl_run(fl_request('delete')))&&fl_snapshot()===$before,'All noncanonical participants rejected before mutation');sd_unlocked();sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC,CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}}
 foreach(array('', 'ANSI_QUOTES','STRICT_ALL_TABLES,NO_BACKSLASH_ESCAPES','TRADITIONAL')as$mode){fl_reset();$seen=false;$sd_hook=function($sql,$connection)use($mode,&$seen){if(strpos($sql,'SET SESSION sql_mode ')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;sd_check($connection->inner->sql_query("SET SESSION sql_mode='".$mode."'"),'Owned SQL mode');};$r=fl_request('edit');sd_check(fl_success('edit',fl_run($r))&&$seen&&fl_snapshot()[0][0]['flag_name']===phpbb_request_raw_value($r['title']),'Actual writer under four SQL modes');sd_unlocked();$cases++;}
 fl_reset();$original_wait=phpbb_acl_rows($peer,'SELECT @@SESSION.lock_wait_timeout n')[0]['n'];sd_sql('SET SESSION lock_wait_timeout=0');$seen=$blocked=false;
 $sd_hook=function($sql)use(&$seen,&$blocked){if(strpos($sql,'DELETE FROM fixture_flags ')!==0){return;}$GLOBALS['sd_hook']=null;$seen=true;if(!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_flags ENGINE=MyISAM')){$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Real transaction metadata lock only');$blocked=true;}};
 try{sd_check(fl_success('delete',fl_run(fl_request('delete')))&&$seen&&$blocked,'Pinned flag metadata prevents an engine swap before commit');}finally{sd_sql('SET SESSION lock_wait_timeout='.(int)$original_wait);}sd_unlocked();$cases++;
 fl_reset();sd_check($db->sql_query('START TRANSACTION'),'Caller transaction');sd_check($db->sql_query("UPDATE fixture_themes SET tr_color1='202020' WHERE themes_id=1"),'Caller unrelated pending write');$caller=phpbb_acl_rows($db,'SELECT CONNECTION_ID() id,@@SESSION.sql_mode mode,@@SESSION.autocommit autocommit');sd_check(fl_success('delete',fl_run(fl_request('delete'))),'Independent owner while caller active');sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() id,@@SESSION.sql_mode mode,@@SESSION.autocommit autocommit')===$caller&&phpbb_acl_rows($db,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='202020'&&phpbb_acl_rows($peer,'SELECT tr_color1 FROM fixture_themes WHERE themes_id=1')[0]['tr_color1']==='aaaaaa','Caller pending row stays private');sd_check($db->sql_query('ROLLBACK'),'Caller retains rollback');sd_unlocked();$cases++;
 class FlagBorrowedDatabase extends StyleDataDatabase{function sql_dedicated_connection(){return$this;}}
 fl_reset();$borrowed=new FlagBorrowedDatabase($db->inner);$before=fl_snapshot();$caught=false;try{phpbb_flags_change($borrowed,fl_request('delete'));}catch(PhpbbAclException $e){$caught=true;}sd_check($caught&&fl_snapshot()===$before&&$db->sql_query('SELECT 1'),'Borrowed factory refused without closing caller');sd_unlocked();$cases++;
 $fl_other=$fixture.'_other';sd_check($control->sql_query('CREATE DATABASE '.$fl_other.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Second owned schema');
 class FlagForeignDatabase extends StyleDataDatabase{function sql_dedicated_connection(){return new StyleDataConnection(new sql_db($GLOBALS['host'],'root',$GLOBALS['password'],$GLOBALS['fl_other'],false));}}
 fl_reset();$foreign=new FlagForeignDatabase($db->inner);$before=fl_snapshot();$caught=false;try{phpbb_flags_change($foreign,fl_request('delete'));}catch(PhpbbAclException $e){$caught=true;}sd_check($caught&&fl_snapshot()===$before&&$db->sql_query('SELECT 1'),'A different selected database is rejected before any table or data access');sd_unlocked();$cases++;
 foreach(array('autocommit','foreign-update','foreign-insert','ddl','double-begin','after-commit','after-release')as$kind){fl_reset();$before=fl_snapshot();$owner=new PhpbbFlagsWriter($db);$caught=false;try{
  if($kind!=='autocommit'&&$kind!=='after-release'){$owner->sql_query('START TRANSACTION');}
  if($kind==='ddl'){$owner->sql_query('ALTER TABLE fixture_flags ENGINE=MyISAM');}
  elseif($kind==='double-begin'){$owner->sql_query('START TRANSACTION');}
  elseif($kind==='foreign-update'){$owner->sql_query('UPDATE fixture_users SET user_level=1');}
  elseif($kind==='foreign-insert'){$owner->sql_query('INSERT INTO fixture_users(user_id) VALUES(99)');}
  else{if($kind==='after-commit'){$owner->sql_query('COMMIT');}if($kind==='after-release'){$owner->release();}$owner->sql_query("UPDATE fixture_flags SET flag_name='bad'");}
 }catch(PhpbbAclException $e){$caught=true;}finally{$owner->release();}sd_check($caught&&fl_snapshot()===$before,'Owner rejects autocommit, foreign table or completed lifecycle');sd_unlocked();$cases++;}
 // Actual standard templates and controller views; no source assertions stand
 // in for the rendered form, locale, session field or stale selection handling.
 require_once $sd_source.'includes/template.php';
 $footer=$sd_root.'/admin/page_footer_admin.php';file_put_contents($footer,'<?php throw new StyleDataExit("view");');$sd_files[]=$footer;$views=0;
 foreach(array('english','german')as$locale){$lang=array();require $sd_source.'language/lang_'.$locale.'/lang_main.php';require $sd_source.'language/lang_'.$locale.'/lang_admin.php';foreach(array('list','add','edit','delete','cancel','missing','bad-id','unconfirmed')as$view){
  fl_reset();$template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($sd_source.'templates/fisubsilversh',false);$before=fl_snapshot();$get=$post=array();$_SERVER['REQUEST_METHOD']='GET';
  if($view==='cancel'){$post=array('mode'=>'do_delete','id'=>'1','confirm'=>'1','cancel'=>'1');}
  elseif($view==='unconfirmed'){$post=array('mode'=>'do_delete','id'=>'1');}
  elseif($view!=='list'){$get=array('mode'=>$view==='missing'||$view==='bad-id'?'edit':$view,'id'=>$view==='missing'?'999':($view==='bad-id'?array('1'):'1'));}
  $out=fl_run($post,$get);sd_check(fl_snapshot()===$before,'All GET/preview/cancel/unconfirmed views leave data intact');
  if($view==='missing'||$view==='bad-id'){sd_check($out===$lang['Must_select_flag']&&$fl_html==='','Missing selection has localized response, no broken form');}
  else{sd_check($out==='view'&&strpos($fl_html,'<form')!==false,'Actual XS controller view');if($view==='add'||$view==='edit'){sd_check(substr_count($fl_html,'maxlength="25"')===2&&strpos($fl_html,'name="sid" value="fixture-admin"')!==false,'Form capacities and signed session');}if($view==='delete'){sd_check(strpos($fl_html,'name="mode" value="do_delete"')!==false&&strpos($fl_html,'name="sid" value="fixture-admin"')!==false,'Actual standard confirmation');}}
  $views++;
 }}
 echo 'Flags native: '.$cases.' cases, '.$faults.' SQL faults, '.$serialized.' serialized revocations, '.$views.' actual EN/DE XS views PASS; atomic flag/profile changes, exact filename references, current authority, caller isolation, input capacity and owner lifecycle.',"\n";
PHP;
$tail= <<<'PHP'
}finally{
 $sd_hook=null;$sd_failure='';$db->sql_close();$peer->sql_close();if(isset($fl_other)){$control->sql_query('DROP DATABASE '.$fl_other);}$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($sd_previous);
 if(is_file($sd_root.'/cache/themes.cache')){unlink($sd_root.'/cache/themes.cache');}foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}}foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($head.$body.$tail);
