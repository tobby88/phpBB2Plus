<?php
require_once dirname(dirname(__DIR__)).'/phpBB2/includes/functions_profile_fields.php';
function pad_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$pad_fields=array();$pad_defaults=array('text_field_default','text_area_default','radio_button_default','checkbox_default');
foreach($pad_defaults as $type=>$key){$pad_fields[]=array('field_name'=>'Label '.$type,'field_column'=>'custom_'.$type,'field_type'=>$type,$key=>"Grüße \\ &amp; ' 😀 0");}
$pad_values=phpbb_profile_new_account_values($pad_fields);
pad_check(phpbb_profile_field_substr('',255)===''&&phpbb_profile_field_substr('value',0)==='','Empty text remains string on PHP 5.6 with/without mbstring');
pad_check(count($pad_values)===4&&count(array_unique($pad_values))===1,'All four defaults preserve storage bytes');
pad_check(phpbb_profile_new_account_values($pad_fields,array('custom_0'=>'','custom_1'=>'0'))===array_merge($pad_values,array('custom_0'=>'','custom_1'=>'0')),'Explicit empty/zero overrides, absent defaults');
foreach(array('core','duplicate','invalid-column','bad-type','array-default','nul','utf8','unknown-input','array-input') as $case){
 $fields=$pad_fields;$submitted=array();
 if($case==='core'){$fields[0]['field_column']='user_level';}elseif($case==='duplicate'){$fields[1]['field_column']='custom_0';}elseif($case==='invalid-column'){$fields[0]['field_column']='x`,user_level';}elseif($case==='bad-type'){$fields[0]['field_type']='8';}
 elseif($case==='array-default'){$fields[0]['text_field_default']=array('bad');}elseif($case==='nul'){$fields[0]['text_field_default']="bad\0text";}elseif($case==='utf8'){$fields[0]['text_field_default']="\xc3";}elseif($case==='unknown-input'){$submitted['user_password']='bad';}else{$submitted['custom_0']=array('bad');}
 $denied=false;try{phpbb_profile_new_account_values($fields,$submitted);}catch(UnexpectedValueException $e){$denied=true;}pad_check($denied,'Reject unsafe defaults '.$case);
}
echo "Account profile defaults: all types, exact storage bytes, empty/zero and unsafe mappings passed.\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_REGISTRATION_NATIVE')!=='1'){return;}
$source=file_get_contents(__DIR__.'/check-registration-native.php');$cut=strpos($source," foreach(array(0,1,2,3) as \$mode)");
pad_check($cut!==false,'Actual registration setup');$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_registration_','codex_profile_defaults_',$head);
$tail= <<<'PHP'
 require_once $ats_source.'includes/functions_admin_registration_storage.php';
 require $ats_source.'language/lang_english/lang_admin.php';
 if(!defined('THEMES_TABLE')){define('THEMES_TABLE','fixture_themes');}
 foreach(array('jr_admin_users'=>'jr','themes'=>'themes') as $original=>$suffix){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$original.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical quick-add dependency');rgn_sql(str_replace('phpbb_'.$original,'fixture_'.$suffix,$m[0]));$rgn_tables[]=$suffix;}
 foreach(array('qad_reset','qad_run','qad_success') as $name){ats_load_function(__DIR__.'/check-admin-registration-native.php',$name);}
 $source=str_replace("\r\n","\n",file_get_contents($ats_source.'admin/admin_user_register.php'));$a=strpos($source,'$account_created_at = time();');$b=strpos($source,"\n\t}\n} // End of submit",$a);ats_check($a!==false&&$b>$a,'Actual quick-add controller');$GLOBALS['qad_body']=substr($source,$a,$b-$a);
 rgn_sql("ALTER TABLE fixture_users ADD pad_hidden MEDIUMTEXT NULL, ADD pad_zero MEDIUMTEXT NULL, ADD pad_radio MEDIUMTEXT NULL, ADD pad_check MEDIUMTEXT NULL, ADD pad_retired VARCHAR(255) DEFAULT 'Inactive default', ADD pad_unowned VARCHAR(255) DEFAULT 'Plugin default'");
 function pad_reset($route,$value){
  global $profile_data;
  if($route==='public'){rgn_reset(0,'none');}else{qad_reset();}
  foreach(array('pad_hidden'=>array(0,'text_field_default',$value),'pad_zero'=>array(1,'text_area_default','0'),'pad_radio'=>array(2,'radio_button_default','0'),'pad_check'=>array(3,'checkbox_default','0,one')) as $column=>$def){
   rgn_sql(rgn_insert_sql('fixture_profile_fields',array('field_name'=>'Label '.$column,'field_column'=>$column,'field_type'=>$def[0],'users_can_view'=>0,$def[1]=>$def[2],'radio_button_values'=>'0,one','checkbox_values'=>'0,one')));
  }
  rgn_sql("UPDATE fixture_profile_fields SET text_field_default='Visible default',is_required=0 WHERE field_id=1");
  $r=rgn_sql('SELECT * FROM fixture_profile_fields WHERE users_can_view=1 ORDER BY field_id ASC');$profile_data=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);
  rgn_sql("UPDATE fixture_users SET pad_hidden='Existing owner secret',pad_zero='old' WHERE user_id=1");
  rgn_sql("UPDATE fixture_users SET pad_retired='Existing archived value'");
  foreach(array('a'=>array('pad_retired','retired'),'b'=>array('pad_hidden','restored'),'c'=>array('pad_purged_absent','purged')) as $key=>$action){rgn_sql(rgn_insert_sql('fixture_profile_field_actions',array('operation_key'=>str_repeat($key,64),'field_id'=>90,'field_column'=>$action[0],'action_state'=>$action[1])));}
  $_POST['pad_hidden']='attacker hidden value';$_POST['fixture_profile']='';
  $_POST['pad_retired']='forged inactive field';
 }
 function pad_success($route,$out){return $route==='public'?rgn_success($out):qad_success($out);}
 function pad_run($route){return $route==='public'?rgn_run():qad_run();}
 $cases=0;
 foreach(array('public','quick') as $route){
  foreach(array('', '0',"Grüße \\ &amp; ' 😀") as $value){
   pad_reset($route,$value);$before=rgn_rows('SELECT * FROM fixture_users ORDER BY user_id');
   $out=pad_run($route);ats_check(pad_success($route,$out),'Actual creation '.$route.' '.strip_tags($out));
   $row=rgn_rows('SELECT * FROM fixture_users WHERE user_id=8')[0];ats_check($row['pad_hidden']===$value&&$row['pad_zero']==='0'&&$row['pad_radio']==='0'&&$row['pad_check']==='0,one','Current defaults for hidden/all types '.$route);
   ats_check($row['pad_retired']===''&&$row['pad_unowned']==='Plugin default','Inactive defaults blank, forged input ignored, unowned plugin preserved '.$route);
   ats_check($row['fixture_profile']===($route==='public'?'':'Visible default'),'Public empty overrides default; quick-add uses configured default');
   ats_check(rgn_rows('SELECT * FROM fixture_users WHERE user_id<>8 ORDER BY user_id')===$before,'Existing owners unchanged; no inheritance '.$route);$cases++;
  }
  foreach(array('write','commit','ack','core','duplicate','column','retired-core','retired-missing','retired-conflict','purged-recreated','retired-state') as $failure){
   pad_reset($route,'default');
   if($failure==='write'){$rgn_fail=3;}elseif($failure==='commit'){$rgn_commit='fail';}elseif($failure==='ack'){$rgn_commit='ack';}
   elseif($failure==='core'){rgn_sql("UPDATE fixture_profile_fields SET field_column='user_level' WHERE field_column='pad_hidden'");}
   elseif($failure==='duplicate'){rgn_sql("UPDATE fixture_profile_fields SET field_column='fixture_profile' WHERE field_column='pad_hidden'");}
   elseif($failure==='column'){rgn_sql("UPDATE fixture_profile_fields SET field_column='missing_column' WHERE field_column='pad_hidden'");}
   else{$set=$failure==='retired-core'?"field_column='user_level'":($failure==='retired-missing'?"field_column='missing_retired'":($failure==='retired-conflict'?"field_column='pad_hidden'":($failure==='purged-recreated'?"action_state='purged'":"action_state='unknown'")));rgn_sql("UPDATE fixture_profile_field_actions SET $set WHERE operation_key='".str_repeat('a',64)."'");}
   $before=rgn_snap();ats_check(!pad_success($route,pad_run($route)),'Unconfirmed unsafe creation '.$route.' '.$failure);
   if($failure==='ack'){$row=rgn_rows('SELECT * FROM fixture_users WHERE user_id=8')[0];ats_check($row['pad_hidden']==='default'&&$row['pad_retired']==='','Lost ACK contains complete active/inactive defaults');}
   else{ats_check(rgn_snap()===$before,'Creation/defaults roll back together '.$route.' '.$failure);}$cases++;
  }
  foreach(array('update','insert') as $change){
   pad_reset($route,'pinned');$blocked=false;
   $rgn_hook=function($sql)use($change,&$blocked){if(strpos($sql,'INSERT INTO fixture_users')!==0){return;}$GLOBALS['rgn_hook']=null;$query=$change==='update'?"UPDATE fixture_profile_fields SET text_field_default='changed' WHERE field_column='pad_hidden'":rgn_insert_sql('fixture_profile_fields',array('field_name'=>'New hidden','users_can_view'=>0));$result=$GLOBALS['peer']->sql_query($query);$blocked=!$result&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
   ats_check(pad_success($route,pad_run($route))&&$blocked,'All hidden definitions/range pinned '.$route.' '.$change);$cases++;
  }
  foreach(array('update','insert') as $change){
   pad_reset($route,'pinned');$blocked=false;
   $rgn_hook=function($sql)use($change,&$blocked){if(strpos($sql,'INSERT INTO fixture_users')!==0){return;}$GLOBALS['rgn_hook']=null;$query=$change==='update'?"UPDATE fixture_profile_field_actions SET action_state='restored' WHERE operation_key='".str_repeat('a',64)."'":rgn_insert_sql('fixture_profile_field_actions',array('operation_key'=>str_repeat('d',64),'field_id'=>91,'field_column'=>'pad_unowned','action_state'=>'retired'));$result=$GLOBALS['peer']->sql_query($query);$blocked=!$result&&(int)$GLOBALS['peer']->sql_error()['code']===1205;};
   ats_check(pad_success($route,pad_run($route))&&$blocked,'Retirement receipts/range pinned '.$route.' '.$change);$cases++;
  }
 }
 echo 'Native account profile defaults: '.$cases." public/quick creation, hidden metadata, rollback, ACK, core protection and concurrency cases passed.\n";
}finally{$rgn_hook=$rgn_after=null;$rgn_main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned defaults schema');$control->sql_close();restore_error_handler();}
PHP;
$tail=str_replace('__DIR__',var_export(__DIR__,true),$tail);
if(eval($head.$tail)===false){throw new RuntimeException('Native defaults fixture did not execute');}
