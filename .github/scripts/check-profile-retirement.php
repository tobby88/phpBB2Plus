<?php
$worker=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/functions_profile_definition_retirement.php');
if(preg_match('/\b(?:DROP|ALTER)\s+TABLE\b/i',$worker)){throw new RuntimeException('Retirement/restoration must not execute schema destruction');}
$controller=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/admin/admin_profile_fields.php');
if(preg_match('/\b(?:DELETE FROM|ALTER TABLE|DROP COLUMN)\b/i',$controller)||strpos($controller,'profile_field_column_identifier')!==false){throw new RuntimeException('Controller must not retain legacy destructive/name-derived SQL');}
if(PHP_SAPI!=='cli'||getenv('PHPBB_PROFILE_DEFINITION_NATIVE')!=='1'){echo "Profile retirement physical-data separation checked; native checks require an explicitly enabled disposable fixture.\n";return;}
$fixture_source=file_get_contents(__DIR__.'/check-profile-definition-storage.php');
$cut=strpos($fixture_source," foreach(array('root','edit','add') as \$actor)");
if($cut===false){throw new RuntimeException('Actual profile fixture setup boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($fixture_source,5,$cut-5));
$head=str_replace('codex_profile_definition_','codex_profile_retirement_',$head);
$tail= <<<'PHP'
 require_once $ats_source.'includes/functions_profile_definition_retirement.php';
 require_once $ats_source.'includes/functions_profile_retirement_form.php';
 require_once $ats_source.'includes/template.php';
 function redirect($url){throw new AttachSettingsExit('redirect:'.$url);}
 function pr_controller($request,$method='POST'){
  global $db,$userdata,$lang,$phpEx,$phpbb_root_path,$board_config,$theme;
  $db=$GLOBALS['main'];$phpbb_root_path=$GLOBALS['ats_source'];$filename='admin_profile_fields.php';
  $theme=array('template_name'=>'fisubsilversh');$userdata['user_lang']='english';$userdata['template_name']='fisubsilversh';
  $board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
  $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);
  profile_fixture_request($method==='POST'?$request:array());if($method==='GET'){$_GET=phpbb_addslashes_recursive($request);}$_SERVER['REQUEST_METHOD']=$method;
  $source=str_replace("\r\n","\n",file_get_contents($phpbb_root_path.'admin/admin_profile_fields.php'));
  $a=strpos($source,'$mode_value =');$b=strpos($source,'function profile_field_post_value(',$a);$c=strpos($source,'$session_field =',$b);$d=strpos($source,"\$template->assign_vars(array(\n  'L_NEW_FIELD_NAME'",$c);
  ats_check($a!==false&&$b>$a&&$c>$b&&$d>$c,'Actual retirement controller boundaries');
  $mode='';$exit='';ob_start();try{eval(substr($source,$a,$b-$a).substr($source,$c,$d-$c));$template->pparse('body');}
  catch(AttachSettingsExit $e){$exit=$e->getMessage();}finally{$html=ob_get_contents();ob_end_clean();}
  return array($mode,$html,$template->_tpldata['.'][0],$exit);
 }
 function pr_hidden($html){$values=array();preg_match_all('/<input\b[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/',$html,$m,PREG_SET_ORDER);foreach($m as $row){$values[$row[1]]=html_entity_decode($row[2],ENT_QUOTES,'UTF-8');}return $values;}
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
 // Actual common.php escaping, ACP request gates, controller dispatch and
 // Extreme Styles templates, not an invented parallel HTTP form.
 foreach(array('english','german') as $language){
  require $ats_source.'language/lang_'.$language.'/lang_main.php';require $ats_source.'language/lang_'.$language.'/lang_admin.php';
  foreach(array('commit','ack') as $failure){
   $rev=pds_reset();$label="Notizen &amp; 😀 </p><script>bad</script>";pds_sql("UPDATE fixture_profile_fields SET field_name='".$peer->sql_escape($label)."',field_column='user_notes' WHERE field_id=1");
   $before=pr_state();$view=pr_controller(array('mode'=>'delete','pfid'=>'1'),'GET');$request=pr_hidden($view[1]);$request['confirm']='Yes';
   ats_check($view[3]===''&&strpos($view[1],'<script>bad</script>')===false&&strpos($view[1],'&lt;script&gt;')!==false,'Safe actual removal form '.$language);
   ats_check(isset($request['sid'],$request['definition_operation'],$request['definition_revision'])&&pr_state()===$before,'GET generates tokens without mutations');
   $other=pr_hidden(pr_controller(array('mode'=>'delete','pfid'=>'1'),'GET')[1]);ats_check($request['definition_operation']!==$other['definition_operation'],'Fresh confirmation has fresh operation');
   $pds_failure=$failure;$failed=pr_controller($request);$retry=pr_hidden($failed[1]);
   ats_check($failed[0]==='delete'&&$failed[3]===''&&strpos($failed[1],$lang['Profile_retirement_failed'])!==false,'Actual failed removal stays in confirmation');
   ats_check($retry['definition_operation']===$request['definition_operation']&&$retry['definition_revision']===$request['definition_revision'],'Same revision/operation after uncertain commit');
   $pds_failure='';$retry['confirm']='Yes';$saved=pr_controller($retry);ats_check($saved[0]==='confirmdelete'&&$saved[3]===''&&!pr_state()[0]&&pr_state()[2]===$before[2],'Actual retirement retry succeeds without changing values');
   $list=pr_controller(array('mode'=>'edit','pfid'=>'x'),'GET');ats_check($list[3]===''&&strpos($list[1],'mode=restore')!==false&&strpos($list[1],$lang['Profile_retirement_explain'])!==false&&strpos($list[1],'<script>')===false,'Actual recovery list renders safely and explains retained data');
   $restore=pr_controller(array('mode'=>'restore','pfid'=>'x','definition_operation'=>$request['definition_operation']),'GET');$restore_request=pr_hidden($restore[1]);$restore_request['confirm']='Yes';
   ats_check($restore[3]===''&&strpos($restore[1],'&lt;script&gt;')!==false,'Actual restore form');
   $pds_failure=$failure;$failed=pr_controller($restore_request);ats_check($failed[0]==='restore'&&$failed[3]==='','Actual failed restore remains retryable even after committed restoration');
   $again=pr_hidden($failed[1]);ats_check($again['definition_operation']===$request['definition_operation'],'Restore token retained');$again['confirm']='Yes';$pds_failure='';
   $saved=pr_controller($again);ats_check($saved[0]==='confirmrestore'&&$saved[3]===''&&pr_state()[2]===$before[2]&&count(pr_state()[0])===1,'Actual restoration retry preserves values');
   $stable=pr_state();$stale=pr_controller($request);ats_check($stale[0]==='delete'&&pr_state()===$stable,'Old real deletion form cannot remove restored field');$cases++;
  }
 }
 foreach(array('cancel','both','no-confirm','bad-sid','get','array-operation','array-revision','changed','grant','role') as $change){
  pds_reset($change==='grant'?'add':'root');$view=$change==='grant'?null:pr_controller(array('mode'=>'delete','pfid'=>'1'),'GET');
  $request=$view?pr_hidden($view[1]):array('mode'=>'confirmdelete','pfid'=>'1','sid'=>'exact-session','definition_operation'=>pr_operation(),'definition_revision'=>str_repeat('a',64));$request['confirm']='Yes';$method='POST';
  if($change==='cancel'){unset($request['confirm']);$request['cancel']='No';}elseif($change==='both'){$request['cancel']='No';}elseif($change==='no-confirm'){unset($request['confirm']);}
  elseif($change==='bad-sid'){$request['sid']='invalid';}elseif($change==='get'){$method='GET';}elseif($change==='array-operation'){$request['definition_operation']=array('bad');}elseif($change==='array-revision'){$request['definition_revision']=array('bad');}
  elseif($change==='changed'){pds_sql("UPDATE fixture_profile_fields SET field_description='concurrent edit'");}elseif($change==='role'){pds_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=2');pds_sql("UPDATE fixture_jr SET user_jr_admin=''");}
  $before=pr_state();$out=pr_controller($request,$method);ats_check(pr_state()===$before,'Actual request cannot mutate '.$change);
  ats_check($out[3]!==''||($out[0]==='delete'&&strpos($out[1],$lang['Profile_retirement_failed'])!==false),'Rejected controller response '.$change);$cases++;
 }
 pds_reset('edit');$before=pr_state();$view=pr_controller(array('mode'=>'delete','pfid'=>'1'),'GET');$request=pr_hidden($view[1]);$request['confirm']='Yes';$out=pr_controller($request);
 ats_check($view[3]===''&&$out[0]==='confirmdelete'&&$out[3]==='','Delegated edit administrator can use actual retirement form');
 $view=pr_controller(array('mode'=>'restore','pfid'=>'x','definition_operation'=>$request['definition_operation']),'GET');$request=pr_hidden($view[1]);$request['confirm']='Yes';$out=pr_controller($request);
 ats_check($view[3]===''&&$out[0]==='confirmrestore'&&$out[3]===''&&pr_state()[2]===$before[2],'Delegated edit administrator can restore through actual form');$cases++;
 echo 'Native profile retirement: '.$cases." authorization, atomic rollback/retry, concurrent changes, actual controller/form and recovery cases passed.\n";
}finally{$pds_hook=null;$main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned retirement schema');$control->sql_close();restore_error_handler();}
PHP;
eval($head.$tail);
