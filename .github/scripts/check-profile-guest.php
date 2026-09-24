<?php
// Reuse only fixture definitions, then execute the real ERC guest branch and
// its real credential verifier. Native mode is provided by the owned runner.
$source=file_get_contents(__DIR__.'/check-maintenance-users.php');
$cut=strpos($source,'set_error_handler(');
if($cut===false){throw new RuntimeException('Guest fixture setup boundary missing');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));
if(eval($head)===false){throw new RuntimeException('Guest fixture did not execute');}
require_once $root.'includes/php_compat.php';
$source=file_get_contents($root.'includes/functions_dbmtnc.php');
$a=strpos($source,'function check_authorisation(');$b=strpos($source,'function get_config_data(',$a);
reset_check($a!==false&&$b>$a,'Actual ERC authorization boundary');
if(eval(substr($source,$a,$b-$a))===false){throw new RuntimeException('ERC authorization did not load');}
$source=file_get_contents($root.'admin/erc.php');
$a=strpos($source,'// Serialize with profile definitions');$b=strpos($source,"success_message(\$lang['cbl_success_anonymous']);",$a);
reset_check($a!==false&&$b>$a,'Actual ERC guest branch boundary');
$guestCode=substr($source,$a,$b-$a)."success_message(\$lang['cbl_success_anonymous']);";
function erc_throw_error($message){reset_check($GLOBALS['resetServer']->owner===null,'ERC releases owner before terminal error');throw new ResetControllerFailure($message);}
function success_message($message){reset_check($GLOBALS['resetServer']->owner===null,'ERC releases owner before success');echo $message;}
function guest_run($expected=''){
 global $db,$phpbb_root_path,$phpEx,$lang,$guestCode;
 $db=new UserForum();$original=$db;$error='';ob_start();
 try{if(eval($guestCode)===false){throw new RuntimeException('ERC guest branch did not execute');}}
 catch(ResetControllerFailure $e){$error=$e->getMessage();}finally{$html=ob_get_clean();}
 reset_check($db===$original&&$GLOBALS['resetServer']->owner===null,'ERC restores main connection and releases owner');
 reset_check($error===$expected,'ERC result '.$error.' / '.$expected);
 reset_check(($expected==='')===($html==='recovered'),'ERC success only after confirmed result');
}
function guest_fixture($engine,$method='board'){
 global $lang,$dbuser,$dbpasswd,$option,$HTTP_POST_VARS;
 user_fixture($engine,1,true);$lang=array('cbl_success_anonymous'=>'recovered');$option='cbl';$dbuser='fixture';$dbpasswd='local-only';
 $HTTP_POST_VARS=array('auth_method'=>$method,'db_user'=>$dbuser,'db_password'=>$dbpasswd,'board_user'=>'Admin','board_password'=>'fixture-password');
 $GLOBALS['resetServer']->pdo->exec("UPDATE fixture_users SET username='Admin',user_password='".md5('fixture-password')."' WHERE user_id=1");
}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $physical=array(array('COLUMN_NAME'=>'custom','DATA_TYPE'=>'varchar','EXTRA'=>''));
 $field=array('field_name'=>'Label','field_column'=>'custom');
 reset_check(phpbb_profile_guest_columns(array($field),array(),$physical)===array('custom'),'Explicit mapping');
 $physical[0]['EXTRA']='DEFAULT_GENERATED';reset_check(phpbb_profile_guest_columns(array($field),array(),$physical)===array('custom'),'Default expression is writable, not a generated column');$physical[0]['EXTRA']='';
 reset_check(phpbb_profile_guest_columns(array(array('field_name'=>'custom')),array(),$physical)===array('custom'),'Legacy mapping');
 reset_check(phpbb_profile_guest_columns(array(),array(array('field_column'=>'gone','action_state'=>'purged')),array())===array(),'Purged receipt does not recreate a dropped column');
 foreach(array('identifier','core','duplicate','missing','nontext','generated','state','archive-core','conflict','mixed-receipts','purged-recreated') as $case){
  $fields=array($field);$actions=array();$columns=$physical;
  if($case==='identifier'){$fields[0]['field_column']='custom`,user_level';}elseif($case==='core'){$fields[0]['field_column']='user_level';}
  elseif($case==='duplicate'){$fields[]=$field;}elseif($case==='missing'){$columns=array();}
  elseif($case==='nontext'){$columns[0]['DATA_TYPE']='int';}elseif($case==='generated'){$columns[0]['EXTRA']='VIRTUAL GENERATED';}
  elseif($case==='state'){$actions[]=array('field_column'=>'custom','action_state'=>'unknown');}
  elseif($case==='archive-core'){$actions[]=array('field_column'=>'user_password','action_state'=>'retired');}
  elseif($case==='conflict'){$actions[]=array('field_column'=>'custom','action_state'=>'retired');}
  elseif($case==='purged-recreated'){$fields=array();$actions[]=array('field_column'=>'custom','action_state'=>'purged');}
  else{$fields=array();$columns=array();$actions=array(array('field_column'=>'custom','action_state'=>'purged'),array('field_column'=>'custom','action_state'=>'retired'));}
  $denied=false;try{phpbb_profile_guest_columns($fields,$actions,$columns);}catch(UnexpectedValueException $e){$denied=true;}reset_check($denied,'Unsafe guest metadata '.$case);
 }
 $cases=0;
 foreach($resetNative?array('MyISAM','InnoDB'):array('SQLite') as $engine){
  foreach(array('board','db') as $method){
   guest_fixture($engine,$method);$before=user_snapshot();guest_run();
   reset_check(user_value('SELECT guest_custom FROM fixture_users WHERE user_id=-1')===''&&user_value('SELECT guest_retired FROM fixture_users WHERE user_id=-1')==='','ERC active and retired defaults blank');
   reset_check(user_value('SELECT guest_other FROM fixture_users WHERE user_id=-1')==='Plugin default','ERC leaves unowned plugin defaults alone');
   $after=user_snapshot();$existing=array_values(array_filter($after['users'],function($row){return (int)$row['user_id']!==-1;}));reset_check($existing===$before['users'],'ERC preserves other accounts byte-for-byte');
   foreach($before as $table=>$rows){if($table!=='users'){reset_check($rows===$after[$table],'Guest branch does not modify '.$table);}}
   $GLOBALS['resetServer']->pdo->exec("UPDATE fixture_users SET guest_custom='Existing guest value' WHERE user_id=-1");$stable=user_snapshot();guest_run();reset_check(user_snapshot()===$stable,'Existing guest is never overwritten');$cases++;
  }
  foreach(array('busy','read','auth-read','insert','ack','core','invalid-auth','demoted','inactive','password','reappeared','lost-owner') as $case){
   guest_fixture($engine);$s=$GLOBALS['resetServer'];$expected="Couldn't add user data!";$injected=null;
   if($case==='busy'){$s->failure='lock';}elseif($case==='read'){$s->failure='SELECT field_column,action_state';}
   elseif($case==='auth-read'){$s->failure='SELECT user_id, username, user_password';}
   elseif($case==='insert'||$case==='ack'){$s->failure='INSERT INTO fixture_users';$s->lostAck=$case==='ack';}
   elseif($case==='core'){$s->pdo->exec("UPDATE fixture_profile_fields SET field_column='user_level'");}
   elseif($case==='invalid-auth'){$HTTP_POST_VARS['board_password']='wrong';}
   else{
    if($case==='reappeared'){$expected='';}
    $s->hook=function($sql,$connection)use($case,&$injected){if(strpos($sql,'INSERT INTO fixture_users')!==0){return;}$s=$GLOBALS['resetServer'];$s->hook=null;
     if($case==='demoted'){$s->pdo->exec('UPDATE fixture_users SET user_level=0 WHERE user_id=1');}
     elseif($case==='inactive'){$s->pdo->exec('UPDATE fixture_users SET user_active=0 WHERE user_id=1');}
     elseif($case==='password'){$s->pdo->exec("UPDATE fixture_users SET user_password='changed' WHERE user_id=1");}
     elseif($case==='reappeared'){$s->pdo->exec("INSERT INTO fixture_users (user_id,username,guest_custom) VALUES (-1,'Anonymous','Peer guest')");}
     else{$connection->sql_close();}
     $injected=user_snapshot();
    };
   }
   $before=user_snapshot();guest_run($expected);
   if($case==='ack'){
    reset_check(user_value('SELECT guest_custom FROM fixture_users WHERE user_id=-1')===''&&user_value('SELECT guest_retired FROM fixture_users WHERE user_id=-1')==='','Uncertain ACK leaves a complete guest');
    $s->failure='';$s->lostAck=false;$stable=user_snapshot();guest_run();reset_check(user_snapshot()===$stable,'Lost ACK retry preserves existing guest');
   }else{reset_check(user_snapshot()===($injected===null?$before:$injected),'No mutation after guest repair rejection/race '.$case);}
   $cases++;
  }
 }
 echo 'Guest recovery: metadata guards and '.$cases." actual ERC authorization/default/failure/race cases passed.\n";
}finally{restore_error_handler();}
