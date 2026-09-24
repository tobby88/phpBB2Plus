<?php
$worker=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/functions_profile_definition_retirement.php');
if(preg_match('/\b(?:DROP|ALTER)\s+TABLE\b/i',$worker)){throw new RuntimeException('Retirement/restoration must not execute schema destruction');}
if(PHP_SAPI!=='cli'||getenv('PHPBB_PROFILE_DEFINITION_NATIVE')!=='1'){echo "Profile retirement physical-data separation checked; native checks require an explicitly enabled disposable fixture.\n";return;}
$fixture_source=file_get_contents(__DIR__.'/check-profile-definition-storage.php');
$cut=strpos($fixture_source," foreach(array('root','edit','add') as \$actor)");
if($cut===false){throw new RuntimeException('Actual profile fixture setup boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($fixture_source,5,$cut-5));
$head=str_replace('codex_profile_definition_','codex_profile_retirement_',$head);
$tail= <<<'PHP'
 require_once $ats_source.'includes/functions_profile_definition_retirement.php';
 function pr_run($operation,$revision=null,$restore=false,$id=1,$request=null){
  if($request===null){$request=array('sid'=>$GLOBALS['userdata']['session_id']);}
  $writer=null;try{$writer=new PhpbbProfileDefinitionRetirement($GLOBALS['main'],$request);$result=$restore?$writer->restore($operation):$writer->retire($id,$revision,$operation);return $writer->confirmed?$result:false;}
  catch(PhpbbAclException $e){$GLOBALS['pr_error']=$e->getMessage();return false;}finally{if($writer){$writer->release();}}
 }
 function pr_state(){return array(pds_rows('SELECT * FROM fixture_profile_fields ORDER BY field_id'),pds_rows('SELECT * FROM fixture_profile_field_actions ORDER BY operation_key'),pds_rows('SELECT * FROM fixture_users ORDER BY user_id'));}
 function pr_operation(){return bin2hex(phpbb_random_bytes(32));}
 $cases=0;
 foreach(array('root','edit','add') as $actor){
  $rev=pds_reset($actor);$op=pr_operation();$before=pr_state();$retired=pr_run($op,$rev);
  ats_check(($retired!==false)===($actor!=='add'),'Exact retirement grant '.$actor);
  if($actor==='add'){ats_check(pr_state()===$before,'Add-only grant cannot retire');$cases++;continue;}
  ats_check(!pds_rows('SELECT * FROM fixture_profile_fields')&&pr_state()[2]===$before[2],'Retirement removes only definition');
  ats_check(pr_run($op,$rev)===$op&&pr_state()[2]===$before[2],'Repeated retirement never touches user text');
  ats_check(pr_run($op,null,true)===1,'Restore retired definition');$row=pds_rows('SELECT * FROM fixture_profile_fields')[0];ats_check($row['field_column']==='user_notes','Restore pins stable legacy storage');
  ats_check(pr_state()[2]===$before[2],'Restore preserves every user byte');
  pds_sql("UPDATE fixture_profile_fields SET field_description='later edit' WHERE field_id=1");$restored=pr_state();
  ats_check(pr_run($op,null,true)===1&&pr_state()===$restored,'Restore receipt does not overwrite newer edits');
  ats_check(pr_run($op,$rev)===false&&pr_state()===$restored,'Old delete form cannot delete a restored field');$cases++;
 }
 foreach(array('action-insert','retire-delete','commit','ack') as $failure){
  $rev=pds_reset();$op=pr_operation();$before=pr_state();$pds_failure=$failure;
  ats_check(pr_run($op,$rev)===false,'Unconfirmed retirement '.$failure);
  ats_check(pr_state()[2]===$before[2],'Failure never changes user data '.$failure);
  if($failure==='ack'){ats_check(!pr_state()[0]&&count(pr_state()[1])===1,'Lost ACK retains committed retirement');}
  else{ats_check(pr_state()===$before,'Journal and definition roll back together '.$failure);}
  $pds_failure='';ats_check(pr_run($op,$rev)===$op,'Retirement retry '.$failure);$cases++;
 }
 foreach(array('publish','action-update','commit','ack') as $failure){
  $rev=pds_reset();$op=pr_operation();ats_check(pr_run($op,$rev)===$op,'Retire before restoration failure');$before=pr_state();$pds_failure=$failure;
  ats_check(pr_run($op,null,true)===false,'Unconfirmed restore '.$failure);ats_check(pr_state()[2]===$before[2],'Restore never touches values');
  if($failure==='ack'){ats_check(count(pr_state()[0])===1&&pr_state()[1][0]['action_state']==='restored','Lost ACK preserves committed restoration');}else{ats_check(pr_state()===$before,'Atomic restore rollback '.$failure);}
  $pds_failure='';ats_check(pr_run($op,null,true)===1,'Restore retry '.$failure);$cases++;
 }
 foreach(array('role','session','grant','definition','ban') as $change){
  $rev=pds_reset($change==='grant'?'edit':'root');$op=pr_operation();$blocked=false;
  $pds_hook=function($sql)use($change,&$blocked){if(strpos($sql,'DELETE FROM fixture_profile_fields')!==0){return;}$GLOBALS['pds_hook']=null;$queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','session'=>'DELETE FROM fixture_sessions','grant'=>"UPDATE fixture_jr SET user_jr_admin=''",'definition'=>"UPDATE fixture_profile_fields SET field_description='peer' WHERE field_id=1",'ban'=>"INSERT INTO fixture_banlist (ban_userid,ban_ip) VALUES (2,'')");$r=$GLOBALS['peer']->sql_query($queries[$change]);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
  ats_check(pr_run($op,$rev)===$op&&$blocked,'Retirement pins concurrent '.$change);$cases++;
 }
 foreach(array('role','session','grant','action','definition') as $change){
  $rev=pds_reset($change==='grant'?'edit':'root');$op=pr_operation();ats_check(pr_run($op,$rev)===$op,'Retire before restore race');$blocked=false;
  $pds_hook=function($sql)use($change,$op,&$blocked){if(strpos($sql,'INSERT INTO fixture_profile_fields')!==0){return;}$GLOBALS['pds_hook']=null;$queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=2','session'=>'DELETE FROM fixture_sessions','grant'=>"UPDATE fixture_jr SET user_jr_admin=''",'action'=>"UPDATE fixture_profile_field_actions SET action_state='purging' WHERE operation_key='$op'",'definition'=>"INSERT INTO fixture_profile_fields (field_id,field_name,field_column) VALUES (1,'peer','user_notes')");$r=$GLOBALS['peer']->sql_query($queries[$change]);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
  ats_check(pr_run($op,null,true)===1&&$blocked,'Restoration pins concurrent '.$change);$cases++;
 }
 foreach(array('role','session','stale','core','duplicate') as $change){
  $rev=pds_reset();$op=pr_operation();
  if($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}elseif($change==='session'){pds_sql('DELETE FROM fixture_sessions');}
  elseif($change==='stale'){pds_sql("UPDATE fixture_profile_fields SET field_description='other' WHERE field_id=1");}
  elseif($change==='core'){pds_sql("UPDATE fixture_profile_fields SET field_column='user_password' WHERE field_id=1");$rev=phpbb_profile_definition_revision(pds_rows('SELECT * FROM fixture_profile_fields')[0]);}
  else{$duplicate=pds_values();$duplicate['field_column']='user_notes';pds_insert('fixture_profile_fields',$duplicate);}
  $before=pr_state();ats_check(pr_run($op,$rev)===false&&pr_state()===$before,'Refuse unsafe retirement '.$change);$cases++;
 }
 foreach(array('column','missing-column','id','label','purging','snapshot','role') as $change){
  $rev=pds_reset();$op=pr_operation();ats_check(pr_run($op,$rev)===$op,'Retire before unsafe restore');
  if($change==='column'){pds_sql("ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) DEFAULT 'different'");}
  elseif($change==='missing-column'){pds_sql('ALTER TABLE fixture_users DROP user_notes');}
  elseif($change==='id'){$v=pds_values();$v['field_id']=1;$v['field_name']='other';pds_insert('fixture_profile_fields',$v);}
  elseif($change==='label'){$v=pds_values();$v['field_name']='USER_NOTES';$v['field_column']='other_notes';pds_insert('fixture_profile_fields',$v);}
  elseif($change==='purging'){pds_sql("UPDATE fixture_profile_field_actions SET action_state='purging'");}
  elseif($change==='snapshot'){pds_sql("UPDATE fixture_profile_field_actions SET definition_snapshot='{}'");}
  else{pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}
  $before=pr_state();ats_check(pr_run($op,null,true)===false&&pr_state()===$before,'Refuse unsafe restoration '.$change);
  if($change==='missing-column'){pds_sql("ALTER TABLE fixture_users ADD user_notes VARCHAR(255) DEFAULT 'old default'");}elseif($change==='column'){pds_sql("ALTER TABLE fixture_users MODIFY user_notes VARCHAR(255) DEFAULT 'old default'");}$cases++;
 }
 $rev=pds_reset();$op=pr_operation();ats_check(pr_run($op,$rev)===$op&&pr_run($op,null,true)===1,'First retirement cycle');$rev=phpbb_profile_definition_revision(pds_rows('SELECT * FROM fixture_profile_fields')[0]);$next=pr_operation();ats_check(pr_run($next,$rev)===$next&&pr_run($next,null,true)===1,'New retirement uses fresh receipt');$before=pr_state();ats_check(pr_run($op,$rev)===false&&pr_state()===$before,'Old operation cannot resurrect deletion after later restore');$cases++;
 $rev=pds_reset();pds_sql('ALTER TABLE fixture_profile_field_actions ENGINE=MyISAM');$before=pr_state();ats_check(pr_run(pr_operation(),$rev)===false&&pr_state()===$before,'Require transactional recovery journal');pds_sql('ALTER TABLE fixture_profile_field_actions ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;
 // Explicit generated storage must survive label changes, even to a core name.
 pds_reset();$creation=pr_operation();$column='cpf_'.substr($creation,0,32);$id=pds_create($creation,pds_values());ats_check(is_int($id),'Create generated field for retirement');
 pds_sql("UPDATE fixture_profile_fields SET field_name='user_password' WHERE field_id=".$id);$row=pds_rows('SELECT * FROM fixture_profile_fields WHERE field_id='.$id)[0];$rev=phpbb_profile_definition_revision($row);$before=pr_state();$op=pr_operation();
 ats_check(pr_run($op,$rev,false,$id)===$op&&pr_run($op,null,true)===$id,'Restore renamed generated field');
 ats_check(pr_state()[2]===$before[2],'Generated and core values preserved');
 ats_check(pds_rows('SELECT field_column FROM fixture_profile_fields WHERE field_id='.$id)[0]['field_column']===$column,'Label never redirects physical storage');$cases++;
 foreach(array(false,true) as $restore){
  foreach(array('get','sid','sid-array','operation','operation-array','role','session','grant','ban','inactive') as $change){
   $rev=pds_reset($change==='grant'?'edit':'root');$op=pr_operation();if($restore){ats_check(pr_run($op,$rev)===$op,'Retire before request rejection');}
   $request=array('sid'=>'exact-session');
   if($change==='get'){$_SERVER['REQUEST_METHOD']='GET';}elseif($change==='sid'){$request['sid']='other';}elseif($change==='sid-array'){$request['sid']=array('exact-session');}
   elseif($change==='operation'){$op='not-a-receipt';}elseif($change==='operation-array'){$op=array($op);}
   elseif($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}
   elseif($change==='session'){pds_sql('DELETE FROM fixture_sessions');}elseif($change==='grant'){pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}
   elseif($change==='ban'){pds_sql("INSERT INTO fixture_banlist (ban_userid,ban_ip) VALUES (2,'')");}else{pds_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}
   $before=pr_state();ats_check(pr_run($op,$rev,$restore,1,$request)===false&&pr_state()===$before,'Reject request before mutation '.$restore.' '.$change);$cases++;
  }
  foreach(array('role','session','grant') as $change){
   $rev=pds_reset($change==='grant'?'edit':'root');$op=pr_operation();if($restore){ats_check(pr_run($op,$rev)===$op,'Retire before early revocation');}$seen=false;
   $pds_hook=function($sql)use($change,&$seen){if($sql!=='START TRANSACTION'){return;}$GLOBALS['pds_hook']=null;$seen=true;
    if($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}elseif($change==='session'){pds_sql('DELETE FROM fixture_sessions');}else{pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}
   };
   $before=pr_state();ats_check(pr_run($op,$rev,$restore)===false&&$seen,'Reject authority change before transaction '.$restore.' '.$change);$after=pr_state();ats_check($before[0]===$after[0]&&$before[1]===$after[1],'Early revocation leaves metadata and receipts unchanged');$cases++;
  }
  $rev=pds_reset();$op=pr_operation();if($restore){ats_check(pr_run($op,$rev)===$op,'Retire before disconnect');}$before=pr_state();
  $pds_hook=function($sql,$connection)use($restore){$prefix=$restore?'UPDATE fixture_profile_field_actions':'DELETE FROM fixture_profile_fields';if(strpos($sql,$prefix)!==0){return;}$GLOBALS['pds_hook']=null;pds_sql('KILL CONNECTION '.(int)$connection->db_connect_id->thread_id);};
  ats_check(pr_run($op,$rev,$restore)===false&&pr_state()===$before,'Real disconnect rolls back definition and receipt '.$restore);$cases++;
 }
 foreach(array('session','actor') as $change){
  $rev=pds_reset();$op=pr_operation();ats_check(pr_run($op,$rev)===$op,'Retire before foreign receipt replay');
  if($change==='session'){pds_sql("UPDATE fixture_sessions SET session_id='new-session'");$userdata['session_id']='new-session';}
  else{pds_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=7');pds_sql('UPDATE fixture_sessions SET session_user_id=7');$userdata['user_id']=7;}
  $before=pr_state();ats_check(pr_run($op,$rev)===false&&pr_state()===$before,'Old deletion receipt bound to original '.$change);
  ats_check(pr_run($op,null,true)===1,'Fresh current administrator can explicitly restore '.$change);$cases++;
 }
 echo 'Native profile retirement: '.$cases." authorization, atomic rollback/retry, concurrent changes, stable storage and recovery cases passed.\n";
}finally{$pds_hook=null;$main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned retirement schema');$control->sql_close();restore_error_handler();}
PHP;
eval($head.$tail);
