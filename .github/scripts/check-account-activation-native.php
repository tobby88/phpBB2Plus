<?php
// Actual activation controller and storage on a disposable loopback database.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('ANONYMOUS'=>-1,'CONFIG_TABLE'=>'fixture_config','SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','POST_USERS_URL'=>'u','USER_ACTIVATION_ADMIN'=>2,'PHPBB_PASSWORD_RESET_PENDING'=>'!reset-pending!') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_account_activation.php';require $ats_source.'includes/functions_validate.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_ACTIVATION_NATIVE')!=='1'){echo "Native activation checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_ACTIVATION_PORT')?:'3306';$password=getenv('PHPBB_ACTIVATION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_activation_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class ActivationNativeConnection {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($m,$a){return call_user_func_array(array($this->db,$m),$a);}
 function sql_query($sql,$tx=false){
  if($GLOBALS['an_ack']){$GLOBALS['an_after_queries'][]=$sql;}$GLOBALS['an_queries'][]=$sql;
  if(is_callable($GLOBALS['an_hook'])){call_user_func($GLOBALS['an_hook'],$sql,$this);}
  if(preg_match('/^(UPDATE|DELETE)\b/',$sql)&&++$GLOBALS['an_writes']===$GLOBALS['an_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['an_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);
  if($sql==='COMMIT'&&$r&&$GLOBALS['an_commit']!=='ack'){$GLOBALS['an_ack']=true;if(is_callable($GLOBALS['an_after'])){call_user_func($GLOBALS['an_after'],$this);}}
  return $sql==='COMMIT'&&$GLOBALS['an_commit']==='ack'?false:$r;
 }
}
class ActivationNativeDatabase extends sql_db {
 function sql_dedicated_connection(){return new ActivationNativeConnection(parent::sql_dedicated_connection());}
 function sql_query($sql='',$tx=false){ats_check(!preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql),'Controller never writes outside owned transaction');return parent::sql_query($sql,$tx);}
}
class ActivationNativeTemplate {function assign_vars($vars){}}
function phpbb_setcookie(){ats_check($GLOBALS['an_ack'],'Cookies only after acknowledged commit');$GLOBALS['an_cookies'][]=func_get_args();}
// Replace only the external mail transport/render boundary, not controller SQL.
eval('namespace ActivationControllerFixture; class emailer { function __construct($smtp,$optional=false){\ats_check($optional,"Activation mail is optional after publication");} function __call($m,$args){} function send(){\ats_check($GLOBALS["an_ack"],"No mail before confirmed commit");$GLOBALS["an_mail"]++;if($GLOBALS["an_mail_fail"]){throw new \\RuntimeException("fixture delivery failure");}return true;} } function usercp_render_password_reset($row,$key,$error=""){throw new \\AttachSettingsExit("render-reset:".$error);}');
$an_main=new ActivationNativeDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$an_main;
$an_tables=array('users','sessions','sessions_keys','config');$an_hook=$an_after=null;$an_ack=false;$an_queries=$an_after_queries=$an_cookies=array();$an_writes=$an_fail=$an_mail=0;$an_commit='';$an_mail_fail=false;
$controller=file_get_contents($ats_source.'includes/usercp_activate.php');$start=strpos($controller,'$activation_user_id =');ats_check($start!==false,'Actual controller after function declarations');$an_body='namespace ActivationControllerFixture;use \\PhpbbActivationException;use \\Exception;use \\Throwable;'.substr($controller,$start);
function an_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Owned fixture SQL '.(strpos($sql,'ALTER TABLE')===0?$sql:'').': '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function an_rows($sql){$r=an_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function an_snap(){$snap=array();foreach($GLOBALS['an_tables'] as $s){$rows=an_rows('SELECT * FROM fixture_'.$s);foreach($rows as &$row){foreach(array('ct_last_pw_change','user_passwd_change') as $key){if(isset($row[$key])&&(int)$row[$key]>1){$row[$key]='fresh';}}}unset($row);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$snap[$s]=$rows;}return $snap;}
function an_insert($table,$values){foreach(an_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}an_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');}
function an_reset($mode){
 global $db,$userdata,$board_config,$phpbb_root_path,$phpEx,$an_mode,$an_hook,$an_after,$an_ack,$an_queries,$an_after_queries,$an_cookies,$an_writes,$an_fail,$an_mail,$an_mail_fail,$an_commit;
 $db=$GLOBALS['an_main'];$an_mode=$mode;$an_hook=$an_after=null;$an_ack=false;$an_queries=$an_after_queries=$an_cookies=array();$an_writes=$an_fail=$an_mail=0;$an_mail_fail=false;$an_commit='';
 an_sql('START TRANSACTION');foreach($GLOBALS['an_tables'] as $s){an_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1,1,2,7) as $id){an_insert('fixture_users',array('user_id'=>$id,'username'=>'Grüße-'.$id,'user_email'=>'member'.$id.'@example.invalid','user_password'=>'before','user_newpasswd'=>'','user_actkey'=>'','user_active'=>1,'user_level'=>$id===1?ADMIN:0));}
 $pending=strpos($mode,'reset')===0?PHPBB_PASSWORD_RESET_PENDING:(strpos($mode,'legacy')===0?md5('LegacyFixture!9'):'');
 an_sql("UPDATE fixture_users SET user_active=".($pending===''?0:1).",user_actkey='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',user_newpasswd='".$pending."',ct_last_pw_reset=2000000000 WHERE user_id=2");
 $actor=$mode==='admin'?1:(strpos($mode,'self-reset')===0||substr($mode,-4)==='self'?2:-1);
 // Account self-activation remains a guest bearer-link action.
 if($mode==='self'){$actor=-1;}
 foreach(array('fixture-sid'=>$actor,'target-old'=>2,'root-other'=>1,'unrelated'=>7) as $sid=>$id){an_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>$id>0?1:0));}
 foreach(array(2,7) as $id){an_insert('fixture_sessions_keys',array('key_id'=>md5('key-'.$id),'user_id'=>$id));}
 $board_config=array('require_activation'=>$mode==='admin'?2:1,'min_password_len'=>8,'password_not_login'=>1,'force_complex_password'=>1,'password_hashing'=>0);
 foreach($board_config as $name=>$value){an_insert('fixture_config',array('config_name'=>$name,'config_value'=>$value));}an_sql('COMMIT');
 $board_config+=array('cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0,'smtp_delivery'=>false,'board_email'=>'forum@example.invalid','sitename'=>'Fixture');
 $userdata=array('user_id'=>$actor,'user_level'=>$actor===1?ADMIN:0,'session_logged_in'=>$actor>0,'session_id'=>'fixture-sid','session_key'=>'old-key');
 $_SERVER['REQUEST_METHOD']='POST';$_GET=array('u'=>'2','act_key'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');$_POST=array('sid'=>'fixture-sid','new_password'=>'Quote\' Grüße!9','password_confirm'=>'Quote\' Grüße!9');if(strpos($mode,'reset')===0){$_POST['reset_password']='1';}$phpbb_root_path=$GLOBALS['ats_source'];$phpEx='php';
}
function an_run(){global $db,$userdata,$board_config,$phpbb_root_path,$phpEx,$lang;$template=new ActivationNativeTemplate();try{eval($GLOBALS['an_body']);}catch(AttachSettingsExit $e){return $e->getMessage();}throw new RuntimeException('Controller must return a message');}
function an_success($out,$mode){$key=strpos($mode,'reset')===0?'Password_reset_complete':(strpos($mode,'legacy')===0?'Password_activated':($mode==='admin'?'Account_active_admin':'Account_active'));return strpos($out,$GLOBALS['lang'][$key])===0;}
function an_boundary($sql){return preg_match('/^(UPDATE|DELETE)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' FOR UPDATE')!==false||strpos($sql,' LOCK IN SHARE MODE')!==false;}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach($an_tables as $s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical participant');an_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 an_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('self','admin','reset-guest','reset-self','legacy-guest','legacy-self') as $mode){
  an_reset($mode);$before=an_snap();$out=an_run();ats_check(an_success($out,$mode),'Authorized actual controller '.$mode);$after=an_snap();$writes=$an_writes;$boundaries=array_values(array_filter($an_queries,'an_boundary'));
  ats_check(an_rows('SELECT user_actkey FROM fixture_users WHERE user_id=2')[0]['user_actkey']==='','Consume once');
  if(strpos($mode,'reset')===0||strpos($mode,'legacy')===0){ats_check(!an_rows('SELECT * FROM fixture_sessions WHERE session_user_id=2')&&!an_rows('SELECT * FROM fixture_sessions_keys WHERE user_id=2'),'Revoke every target login/key');ats_check(phpbb_password_verify(strpos($mode,'reset')===0?$_POST['new_password']:'LegacyFixture!9',an_rows('SELECT user_password FROM fixture_users WHERE user_id=2')[0]['user_password']),'Exact Unicode or legacy credential');}
  ats_check(count(an_rows("SELECT * FROM fixture_sessions WHERE session_id='unrelated'"))===1&&count(an_rows('SELECT * FROM fixture_sessions_keys WHERE user_id=7'))===1,'Unrelated logins unchanged');
  ats_check(count($an_cookies)===(substr($mode,-4)==='self'&&$mode!=='self'?2:0),'Self-reset publishes logout only after commit');ats_check($an_mail===($mode==='admin'?1:0),'Only confirmed admin activation notifies');
  $mail=$an_mail;an_run();ats_check(an_snap()===$after&&$an_mail===$mail,'One-use link never repeats writes/mail');
  for($i=1;$i<=$writes;$i++){an_reset($mode);$before=an_snap();$an_fail=$i;$out=an_run();ats_check(!an_success($out,$mode)&&an_snap()===$before&&!$an_cookies&&!$an_mail,'Every failed write rolls back credentials/token/logins '.$mode.' '.$i);$cases++;}
  foreach(array('fail','ack') as $commit){an_reset($mode);$before=an_snap();$an_commit=$commit;$out=an_run();ats_check(!an_success($out,$mode)&&an_snap()===($commit==='fail'?$before:$after)&&!$an_cookies&&!$an_mail,'Failed/lost commit never falsely announces success');$cases++;}
  foreach(array('token','disconnect') as $kind){an_reset($mode);$stored=null;$an_after=function($writer)use($kind,&$stored){$stored=an_snap();an_sql($kind==='disconnect'?'KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id):"UPDATE fixture_users SET user_actkey='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE user_id=2");};$out=an_run();ats_check(an_success($out,$mode)&&$stored===$after&&!$an_after_queries,'Post-ack changes do not revoke completed result');$cases++;}
  $changes=array('token'=>"UPDATE fixture_users SET user_actkey='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE user_id=2",'email'=>"UPDATE fixture_users SET user_email='changed@example.invalid' WHERE user_id=2");
  if($mode==='admin'){$changes['root']='UPDATE fixture_users SET user_level=0 WHERE user_id=1';$changes['sid']="DELETE FROM fixture_sessions WHERE session_id='fixture-sid'";}
  if(strpos($mode,'reset')===0){$changes['inactive']='UPDATE fixture_users SET user_active=0 WHERE user_id=2';$changes['sid']="DELETE FROM fixture_sessions WHERE session_id='fixture-sid'";}
  foreach($changes as $kind=>$change){for($nth=1;$nth<=count($boundaries);$nth++){
   an_reset($mode);$before=an_snap();$seen=0;$reached=$blocked=false;$revoked=null;
   $an_hook=function($sql)use($change,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!an_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['an_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Real lock timeout');$blocked=true;}else{$revoked=an_snap();}};
   $out=an_run();ats_check($reached,'Every authority/write/commit boundary reached');if($blocked){ats_check(an_success($out,$mode)&&an_snap()===$after,'Concurrent change serializes after whole publication');$serialized++;}else{ats_check(!an_success($out,$mode)&&an_snap()===$revoked&&!$an_cookies&&!$an_mail,'Effective concurrent change rejects whole publication');}$cases++;
  }}
  echo $mode." native token/authority/atomic publication passed\n";
 }
 foreach(array('token','case','pending','expired','inactive','sid','get','policy','root','root-inactive','admin-sid','wrong-policy','legacy-marker') as $case){
  $mode=strpos($case,'root')===0||$case==='admin-sid'?'admin':($case==='legacy-marker'?'legacy-guest':'reset-guest');an_reset($mode);
  $changes=array('token'=>"UPDATE fixture_users SET user_actkey='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE user_id=2",'case'=>"UPDATE fixture_users SET user_actkey=UPPER(user_actkey) WHERE user_id=2",'pending'=>"UPDATE fixture_users SET user_newpasswd='' WHERE user_id=2",'expired'=>'UPDATE fixture_users SET ct_last_pw_reset=1 WHERE user_id=2','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','sid'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'",'policy'=>"UPDATE fixture_config SET config_value='20' WHERE config_name='min_password_len'",'root'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','root-inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','admin-sid'=>"UPDATE fixture_sessions SET session_id='FIXTURE-SID' WHERE session_id='fixture-sid'",'wrong-policy'=>"UPDATE fixture_config SET config_value='2' WHERE config_name='require_activation'",'legacy-marker'=>"UPDATE fixture_users SET user_newpasswd='not-a-password-hash' WHERE user_id=2");
  if(isset($changes[$case])){an_sql($changes[$case]);}if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}
  // Admin activation policy does not prohibit an active user's password reset.
  if($case==='wrong-policy'){ats_check(an_success(an_run(),$mode),'Reset independent of account activation policy');continue;}
  $before=an_snap();$out=an_run();ats_check(!an_success($out,$mode)&&an_snap()===$before&&!$an_mail&&!$an_cookies,'Reject invalid current activation '.$case);$cases++;
 }
 foreach($an_tables as $s){an_reset('reset-guest');an_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=an_snap();ats_check(!an_success(an_run(),'reset-guest')&&an_snap()===$before,'Reject nontransactional '.$s);an_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 an_reset('reset-guest');an_sql('ALTER TABLE fixture_config ROW_FORMAT=COMPACT');$before=an_snap();ats_check(!an_success(an_run(),'reset-guest')&&an_snap()===$before,'Reject compact rows');an_sql('ALTER TABLE fixture_config ROW_FORMAT=DYNAMIC');
 an_sql('ALTER TABLE fixture_users MODIFY username VARCHAR(25) CHARACTER SET latin1 NOT NULL');$before=an_snap();ats_check(!an_success(an_run(),'reset-guest')&&an_snap()===$before,'Reject old column encoding');an_sql('ALTER TABLE fixture_users MODIFY username VARCHAR(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
 an_reset('admin');$an_mail_fail=true;$out=an_run();ats_check(an_success($out,'admin')&&strpos($out,$lang['Activation_mail_failed'])!==false&&an_rows('SELECT user_active FROM fixture_users WHERE user_id=2')[0]['user_active']==1,'Mail failure does not undo confirmed activation');
 an_reset('reset-guest');$board_config['password_hashing']=1;an_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='password_hashing'");ats_check(an_success(an_run(),'reset-guest')&&password_verify($_POST['new_password'],an_rows('SELECT user_password FROM fixture_users WHERE user_id=2')[0]['user_password']),'Modern bcrypt Unicode reset');
 foreach(array('view','confirmation','empty','long','nul','array-sid','foreign-session','logged-out-admin','missing-policy') as $case){
  an_reset($case==='logged-out-admin'?'admin':'reset-guest');
  if($case==='view'){unset($_POST['reset_password']);$_SERVER['REQUEST_METHOD']='GET';}
  if($case==='confirmation'){$_POST['password_confirm']='different';}
  if($case==='empty'){$_POST['new_password']='';}
  if($case==='long'||$case==='nul'){$_POST['new_password']=$_POST['password_confirm']=$case==='long'?str_repeat('ä',37):"Nul\0password9";}
  if($case==='array-sid'){$_POST['sid']=array('fixture-sid');}
  if($case==='foreign-session'){an_sql("UPDATE fixture_sessions SET session_user_id=7 WHERE session_id='fixture-sid'");}
  if($case==='logged-out-admin'){an_sql("UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-sid'");}
  if($case==='missing-policy'){an_sql("DELETE FROM fixture_config WHERE config_name='password_hashing'");}
  $before=an_snap();$out=an_run();ats_check(!an_success($out,$an_mode)&&an_snap()===$before&&!$an_mail&&!$an_cookies,'Read/form/rejected input has no publication '.$case);$cases++;
 }
 foreach(array('ddl','raw-commit','raw-rollback','after-commit','after-release','second-commit') as $case){
  an_reset('self');$owner=new PhpbbActivationDatabase($an_main);$before=an_snap();$denied=false;
  try{
   if($case==='ddl'){$owner->sql_query('ALTER TABLE fixture_users ENGINE=MyISAM');}elseif($case==='raw-commit'){$owner->sql_query('COMMIT');}elseif($case==='raw-rollback'){$owner->sql_query('ROLLBACK');}
   else{if($case==='after-release'){$owner->release();}else{$owner->commit();}if($case==='second-commit'){$owner->commit();}else{$owner->sql_query("UPDATE fixture_users SET user_active=1 WHERE user_id=2");}}
  }catch(PhpbbActivationException $e){$denied=true;}finally{$owner->release();}
  ats_check($denied&&an_snap()===$before,'No transaction escape '.$case);$cases++;
 }
 echo 'Native activation/reset: '.$cases.' boundary/failure cases, '.$serialized." serialized changes passed.\n";
}finally{$an_hook=$an_after=null;$an_fail=0;$an_commit='';$an_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
