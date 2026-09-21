<?php
// Actual public publication and owned writer, native disposable schemas only.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('ANONYMOUS'=>-1,'CONFIG_TABLE'=>'fixture_config','PLUS_TABLE'=>'fixture_plus','CTRACKER_CONFIG'=>'fixture_ctracker_config',
 'CTRACKER_RATE_LIMITS'=>'fixture_ctracker_rate_limits','GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group',
 'PROFILE_FIELDS_TABLE'=>'fixture_profile_fields','DISALLOW_TABLE'=>'fixture_disallow','WORDS_TABLE'=>'fixture_words','BANLIST_TABLE'=>'fixture_banlist',
 'CONFIRM_TABLE'=>'fixture_confirm','ANTI_ROBOT_TABLE'=>'fixture_anti_robotic_reg','BEGIN_TRANSACTION'=>1,'END_TRANSACTION'=>2,
 'USER_ACTIVATION_SELF'=>1,'USER_ACTIVATION_ADMIN'=>2,'ALLOW_VIEW'=>1,'CHECKBOX'=>3,'RADIO'=>2,'TEXTAREA'=>1,'TEXT_FIELD_MAXLENGTH'=>255,'TEXTAREA_MAXLENGTH'=>60000,'POST_USERS_URL'=>'u') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_registration_storage.php';require $ats_source.'includes/functions_user_ids.php';
require $ats_source.'includes/functions_validate.php';require $ats_source.'includes/functions_profile_fields.php';require $ats_source.'includes/usercp_avatar.php';
require $ats_source.'ctracker/engines/ct_request_limiter.php';
require $ats_source.'language/lang_english/lang_cback_ctracker.php';
foreach(array('phpbb_clean_username','phpbb_rtrim','phpbb_ltrim') as $name){if(!function_exists($name)){ats_load_function($ats_source.'includes/functions.php',$name);}}
ats_load_function($ats_source.'includes/usercp_register.php','usercp_sql_value');ats_load_function($ats_source.'profile.php','gen_rand_string');
if(PHP_SAPI!=='cli'||getenv('PHPBB_REGISTRATION_NATIVE')!=='1'){echo "Native registration checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_REGISTRATION_PORT')?:'3306';$password=getenv('PHPBB_REGISTRATION_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_registration_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned registration schema');
class RegistrationNativeConnection {
 var $db;var $db_connect_id;var $owner=false;var $ack=false;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;$GLOBALS['rgn_open']++;}
 function __call($m,$a){return call_user_func_array(array($this->db,$m),$a);}
 function sql_close(){if($this->db_connect_id!==null){$GLOBALS['rgn_open']--;try{$this->db->sql_close();}finally{$this->db_connect_id=null;}}}
 function sql_query($sql,$tx=false){
  if($sql==='START TRANSACTION'){$this->owner=true;}
  if($this->owner){
   if($this->ack){$GLOBALS['rgn_after_queries'][]=$sql;}$GLOBALS['rgn_queries'][]=$sql;
   if(is_callable($GLOBALS['rgn_hook'])){call_user_func($GLOBALS['rgn_hook'],$sql,$this);}
   if(preg_match('/^(INSERT|UPDATE|DELETE)\b/',trim($sql))&&++$GLOBALS['rgn_writes']===$GLOBALS['rgn_fail']){return false;}
   if($sql==='COMMIT'&&$GLOBALS['rgn_commit']==='fail'){return false;}
  }
  $r=$this->db->sql_query($sql,$tx);
  if($this->owner&&$sql==='COMMIT'&&$r&&$GLOBALS['rgn_commit']!=='ack'){$this->ack=true;$GLOBALS['rgn_ack']=true;if(is_callable($GLOBALS['rgn_after'])){call_user_func($GLOBALS['rgn_after'],$this);}}
  return $this->owner&&$sql==='COMMIT'&&$GLOBALS['rgn_commit']==='ack'?false:$r;
 }
}
class RegistrationNativeDatabase extends sql_db {
 function sql_dedicated_connection(){return new RegistrationNativeConnection(parent::sql_dedicated_connection());}
 function sql_query($sql='',$tx=false){ats_check(!preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i',$sql),'No registration writes on main connection');return parent::sql_query($sql,$tx);}
}
eval('namespace RegistrationControllerFixture; class emailer {var $vars;function __construct($smtp,$optional=false){\ats_check($optional,"Post-commit registration mail is optional");}function __call($m,$a){}function assign_vars($v){$this->vars=$v;}function send(){\ats_check($GLOBALS["rgn_ack"]&&$GLOBALS["rgn_open"]===0,"No notification before ACK and owner release");$GLOBALS["rgn_mails"][]=$this->vars;if($GLOBALS["rgn_mail_throw"]){throw new \\RuntimeException("Fixture mail failure");}return !$GLOBALS["rgn_mail_fail"];}}');
$rgn_main=new RegistrationNativeDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$rgn_main;
$rgn_tables=array('users','sessions','config','plus','ctracker_config','groups','user_group','profile_fields','disallow','words','banlist','confirm','anti_robotic_reg','ctracker_rate_limits');
$rgn_hook=$rgn_after=null;$rgn_open=$rgn_fail=$rgn_writes=0;$rgn_commit='';$rgn_ack=$rgn_mail_fail=$rgn_mail_throw=false;$rgn_queries=$rgn_after_queries=$rgn_mails=array();
$source=file_get_contents($ats_source.'includes/usercp_register.php');$a=strpos($source,"\t\t\trequire_once(\$phpbb_root_path . 'includes/functions_user_ids.'");$b=strpos($source,'} // if mode == register',$a);
ats_check($a!==false&&$b>$a,'Actual public registration through final response');
$rgn_body='namespace RegistrationControllerFixture;use \\PhpbbRegistrationScope;use \\PhpbbRegistrationException;use \\PhpbbUserIdException;use \\RuntimeException;use \\Exception;use \\Throwable;'.substr($source,$a,$b-$a);
function rgn_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Owned registration SQL '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function rgn_rows($sql){$r=rgn_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function rgn_insert_sql($table,$values){
 foreach(rgn_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}return 'INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')';
}
function rgn_snap(){
 $snap=array();foreach($GLOBALS['rgn_tables'] as $s){$rows=rgn_rows('SELECT * FROM fixture_'.$s);foreach($rows as &$row){foreach(array('user_regdate','ct_last_pw_change','user_passwd_change','updated_at','window_start') as $k){if(isset($row[$k])&&(int)$row[$k]>1){$row[$k]='fresh';}}}unset($row);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$snap[$s]=$rows;}return $snap;
}
function rgn_reset($activation=0,$challenge='confirm'){
 global $db,$userdata,$board_config,$plus_config,$ctracker_config,$rgn_hook,$rgn_after,$rgn_open,$rgn_fail,$rgn_writes,$rgn_commit,$rgn_ack,$rgn_mail_fail,$rgn_mail_throw,$rgn_queries,$rgn_after_queries,$rgn_mails,$profile_data,$rgn_activation,$rgn_challenge;
 ats_check($rgn_open===0,'Every prior owner released');$db=$GLOBALS['rgn_main'];$rgn_hook=$rgn_after=null;$rgn_fail=$rgn_writes=0;$rgn_commit='';$rgn_ack=$rgn_mail_fail=$rgn_mail_throw=false;$rgn_queries=$rgn_after_queries=$rgn_mails=array();$rgn_activation=$activation;$rgn_challenge=$challenge;
 rgn_sql('START TRANSACTION');foreach(array_merge($GLOBALS['rgn_tables'],array('user_id_sequence')) as $s){rgn_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1,1,7) as $id){rgn_sql(rgn_insert_sql('fixture_users',array('user_id'=>$id,'username'=>'Existing '.$id,'user_email'=>'existing'.$id.'@example.invalid','user_password'=>'kept','user_active'=>1,'user_level'=>$id===1?ADMIN:0,'user_lang'=>'english')));}
 rgn_sql(rgn_insert_sql('fixture_groups',array('group_id'=>10,'group_name'=>'Existing group','group_single_user'=>0)));
 rgn_sql(rgn_insert_sql('fixture_sessions',array('session_id'=>'fixture-sid','session_user_id'=>-1,'session_logged_in'=>0)));
 rgn_sql('INSERT INTO fixture_user_id_sequence VALUES (1,7)');
 $board_config=array();foreach(PhpbbRegistrationScope::policy_keys() as $key){ats_check(preg_match("/VALUES\\s*\\('".preg_quote($key,'/')."'\\s*,\\s*'([^']*)'\\)/",$GLOBALS['rgn_basic'],$m)===1,'Installer policy '.$key);$board_config[$key]=$m[1];}
 $board_config['require_activation']=$activation===3?0:$activation;$board_config['registration_status']=0;$board_config['enable_confirm']=$challenge==='confirm'?1:0;$board_config['sfs_enable']=0;
 foreach($board_config as $k=>$v){rgn_sql(rgn_insert_sql('fixture_config',array('config_name'=>$k,'config_value'=>$v)));}
 $board_config+=array('smtp_delivery'=>0,'board_email'=>'forum@example.invalid','board_email_sig'=>'Fixture','sitename'=>'Fixture','coppa_fax'=>'','coppa_mail'=>'');
 $plus_config=array('enable_antirobot'=>$challenge==='robot'?1:0);rgn_sql(rgn_insert_sql('fixture_plus',array('config_name'=>'enable_antirobot','config_value'=>$plus_config['enable_antirobot'])));
 $ctracker_config=(object)array('settings'=>array('reg_protection'=>1,'reg_blocktime'=>30,'autoban_mails'=>0,'spam_keyword_det'=>0,'pw_complex'=>0,'pw_complex_min'=>4,'pw_complex_mode'=>1),'user_ip_value'=>'127.0.0.1');
 foreach($ctracker_config->settings as $k=>$v){rgn_sql(rgn_insert_sql('fixture_ctracker_config',array('ct_config_name'=>$k,'ct_config_value'=>$v)));}
 rgn_sql(rgn_insert_sql('fixture_profile_fields',array('field_id'=>1,'field_name'=>'Fixture profile','is_required'=>1,'users_can_view'=>1)));
 rgn_sql(rgn_insert_sql('fixture_confirm',array('session_id'=>'fixture-sid','confirm_id'=>str_repeat('a',32),'code'=>'AbC123')));
 rgn_sql(rgn_insert_sql('fixture_anti_robotic_reg',array('session_id'=>'fixture-sid','reg_key'=>'abcde','timestamp'=>time())));
 rgn_sql('COMMIT');$r=rgn_sql('SELECT * FROM fixture_profile_fields WHERE users_can_view=1 ORDER BY field_id ASC');$profile_data=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);
 $userdata=array('session_id'=>'fixture-sid','user_id'=>-1,'session_logged_in'=>false,'username'=>'Anonymous','user_email'=>'');
 $_SERVER['REQUEST_METHOD']='POST';$_SERVER['REMOTE_ADDR']='127.0.0.1';$_POST=array('submit'=>1,'sid'=>'fixture-sid','agreed'=>1,'reg_key'=>'abcde','confirm_id'=>str_repeat('a',32),'confirm_code'=>'AbC123','fixture_profile'=>'Grüße 😀');
}
function rgn_run($name='New fixture',$address='new@example.invalid'){
 global $db,$userdata,$board_config,$plus_config,$ctracker_config,$profile_data,$phpbb_root_path,$phpEx,$lang,$table_prefix,$user_ip;
 $phpbb_root_path=$GLOBALS['ats_source'];$phpEx='php';$table_prefix='fixture_';$user_ip='7f000001';$sid=isset($_POST['sid'])?$_POST['sid']:'';
 $username=$name;$email=$address;$new_password=md5('fixture-password');$user_actkey='';$server_url='https://fixture.invalid/profile.php';
 $user_style=1;$user_timezone=0;$user_dateformat='Y-m-d';$user_lang='german';
 $icq=$website=$occupation=$location=$user_flag=$interests=$user_absence_text=$signature=$signature_bbcode_uid=$aim=$yim=$msn='';
 $fb=$ig=$pt=$twr=$skp=$tg=$li=$tt=$dc=$signal=$threema='';
 $user_absence_mode=$user_absence=$viewemail=$attachsig=$setbm=$allowsmilies=$allowhtml=$allowbbcode=$allowviewonline=$notifyreply=$notifypm=$games_block_pm=$popup_pm=$gender=0;
 $avatar_sql="'', 0";$birthday=999999;$next_birthday_greeting=0;$coppa=$GLOBALS['rgn_activation']===3?1:0;$HTTP_POST_VARS=$_POST;
 $unhtml_specialchars_match=array('#&gt;#','#&lt;#','#&quot;#','#&amp;#');$unhtml_specialchars_replace=array('>','<','"','&');
 $public_avatar_scope=new PhpbbPublicAvatarScope($db);$GLOBALS['rgn_avatar']=$public_avatar_scope;
 try{eval($GLOBALS['rgn_body']);}catch(AttachSettingsExit $e){return $e->getMessage();}finally{$public_avatar_scope->release();}
 throw new RuntimeException('Registration must render a final result');
}
function rgn_success($out){global $lang;foreach(array('Account_added','Account_inactive','Account_inactive_admin','COPPA') as $key){if(strpos($out,$lang[$key])===0){return true;}}return false;}
function rgn_boundary($sql){return strpos($sql,' LOCK IN SHARE MODE')!==false||strpos($sql,' FOR UPDATE')!==false||preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/',$sql)||$sql==='COMMIT';}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');$rgn_basic=file_get_contents($ats_source.'install/schemas/mysql_basic.sql');
 foreach(array_merge($rgn_tables,array('user_id_sequence')) as $s){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$s.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical registration participant '.$s);rgn_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));}
 ats_check(preg_match('/ALTER TABLE phpbb_users\s+[\s\S]*?ADD games_block_pm[\s\S]*?;/',$schema,$m)===1,'Canonical Arcade fields');rgn_sql(str_replace('phpbb_users','fixture_users',$m[0]));
 rgn_sql("ALTER TABLE fixture_users ADD fixture_profile VARCHAR(255) NOT NULL DEFAULT ''");rgn_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array(0,1,2,3) as $mode){foreach(array('confirm','robot','none') as $challenge){
  rgn_reset($mode,$challenge);$before=rgn_snap();$out=rgn_run();$after=rgn_snap();$writes=$rgn_writes;
  ats_check(rgn_success($out)&&$rgn_avatar->confirmed&&count($rgn_mails)===($mode===2?2:1),'Confirmed actual public registration '.$mode.'/'.$challenge);
  $row=rgn_rows('SELECT * FROM fixture_users WHERE user_id=8')[0];ats_check((int)$row['user_active']===($mode===0?1:0)&&$row['fixture_profile']==='Grüße 😀'&&$row['user_reg_ip']==='127.0.0.1','Activation/COPPA/Unicode/IP preserved');
  ats_check(count(rgn_rows('SELECT * FROM fixture_user_group WHERE user_id=8 AND user_pending=0'))===1&&count(rgn_rows('SELECT * FROM fixture_ctracker_rate_limits'))===1,'Personal membership and cooldown committed');
  foreach(range(1,$writes) as $nth){rgn_reset($mode,$challenge);$before=rgn_snap();$rgn_fail=$nth;$out=rgn_run();ats_check(!rgn_success($out)&&rgn_snap()===$before&&!$rgn_mails&&!$rgn_avatar->confirmed&&!$rgn_avatar->attempted,'Every failed write rolls back group/user/challenge/cooldown/avatar');ats_check(rgn_rows('SELECT last_id FROM fixture_user_id_sequence')[0]['last_id']==='8','Reserved ID never reused on rollback');$cases++;}
 }}
 echo "Registration activation/COPPA/challenge variants and per-write rollback passed.\n";
 foreach(array('fail','ack') as $failure){rgn_reset();$before=rgn_snap();$rgn_commit=$failure;$out=rgn_run();ats_check(!rgn_success($out)&&!$rgn_mails&&!$rgn_avatar->confirmed&&$rgn_avatar->attempted,'Unconfirmed commit never claims success or removes staged avatar');ats_check($failure==='fail'?rgn_snap()===$before:count(rgn_rows('SELECT * FROM fixture_users WHERE user_id=8'))===1,'Uncertain publication is whole, not partial');$cases++;}
 rgn_reset();rgn_run();$boundaries=array_values(array_filter($rgn_queries,'rgn_boundary'));
 foreach(array('session','policy','username','email','captcha','rate','fields','disallow','ban') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
  rgn_reset();$change=$kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'":($kind==='policy'?"UPDATE fixture_config SET config_value='2' WHERE config_name='require_activation'":($kind==='username'||$kind==='email'?rgn_insert_sql('fixture_users',array('user_id'=>900,'username'=>$kind==='username'?'New fixture':'Other fixture','user_email'=>$kind==='email'?'new@example.invalid':'other@example.invalid','user_active'=>1)):($kind==='captcha'?"DELETE FROM fixture_confirm WHERE session_id='fixture-sid'":($kind==='rate'?"INSERT INTO fixture_ctracker_rate_limits VALUES ('".hash('sha256',"registration-success\0".'127.0.0.1')."',".time().',1,'.time().')':($kind==='fields'?"UPDATE fixture_profile_fields SET text_field_maxlen=1 WHERE field_id=1":($kind==='disallow'?rgn_insert_sql('fixture_disallow',array('disallow_username'=>'New*')):rgn_insert_sql('fixture_banlist',array('ban_email'=>'new@example.invalid'))))))));
  $seen=0;$reached=$blocked=false;$revoked=null;$rgn_hook=function($sql)use($nth,$change,&$seen,&$reached,&$blocked,&$revoked){if(!rgn_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['rgn_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query($change)){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Independent write blocks, not query failure');$blocked=true;}else{$revoked=rgn_snap();}};
  $out=rgn_run();ats_check($reached,'Every registration boundary reached');if($blocked){ats_check(rgn_success($out)&&count(rgn_rows('SELECT * FROM fixture_users WHERE user_id=8'))===1,'Independent change serializes after complete publication');$serialized++;}else{ats_check(!rgn_success($out)&&!$rgn_mails&&rgn_snap()===$revoked,'Changed current rule/session/identity prevents publication');}$cases++;
 }
 echo 'Registration concurrent '.$kind." boundaries passed.\n";
 }
 foreach(array('session','policy','disconnect') as $kind){rgn_reset();$stored=null;$rgn_after=function($writer)use($kind,&$stored){$stored=rgn_snap();rgn_sql($kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='fixture-sid'":($kind==='policy'?"UPDATE fixture_config SET config_value='2' WHERE config_name='require_activation'":'KILL CONNECTION '.(int)mysqli_thread_id($writer->db_connect_id)));};ats_check(rgn_success(rgn_run())&&count($rgn_mails)===1&&!$rgn_after_queries,'Acknowledged registration remains success after later changes');$cases++;}
 foreach(array('wrong-sid','case-sid','get','logged-in','closed','robot-wrong','confirm-wrong','challenge-reused','missing-policy','plus-policy','ct-policy','group-name','word-rule','ip-ban','mail-false','mail-throw') as $case){
  rgn_reset(0,$case==='robot-wrong'?'robot':'confirm');
  if($case==='wrong-sid'){$_POST['sid']='wrong';}if($case==='case-sid'){rgn_sql("UPDATE fixture_sessions SET session_id='FIXTURE-SID'");}if($case==='get'){$_SERVER['REQUEST_METHOD']='GET';}if($case==='logged-in'){$userdata['session_logged_in']=true;}
  if($case==='closed'){$board_config['registration_status']=1;rgn_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='registration_status'");}if($case==='robot-wrong'){$_POST['reg_key']='wrong';}if($case==='confirm-wrong'){$_POST['confirm_code']='wrong';}if($case==='challenge-reused'){rgn_sql('DELETE FROM fixture_confirm');}if($case==='missing-policy'){rgn_sql("DELETE FROM fixture_config WHERE config_name='password_hashing'");}
  if($case==='plus-policy'){rgn_sql("UPDATE fixture_plus SET config_value='1' WHERE config_name='enable_antirobot'");}
  if($case==='ct-policy'){rgn_sql("UPDATE fixture_ctracker_config SET ct_config_value='20' WHERE ct_config_name='pw_complex_min'");}
  if($case==='group-name'){rgn_sql("UPDATE fixture_groups SET group_name='New fixture' WHERE group_id=10");}
  if($case==='word-rule'){rgn_sql(rgn_insert_sql('fixture_words',array('word'=>'New*')));}
  if($case==='ip-ban'){rgn_sql(rgn_insert_sql('fixture_banlist',array('ban_ip'=>'7f000001')));}
  $rgn_mail_fail=$case==='mail-false';$rgn_mail_throw=$case==='mail-throw';$before=rgn_snap();$out=rgn_run();
  if($rgn_mail_fail||$rgn_mail_throw){ats_check(rgn_success($out)&&strpos($out,$lang['Registration_mail_failed'])!==false&&count(rgn_rows('SELECT * FROM fixture_users WHERE user_id=8'))===1,'Mail failure separate from saved account');}else{ats_check(!rgn_success($out)&&rgn_snap()===$before&&!$rgn_mails,'Invalid request has no publication');}$cases++;
 }
 rgn_reset(0,'none');ats_check(!validate_username('New fixture',false)['error']&&!validate_email('new@example.invalid',false)['error'],'Initially matching form valid');rgn_run();$before=rgn_snap();ats_check(!rgn_success(rgn_run())&&rgn_snap()===$before&&count($rgn_mails)===1,'Overlapping prevalidated duplicate rejected');
 rgn_reset(0,'none');rgn_run();$before=rgn_snap();ats_check(!rgn_success(rgn_run('Distinct fixture','distinct@example.invalid'))&&rgn_snap()===$before,'Same-IP cooldown enforced for distinct registrations');
 rgn_reset(0,'none');$ctracker_config->settings['reg_protection']=0;rgn_sql("UPDATE fixture_ctracker_config SET ct_config_value='0' WHERE ct_config_name='reg_protection'");
 ats_check(rgn_success(rgn_run())&&rgn_success(rgn_run('Distinct fixture','distinct@example.invalid'))&&!rgn_rows('SELECT * FROM fixture_ctracker_rate_limits'),'Explicitly disabled cooldown remains disabled');
 rgn_reset();$rgn_hook=function($sql){if(rgn_boundary($sql)){ats_check(!rgn_rows('SELECT * FROM fixture_users WHERE user_id=8')&&!rgn_rows('SELECT * FROM fixture_user_group WHERE user_id=8')&&!rgn_rows('SELECT * FROM fixture_groups WHERE group_single_user=1'),'No uncommitted account/group visible to independent reader');}};ats_check(rgn_success(rgn_run()),'Whole publication becomes visible at commit');
 foreach(array('ddl','raw-commit','raw-rollback','after-release') as $case){rgn_reset();$before=rgn_snap();$avatar=new PhpbbPublicAvatarScope($rgn_main);$owner=new PhpbbRegistrationScope($rgn_main,'fixture-sid','New fixture','new@example.invalid',$profile_data,$avatar);$denied=false;
  try{if($case==='ddl'){$owner->sql_query('ALTER TABLE fixture_users ENGINE=MyISAM');}elseif($case==='raw-commit'){$owner->sql_query('COMMIT');}elseif($case==='raw-rollback'){$owner->sql_query('ROLLBACK');}else{$owner->release();$owner->finish();}}
  catch(PhpbbRegistrationException $e){$denied=true;}finally{$owner->release();}ats_check($denied&&rgn_snap()===$before,'No transaction escape '.$case);$cases++;
 }
 foreach($rgn_tables as $s){rgn_reset();rgn_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=rgn_snap();ats_check(!rgn_success(rgn_run())&&rgn_snap()===$before&&!$rgn_mails,'Reject nontransactional participant '.$s);rgn_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 rgn_reset();rgn_sql('ALTER TABLE fixture_config ROW_FORMAT=COMPACT');$before=rgn_snap();ats_check(!rgn_success(rgn_run())&&rgn_snap()===$before,'Reject compact participant');rgn_sql('ALTER TABLE fixture_config ROW_FORMAT=DYNAMIC');
 rgn_reset();rgn_sql('ALTER TABLE fixture_confirm MODIFY code CHAR(6) CHARACTER SET latin1 NOT NULL');$before=rgn_snap();ats_check(!rgn_success(rgn_run())&&rgn_snap()===$before,'Reject legacy character column');rgn_sql('ALTER TABLE fixture_confirm MODIFY code CHAR(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
 ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_identifier');ats_load_function(dirname(rtrim($ats_source,'/')).'/update/innodb_migration.php','plus_storage_tables');
 $covered=plus_storage_tables($schema,'fixture_');foreach($rgn_tables as $s){ats_check(in_array('fixture_'.$s,$covered,true),'Existing migration covers participant '.$s);}
 echo 'Native registration: '.$cases.' boundary/failure cases, '.$serialized." serialized changes; activation/COPPA/challenges/rollback/durable IDs/mail passed.\n";
}finally{$rgn_hook=$rgn_after=null;$rgn_fail=0;$rgn_commit='';$rgn_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
