<?php
// Reuse the owned MariaDB fixture and production entry points, never a site DB.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_QUOTA_NATIVE') !== '1') { echo "Quota lifecycle checks require an explicitly enabled disposable database.\n"; return; }
$ql_file=__DIR__.'/check-attachment-quota-storage.php';$ql_source=file_get_contents($ql_file);
$ql_cut=strpos($ql_source," foreach(array('root','delegated') as \$actor){");
if($ql_cut===false){throw new RuntimeException('Native quota fixture boundary missing');}
$ql_head=str_replace('__DIR__',var_export(__DIR__,true),substr($ql_source,5,$ql_cut-5));
$ql_head=str_replace('codex_quota_','codex_quota_lifecycle_',$ql_head);
function ql_denied($action,$message){
 $denied=false;try{$action();}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,$message);
}
$ql_tail= <<<'PHP'
 foreach(array('root','delegated') as $actor){foreach(array('quota','user','group') as $mode){
  qreset($actor,$mode);$before=qsnap();$owner=new PhpbbAttachQuotaWriter($db,$mode);
  try{
   $update="UPDATE ".QUOTA_LIMITS_TABLE." SET quota_desc='owned-change' WHERE quota_limit_id=1";
   foreach(array($update,'DELETE FROM '.QUOTA_TABLE,'INSERT INTO '.QUOTA_TABLE.' VALUES (99,0,1,1)',
    'COMMIT','SET autocommit=1','ALTER TABLE '.QUOTA_TABLE.' ENGINE=MyISAM','TRUNCATE '.QUOTA_TABLE) as $sql){
    $queries=count($qqueries);ql_denied(function()use($owner,$sql){$owner->sql_query($sql);},'No autocommit DML or implicit commit');
    ats_check(count($qqueries)===$queries&&qsnap()===$before,'Rejected statement never reaches connection');$cases++;
   }
   ql_denied(function()use($owner){$owner->commit();},'No commit before begin');
   $owner->begin(array(QUOTA_LIMITS_TABLE,QUOTA_TABLE));$owner->sql_query($update);
   $queries=count($qqueries);ql_denied(function()use($owner){$owner->begin(array(QUOTA_TABLE));},'Repeated begin cannot implicitly commit');
   ql_denied(function()use($owner){$owner->sql_query('START TRANSACTION');},'Raw repeated start cannot implicitly commit');
   ql_denied(function()use($owner){$owner->sql_query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");},'Setup cannot change active transaction');
   ats_check(count($qqueries)===$queries&&qsnap()===$before,'Rejected restart leaves transaction isolated');
   foreach(array('SHOW COLUMNS FROM '.QUOTA_TABLE,'DESCRIBE '.QUOTA_TABLE) as $sql){$r=$owner->sql_query($sql);$owner->sql_freeresult($r);}
   $owner->release();ats_check(qsnap()===$before,'Unfinished transaction rolls back');
   $queries=count($qqueries);$owner->release();ats_check(count($qqueries)===$queries,'Repeated release is inert');
   ql_denied(function()use($owner,$update){$owner->sql_query($update);},'Released writer cannot write');
   ql_denied(function()use($owner){$owner->begin(array(QUOTA_TABLE));},'Released writer cannot restart');
  }finally{$owner->release();}
  foreach(array('normal','disconnect','fail','ack','rollback-exception') as $fault){
   qreset($actor,$mode);$before=qsnap();$owner=new PhpbbAttachQuotaWriter($db,$mode);$late=array();$confirmed=false;
   try{
    $owner->begin(array(QUOTA_LIMITS_TABLE));$owner->sql_query($update);
    $qcommit=$fault==='rollback-exception'?'fail':$fault;
    $qafter_commit=function($connection)use($fault,&$late){
     if($fault==='disconnect'){qsql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}
     $GLOBALS['qhook']=function($sql)use(&$late){$late[]=$sql;};
    };
    if($fault==='rollback-exception'){$qhook=function($sql){if($sql==='ROLLBACK'){throw new RuntimeException('Owned rollback failure');}};}
    try{$owner->commit();$confirmed=true;}catch(PhpbbAclException $e){}
    $owner->release();
    ats_check($confirmed===in_array($fault,array('normal','disconnect'),true),'Only acknowledged commit confirms success');
    if($confirmed){ats_check(!$late&&qsnap()!==$before,'No SQL after acknowledged commit, including release');}
    else{ats_check($fault==='ack'?qsnap()!==$before:qsnap()===$before,'Uncertainty and failed rollback preserve atomic outcome');}
    ql_denied(function()use($owner){$owner->commit();},'No repeat/released commit');
   }finally{$qhook=null;$qafter_commit=null;$qcommit='';$owner->release();}
   // Closing a socket starts the server's rollback; it need not have finished
   // when an immediate GET_LOCK(...,0) arrives. Use the normal writer timeout.
   $other=new attach_mutation_lock($peer);ats_check($other->acquired,'Every outcome releases shared mutex: '.$actor.' '.$mode.' '.$fault);$other->release();$cases++;
  }
  qreset($actor,$mode);$owner=new PhpbbAttachQuotaWriter($db,$mode);
  try{
   $owner->begin(array(QUOTA_LIMITS_TABLE));qsql(qrevoke($actor==='root'?'role':'grant'));$before=qsnap();
   ql_denied(function()use($owner,$update){$owner->sql_query($update);},'Fresh authority required even through direct writer API');
   $owner->release();ats_check(qsnap()===$before,'Revoked actor cannot write');$cases++;
  }finally{$owner->release();}
 }}
 echo 'Native quota lifecycle: '.$cases." guarded cases passed.\n";
}finally{
 $qhook=null;$qafter_commit=null;$qfailWrite=0;$qcommit='';
 if(isset($owner)){$owner->release();}
 $db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($previous);
 foreach($files as $f=>$body){unlink($root.'/'.$f);}foreach(array_reverse($dirs) as $dir){rmdir($dir);}restore_error_handler();
}
PHP;
eval($ql_head.$ql_tail);
