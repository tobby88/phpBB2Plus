<?php
// Real public controller/driver, independent native peer, owned fixture only.
if(PHP_SAPI!=='cli'||getenv('PHPBB_PUBLIC_PROFILE_NATIVE')!=='1'){echo "Public profile identity checks require an explicitly enabled disposable native fixture.\n";return;}
$pi_file=__DIR__.'/check-public-profile-native.php';$pi_source=file_get_contents($pi_file);
$pi_cut=strpos($pi_source," foreach(array('edit','password','rename','avatar','reactivate','reactivate-password') as \$scenario)");
if($pi_cut===false){throw new RuntimeException('Public profile fixture boundary changed');}
$pi_head=str_replace('__DIR__',var_export(__DIR__,true),substr($pi_source,5,$pi_cut-5));
$pi_head=str_replace('codex_public_profile_','codex_profile_identity_',$pi_head);
function pi_suite(){
 global $db,$userdata,$board_config,$ctracker_config,$public_avatar_scope,$pp_hook,$pp_cookies,$schema,$ats_source,$pp_main;
 $cases=$serialized=0;
 pp_reset();pp_sql("UPDATE fixture_users SET username='member-2',user_email='member2@example.invalid' WHERE user_id=7");
 ats_check(pp_run('edit')===true,'Unchanged historical duplicates remain editable');$cases++;
 pp_reset();$board_config['default_lang']='german';$board_config['board_timezone']=2;$board_config['default_dateformat']='Y-m-d';
 ats_check(pp_run('edit')===true,'Personalized display preferences do not look like stale board policy');$cases++;
 foreach(array('case-email','quoted-email') as $variant){
  pp_reset();$email=$variant==='case-email'?'MEMBER2@example.invalid':"member'quote@example.invalid";$fields=get_fields('WHERE users_can_view = '.ALLOW_VIEW);
  $scope=new PhpbbPublicProfileScope($db,2,'exact-session',$public_avatar_scope);$db=$scope;
  try{$scope->validate_identity('member-2',$email,1,$fields);$scope->sql_query("UPDATE fixture_users SET user_email='".$scope->sql_escape($email)."' WHERE user_id=2");$scope->finish();}finally{$scope->release();}
  ats_check(pp_rows('SELECT user_email FROM fixture_users WHERE user_id=2')[0]['user_email']===$email,'Own collation-equivalent or quoted valid email accepted by owner');$cases++;
 }
 foreach(PhpbbPublicProfileScope::policy_keys() as $key){
  pp_reset();pp_sql("UPDATE fixture_config SET config_value='changed-since-validation' WHERE config_name='".$GLOBALS['peer']->sql_escape($key)."'");
  $before=pp_snap();ats_check(pp_run('edit')==='error'&&pp_snap()===$before&&!$pp_cookies,'Reject changed request policy '.$key);$cases++;
 }
 foreach(array('pw_complex','pw_complex_min','pw_complex_mode') as $key){pp_reset();pp_sql("UPDATE fixture_ctracker_config SET ct_config_value='17' WHERE ct_config_name='".$key."'");$before=pp_snap();ats_check(pp_run('password')==='error'&&pp_snap()===$before&&!$pp_cookies,'Reject changed CrackerTracker password rule '.$key);$cases++;}
 foreach(array('missing-policy','missing-style','namechange-disabled','avatar-permission','missing-ct-policy') as $case){
  pp_reset();
  if($case==='missing-policy'){pp_sql("DELETE FROM fixture_config WHERE config_name='require_activation'");}
  if($case==='missing-style'){pp_sql('DELETE FROM fixture_themes');}
  if($case==='namechange-disabled'){$board_config['allow_namechange']=0;pp_sql("UPDATE fixture_config SET config_value='0' WHERE config_name='allow_namechange'");}
  if($case==='avatar-permission'){pp_sql('UPDATE fixture_users SET user_allowavatar=1-user_allowavatar WHERE user_id=2');}
  if($case==='missing-ct-policy'){pp_sql("DELETE FROM fixture_ctracker_config WHERE ct_config_name='pw_complex'");}
  $before=pp_snap();ats_check(pp_run('rename')==='error'&&pp_snap()===$before&&!$pp_cookies,'Reject invalid current policy/account/style '.$case);$cases++;
 }
 foreach(array('name','email','reactivation','ct-policy','disallow','word','group','fields','style','email-ban') as $kind){
  foreach(array('before-owner','before-identity','after-identity','commit') as $boundary){
   $scenario=in_array($kind,array('email','reactivation','email-ban'),true)?'reactivate':'rename';pp_reset($scenario);
   if($kind==='reactivation'){$board_config['require_activation']=0;pp_sql("UPDATE fixture_config SET config_value='0' WHERE config_name='require_activation'");}
   $changes=array('name'=>"UPDATE fixture_users SET username='renamed' WHERE user_id=7",'email'=>"UPDATE fixture_users SET user_email='new@example.invalid' WHERE user_id=7",
    'reactivation'=>"UPDATE fixture_config SET config_value='1' WHERE config_name='require_activation'",'ct-policy'=>"UPDATE fixture_ctracker_config SET ct_config_value='17' WHERE ct_config_name='pw_complex_min'",
    'disallow'=>"INSERT INTO fixture_disallow (disallow_username) VALUES ('renamed')",'word'=>"INSERT INTO fixture_words (word,replacement) VALUES ('renamed','redacted')",
    'group'=>"UPDATE fixture_groups SET group_name='renamed' WHERE group_id=10",'fields'=>'UPDATE fixture_profile_fields SET text_field_maxlen=1 WHERE field_id=1',
    'style'=>'DELETE FROM fixture_themes','email-ban'=>"INSERT INTO fixture_banlist (ban_userid,ban_ip,ban_email) VALUES (0,'','new@example.invalid')");
   $change=$changes[$kind];$seen=$blocked=false;$peer_state=null;
   $pp_hook=function($sql)use($boundary,$change,&$seen,&$blocked,&$peer_state){
    $match=$boundary==='before-owner'?$sql==='START TRANSACTION':($boundary==='before-identity'?strpos($sql,'SELECT user_id FROM fixture_users WHERE ')===0:($boundary==='after-identity'?strpos($sql,'UPDATE fixture_users')===0:$sql==='COMMIT'));
    if(!$match||$seen){return;}$seen=true;$GLOBALS['pp_hook']=null;
    pp_sql('SET SESSION innodb_lock_wait_timeout=0');
    try{if(!$GLOBALS['peer']->sql_query($change)){$error=$GLOBALS['peer']->sql_error();ats_check((int)$error['code']===1205,'Only real contention serializes the peer: '.json_encode($error));$blocked=true;}else{$peer_state=pp_snap();}}
    finally{pp_sql('SET SESSION innodb_lock_wait_timeout=1');}
   };
   $result=pp_run($scenario);ats_check($seen,'Actual identity boundary reached '.$kind.'/'.$boundary);
   if($blocked){ats_check($result===true,'Owner completes before blocked competitor '.$kind.'/'.$boundary);pp_sql($change);$serialized++;}
   else{ats_check($result==='error'&&pp_snap()===$peer_state&&!$pp_cookies,'Effective rule/identity change prevents all publication '.$kind.'/'.$boundary);}
   $cases++;
  }
 }
 // Even an initially empty custom-field range must reject a new requirement
 // inserted after request validation, and stay locked after final validation.
 foreach(array('before-owner','commit') as $boundary){
  pp_reset();pp_sql('DELETE FROM fixture_profile_fields');$seen=$blocked=false;$peer_state=null;
  $columns=pp_rows('SHOW COLUMNS FROM fixture_profile_fields');$names=$values=array();
  foreach($columns as $c){if(strpos($c['Extra'],'auto_increment')!==false){continue;}$names[]=$c['Field'];$values[]=$c['Field']==='field_name'?"'new_required'":($c['Field']==='users_can_view'?"'1'":($c['Default']===null?"'0'":"'".$GLOBALS['peer']->sql_escape($c['Default'])."'"));}
  $change='INSERT INTO fixture_profile_fields ('.implode(',',$names).') VALUES ('.implode(',',$values).')';
  $pp_hook=function($sql)use($boundary,$change,&$seen,&$blocked,&$peer_state){if($seen||$sql!==($boundary==='commit'?'COMMIT':'START TRANSACTION')){return;}$seen=true;$GLOBALS['pp_hook']=null;pp_sql('SET SESSION innodb_lock_wait_timeout=0');try{if(!$GLOBALS['peer']->sql_query($change)){$blocked=(int)$GLOBALS['peer']->sql_error()['code']===1205;ats_check($blocked,'Empty field range contention');}else{$peer_state=pp_snap();}}finally{pp_sql('SET SESSION innodb_lock_wait_timeout=1');}};
  $result=pp_run('edit');ats_check($seen&&($blocked?$result===true:$result==='error'&&pp_snap()===$peer_state),'Empty field-range safety');$cases++;if($blocked){$serialized++;}
 }
 pp_reset();$before=pp_snap();$scope=new PhpbbPublicProfileScope($db,2,'exact-session',$public_avatar_scope);$db=$scope;$rejected=false;
 try{$scope->finish();}catch(PhpbbPublicProfileException $e){$rejected=true;}finally{$scope->release();}
 ats_check($rejected&&pp_snap()===$before&&!$pp_cookies,'Missing final validation cannot commit');$cases++;
 ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_identifier');ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_tables');$covered=plus_storage_tables($schema,'fixture_');
 foreach($GLOBALS['pp_tables'] as $table){ats_check(in_array('fixture_'.$table,$covered,true),'Consolidated migration covers public profile participant '.$table);}
 echo 'Native public profile identity: '.$cases.' cases, '.$serialized." serialized changes; current identities/rules/policy/fields and updater coverage passed.\n";
}
$pi_tail= <<<'PHP'
 pi_suite();
} finally {
 if($public_avatar_scope){$public_avatar_scope->release();}$pp_hook=$pp_after=null;$pp_main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned identity schema');$control->sql_close();
 foreach(array('avatars/before.png','avatars/after.png','cache/cg_users.cache','cache/arcade_best_player.cache','cache/arcade_best_at_player.cache') as $file){if(is_file($pp_files.'/'.$file)){unlink($pp_files.'/'.$file);}}
 rmdir($pp_files.'/avatars');rmdir($pp_files.'/cache');rmdir($pp_files);restore_error_handler();
}
PHP;
eval($pi_head.$pi_tail);
