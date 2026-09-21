<?php
// Native disposable schema; actual profile owner/controller projection/validators.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ADMIN_PROFILE_NATIVE')!=='1'){echo "Native ACP identity checks require an explicitly enabled disposable database.\n";return;}
$ai_file=__DIR__.'/check-admin-profile-native.php';$ai_source=file_get_contents($ai_file);
$ai_cut=strpos($ai_source," foreach(array('root','junior') as \$actor)");
if($ai_cut===false){throw new RuntimeException('Profile fixture setup boundary changed');}
$ai_head=str_replace('__DIR__',var_export(__DIR__,true),substr($ai_source,5,$ai_cut-5));
$ai_head=str_replace('codex_admin_profile_','codex_admin_identity_',$ai_head);
function ai_suite()
{
 global $db,$ap_main,$ap_hook,$ap_actor,$ap_target,$board_config,$userdata,$schema,$ats_source,$ap_queries;
 $cases=$serialized=0;
 // Existing duplicates and historically invalid email values remain editable.
 ap_reset();ap_sql("UPDATE fixture_users SET username='fixture-2',user_email='fixture@example.invalid' WHERE user_id IN (2,5)");
 ats_check(ap_run('edit')===true,'Unchanged historical duplicate identities remain editable');$cases++;
 ap_reset();$board_config['default_lang']='german';$board_config['default_dateformat']='Y-m-d';$board_config['board_timezone']=2;
 ats_check(ap_run('edit')===true,'Personalized language/timezone/date display are not board validation policy');$cases++;
 foreach(array('case-name','case-email','quoted-email') as $variant){
  ap_reset();$name=$variant==='case-name'?'FIXTURE-2':'fixture-2';$email=$variant==='case-email'?'FIXTURE@example.invalid':($variant==='quoted-email'?"fixture'quote@example.invalid":'fixture@example.invalid');
  if($variant==='case-email'){ap_sql("UPDATE fixture_users SET user_email='fixture@example.invalid' WHERE user_id=2");}
  $scope=new PhpbbAdminProfileScope($db,2,false,$_POST);$db=$scope;
  try{$scope->validate_identity($name,$email,1);$scope->sql_query("UPDATE fixture_users SET username='".$scope->sql_escape($name)."',user_email='".$scope->sql_escape($email)."' WHERE user_id=2");$scope->finish();}finally{$scope->release();}
  $row=ap_rows('SELECT username,user_email FROM fixture_users WHERE user_id=2')[0];ats_check($row['username']===$name&&$row['user_email']===$email,'Changed identity may retain own collation-equivalent value or quoted valid email');$cases++;
 }
 foreach(array('name','email','own-name','own-email','missing-policy','policy','style','disallow','word','group') as $case){
  ap_reset('root','rename');
  if($case==='name'){ap_sql("UPDATE fixture_users SET username='renamed' WHERE user_id=5");}
  if($case==='email'){ap_sql("UPDATE fixture_users SET user_email='fixture@example.invalid' WHERE user_id=5");}
  if($case==='own-name'){ap_sql("UPDATE fixture_users SET username='renamed' WHERE user_id=1");$userdata['username']='renamed';}
  if($case==='own-email'){ap_sql("UPDATE fixture_users SET user_email='fixture@example.invalid' WHERE user_id=1");}
  if($case==='missing-policy'){ap_sql("DELETE FROM fixture_config WHERE config_name='min_password_len'");}
  if($case==='policy'){ap_sql("UPDATE fixture_config SET config_value='72' WHERE config_name='min_password_len'");}
  if($case==='style'){ap_sql('DELETE FROM fixture_themes');}
  if($case==='disallow'){ap_insert('fixture_disallow',array('disallow_username'=>'renamed'));}
  if($case==='word'){ap_insert('fixture_words',array('word'=>'renamed'));}
  if($case==='group'){ap_sql("UPDATE fixture_groups SET group_name='renamed'");}
  $before=ap_snap();ats_check(ap_run('rename')==='error'&&ap_snap()===$before,'Reject current collision/rule/policy: '.$case);$cases++;
 }
 foreach(array('name','email','disallow','fields','policy','style','group') as $kind){
  foreach(array('before-policy','before-name','after-name','before-commit') as $boundary){
   ap_reset('root','rename');$seen=$blocked=false;$peer_state=null;
   $changes=array('name'=>"UPDATE fixture_users SET username='renamed' WHERE user_id=5",'email'=>"UPDATE fixture_users SET user_email='fixture@example.invalid' WHERE user_id=5",
    'disallow'=>"INSERT INTO fixture_disallow (disallow_username) VALUES ('renamed')",'fields'=>"INSERT INTO fixture_profile_fields (field_name) VALUES ('New requirement')",
    'policy'=>"UPDATE fixture_config SET config_value='72' WHERE config_name='min_password_len'",'style'=>'DELETE FROM fixture_themes','group'=>"UPDATE fixture_groups SET group_name='renamed'");
   // Use complete canonical values for mandatory custom-field columns.
   if($kind==='fields'){
    $columns=ap_rows('SHOW COLUMNS FROM fixture_profile_fields');$names=$values=array();foreach($columns as $c){if(strpos($c['Extra'],'auto_increment')!==false){continue;}$names[]=$c['Field'];$values[]=$c['Field']==='field_name'?"'New requirement'":($c['Default']===null?"'0'":"'".$GLOBALS['peer']->sql_escape($c['Default'])."'");}
    $changes['fields']='INSERT INTO fixture_profile_fields ('.implode(',',$names).') VALUES ('.implode(',',$values).')';
   }
   $change=$changes[$kind];
   $ap_hook=function($sql)use($boundary,$change,&$seen,&$blocked,&$peer_state){
    $match=$boundary==='before-policy'?strpos($sql,'SELECT config_name,config_value FROM ')===0:($boundary==='before-name'?strpos($sql,"SELECT user_id FROM fixture_users WHERE username='renamed'")===0:($boundary==='after-name'?strpos($sql,'UPDATE fixture_users')===0&&strpos($sql,"username='renamed'")!==false:$sql==='COMMIT'));
    if(!$match||$seen){return;}$seen=true;$GLOBALS['ap_hook']=null;
    if(!$GLOBALS['peer']->sql_query($change)){$error=$GLOBALS['peer']->sql_error();ats_check((int)$error['code']===1205,'Peer is serialized, not failed for another reason');$blocked=true;}else{$peer_state=ap_snap();}
   };
   $result=ap_run('rename');ats_check($seen,'Requested identity boundary reached');
   if($blocked){ats_check($result===true,'Blocked competitor follows committed profile');$serialized++;}
   elseif($kind==='fields'){ats_check($result===true,'Definitions changed before being loaded are current');}
   else{ats_check($result==='error'&&ap_snap()===$peer_state,'Effective collision/rule change prevents full save');}
   $cases++;
  }
 }
 foreach(array('config','disallow','words','profile_fields','themes') as $table){ap_reset();ap_sql('ALTER TABLE fixture_'.$table.' ENGINE=MyISAM');$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before,'Reject nontransactional validation participant '.$table);ap_sql('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 ap_reset();$scope=new PhpbbAdminProfileScope($db,2,false,$_POST);$db=$scope;$denied=false;try{$scope->finish();}catch(PhpbbAclException $e){$denied=true;}finally{$scope->release();}ats_check($denied,'Cannot commit without validating final identity');$cases++;
 ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_identifier');ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_tables');$covered=plus_storage_tables($schema,'fixture_');
 foreach(array('config','disallow','words','profile_fields','themes') as $table){ats_check(in_array('fixture_'.$table,$covered,true),'Existing updater covers validation participant');}
 echo 'Native ACP identity: '.$cases.' cases, '.$serialized." serialized changes; collisions, rules, policy, defaults and metadata passed.\n";
}
$ai_tail= <<<'PHP'
 ai_suite();
}finally{
 if(isset($admin_profile_scope)&&$admin_profile_scope!==null){$admin_profile_scope->release();}
 $ap_hook=null;$ap_fail=0;$ap_commit='';$ap_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 foreach(array('before.png','after.png','cache/cg_users.cache') as $file){if(is_file($ap_files.'/'.$file)){unlink($ap_files.'/'.$file);}}rmdir($ap_files.'/cache');rmdir($ap_files);restore_error_handler();
}
PHP;
eval($ai_head.$ai_tail);
