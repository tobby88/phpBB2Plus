<?php
// Share the canonical disposable registration schema/instrumented native driver,
// never a live configuration. Execute the actual quick-add publication controller.
if (PHP_SAPI !== 'cli' || getenv('PHPBB_QUICK_ADD_NATIVE') !== '1') { echo "Native ACP quick-add requires an explicitly enabled disposable database.\n"; return; }
putenv('PHPBB_REGISTRATION_NATIVE=1');
putenv('PHPBB_REGISTRATION_PORT=' . (getenv('PHPBB_QUICK_ADD_PORT') ?: '3306'));
putenv('PHPBB_REGISTRATION_PASSWORD=' . (getenv('PHPBB_QUICK_ADD_PASSWORD') ?: ''));
$fixture_file = __DIR__ . '/check-registration-native.php';
$fixture_source = file_get_contents($fixture_file);
$fixture_cut = strpos($fixture_source, " foreach(array(0,1,2,3) as \$mode)");
if ($fixture_cut === false) { throw new RuntimeException('Registration fixture setup boundary changed'); }
$fixture_head = str_replace('__DIR__', var_export(dirname($fixture_file), true), substr($fixture_source, 5, $fixture_cut - 5));
$fixture_tail = <<<'PHP'
 qad_suite();
} finally { $rgn_hook=$rgn_after=null;$rgn_fail=0;$rgn_commit='';$rgn_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler(); }
PHP;

function qad_reset($junior = false)
{
 rgn_reset(0, 'none');
 $GLOBALS['userdata']=array('user_id'=>1,'user_level'=>$junior?0:ADMIN,'user_active'=>1,'session_id'=>'fixture-sid','session_logged_in'=>true,'session_admin'=>true,'username'=>'Existing 1','user_email'=>'existing1@example.invalid');
 rgn_sql('UPDATE fixture_users SET user_level='.($junior?0:ADMIN).' WHERE user_id=1');
 rgn_sql("UPDATE fixture_sessions SET session_user_id=1,session_logged_in=1,session_admin=1 WHERE session_id='fixture-sid'");
 rgn_sql(rgn_insert_sql('fixture_themes',array('themes_id'=>1,'template_name'=>'fisubsilversh')));
 if($junior){rgn_sql(rgn_insert_sql('fixture_jr',array('user_id'=>1,'user_jr_admin'=>md5('UsersAdd_newadmin_user_register.php'))));}
}
function qad_run($name='Quick Grüße 😀', $address='quick@example.invalid')
{
 global $db,$userdata,$board_config,$phpbb_root_path,$phpEx,$lang,$table_prefix;
 $username=$name;$email=$address;$new_password=md5('fixture-password');$user_style=1;$user_timezone=0;$user_dateformat='Y-m-d';$user_lang='german';
 $user_id=phpbb_allocate_user_id($db,'fixture_');
 try{eval($GLOBALS['qad_body']);}catch(AttachSettingsExit $e){return $e->getMessage();}
 throw new RuntimeException('Quick-add must render a result');
}
function qad_success($out){return $out===$GLOBALS['lang']['Account_added'];}
function qad_suite()
{
 global $rgn_tables,$rgn_writes,$rgn_fail,$rgn_commit,$rgn_hook,$rgn_after,$rgn_after_queries,$rgn_queries,$rgn_open,$rgn_ack,$ats_source,$lang,$schema,$board_config,$userdata,$phpEx;
 require $ats_source.'includes/functions_admin_registration_storage.php';
 require $ats_source.'language/lang_english/lang_admin.php';
 if(!defined('THEMES_TABLE')){define('THEMES_TABLE','fixture_themes');}
 foreach(array('jr_admin_users'=>'jr','themes'=>'themes') as $original=>$suffix){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$original.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical ACP participant');rgn_sql(str_replace('phpbb_'.$original,'fixture_'.$suffix,$m[0]));$rgn_tables[]=$suffix;}
 $source=file_get_contents($ats_source.'admin/admin_user_register.php');
 $source=str_replace("\r\n","\n",$source);$a=strpos($source,'$account_created_at = time();');$b=strpos($source,"\n\t}\n} // End of submit",$a);
 ats_check($a!==false&&$b>$a,'Actual quick-add through final message');$GLOBALS['qad_body']=substr($source,$a,$b-$a);
 $routes=jr_admin_authorization_routes();ats_check($routes[md5('UsersAdd_newadmin_user_register.php')]==='admin_user_register.php','Exact installed quick-add module route');
 $cases=$serialized=0;
 foreach(array(false,true) as $junior){
  qad_reset($junior);$before=rgn_snap();ats_check(qad_success(qad_run()),'Root and exact delegated grant succeed');$writes=$rgn_writes;
  $row=rgn_rows('SELECT * FROM fixture_users WHERE user_id=8')[0];ats_check($row['username']==='Quick Grüße 😀'&&$row['user_active']==='1'&&$row['user_level']==='0'&&$row['user_actkey']===''&&$row['user_password']===md5('fixture-password'),'Active ordinary account, Unicode/password preserved, no bogus activation token');
  ats_check(count(rgn_rows('SELECT g.group_id FROM fixture_groups g JOIN fixture_user_group ug ON g.group_id=ug.group_id WHERE ug.user_id=8 AND ug.user_pending=0 AND g.group_single_user=1'))===1,'One personal group/membership');
  for($nth=1;$nth<=$writes;$nth++){qad_reset($junior);$before=rgn_snap();$rgn_fail=$nth;ats_check(!qad_success(qad_run())&&rgn_snap()===$before,'Every failed INSERT rolls back every account/group/member row');ats_check(rgn_rows('SELECT last_id FROM fixture_user_id_sequence')[0]['last_id']==='8','Failed account never reuses reserved ID');$cases++;}
 }
 foreach(array('fail','ack') as $kind){qad_reset();$before=rgn_snap();$rgn_commit=$kind;ats_check(!qad_success(qad_run()),'Missing commit ACK never claims success');ats_check($kind==='fail'?rgn_snap()===$before:count(rgn_rows('SELECT * FROM fixture_users WHERE user_id=8'))===1,'Unknown COMMIT outcome is whole');$cases++;}
 foreach(array('root','session','session-admin','inactive','deleted-actor','wrong-module','revoked-module','wrong-sid','case-sid','get','own-name','own-email','policy','missing-policy','theme','disallow','group','word','ban') as $case){
  qad_reset(in_array($case,array('wrong-module','revoked-module'),true));$name='Quick Grüße 😀';$email='quick@example.invalid';
  if($case==='root'){rgn_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
  if($case==='session'){rgn_sql("DELETE FROM fixture_sessions WHERE session_id='fixture-sid'");}
  if($case==='session-admin'){rgn_sql('UPDATE fixture_sessions SET session_admin=0');}
  if($case==='inactive'){rgn_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
  if($case==='deleted-actor'){rgn_sql('DELETE FROM fixture_users WHERE user_id=1');}
  if($case==='wrong-module'){rgn_sql("UPDATE fixture_jr SET user_jr_admin='".md5('UsersManageadmin_users.php')."'");}
  if($case==='revoked-module'){rgn_sql('DELETE FROM fixture_jr');}
  if($case==='wrong-sid'){$_POST['sid']='other';}
  if($case==='case-sid'){rgn_sql("UPDATE fixture_sessions SET session_id='FIXTURE-SID'");}
  if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}
  if($case==='own-name'){$name='Existing 1';}
  if($case==='own-email'){$email='existing1@example.invalid';}
  if($case==='policy'){rgn_sql("UPDATE fixture_config SET config_value='72' WHERE config_name='min_password_len'");}
  if($case==='missing-policy'){rgn_sql("DELETE FROM fixture_config WHERE config_name='password_hashing'");}
  if($case==='theme'){rgn_sql('DELETE FROM fixture_themes');}
  if($case==='disallow'){rgn_sql(rgn_insert_sql('fixture_disallow',array('disallow_username'=>'Quick*')));}
  if($case==='group'){rgn_sql("UPDATE fixture_groups SET group_name='Quick Grüße 😀'");}
  if($case==='word'){rgn_sql(rgn_insert_sql('fixture_words',array('word'=>'Quick*')));}
  if($case==='ban'){rgn_sql(rgn_insert_sql('fixture_banlist',array('ban_email'=>'quick@example.invalid')));}
  $before=rgn_snap();$out=qad_run($name,$email);ats_check(!qad_success($out)&&rgn_snap()===$before,'Invalid/stale quick-add rejected: '.$case);$cases++;
 }
 foreach(array('root','session','grant','policy','username','email','theme','disallow','ban') as $kind){
  qad_reset($kind==='grant');qad_run();$boundaries=array_values(array_filter($rgn_queries,'rgn_boundary'));
  for($nth=1;$nth<=count($boundaries);$nth++){
   qad_reset($kind==='grant');
   $changes=array('root'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','session'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'",'grant'=>'DELETE FROM fixture_jr WHERE user_id=1','policy'=>"UPDATE fixture_config SET config_value='72' WHERE config_name='min_password_len'",'username'=>rgn_insert_sql('fixture_users',array('user_id'=>900,'username'=>'Quick Grüße 😀','user_email'=>'other@example.invalid')),'email'=>rgn_insert_sql('fixture_users',array('user_id'=>900,'username'=>'Other','user_email'=>'quick@example.invalid')),'theme'=>'DELETE FROM fixture_themes','disallow'=>rgn_insert_sql('fixture_disallow',array('disallow_username'=>'Quick*')),'ban'=>rgn_insert_sql('fixture_banlist',array('ban_email'=>'quick@example.invalid')));
   $change=$changes[$kind];$seen=0;$reached=$blocked=false;$revoked=null;
   $rgn_hook=function($sql)use($nth,$change,&$seen,&$reached,&$blocked,&$revoked){if(!rgn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['rgn_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Independent change blocks, no unexpected SQL error');$blocked=true;}else{$revoked=rgn_snap();}};
   $out=qad_run();ats_check($reached,'Every quick-add boundary reached');if($blocked){ats_check(qad_success($out),'Change serializes after publication');$serialized++;}else{ats_check(!qad_success($out)&&rgn_snap()===$revoked,'Current authority/rules win before publication');}$cases++;
  }
  echo 'Quick-add concurrent '.$kind." boundaries passed.\n";
 }
 foreach(array('root','session','disconnect') as $kind){qad_reset();$rgn_after=function($writer)use($kind){rgn_sql($kind==='root'?'UPDATE fixture_users SET user_level=0 WHERE user_id=1':($kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'":'KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id)));};ats_check(qad_success(qad_run())&&!$rgn_after_queries,'Post-ACK change never reverses acknowledged creation');$cases++;}
 qad_reset();$seen=false;$rgn_hook=function($sql)use(&$seen){if($sql==='COMMIT'){$seen=true;ats_check(!rgn_rows('SELECT user_id FROM fixture_users WHERE user_id=8')&&!rgn_rows('SELECT user_id FROM fixture_user_group WHERE user_id=8'),'Independent reader sees no partial account');}};ats_check(qad_success(qad_run())&&$seen,'Atomic publication visibility');
 qad_reset();ats_check(qad_success(qad_run())&&!qad_success(qad_run()),'Overlapping prevalidated duplicate creates only one account');
 foreach(array('users','sessions','jr','config','groups','user_group','disallow','words','banlist','themes','profile_fields') as $s){qad_reset();rgn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=rgn_snap();ats_check(!qad_success(qad_run())&&rgn_snap()===$before,'Reject nontransactional participant '.$s);rgn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 qad_reset();$scope=new PhpbbAdminRegistrationScope($GLOBALS['db'],$_POST,'Quick','quick@example.invalid',1);
 foreach(array('COMMIT','ROLLBACK','ALTER TABLE fixture_users ADD unexpected INT') as $sql){$denied=false;try{$scope->sql_query($sql);}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,'Only owner controls transaction/DDL');}
 $scope->release();$denied=false;try{$scope->finish();}catch(PhpbbAclException $e){$denied=true;}ats_check($denied&&$rgn_open===0,'Released owner cannot commit');
 ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_identifier');ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_tables');$covered=plus_storage_tables($schema,'fixture_');
 foreach(array('users','sessions','jr_admin_users','config','groups','user_group','disallow','words','banlist','themes','profile_fields') as $s){ats_check(in_array('fixture_'.$s,$covered,true),'Existing migration covers ACP participant '.$s);}
 echo 'Native ACP quick-add: '.$cases.' boundary/failure cases, '.$serialized." serialized changes; exact grants, rollback, IDs, Unicode and ACK passed.\n";
}
eval($fixture_head . $fixture_tail);
