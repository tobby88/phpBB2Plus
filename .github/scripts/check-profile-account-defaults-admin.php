<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ADMIN_PROFILE_NATIVE')!=='1'){echo "Full ACP account defaults require an explicitly enabled owned database.\n";return;}
$source=file_get_contents(__DIR__.'/check-admin-profile-native.php');$cut=strpos($source," foreach(array('root','junior') as \$actor)");
if($cut===false){throw new RuntimeException('Actual ACP fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_admin_profile_','codex_profile_admin_defaults_',$head);
$tail= <<<'PHP'
 ap_sql("ALTER TABLE fixture_users ADD pad_hidden MEDIUMTEXT NULL, ADD pad_retired VARCHAR(255) DEFAULT 'Inactive default', ADD pad_unowned VARCHAR(255) DEFAULT 'Plugin default'");
 function pad_admin_reset($actor){
  ap_reset($actor,'new');
  ap_insert('fixture_profile_fields',array('field_name'=>'Member notes','field_column'=>'pad_hidden','field_type'=>0,'text_field_default'=>"Default \\ &amp; ' 😀",'users_can_view'=>0));
  ap_sql("UPDATE fixture_users SET pad_hidden='Owner private value'");
  ap_sql("UPDATE fixture_users SET pad_retired='Owner archived private value'");
  foreach(array('a'=>array('pad_retired','retired'),'b'=>array('pad_hidden','restored'),'c'=>array('pad_purged_absent','purged')) as $key=>$action){ap_insert('fixture_profile_field_actions',array('operation_key'=>str_repeat($key,64),'field_id'=>90,'field_column'=>$action[0],'action_state'=>$action[1]));}
  $_POST['pad_retired']='forged inactive field';
 }
 $cases=0;
 foreach(array('root','junior') as $actor){
  pad_admin_reset($actor);$before=ap_rows('SELECT * FROM fixture_users ORDER BY user_id');$observed=false;
  $ap_hook=function($sql)use(&$observed){if(strpos($sql,'INSERT INTO fixture_groups')!==0){return;}$GLOBALS['ap_hook']=null;$db=$GLOBALS['db'];$r=$db->sql_query('SELECT pad_hidden FROM fixture_users WHERE user_id=42');$row=$db->sql_fetchrow($r);$db->sql_freeresult($r);$observed=$row['pad_hidden']==="Default \\ &amp; ' 😀";ats_check(!ap_rows('SELECT user_id FROM fixture_users WHERE user_id=42'),'Defaults remain invisible until final publication');};
  ats_check(ap_run('new')===true&&$observed,'Actual full ACP placeholder has metadata default '.$actor);$writes=$ap_write;
  $row=ap_rows('SELECT pad_retired,pad_unowned FROM fixture_users WHERE user_id=42')[0];ats_check($row['pad_retired']===''&&$row['pad_unowned']==='Plugin default','Full ACP inactive blank and unowned plugin preserved');
  ats_check(ap_rows('SELECT * FROM fixture_users WHERE user_id<>42 ORDER BY user_id')===$before,'Full ACP does not copy or change existing user values');$cases++;
  for($nth=1;$nth<=$writes;$nth++){pad_admin_reset($actor);$before=ap_snap();$ap_fail=$nth;ats_check(ap_run('new')==='error'&&ap_snap()===$before,'Every full ACP creation write rolls defaults back '.$actor.' '.$nth);$cases++;}
 }
 foreach(array('commit','ack','core','duplicate','retired-core','retired-missing','retired-conflict','purged-recreated','retired-state') as $failure){
  pad_admin_reset('root');
  if($failure==='commit'){$ap_commit='fail';}elseif($failure==='ack'){$ap_commit='ack';}elseif($failure==='core'){ap_sql("UPDATE fixture_profile_fields SET field_column='user_password'");}
  elseif($failure==='duplicate'){ap_insert('fixture_profile_fields',array('field_name'=>'pad_hidden','field_type'=>0));}
  else{$set=$failure==='retired-core'?"field_column='user_level'":($failure==='retired-missing'?"field_column='missing_retired'":($failure==='retired-conflict'?"field_column='pad_hidden'":($failure==='purged-recreated'?"action_state='purged'":"action_state='unknown'")));ap_sql("UPDATE fixture_profile_field_actions SET $set WHERE operation_key='".str_repeat('a',64)."'");}
  $before=ap_snap();ats_check(ap_run('new')==='error','Unsafe/unconfirmed full ACP defaults '.$failure);
  if($failure==='ack'){$row=ap_rows('SELECT pad_hidden,pad_retired FROM fixture_users WHERE user_id=42')[0];ats_check($row['pad_hidden']==="Default \\ &amp; ' 😀"&&$row['pad_retired']==='','Uncertain full commit contains exact active/inactive defaults');}else{ats_check(ap_snap()===$before,'Full ACP defaults roll back '.$failure);}$cases++;
 }
 foreach(array('update','insert') as $change){
  pad_admin_reset('root');$blocked=false;$ap_hook=function($sql)use($change,&$blocked){if(strpos($sql,'INSERT INTO fixture_users')!==0){return;}$GLOBALS['ap_hook']=null;$query=$change==='update'?"UPDATE fixture_profile_fields SET text_field_default='peer'": "INSERT INTO fixture_profile_fields (field_name) VALUES ('new hidden')";$r=$GLOBALS['peer']->sql_query($query);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
  ats_check(ap_run('new')===true&&$blocked,'Full ACP pins hidden metadata '.$change);$cases++;
 }
 foreach(array('update','insert') as $change){
  pad_admin_reset('root');$blocked=false;$ap_hook=function($sql)use($change,&$blocked){if(strpos($sql,'INSERT INTO fixture_users')!==0){return;}$GLOBALS['ap_hook']=null;
   if($change==='update'){$query="UPDATE fixture_profile_field_actions SET action_state='restored' WHERE operation_key='".str_repeat('a',64)."'";}
   else{$query="INSERT INTO fixture_profile_field_actions SELECT '".str_repeat('d',64)."',field_id,'pad_unowned',definition_revision,definition_snapshot,column_signature,actor_id,session_hash,'retired',created_at,updated_at FROM fixture_profile_field_actions WHERE operation_key='".str_repeat('a',64)."'";}
   $r=$GLOBALS['peer']->sql_query($query);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;
  };
  ats_check(ap_run('new')===true&&$blocked,'Full ACP pins retirement metadata/range '.$change);$cases++;
 }
 pad_admin_reset('root');ap_sql('ALTER TABLE fixture_profile_field_actions ENGINE=MyISAM');$before=ap_snap();ats_check(ap_run('new')==='error'&&ap_snap()===$before,'Full ACP rejects legacy retirement storage');ap_sql('ALTER TABLE fixture_profile_field_actions ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;
 echo 'Native full ACP defaults: '.$cases." exact defaults, no inheritance, atomic failures, ACK and metadata locking cases passed.\n";
}finally{
 if(isset($admin_profile_scope)&&$admin_profile_scope!==null){$admin_profile_scope->release();}
 $ap_hook=null;$ap_fail=0;$ap_commit='';$ap_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 foreach(array('before.png','after.png','cache/cg_users.cache') as $file){if(is_file($ap_files.'/'.$file)){unlink($ap_files.'/'.$file);}}rmdir($ap_files.'/cache');rmdir($ap_files);restore_error_handler();
}
PHP;
if(eval($head.$tail)===false){throw new RuntimeException('Native ACP defaults fixture did not execute');}
