<?php
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('ANONYMOUS'=>-1,'CONFIG_TABLE'=>'fixture_config','SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','CTRACKER_CONFIG'=>'fixture_ctracker_config','POST_USERS_URL'=>'u') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_password_reset.php';
foreach(array('phpbb_clean_username','phpbb_rtrim','phpbb_ltrim') as $name){if(!function_exists($name)){ats_load_function($ats_source.'includes/functions.php',$name);}}
if(PHP_SAPI!=='cli'||getenv('PHPBB_RESET_REQUEST_NATIVE')!=='1'){echo "Native reset request checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_RESET_REQUEST_PORT')?:'3306';$password=getenv('PHPBB_RESET_REQUEST_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_reset_request_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class RrConnection {
 var $db;var $db_connect_id;var $ack=false;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;$GLOBALS['rr_open']++;}
 function __call($m,$a){return call_user_func_array(array($this->db,$m),$a);}
 function sql_close(){if($this->db_connect_id!==null){$GLOBALS['rr_open']--;try{$this->db->sql_close();}finally{$this->db_connect_id=null;}}}
 function sql_query($sql,$tx=false){
  if($this->ack){$GLOBALS['rr_after_queries'][]=$sql;}$GLOBALS['rr_queries'][]=$sql;if(is_callable($GLOBALS['rr_hook'])){call_user_func($GLOBALS['rr_hook'],$sql,$this);}
  if(preg_match('/^UPDATE\b/',$sql)&&++$GLOBALS['rr_writes']===$GLOBALS['rr_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['rr_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);
  if($sql==='COMMIT'&&$r&&$GLOBALS['rr_commit']!=='ack'){$this->ack=true;$GLOBALS['rr_ack']=true;if(is_callable($GLOBALS['rr_after'])){call_user_func($GLOBALS['rr_after'],$this);}}
  return $sql==='COMMIT'&&$GLOBALS['rr_commit']==='ack'?false:$r;
 }
}
class RrDatabase extends sql_db {function sql_dedicated_connection(){return new RrConnection(parent::sql_dedicated_connection());}function sql_query($sql='',$tx=false){ats_check(!preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql),'No controller writes on main connection');return parent::sql_query($sql,$tx);}}
class RrTemplate {function assign_vars($v){}}
eval('namespace ResetRequestControllerFixture; class emailer {var $address;var $vars;function __construct($smtp,$optional=false){\ats_check($optional,"Optional reset mail");}function __call($m,$a){}function email_address($v){$this->address=$v;}function assign_vars($v){$this->vars=$v;}function send(){\ats_check($GLOBALS["rr_open"]===0&&$GLOBALS["rr_ack"],"Mail only after commit and release");$GLOBALS["rr_mails"][]=array($this->address,$this->vars);if(is_callable($GLOBALS["rr_delivery_hook"])){call_user_func($GLOBALS["rr_delivery_hook"]);}return !$GLOBALS["rr_mail_fail"];}}function error_log($m){$GLOBALS["rr_logs"][]=$m;}');
$rr_main=new RrDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$rr_main;
$rr_tables=array('users','sessions','sessions_keys','config','ctracker_config');$rr_hook=$rr_after=$rr_delivery_hook=null;$rr_queries=$rr_after_queries=$rr_mails=$rr_logs=array();$rr_fail=$rr_writes=$rr_open=0;$rr_commit='';$rr_ack=$rr_mail_fail=false;
$source=file_get_contents($ats_source.'includes/usercp_sendpasswd.php');$a=strpos($source,"if ( isset(\$_POST['submit']) )");ats_check($a!==false,'Actual request controller');$rr_body='namespace ResetRequestControllerFixture;use \\PhpbbActivationException;use \\Exception;use \\Throwable;'.substr($source,$a);
function rr_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Owned reset fixture SQL '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function rr_rows($sql){$r=rr_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function rr_snap(){$out=array();foreach($GLOBALS['rr_tables'] as $s){$r=rr_rows('SELECT * FROM fixture_'.$s);usort($r,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$r;}return $out;}
function rr_insert($table,$v){foreach(rr_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$v)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$v[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$values=array();foreach($v as $x){$values[]="'".$GLOBALS['peer']->sql_escape((string)$x)."'";}rr_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($v)).') VALUES ('.implode(',',$values).')');}
function rr_reset(){
 global $db,$userdata,$board_config,$rr_hook,$rr_after,$rr_delivery_hook,$rr_queries,$rr_after_queries,$rr_mails,$rr_logs,$rr_fail,$rr_writes,$rr_commit,$rr_ack,$rr_mail_fail;
 ats_check($GLOBALS['rr_open']===0,'No abandoned owner');$db=$GLOBALS['rr_main'];$rr_hook=$rr_after=$rr_delivery_hook=null;$rr_queries=$rr_after_queries=$rr_mails=$rr_logs=array();$rr_fail=$rr_writes=0;$rr_commit='';$rr_ack=$rr_mail_fail=false;
 rr_sql('START TRANSACTION');foreach($GLOBALS['rr_tables'] as $s){rr_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1,2,7) as $id){rr_insert('fixture_users',array('user_id'=>$id,'username'=>'Grüße-'.$id,'user_email'=>'member'.$id.'@example.invalid','user_active'=>1,'user_level'=>0,'user_password'=>'before','user_newpasswd'=>'','user_actkey'=>'','user_lang'=>'english','ct_last_pw_reset'=>0));}
 foreach(array('request-session'=>-1,'target-session'=>2,'unrelated'=>7) as $sid=>$id){rr_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>$id>0?1:0));}
 rr_insert('fixture_sessions_keys',array('user_id'=>2,'key_id'=>md5('existing-login')));foreach(array('pwreset_time'=>20,'pw_reset_feature'=>1) as $name=>$value){rr_insert('fixture_ctracker_config',array('ct_config_name'=>$name,'ct_config_value'=>$value));}rr_sql('COMMIT');
 $userdata=array('user_id'=>-1,'session_id'=>'request-session','session_logged_in'=>false);$board_config=array('smtp_delivery'=>false,'board_email'=>'forum@example.invalid','sitename'=>'Fixture');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('submit'=>1,'sid'=>'request-session','username'=>'Grüße-2','email'=>'member2@example.invalid');
}
function rr_run(){global $db,$userdata,$board_config,$phpEx,$phpbb_root_path,$lang;$phpEx='php';$phpbb_root_path=$GLOBALS['ats_source'];$server_url='https://fixture.invalid/profile.php';$template=new RrTemplate();try{eval($GLOBALS['rr_body']);}catch(AttachSettingsExit $e){return $e->getMessage();}throw new RuntimeException('Request controller must render a message');}
function rr_success($out){return strpos($out,$GLOBALS['lang']['Password_reset_requested'])===0;}
function rr_bound_token($key){$row=rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0];$row['user_actkey']=$key;$row['ct_last_pw_reset']=time()+1200;$marker=phpbb_reset_binding($row);return "UPDATE fixture_users SET user_actkey='$key',user_newpasswd='$marker',ct_last_pw_reset=".$row['ct_last_pw_reset'].' WHERE user_id=2';}
function rr_boundary($sql){return strpos($sql,' LOCK IN SHARE MODE')!==false||strpos($sql,' FOR UPDATE')!==false||strpos($sql,'UPDATE ')===0||$sql==='COMMIT';}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach($rr_tables as $s){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$s.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical request participant');rr_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 rr_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;rr_reset();$before=rr_snap();$response=rr_run();$after=rr_snap();$boundaries=array_values(array_filter($rr_queries,'rr_boundary'));
 $row=rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0];ats_check(rr_success($response)&&phpbb_reset_binding_valid($row)&&count($rr_mails)===1&&strpos($rr_mails[0][1]['U_ACTIVATE'],'act_key='.$row['user_actkey'])!==false&&$rr_mails[0][0]===$row['user_email'],'New bound token delivered to current account');
 ats_check($before['sessions']===$after['sessions']&&$before['sessions_keys']===$after['sessions_keys'],'Request never alters credentials/logins');rr_run();ats_check(count($rr_mails)===1&&rr_snap()===$after,'Concurrent/repeated cooldown does not replace token or notify again');
 foreach(array('update','commit','ack') as $failure){rr_reset();$before=rr_snap();if($failure==='update'){$rr_fail=1;}else{$rr_commit=$failure==='ack'?'ack':'fail';}$out=rr_run();ats_check(!rr_success($out)&&!$rr_mails&&($failure==='ack'?phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]):rr_snap()===$before),'Failure has whole token outcome without mail');$cases++;}
 foreach(array('email','password','inactive','sid','cooldown','setting') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
  rr_reset();$change=$kind==='cooldown'?rr_bound_token(str_repeat('b',32)):($kind==='email'?"UPDATE fixture_users SET user_email='other@example.invalid' WHERE user_id=2":($kind==='password'?"UPDATE fixture_users SET user_password='after' WHERE user_id=2":($kind==='inactive'?'UPDATE fixture_users SET user_active=0 WHERE user_id=2':($kind==='sid'?"DELETE FROM fixture_sessions WHERE session_id='request-session'":"UPDATE fixture_ctracker_config SET ct_config_value='60' WHERE ct_config_name='pwreset_time'"))));$seen=0;$reached=$blocked=false;$revoked=null;
  $rr_hook=function($sql)use($change,$nth,&$seen,&$reached,&$blocked,&$revoked){if(!rr_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['rr_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Independent native lock timeout');$blocked=true;}else{$revoked=rr_snap();}};
  $out=rr_run();ats_check($reached,'Every request boundary reached');
  if($blocked){ats_check(rr_success($out)&&count($rr_mails)===1&&phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]),'Revocation serializes after complete publication');$serialized++;}
  elseif(in_array($kind,array('password','setting'),true)){ats_check(rr_success($out)&&count($rr_mails)===1&&phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]),'New request uses fresh credential/config state');}
  else{ats_check(!$rr_mails&&rr_snap()===$revoked,'Stale recipient/session/inactive/cooldown never publishes or mails');}$cases++;
 }}
 foreach(array('disconnect','email') as $kind){rr_reset();$stored=null;$rr_after=function($writer)use($kind,&$stored){$stored=rr_snap();rr_sql($kind==='disconnect'?'KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id):"UPDATE fixture_users SET user_email='later@example.invalid' WHERE user_id=2");};ats_check(rr_success(rr_run())&&count($rr_mails)===1&&!$rr_after_queries,'Acknowledged request survives post-commit event');if($kind==='email'){ats_check(!phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]),'Later email change invalidates mailed link');}$cases++;}
 foreach(array('unknown','inactive','throttle','get','wrong-sid','array-sid','missing-session','bad-config') as $case){rr_reset();if($case==='unknown'){$_POST['email']='missing@example.invalid';}if($case==='inactive'){rr_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}if($case==='throttle'){rr_sql(rr_bound_token(str_repeat('b',32)));}if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}if($case==='wrong-sid'){$_POST['sid']='wrong';}if($case==='array-sid'){$_POST['sid']=array();}if($case==='missing-session'){rr_sql("DELETE FROM fixture_sessions WHERE session_id='request-session'");}if($case==='bad-config'){rr_sql("UPDATE fixture_ctracker_config SET ct_config_value='999' WHERE ct_config_name='pwreset_time'");}$before=rr_snap();$out=rr_run();ats_check(!$rr_mails&&rr_snap()===$before,'Rejected request has no writes/mail');if(in_array($case,array('unknown','inactive','throttle'),true)){ats_check($out===$response,'Identical public response');}$cases++;}
 rr_reset();$rr_mail_fail=true;$out=rr_run();ats_check($out===$response&&count($rr_logs)===1&&rr_rows('SELECT user_actkey,ct_last_pw_reset FROM fixture_users WHERE user_id=2')[0]===array('user_actkey'=>'','ct_last_pw_reset'=>'0'),'Mail failure retires own token and cooldown without account oracle');
 rr_reset();$rr_mail_fail=true;$replacement='';$rr_delivery_hook=function()use(&$replacement){$replacement=rr_bound_token(str_repeat('c',32));rr_sql($replacement);};rr_run();ats_check(rr_rows('SELECT user_actkey FROM fixture_users WHERE user_id=2')[0]['user_actkey']===str_repeat('c',32),'Cleanup never erases a newer request');
 rr_reset();rr_sql('UPDATE fixture_users SET ct_last_pw_change=NULL WHERE user_id=2');
 ats_check(rr_success(rr_run())&&phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]),'Historical nullable timestamp can request a bound link');
 $row=rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0];unset($row['ct_last_pw_change']);ats_check(phpbb_reset_binding($row)==='','Missing context column still fails closed');
 foreach(array(false,true) as $legacy){rr_reset();rr_sql("UPDATE fixture_users SET user_newpasswd='".($legacy?md5('legacy'):'!phpbb-password-reset!')."',user_actkey='old-link',ct_last_pw_reset=2000000000 WHERE user_id=2");ats_check(rr_success(rr_run())&&count($rr_mails)===1,'Obsolete links can be replaced immediately');}
 rr_reset();rr_sql("UPDATE fixture_ctracker_config SET ct_config_value='0' WHERE ct_config_name='pw_reset_feature'");rr_run();$first=rr_rows('SELECT user_actkey FROM fixture_users WHERE user_id=2')[0]['user_actkey'];rr_run();ats_check(count($rr_mails)===2&&rr_rows('SELECT user_actkey FROM fixture_users WHERE user_id=2')[0]['user_actkey']!==$first,'Explicitly disabled throttle remains supported');
 foreach($rr_tables as $s){rr_reset();rr_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=rr_snap();ats_check(!rr_success(rr_run())&&rr_snap()===$before&&!$rr_mails,'Reject nontransactional request participant');rr_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 // Execute the actual updater's cleanup statement twice against mixed tokens.
 rr_reset();rr_sql(rr_bound_token(str_repeat('b',32)));rr_insert('fixture_users',array('user_id'=>3,'username'=>'Old','user_password'=>'kept','user_newpasswd'=>'!phpbb-password-reset!','user_actkey'=>'old-reset','ct_last_pw_reset'=>2000000000));rr_insert('fixture_users',array('user_id'=>4,'username'=>'Legacy','user_password'=>'kept','user_newpasswd'=>md5('old'),'user_actkey'=>'legacy-reset','ct_last_pw_reset'=>2000000000));rr_sql("UPDATE fixture_users SET user_actkey='account-activation' WHERE user_id=7");
 $file=dirname(rtrim($ats_source,'/')).'/update/update_from_153a.php';
 ats_load_function($file,'update_quote_identifier');$source=file_get_contents($file);
 $a=strpos($source,'// Retire unbound reset links');$b=strpos($source,'// The quota form',$a);
 ats_check($a!==false&&$b>$a,'Actual updater cleanup');$users_table='fixture_users';$operations=array();eval(substr($source,$a,$b-$a));
 ats_check(count($operations)===1,'One scoped idempotent cleanup');rr_sql($operations[0]);$after=rr_snap();rr_sql($operations[0]);
 ats_check(rr_snap()===$after,'Migration idempotent');
 ats_check(phpbb_reset_binding_valid(rr_rows('SELECT * FROM fixture_users WHERE user_id=2')[0])&&rr_rows('SELECT user_actkey FROM fixture_users WHERE user_id=7')[0]['user_actkey']==='account-activation','Bound reset and account activation survive migration');
 ats_check(count(rr_rows("SELECT * FROM fixture_users WHERE user_id IN (3,4) AND user_password='kept' AND user_actkey='' AND user_newpasswd='' AND ct_last_pw_reset=0"))===2,'Only obsolete reset capabilities retired, not credentials');
 echo 'Native reset requests: '.$cases.' boundary/failure cases, '.$serialized." serialized changes; delivery/cancellation/binding/migration passed.\n";
}finally{$rr_hook=$rr_after=$rr_delivery_hook=null;$rr_fail=0;$rr_commit='';$rr_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
