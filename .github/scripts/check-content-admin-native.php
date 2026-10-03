<?php
// Execute the actual ACP controllers against an explicitly enabled disposable DB.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CONTENT_NATIVE') !== '1') { echo "Native content checks require an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_STYLE_DATA_NATIVE=1');
putenv('PHPBB_STYLE_DATA_PORT=' . (getenv('PHPBB_CONTENT_PORT') ?: '3306'));
putenv('PHPBB_STYLE_DATA_PASSWORD=' . (getenv('PHPBB_CONTENT_PASSWORD') ?: ''));
$source = file_get_contents(__DIR__ . '/check-style-data-native.php');
$cut = strpos($source, " if (getenv('PHPBB_STYLE_DATA_PROBE')");
if ($cut === false) { throw new RuntimeException('Shared native fixture boundary missing'); }
$head = str_replace('__DIR__', var_export(__DIR__, true), substr($source, 5, $cut - 5));
$head = str_replace('codex_style_data_', 'codex_content_', $head);
$body = <<<'PHP'
 define('WORDS_TABLE','fixture_words'); define('ACRONYMS_TABLE','fixture_acronyms');
 define('PHPBB_LEGACY_REQUEST_ESCAPED', getenv('PHPBB_CONTENT_RAW') !== '1');
 require $sd_source . 'language/lang_english/lang_admin.php';
 foreach (array('words','acronyms') as $kind) {
  sd_check(preg_match('/CREATE TABLE phpbb_'.$kind.'\s*\([\s\S]*?;/', $schema, $m) === 1, 'Canonical content schema');
  sd_sql(str_replace('phpbb_'.$kind, 'fixture_'.$kind, $m[0]));
 }
 function ca_reset($kind, $actor = 'root') {
  sd_reset($actor === 'root' ? 'root' : 'none');
  foreach (array('words','acronyms') as $table) { sd_sql('DELETE FROM fixture_'.$table); sd_sql('ALTER TABLE fixture_'.$table.' AUTO_INCREMENT=1'); }
  sd_sql("INSERT INTO fixture_words VALUES (1,'Original','Before')");
  sd_sql("INSERT INTO fixture_acronyms VALUES (1,'Original','Before')");
  if ($actor !== 'root') {
   $route = $actor === 'wrong' ? 'admin_ranks.php' : 'admin_'.$kind.'.php';
   $hash = array_search($route, jr_admin_authorization_routes(), true); sd_check($hash !== false, 'Exact registered module');
   sd_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");
  }
  file_put_contents($GLOBALS['sd_root'].'/cache/words.cache', 'stale cache');
 }
 function ca_snapshot() {
  return array(phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_words ORDER BY word_id'),
   phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM fixture_acronyms ORDER BY acronym_id'));
 }
 function ca_request($kind, $mode) {
  $request = array('mode'=>$mode === 'delete' ? 'delete' : 'save', 'sid'=>'fixture-admin');
  if ($mode !== 'add') { $request['id']='1'; }
  if ($mode === 'delete') { $request['confirm']='1'; }
  else { foreach (array_keys(phpbb_content_admin_definition($kind)['fields']) as $field) { $text="Größe ' \\ $1 😀 &amp;"; $request[$field]=PHPBB_LEGACY_REQUEST_ESCAPED?addslashes($text):$text; } }
  return $request;
 }
 function ca_run($kind, $request) {
  global $db,$phpEx,$lang,$userdata,$phpbb_root_path,$template;
  $_GET=array(); $_POST=$request;
  try { include $GLOBALS['sd_source'].'admin/admin_'.$kind.'.php'; throw new RuntimeException('Actual controller must terminate'); }
  catch (StyleDataExit $error) { return $error->getMessage(); }
 }
 function ca_success($kind, $mode, $message) {
  $key = ($kind === 'words' ? 'Word' : 'Acronym').($mode === 'add' ? '_added' : ($mode === 'delete' ? '_removed' : '_updated'));
  return strpos($message, $GLOBALS['lang'][$key]) === 0;
 }
 require_once $sd_source.'includes/functions_content_admin.php';
 require_once dirname(rtrim($sd_source,'/')).'/update/innodb_migration.php';
 $covered=plus_storage_tables($schema,'fixture_');
 foreach(array('words','acronyms','users','sessions','jr_admin_users')as$suffix){
  // The minimal authority fixture calls its canonical jr_admin table fixture_jr.
  sd_check(in_array('fixture_'.$suffix,$covered,true),'Existing updater covers content/authority storage: '.$suffix);
 }
 $cases = 0; $serialized = 0;
 if (getenv('PHPBB_CONTENT_CASE') !== 'tail') {
 foreach (array('words','acronyms') as $kind) {
  foreach (array('root','delegated') as $actor) { foreach (array('add','edit','delete') as $mode) {
   ca_reset($kind,$actor); $request=ca_request($kind,$mode); $before=ca_snapshot();
   sd_check(ca_success($kind,$mode,ca_run($kind,$request)), 'Actual authorized controller '.$kind.'/'.$actor.'/'.$mode);
   $expected=ca_snapshot(); $rows=$expected[$kind === 'words' ? 0 : 1];
   if ($mode === 'delete') { sd_check(!$rows,'Exact row removed'); }
   else {
    $row=$rows[count($rows)-1]; foreach (array_keys(phpbb_content_admin_definition($kind)['fields']) as $field) {
     sd_check($row[$field] === phpbb_request_raw_value($request[$field]),'Raw UTF-8, entities and backslashes stored exactly once');
    }
   }
   sd_check($kind !== 'words' || !file_exists($sd_root.'/cache/words.cache'),'Word cache evicted'); sd_unlocked(); $cases++;
   foreach (array('inactive','missing','admin-off','foreign','logout','case',$actor === 'root' ? 'role' : 'grant') as $change) {
    ca_reset($kind,$actor); sd_sql(sd_revoke($change)); $before=ca_snapshot();
    sd_check(!ca_success($kind,$mode,ca_run($kind,$request)) && ca_snapshot()===$before,'Current authority refusal '.$change);
    sd_check(!array_filter($sd_queries,function($sql){return preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql);}), 'No writes on revoked request'); sd_unlocked(); $cases++;
   }
   foreach (array('missing',$actor === 'root' ? 'role' : 'grant') as $change) { foreach (array('before-lock','write','commit') as $boundary) {
    ca_reset($kind,$actor); $before=ca_snapshot(); $seen=$blocked=false;
    $sd_hook=function($sql) use ($kind,$change,$boundary,&$seen,&$blocked) {
     $match=$boundary === 'before-lock' ? $sql==='SELECT * FROM fixture_'.$kind.' LIMIT 0'
      : ($boundary === 'commit' ? $sql==='COMMIT' : preg_match('/^(INSERT INTO|UPDATE|DELETE FROM) fixture_'.$kind.'\b/',$sql));
     if (!$match) { return; } $GLOBALS['sd_hook']=null; $seen=true;
     sd_sql('SET SESSION innodb_lock_wait_timeout=0');
     try { if (!$GLOBALS['peer']->sql_query(sd_revoke($change))) { $e=$GLOBALS['peer']->sql_error(); sd_check((int)$e['code']===1205,'Native row-lock serialization only'); $blocked=true; } }
     finally { sd_sql('SET SESSION innodb_lock_wait_timeout=1'); }
    };
    $out=ca_run($kind,$request); sd_check($seen,'Native authority boundary reached');
    if ($blocked) { sd_check(ca_success($kind,$mode,$out) && ca_snapshot()===$expected,'Authorized commit precedes concurrent revocation'); sd_sql(sd_revoke($change)); $serialized++; }
    else { sd_check(!ca_success($kind,$mode,$out) && ca_snapshot()===$before,'Earlier revocation refuses the whole operation'); }
    sd_check(!ca_success($kind,$mode,ca_run($kind,$request)), 'Following revoked request denied'); sd_unlocked(); $cases++;
   } }
   $write=($mode==='add'?'INSERT INTO ':($mode==='delete'?'DELETE FROM ':'UPDATE ')).'fixture_'.$kind.' ';
   foreach (array($write,'COMMIT','lost-ack') as $failure) {
    ca_reset($kind,$actor); $before=ca_snapshot(); $sd_failure=$failure;
    sd_check(!ca_success($kind,$mode,ca_run($kind,$request)), 'Failed/uncertain operation never announces success');
    sd_check(ca_snapshot()===($failure==='lost-ack'?$expected:$before),'Checked native rollback / uncertain committed state');
    sd_check($kind!=='words'||!file_exists($sd_root.'/cache/words.cache'),'Failed/uncertain mutation evicts cache'); sd_unlocked(); $cases++;
   }
   ca_reset($kind,$actor); $before=ca_snapshot(); $reached=false;
   $sd_hook=function($sql,$connection) use ($write,&$reached) { if(strpos($sql,$write)!==0){return;} $GLOBALS['sd_hook']=null; $reached=true; sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id)); };
   sd_check(!ca_success($kind,$mode,ca_run($kind,$request)) && $reached && ca_snapshot()===$before,'Killed owner cannot continue unprotected'); sd_unlocked(); $cases++;
  } }
  ca_reset($kind,'wrong'); $before=ca_snapshot(); sd_check(!ca_success($kind,'edit',ca_run($kind,ca_request($kind,'edit'))) && ca_snapshot()===$before,'Unrelated delegated module refused'); sd_unlocked(); $cases++;
  foreach (array(array('id'=>'999'),array('id'=>array('1')),array('id'=>'-1'),array('id'=>'1e0'),array('id'=>'16777216'),array('id'=>true),array('sid'=>array('bad')),array('sid'=>'wrong')) as $bad) {
   ca_reset($kind); $before=ca_snapshot(); sd_check(!ca_success($kind,'edit',ca_run($kind,array_merge(ca_request($kind,'edit'),$bad))) && ca_snapshot()===$before,'Malformed/stale selection cannot mutate'); sd_unlocked(); $cases++;
  }
  foreach (phpbb_content_admin_definition($kind)['fields'] as $field=>$limit) {
   foreach (array(array('bad'),null,true,"\xc3",PHPBB_LEGACY_REQUEST_ESCAPED?addslashes("a\0b"):"a\0b",'',str_repeat('😀',$limit+1)) as $bad) {
    ca_reset($kind); $before=ca_snapshot(); sd_check(!ca_success($kind,'edit',ca_run($kind,array_merge(ca_request($kind,'edit'),array($field=>$bad)))) && ca_snapshot()===$before,'Invalid complete UTF-8 input refused before any writes'); sd_unlocked(); $cases++;
   }
   ca_reset($kind); $request=ca_request($kind,'edit'); $request[$field]=str_repeat('😀',$limit);
   sd_check(ca_success($kind,'edit',ca_run($kind,$request)), 'Full schema character capacity, not bytes'); sd_unlocked(); $cases++;
  }
  foreach (array('fixture_'.$kind,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table) {
   foreach (array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $ddl) {
    ca_reset($kind); sd_sql('ALTER TABLE '.$table.' '.$ddl); $before=ca_snapshot();
    sd_check(!ca_success($kind,'edit',ca_run($kind,ca_request($kind,'edit'))) && ca_snapshot()===$before,'Non-migrated participant refuses mutation'); sd_unlocked();
    sd_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $cases++;
   }
  }
  ca_reset($kind); $blocked=$seen=false; sd_sql('SET SESSION lock_wait_timeout=0');
  $sd_hook=function($sql) use ($kind,&$blocked,&$seen) { if (strpos($sql,'UPDATE fixture_'.$kind.' SET ')!==0) {return;} $GLOBALS['sd_hook']=null; $seen=true; if (!$GLOBALS['peer']->sql_query('ALTER TABLE fixture_'.$kind.' ENGINE=MyISAM')) {$e=$GLOBALS['peer']->sql_error();sd_check((int)$e['code']===1205,'Real metadata lock');$blocked=true;} };
  sd_check(ca_success($kind,'edit',ca_run($kind,ca_request($kind,'edit'))) && $blocked && $seen,'DDL cannot replace canonical metadata during writes'); sd_sql('SET SESSION lock_wait_timeout=1'); sd_unlocked(); $cases++;
  echo $kind." native authority, data and storage checks passed\n";
 }
 }
 ca_reset('acronyms'); $request=ca_request('acronyms','add'); $request['acronym']='ORIGINAL'; $before=ca_snapshot();
 sd_check(count(phpbb_acl_rows($peer,"SELECT acronym_id FROM fixture_acronyms WHERE acronym='ORIGINAL'"))===1,'Native collation matches existing acronym');
 $duplicate=ca_run('acronyms',$request);
 sd_check(in_array("SELECT acronym_id FROM fixture_acronyms WHERE acronym='ORIGINAL' FOR UPDATE",$sd_queries,true),'Actual duplicate query executed');
 sd_check(ca_snapshot()===$before,'Duplicate does not insert another row');
 sd_check(strpos($duplicate,$lang['Content_acronym_exists'])===0 && ca_snapshot()===$before,'Duplicate acronym uses current collation and localized message: '.$duplicate); sd_unlocked(); $cases++;
 // A broken factory must not commit, roll back or close the caller.
 class ContentReuseDatabase extends StyleDataDatabase {function sql_dedicated_connection(){return $this;}}
 ca_reset('words'); $broken=new ContentReuseDatabase($db->inner); $refused=false;
 try {$owner=new PhpbbContentAdminWriter($broken,phpbb_content_admin_definition('words'));$owner->release();} catch (PhpbbAclException $error) {$refused=true;}
 sd_check($refused && phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id'),'Reused caller refused without closing it'); sd_unlocked(); $cases++;
 ca_reset('words'); $before=ca_snapshot(); $reached=false;
 $sd_hook=function($sql,$connection) use (&$reached) {
  if ($sql!=='SELECT CONNECTION_ID() AS content_connection_id'||$connection===$GLOBALS['db']){return;}
  $GLOBALS['sd_hook']=null;$reached=true;sd_sql('KILL CONNECTION '.mysqli_thread_id($connection->db_connect_id));
 };
 sd_check(!ca_success('words','edit',ca_run('words',ca_request('words','edit'))) && $reached && ca_snapshot()===$before,'Failed dedicated identity read returns localized refusal, not a PHP type error'); sd_unlocked(); $cases++;
 ca_reset('words'); sd_sql('INSERT INTO fixture_users VALUES (2,0,1)'); sd_check($db->sql_query('START TRANSACTION'),'Caller transaction');
 sd_check($db->sql_query('UPDATE fixture_users SET user_active=0 WHERE user_id=2'),'Caller pending write');
 $original=phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx');
 sd_check(ca_success('words','edit',ca_run('words',ca_request('words','edit'))),'Separate owner may commit');
 sd_check((int)phpbb_acl_rows($db,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===0
  && (int)phpbb_acl_rows($peer,'SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']===1,'Caller pending write remains private');
 sd_check(phpbb_acl_rows($db,'SELECT CONNECTION_ID() AS id,@@SESSION.sql_mode AS mode,@@SESSION.in_transaction AS tx')===$original,'Caller settings and transaction preserved'); sd_check($db->sql_query('ROLLBACK'),'Only caller rollback'); sd_unlocked(); $cases++;
 ca_reset('words'); unlink($sd_root.'/cache/words.cache'); mkdir($sd_root.'/cache/words.cache');
 try { sd_check(!ca_success('words','edit',ca_run('words',ca_request('words','edit'))),'Cache cleanup failure does not report confirmed success'); sd_unlocked(); $cases++; }
 finally { rmdir($sd_root.'/cache/words.cache'); }
 echo 'Content native checks: '.$cases.' cases; '.$serialized." revocations serialized\n";
PHP;
$tail = <<<'PHP'
} finally {
 $sd_hook=null; $sd_failure=''; $db->sql_close(); $peer->sql_close(); $control->sql_query('DROP DATABASE '.$fixture); $control->sql_close(); chdir($sd_previous);
 foreach(array('themes','words') as $name) {if(is_file($sd_root.'/cache/'.$name.'.cache')){unlink($sd_root.'/cache/'.$name.'.cache');}}
 foreach(array_reverse($sd_files)as$file){if(is_file($file)){unlink($file);}} foreach(array_reverse($sd_dirs)as$dir){rmdir($dir);} restore_error_handler();
}
PHP;
eval($head . $body . $tail);
