<?php
if (PHP_SAPI !== 'cli' || getenv('PHPBB_CT_RECOVERY_NATIVE') !== '1') { echo "Native CrackerTracker commit fixture skipped.\n"; return; }
// Reuse the owned native authority fixture and actual operation schemas.
$cc_source = file_get_contents(__DIR__ . '/check-ctracker-admin-authority.php');
$cc_cut = strpos($cc_source, " foreach(array('backup','auto','restore','hash','scan') as \$operation){");
if ($cc_cut === false) { throw new RuntimeException('Authority fixture boundary missing'); }
$cc_head = str_replace('__DIR__', var_export(__DIR__, true), substr($cc_source, 5, $cc_cut - 5));
$cc_head = str_replace('codex_ct_authority_', 'codex_ct_commit_', $cc_head);
class CtCommitConnection {
 var $inner; var $db_connect_id; var $committed = false;
 function __construct($inner) { $this->inner=$inner; $this->db_connect_id=$inner->db_connect_id; }
 function __call($name,$args) { return call_user_func_array(array($this->inner,$name),$args); }
 function sql_query($sql,$transaction=false) {
  $mode=$GLOBALS['cc_mode'];
  if ($this->committed) {
   $GLOBALS['cc_queries'][]=$sql;
   if (strpos($sql,'DROP TABLE IF EXISTS ')===0) {
    if ($mode==='drop-false') { return false; }
    if ($mode==='drop-exception') { throw new RuntimeException('cleanup failure'); }
    if ($mode==='drop-error') { throw new Error('cleanup failure'); }
   }
  }
  if ($sql==='COMMIT' && ($mode==='reject' || $mode==='rollback-exception')) { return false; }
  if ($sql==='ROLLBACK' && $mode==='rollback-exception') { throw new RuntimeException('rollback failure'); }
  $result=$this->inner->sql_query($sql,$transaction);
  if ($sql==='COMMIT' && $result) {
   $this->committed=true; $GLOBALS['cc_committed']=true;
   $GLOBALS['cc_snapshot']=ca_snapshot();
   if ($mode==='disconnect') { $this->inner->sql_close(); }
   if (is_callable($GLOBALS['cc_after'])) { call_user_func($GLOBALS['cc_after']); }
   if ($mode==='lost-ack') { return false; }
  }
  return $result;
 }
}
class CtCommitDatabase {
 var $inner; var $dbname;
 function __construct($inner) { $this->inner=$inner; $this->dbname=$inner->dbname; }
 function __call($name,$args) { return call_user_func_array(array($this->inner,$name),$args); }
 function sql_dedicated_connection() { return new CtCommitConnection($this->inner->sql_dedicated_connection()); }
}
class CtCommitSuccess extends RuntimeException {}
class CtCommitTemplate {
 var $expected;
 function __construct($expected) { $this->expected=$expected; }
 function set_filenames($files) {}
 function assign_block_vars($block,$values) {
  if ($block==='akt_complete' || $block==='infobox') {
   ca_check($values[$block==='infobox'?'L_MESSAGE_TEXT':'L_UPDATE_ACTION']===$this->expected,'Actual localized completion text');
   if ($block==='infobox') { ca_check($values['COLOR']==='DBFFCF','Success infobox'); }
   throw new CtCommitSuccess('confirmed');
  }
 }
}
function phpbb_admin_post_string($name) { return isset($_POST[$name]) ? $_POST[$name] : ''; }
function phpbb_admin_require_post_session() { ca_check($_POST['sid']===$GLOBALS['userdata']['session_id'],'Actual form SID'); }
function cc_reset($operation,$actor,$mode) {
 ca_reset($operation,$actor);
 $GLOBALS['cc_mode']=$mode; $GLOBALS['cc_queries']=array(); $GLOBALS['cc_after']=null;
 $GLOBALS['cc_committed']=false; $GLOBALS['cc_snapshot']=null;
 $GLOBALS['ctracker_config']=(object)array('settings'=>array('last_file_scan'=>123,'last_checksum_scan'=>123),
  'invalid_settings'=>array('last_file_scan'=>true,'last_checksum_scan'=>true));
}
function cc_controller($operation,$locale) {
 global $source,$phpbb_root_path,$phpEx,$lang,$db,$template;
 $saved_lang=$lang; include $source.'language/lang_'.$locale.'/lang_cback_ctracker.php';
 $template=new CtCommitTemplate($lang[$operation==='hash'?'ctracker_fchk_update_action':($operation==='scan'?'ctracker_fscan_complete':'ctracker_rec_succ')]);
 $_POST['action']=$operation==='hash'?'akt':'scan'; $_POST['mode']=$operation==='restore'?'restore':'backup';
 $file=$operation==='hash'?'changedfiles':($operation==='scan'?'filescanner':'systemrestore');
 $confirmed=false;
 try { include $source.'ctracker/admin/acp_module_'.$file.'.php'; }
 catch (CtCommitSuccess $e) { $confirmed=true; }
 finally { $lang=$saved_lang; }
 ca_check($confirmed,'Actual ACP controller confirms completed operation');
}
$cc_body = <<<'PHP'
 $db=new CtCommitDatabase($db);
 $lang['ctracker_rec_succ']='saved'; $lang['ctracker_fchk_update_action']='saved'; $lang['ctracker_fscan_complete']='saved';
 $cc_cases=0;
 foreach(array('backup','auto','restore','hash','scan') as $operation) { foreach(array('root','delegated') as $actor) {
  $target=$operation==='restore'?CONFIG_TABLE:($operation==='hash'?CTRACKER_FILECHK:($operation==='scan'?CTRACKER_FILESCANNER:CTRACKER_BACKUP));
  $modes=array('missing','normal','inactive','logout','admin-off','foreign','case',$actor==='root'?'role':'grant','disconnect','drop-false','drop-exception','lost-ack','reject','rollback-exception');
  if (class_exists('Error')) { $modes[]='drop-error'; }
  foreach($modes as $mode) {
   cc_reset($operation,$actor,$mode); $before=ca_snapshot();
   if (in_array($mode,array('missing','inactive','logout','admin-off','foreign','case','role','grant'),true)) {
    $cc_after=function()use($mode) { ca_sql(ca_revocation($mode)); };
   }
   $failure=null; try { ca_run($operation); } catch (Exception $e) { $failure=$e; } catch (Error $e) { $failure=$e; }
   $unconfirmed=in_array($mode,array('lost-ack','reject','rollback-exception'),true);
   if ($unconfirmed) {
    ca_check($failure instanceof CtAuthorityExit && $failure->getMessage()==='database','Unconfirmed commit retains original storage failure: '.$operation.' '.$mode);
    ca_check(ca_snapshot()===($mode==='lost-ack'?$cc_snapshot:$before),'Lost ACK is not rolled back fictionally; rejected commit is atomic');
    foreach($cc_queries as $sql) { ca_check($sql==='ROLLBACK','No destructive stage cleanup after unconfirmed commit'); }
   } else {
    ca_check($failure===null,'Confirmed operation must not become a failure: '.$operation.' '.$actor.' '.$mode.($failure?': '.$failure->getMessage():''));
    ca_check($cc_committed && ca_snapshot()===$cc_snapshot && $cc_snapshot!==$before,'Confirmed publication remains intact');
    ca_check($cc_queries===($operation==='restore'?array():array('DROP TABLE IF EXISTS '.$target.'_new')),'Only fixed owned stage cleanup after confirmed COMMIT; no new authorization or rollback');
   }
   if ($operation==='scan'||$operation==='hash') {
    $key=$operation==='scan'?'last_file_scan':'last_checksum_scan';
    ca_check($unconfirmed ? $ctracker_config->settings[$key]===123 : $ctracker_config->settings[$key]>123,'Local scan timestamp follows confirmed commit only');
    ca_check($unconfirmed === isset($ctracker_config->invalid_settings[$key]),'Confirmed timestamp is no longer invalid');
   }
   $cc_cases++;
  }
  // Cleanup failures are retried on the next normally authorized build, not
  // by replaying publication after a success or relaxing access to a new job.
  cc_reset($operation,$actor,'normal'); ca_run($operation);
  if ($operation!=='restore') {
   $r=ca_sql("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$target."_new'");
   $row=$peer->sql_fetchrow($r);$peer->sql_freeresult($r);ca_check((int)$row['n']===0,'Next operation removes its staging table');
  }
  if ($operation!=='auto') {
   foreach(array('english','german') as $locale) {
    cc_reset($operation,$actor,'missing'); $cc_after=function(){ca_sql(ca_revocation('missing'));};
    cc_controller($operation,$locale); $cc_cases++;
    ca_expect_denied($operation);
    cc_reset($operation,$actor,'lost-ack');$failure=null;
    try { cc_controller($operation,$locale); } catch (CtAuthorityExit $e) { $failure=$e; }
    ca_check($failure instanceof CtAuthorityExit,'Actual controller does not confirm a lost acknowledgement');$cc_cases++;
   }
  }
  echo $operation.' '.$actor." commit boundaries passed\n";
 }}
 echo 'Native CrackerTracker commit checks: '.$cc_cases." cases passed\n";
} finally {
 $ca_hook=null;$ca_failure='';$cc_after=null;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 unlink($root.'/source.php');rmdir($root.'/cache');rmdir($root);restore_error_handler();
}
PHP;
eval($cc_head . $cc_body);
