<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_SETTINGS_COMMIT_NATIVE') !== '1') { echo "Settings commit checks require an explicitly enabled disposable database.\n"; return; }
$sc_kind=isset($argv[1])?$argv[1]:'';
if (!in_array($sc_kind,array('board','mods'),true)) { throw new RuntimeException('Choose board or mods'); }
$sc_prefix=$sc_kind==='board'?'bc':'ms';$sc_env=$sc_kind==='board'?'BOARD_CONFIG':'MOD_SETTINGS';
putenv('PHPBB_'.$sc_env.'_NATIVE=1');putenv('PHPBB_'.$sc_env.'_PORT='.(getenv('PHPBB_SETTINGS_COMMIT_PORT')?:'3306'));
putenv('PHPBB_'.$sc_env.'_PASSWORD='.(getenv('PHPBB_SETTINGS_COMMIT_PASSWORD')?:''));
$sc_file=__DIR__.'/check-'.($sc_kind==='board'?'board-config':'mod-settings').'-storage.php';
$sc_source=file_get_contents($sc_file);$sc_cut=strpos($sc_source," foreach(array('root','delegated') as \$actor){");
if ($sc_cut===false) { throw new RuntimeException('Native settings fixture boundary missing'); }
$sc_head=str_replace('__DIR__',var_export(__DIR__,true),substr($sc_source,5,$sc_cut-5));
$sc_head=str_replace(array('codex_board_config_','codex_mod_settings_'),'codex_settings_commit_',$sc_head);
class SettingsCommitConnection {
 var $inner;var $db_connect_id;var $config_write=false;var $committed=false;
 function __construct($inner){$this->inner=$inner;$this->db_connect_id=$inner->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_query($sql,$transaction=false){
  if($this->committed){$GLOBALS['sc_after_queries'][]=$sql;}
  if(strpos($sql,'UPDATE fixture_config ')===0){$this->config_write=true;}
  if($this->config_write&&$sql==='COMMIT'&&in_array($GLOBALS['sc_mode'],array('reject','rollback-exception'),true)){return false;}
  if($sql==='ROLLBACK'&&$GLOBALS['sc_mode']==='rollback-exception'){throw new RuntimeException('Owned rollback failure');}
  $r=$this->inner->sql_query($sql,$transaction);
  if($sql==='COMMIT'&&$r){
   if($this->config_write){
    $this->committed=true;$GLOBALS['sc_stored']=sc_snapshot();
    if(is_callable($GLOBALS['sc_after'])){call_user_func($GLOBALS['sc_after'],$this);}
    if($GLOBALS['sc_mode']==='lost-ack'){return false;}
   }elseif(is_callable($GLOBALS['sc_backup_after'])){call_user_func($GLOBALS['sc_backup_after']);}
  }
  return $r;
 }
}
class SettingsCommitDatabase {
 var $inner;var $dbname;
 function __construct($inner){$this->inner=$inner;$this->dbname=$inner->dbname;}
 function __call($method,$args){return call_user_func_array(array($this->inner,$method),$args);}
 function sql_dedicated_connection(){
  // The Config+ registry may apply request-local user preferences before the
  // writer starts. Compare with that entry state, not the raw stored defaults.
  $GLOBALS['sc_local_entry']=$GLOBALS['board_config'];
  return new SettingsCommitConnection($this->inner->sql_dedicated_connection());
 }
}
function sc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function sc_sql($sql){return call_user_func($GLOBALS['sc_prefix'].'_sql',$sql);}
function sc_snapshot(){return call_user_func($GLOBALS['sc_prefix'].'_snapshot');}
function sc_run($request){return call_user_func($GLOBALS['sc_prefix'].'_run',$request);}
function sc_revoke($kind){return call_user_func($GLOBALS['sc_prefix'].($GLOBALS['sc_kind']==='board'?'_revocation':'_revoke'),$kind);}
function sc_reset($actor,$backup=0){
 call_user_func($GLOBALS['sc_prefix'].'_reset',$actor);
 $GLOBALS['sc_mode']='normal';$GLOBALS['sc_after']=null;$GLOBALS['sc_backup_after']=null;
 $GLOBALS['sc_after_queries']=array();$GLOBALS['sc_stored']=null;
 $GLOBALS['board_config']=sc_snapshot();
 file_put_contents($GLOBALS['sc_root'].'/cache/config_data.cache','owned old cache');
 if($GLOBALS['sc_kind']==='board'){$GLOBALS['ctracker_config']->settings['auto_recovery']=$backup;}
}
function sc_owner($database){return $GLOBALS['sc_kind']==='board'?new PhpbbBoardConfigWriter($database):new PhpbbModSettingsWriter($database);}
$sc_body= <<<'PHP'
 $db=new SettingsCommitDatabase($db);$sc_root=$GLOBALS[$sc_prefix.'_root'];
 $sc_request=$sc_kind==='board'?array('site_desc'=>addslashes("Änderung ' \\ 😀"),'smtp_password'=>addslashes(" p'\\<&😀 ")):array('use_ajax_preview'=>'0','use_ajax_edit'=>'0','use_ajax_edit_over'=>'1');
 $probe=getenv('PHPBB_SETTINGS_COMMIT_PROBE')==='1';$sc_cases=0;
 foreach($probe?array('root'):array('root','delegated') as $actor){foreach($sc_kind==='board'&&!$probe?array(0,1):array(0) as $backup){
  sc_reset($actor,$backup);$baseline=sc_run($sc_request);sc_check(strpos($baseline,'saved')===0,'Baseline actual controller');$expected=sc_snapshot();
  foreach($probe?array('missing','permission','disconnect'):array('normal','missing','permission','inactive','logout','admin-off','foreign','case','disconnect','lost-ack','reject','rollback-exception') as $mode){
   sc_reset($actor,$backup);$before=sc_snapshot();$local=$board_config;$sc_mode=$mode;
   if(!in_array($mode,array('normal','lost-ack','reject','rollback-exception'),true)){
    $sc_after=function($connection)use($mode,$actor){if($mode==='disconnect'){sc_sql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}else{sc_sql(sc_revoke($mode==='permission'?($actor==='root'?'role':'grant'):$mode));}};
   }
   $out=sc_run($sc_request);$actual=sc_snapshot();
   if($probe){sc_check($sc_stored!==null&&$actual===$expected,'Confirmed values persist');echo $sc_kind.'/'.$mode.': persisted=true; response='.(strpos($out,'saved')===0?'success':$out)."\n";continue;}
   if(in_array($mode,array('lost-ack','reject','rollback-exception'),true)){
    sc_check($out==='storage'&&$actual===($mode==='lost-ack'?$expected:$before),'Unconfirmed commit errors without fictional rollback');
    sc_check($board_config===$sc_local_entry,'Local configuration not published on unconfirmed commit');
    foreach($sc_after_queries as $sql){sc_check($sql==='ROLLBACK','Only rollback after lost acknowledgement');}
   }else{
    sc_check($out===$baseline&&$actual===$expected,'Correct actual response after confirmed '.$mode);
    sc_check(!$sc_after_queries,'No post-ACK SQL or rollback');
    foreach($sc_request as $key=>$value){sc_check($board_config[$key]===stripslashes($value),'Local configuration follows confirmed data');}
   }
   sc_check(!is_file($sc_root.'/cache/config_data.cache'),'Evict legacy cache after attempted/uncertain write');$sc_cases++;
  }
 }}
 if(!$probe){
  // The preceding automatic backup does not authorize a later config save.
  if($sc_kind==='board'){foreach(array('root','delegated') as $actor){foreach(array('missing','permission') as $kind){
   sc_reset($actor,1);$before=sc_snapshot();$reached=false;
   $sc_backup_after=function()use($actor,$kind,&$reached){$reached=true;sc_sql(sc_revoke($kind==='permission'?($actor==='root'?'role':'grant'):$kind));};
   sc_check(sc_run($sc_request)==='Not_Authorised'&&$reached&&sc_snapshot()===$before,'Backup never authorizes a subsequently revoked config save');
   $r=sc_sql("SELECT config_value FROM fixture_backup WHERE config_name='site_desc'");$saved=$peer->sql_fetchrow($r);$peer->sql_freeresult($r);
   sc_check($saved['config_value']===$before['site_desc'],'Committed preceding backup retained');$sc_cases++;
  }}}
  foreach(array('commit-without-begin','write-without-begin','double-begin','write-after-rollback','write-after-commit','ddl','repeated-commit','write-after-release') as $state){
   sc_reset('root');$before=sc_snapshot();$tx=sc_owner($db);$caught=false;$key=$sc_kind==='board'?'site_desc':'use_ajax_edit';
   try{
    if($state==='commit-without-begin'){$tx->sql_query('COMMIT');}
    elseif($state==='double-begin'){$tx->sql_query('START TRANSACTION');$tx->sql_query("UPDATE fixture_config SET config_value='draft' WHERE config_name='".$key."'");$tx->sql_query('START TRANSACTION');}
    elseif($state==='ddl'){$tx->sql_query('START TRANSACTION');$tx->sql_query('ALTER TABLE fixture_config ENGINE=MyISAM');}
    elseif($state==='repeated-commit'){$tx->sql_query('START TRANSACTION');$tx->sql_query('COMMIT');$tx->sql_query('COMMIT');}
    else{
     if($state==='write-after-rollback'){$tx->sql_query('START TRANSACTION');$tx->rollback();}
     if($state==='write-after-commit'){$tx->sql_query('START TRANSACTION');$tx->sql_query('COMMIT');}
     if($state==='write-after-release'){$tx->release();}
     $tx->sql_query("UPDATE fixture_config SET config_value='draft' WHERE config_name='".$key."'");
    }
   }catch(PhpbbAclException $e){$caught=true;}finally{$tx->release();}
   sc_check($caught&&sc_snapshot()===$before,'Owned lifecycle rejects '.$state);$sc_cases++;
  }
  $other=$fixture.'_other';sc_check($control->sql_query('CREATE DATABASE '.$other),'Owned unrelated metadata schema');
  try{
   sc_check($control->sql_query('CREATE TABLE '.$other.'.fixture_sessions (session_id VARCHAR(32)) ENGINE=MyISAM DEFAULT CHARSET=latin1'),'Unrelated legacy metadata');
   foreach(array(''=>true,'ENGINE=MyISAM'=>false,'ROW_FORMAT=COMPACT'=>false,'DEFAULT CHARACTER SET latin1'=>false,'MODIFY session_id VARCHAR(32) CHARACTER SET latin1 NOT NULL'=>false,'MODIFY session_id VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'=>false) as $ddl=>$allowed){
    sc_sql('ALTER TABLE fixture_sessions ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');sc_reset('root');
    if($ddl!==''){sc_sql('ALTER TABLE fixture_sessions '.$ddl);}$before=sc_snapshot();
    $ok=strpos(sc_run($sc_request),'saved')===0;
    sc_check($ok===$allowed&&($allowed||sc_snapshot()===$before),'Exact storage refusal '.$ddl);$query='';
    foreach($GLOBALS[$sc_prefix.'_queries'] as $sql){if(strpos($sql,'SELECT ENGINE, ROW_FORMAT, TABLE_COLLATION FROM information_schema.')===0&&strpos($sql,"AND TABLE_NAME='fixture_sessions'")!==false){$query=$sql;}}
    $scope="c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME='fixture_sessions'";
    sc_check($query!==''&&strpos($query,$scope)!==false,'Literal metadata scope');
    sc_check(phpbb_acl_rows($peer,$query)===phpbb_acl_rows($peer,str_replace($scope,'c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME',$query)),'Equivalent strict column policy');$sc_cases++;
   }
  }finally{$control->sql_query('DROP DATABASE '.$other);}
 }
 echo $sc_kind.' settings commit checks: '.$sc_cases." cases passed\n";
}finally{
 $GLOBALS[$sc_prefix.'_hook']=null;$GLOBALS[$sc_prefix.'_failure']='';$sc_after=null;$sc_backup_after=null;
 $db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 if(is_file($sc_root.'/cache/config_data.cache')){unlink($sc_root.'/cache/config_data.cache');}
 if($sc_kind==='board'){chdir($bc_previous);foreach(array_reverse($bc_files) as $file){unlink($file);}foreach(array_reverse($bc_dirs) as $dir){rmdir($dir);}}
 else{chdir($previous);foreach($files as $file=>$body){unlink($ms_root.'/'.$file);}foreach(array_reverse($dirs) as $dir){rmdir($dir);}}
 restore_error_handler();
}
PHP;
eval($sc_head.$sc_body);
