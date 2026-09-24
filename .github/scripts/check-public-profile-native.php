<?php
// Actual public profile controller and helpers on an owned native database.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0'); require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('ANONYMOUS'=>-1,'USER_AVATAR_NONE'=>0,'USER_AVATAR_UPLOAD'=>1,'USER_ACTIVATION_NONE'=>0,'ALLOW_VIEW'=>1,'CHECKBOX'=>3,'RADIO'=>2,'TEXTAREA'=>1,'TEXT_FIELD_MAXLENGTH'=>255,'TEXTAREA_MAXLENGTH'=>60000,
 'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','BANLIST_TABLE'=>'fixture_banlist',
 'PROFILE_FIELDS_TABLE'=>'fixture_profile_fields','DISALLOW_TABLE'=>'fixture_disallow','WORDS_TABLE'=>'fixture_words','CONFIG_TABLE'=>'fixture_config','CTRACKER_CONFIG'=>'fixture_ctracker_config','THEMES_TABLE'=>'fixture_themes',
 'iNA_GAMES_COMMENT'=>'fixture_ina_comment','iNA_AT_SCORES'=>'fixture_ina_at_scores','iNA_HIGHSCORES'=>'fixture_ina_highscore','SHOUTBOX_TABLE'=>'fixture_shout') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_public_profile_storage.php';
require $ats_source.'includes/usercp_avatar.php'; require $ats_source.'includes/functions_profile_fields.php'; require $ats_source.'includes/functions_validate.php';
foreach(array('usercp_post_scalar'=>'includes/usercp_register.php','usercp_sql_value'=>'includes/usercp_register.php','session_reset_keys'=>'includes/sessions.php','phpbb_session_publish_reset_cookie'=>'includes/sessions.php',
 'phpbb_sync_username_references'=>'includes/functions.php','pw_create_date'=>'ctracker/classes/class_ct_userfunctions.php','gen_rand_string'=>'profile.php','phpbb_clean_username'=>'includes/functions.php','phpbb_rtrim'=>'includes/functions.php','phpbb_ltrim'=>'includes/functions.php') as $name=>$file){if(!function_exists($name)){ats_load_function($ats_source.$file,$name);}}
function phpbb_setcookie(){ $GLOBALS['pp_cookies'][]=func_get_args(); }
class PublicProfileSecurityFixture {function pw_create_date($id){pw_create_date($id);}}
if(PHP_SAPI!=='cli'||getenv('PHPBB_PUBLIC_PROFILE_NATIVE')!=='1'){echo "Public profile transaction checks require an explicitly enabled disposable native fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_PUBLIC_PROFILE_PORT')?:'3306';$password=getenv('PHPBB_PUBLIC_PROFILE_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');
$host='127.0.0.1:'.$port;$fixture='codex_public_profile_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned profile schema');
class PublicProfileConnectionFixture {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->db,$m),$args);}
 function sql_query($sql,$tx=false){
  $GLOBALS['pp_queries'][]=$sql;if(is_callable($GLOBALS['pp_hook'])){call_user_func($GLOBALS['pp_hook'],$sql,$this);}
  if(preg_match('/^(UPDATE|INSERT|DELETE)\b/',$sql)&&++$GLOBALS['pp_write']===$GLOBALS['pp_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['pp_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);if(!$r){$GLOBALS['pp_sql_error']=$this->db->sql_error();}
  if($sql==='COMMIT'&&$GLOBALS['pp_commit']==='ack'){return false;}
  if($sql==='COMMIT'&&$r&&is_callable($GLOBALS['pp_after'])){call_user_func($GLOBALS['pp_after'],$this);}
  return $r;
 }
}
class PublicProfileDatabaseFixture extends sql_db {function sql_dedicated_connection(){return new PublicProfileConnectionFixture(parent::sql_dedicated_connection());}}
$pp_main=new PublicProfileDatabaseFixture($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$pp_main;
$pp_tables=array('users','sessions','sessions_keys','groups','user_group','banlist','profile_fields','disallow','words','album','album_comment','ina_comment','ina_at_scores','shout','ina_highscore','config','ctracker_config','themes');
$pp_files=sys_get_temp_dir().'/phpbb-public-profile-'.bin2hex(phpbb_random_bytes(8));ats_check(mkdir($pp_files)&&mkdir($pp_files.'/avatars')&&mkdir($pp_files.'/cache'),'Owned avatar/cache fixtures');
$pp_controller=file_get_contents($ats_source.'includes/usercp_register.php');$a=strpos($pp_controller,'$profile_scope = null;');$b=strpos($pp_controller,"\n\t\t\tif ( !\$user_active )",$a);
ats_check($a!==false&&$b>$a,'Actual complete edit storage controller');$pp_body=substr($pp_controller,$a,$b-$a);
$pp_hook=$pp_after=null;$pp_write=$pp_fail=0;$pp_commit='';$pp_queries=$pp_cookies=array();$public_avatar_scope=null;
function pp_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Fixture SQL '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function pp_rows($sql){$r=pp_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function pp_snap(){ $out=array();foreach($GLOBALS['pp_tables'] as $s){$rows=pp_rows('SELECT * FROM fixture_'.$s);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out; }
function pp_normal($snap){foreach($snap['users'] as &$r){foreach(array('ct_last_pw_change','user_passwd_change') as $key){if((int)$r[$key]>0){$r[$key]='fresh';}}if($r['user_actkey']!==''){$r['user_actkey']='fresh';}}unset($r);foreach($snap['sessions_keys'] as &$r){if($r['key_id']!==md5('old-key')){$r['key_id']='fresh';$r['last_login']='fresh';}}unset($r);foreach($snap as &$rows){usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});}unset($rows);return $snap;}
function pp_insert($table,$values){foreach(pp_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}pp_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');}
function pp_reset($scenario='edit'){
 global $db,$userdata,$board_config,$ctracker_config,$public_avatar_scope,$pp_hook,$pp_after,$pp_write,$pp_fail,$pp_commit,$pp_queries,$pp_cookies,$phpbb_root_path;
 $db=$GLOBALS['pp_main'];$pp_hook=$pp_after=null;$pp_write=$pp_fail=0;$pp_commit='';$pp_queries=$pp_cookies=array();$GLOBALS['pp_sql_error']=null;
 pp_sql('START TRANSACTION');foreach($GLOBALS['pp_tables'] as $s){pp_sql('DELETE FROM fixture_'.$s);}
 foreach(array(-1,2,7) as $id){pp_insert('fixture_users',array('user_id'=>$id,'username'=>'member-'.$id,'user_password'=>'before','user_email'=>'member'.$id.'@example.invalid','user_active'=>1,'user_level'=>0,'user_avatar'=>$id===2?'before.png':'','user_avatar_type'=>$id===2?1:0));}
 pp_insert('fixture_groups',array('group_id'=>10,'group_name'=>'member-2','group_single_user'=>1));pp_insert('fixture_user_group',array('user_id'=>2,'group_id'=>10,'user_pending'=>0));
 foreach(array('exact-session'=>2,'other-session'=>2,'unrelated'=>7) as $sid=>$id){pp_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>1));}
 foreach(array(2,7) as $id){pp_insert('fixture_sessions_keys',array('key_id'=>md5('old-key'),'user_id'=>$id,'last_ip'=>'7f000001','last_login'=>1));}
 $board_config=array('allow_namechange'=>1,'require_activation'=>in_array($scenario,array('reactivate','reactivate-password'),true)?1:0,'avatar_path'=>'avatars','cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0);
 $basic=file_get_contents($GLOBALS['ats_source'].'install/schemas/mysql_basic.sql');
 foreach(PhpbbPublicProfileScope::policy_keys() as $key){ats_check(preg_match("/VALUES\\s*\\('".preg_quote($key,'/')."'\\s*,\\s*'([^']*)'\\)/",$basic,$m)===1,'Installer public profile policy '.$key);if(!isset($board_config[$key])){$board_config[$key]=$m[1];}pp_insert('fixture_config',array('config_name'=>$key,'config_value'=>$board_config[$key]));}
 $ctracker_config=new stdClass();$ctracker_config->settings=array('pw_complex'=>0,'pw_complex_min'=>8,'pw_complex_mode'=>1);
 foreach($ctracker_config->settings as $key=>$value){pp_insert('fixture_ctracker_config',array('ct_config_name'=>$key,'ct_config_value'=>$value));}
 pp_insert('fixture_themes',array('themes_id'=>1,'template_name'=>'fisubsilversh'));
 pp_insert('fixture_profile_fields',array('field_id'=>1,'field_name'=>'user_custom_fixture','field_type'=>0,'text_field_maxlen'=>200,'users_can_view'=>1));
 pp_sql('COMMIT');
 $userdata=pp_rows('SELECT * FROM fixture_users WHERE user_id=2')[0];$userdata['session_logged_in']=true;$userdata['session_id']='exact-session';$userdata['session_key']='old-key';
 $phpbb_root_path=$GLOBALS['pp_files'].'/';$_SERVER['REQUEST_METHOD']='POST';
 file_put_contents($GLOBALS['pp_files'].'/avatars/before.png','before');file_put_contents($GLOBALS['pp_files'].'/avatars/after.png','after');
 $public_avatar_scope=new PhpbbPublicAvatarScope($db);
}
function pp_run($scenario){
 global $db,$userdata,$board_config,$public_avatar_scope,$phpbb_root_path,$table_prefix,$lang;
 $user_id=2;$sid='exact-session';$user_ip='7f000001';$profile_security=new PublicProfileSecurityFixture();
 $username='renamed';$username_sql=$scenario==='rename'?"username='renamed', ":'';$passwd_sql=in_array($scenario,array('password','reactivate-password'),true)?"user_password='after-password', ":'';
 $email=in_array($scenario,array('reactivate','reactivate-password'),true)?'new@example.invalid':$userdata['user_email'];
 $user_style=1;$user_timezone=0;$user_dateformat='Y-m-d';$user_lang='german';
 $icq=$website=$occupation=$location=$user_flag=$interests=$user_absence_text=$aim=$yim=$msn='';
 $fb=$ig=$pt=$twr=$skp=$tg=$li=$tt=$dc=$signal=$threema='';
 $user_absence_mode=$user_absence=$viewemail=$attachsig=$setbm=$allowsmilies=$allowhtml=$allowbbcode=$allowviewonline=$notifyreply=$notifypm=$games_block_pm=$popup_pm=$gender=0;
 $birthday=999999;$next_birthday_greeting=0;$avatar_sql='';
 $profile_data=get_fields('WHERE users_can_view = '.ALLOW_VIEW);$HTTP_POST_VARS=array('user_custom_fixture'=>"Grüße ' 😀");
 if(array_key_exists('pp_custom_input',$GLOBALS)){$HTTP_POST_VARS['user_custom_fixture']=$GLOBALS['pp_custom_input'];}
 if(isset($GLOBALS['pp_text_input'])){
  $_POST=array('email'=>$email,'location'=>$GLOBALS['pp_text_input'],'occupation'=>$GLOBALS['pp_text_input'],'interests'=>$GLOBALS['pp_text_input']);
  $source=$GLOBALS['pp_controller'];$a=strpos($source,"\t\$strip_var_list = array('email'");$b=strpos($source,"\tforeach (array('fb'",$a);
  ats_check($a!==false&&$b>$a,'Actual profile input preparation');eval(substr($source,$a,$b-$a));
  $signature='';validate_optional_fields($icq,$aim,$msn,$yim,$website,$location,$occupation,$interests,$signature);
 }
 if($scenario==='avatar'){$public_avatar_scope->remember('after.png',true);user_avatar_delete(1,'before.png');$avatar_sql=",user_avatar='after.png',user_avatar_type=1";}
 try{eval($GLOBALS['pp_body']);return true;}catch(AttachSettingsExit $e){return 'error';}
 finally{$public_avatar_scope->release();}
}
try {
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
foreach($pp_tables as $s){ats_check(preg_match('/CREATE TABLE `?phpbb_'.$s.'`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical participant '.$s);pp_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$m[0]));preg_match_all('/ALTER TABLE phpbb_'.$s.'\s+[\s\S]*?;/',$schema,$extra);foreach($extra[0] as $ddl){pp_sql(str_replace('phpbb_'.$s,'fixture_'.$s,$ddl));}}
 pp_sql('ALTER TABLE fixture_users ADD user_custom_fixture VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
 // Fixture DDL retains a wait allowance for internal background locks.
 pp_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=0;
 foreach(array('edit','password','rename','avatar','reactivate','reactivate-password') as $scenario){
  pp_reset($scenario);$before=pp_snap();ats_check(pp_run($scenario)===true,'Valid self-service '.$scenario.' '.json_encode($pp_sql_error));$after=pp_snap();$writes=$pp_write;
  ats_check(pp_rows('SELECT user_custom_fixture FROM fixture_users WHERE user_id=2')[0]['user_custom_fixture']===htmlspecialchars("Grüße ' 😀",ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'Unicode custom fields');
  ats_check($db===$pp_main&&$public_avatar_scope->confirmed,'Publication restores owning connection');
  ats_check(count($pp_cookies)===(strpos($scenario,'reactivate')===0?2:($scenario==='password'?1:0)),'Only confirmed cookie publication');
  if(strpos($scenario,'reactivate')===0){ats_check(!pp_rows('SELECT * FROM fixture_sessions WHERE session_user_id=2')&&!pp_rows('SELECT * FROM fixture_sessions_keys WHERE user_id=2')&&!$userdata['session_logged_in'],'Reactivation invalidates all own logins');}
  if($scenario==='avatar'){ats_check(!is_file($pp_files.'/avatars/before.png')&&is_file($pp_files.'/avatars/after.png'),'Avatar cleanup follows COMMIT');}
for($fail=1;$fail<=$writes;$fail++){pp_reset($scenario);$before=pp_snap();$pp_fail=$fail;ats_check(pp_run($scenario)==='error'&&pp_snap()===$before&&!$pp_cookies,'Rollback every write boundary '.$scenario.'/'.$fail);if($scenario==='avatar'){ats_check(is_file($pp_files.'/avatars/before.png')&&!is_file($pp_files.'/avatars/after.png'),'Acknowledged rollback discards only staged upload');}$cases++;}
  foreach(array('fail','ack') as $failure){pp_reset($scenario);$before=pp_snap();$pp_commit=$failure;ats_check(pp_run($scenario)==='error'&&!$pp_cookies,'Unconfirmed COMMIT publishes no cookie');ats_check($failure==='fail'?pp_snap()===$before:pp_normal(pp_snap())===pp_normal($after),'Real COMMIT failure/lost reply outcomes');if($scenario==='avatar'){ats_check(is_file($pp_files.'/avatars/before.png')&&is_file($pp_files.'/avatars/after.png'),'Uncertain commit retains both images');}$cases++;}
  foreach(array('inactive','session','password','disconnect') as $kind){pp_reset($scenario);$pp_after=function($writer)use($kind){$GLOBALS['pp_after']=null;if($kind==='inactive'){pp_sql('UPDATE fixture_users SET user_active=0 WHERE user_id=2');}elseif($kind==='session'){pp_sql("DELETE FROM fixture_sessions WHERE session_id='exact-session'");}elseif($kind==='password'){pp_sql("UPDATE fixture_users SET user_password='later-reset' WHERE user_id=2");}else{pp_sql('KILL CONNECTION '.(int)$writer->db->db_connect_id->thread_id);}};ats_check(pp_run($scenario)===true,'Acknowledged save remains successful after '.$kind);$cases++;}
 }
 foreach(array('inactive','session','logged-out','foreign','case','password','email','role','avatar','ban') as $kind){
  pp_reset();$changes=array('inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','session'=>"DELETE FROM fixture_sessions WHERE session_id='exact-session'",'logged-out'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='exact-session'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=7 WHERE session_id='exact-session'",'case'=>"UPDATE fixture_sessions SET session_id='EXACT-SESSION' WHERE session_id='exact-session'",'password'=>"UPDATE fixture_users SET user_password='fresh-reset' WHERE user_id=2",'email'=>"UPDATE fixture_users SET user_email='other@example.invalid' WHERE user_id=2",'role'=>'UPDATE fixture_users SET user_level=1 WHERE user_id=2','avatar'=>"UPDATE fixture_users SET user_avatar='elsewhere.png' WHERE user_id=2",'ban'=>'INSERT INTO fixture_banlist (ban_userid) VALUES (2)');
  if($kind==='ban'){pp_insert('fixture_banlist',array('ban_userid'=>2));}else{pp_sql($changes[$kind]);}$before=pp_snap();ats_check(pp_run('password')==='error'&&pp_snap()===$before&&!$pp_cookies,'Reject stale authority/credentials '.$kind);$cases++;
 }
 foreach(array('inactive','session','password') as $kind){pp_reset();$blocked=false;$pp_hook=function($sql)use($kind,&$blocked){if(strpos($sql,'UPDATE fixture_users')===0){$GLOBALS['pp_hook']=null;$sql=$kind==='inactive'?'UPDATE fixture_users SET user_active=0 WHERE user_id=2':($kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='exact-session'":"UPDATE fixture_users SET user_password='later-reset' WHERE user_id=2");$r=$GLOBALS['peer']->sql_query($sql);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;}};ats_check(pp_run('password')===true&&$blocked,'Concurrent '.$kind.' waits for profile transaction');$cases++;}
 foreach($pp_tables as $s){pp_reset();pp_sql('ALTER TABLE fixture_'.$s.' ENGINE=MyISAM');$before=pp_snap();ats_check(pp_run('edit')==='error'&&pp_snap()===$before,'Reject nontransactional participant '.$s);pp_sql('ALTER TABLE fixture_'.$s.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;}
 foreach(array('START TRANSACTION','COMMIT','SET autocommit=1','TRUNCATE fixture_users') as $sql){pp_reset();$before=pp_snap();$scope=new PhpbbPublicProfileScope($db,2,'exact-session',$public_avatar_scope);$db=$scope;$rejected=false;try{$scope->sql_query($sql);}catch(PhpbbPublicProfileException $e){$rejected=true;}finally{$scope->release();}ats_check($rejected&&pp_snap()===$before,'Reject helper transaction escape');$cases++;}
 foreach(array('edit','password','reactivate','reactivate-password') as $scenario){
  pp_reset($scenario);pp_sql("UPDATE fixture_users SET user_newpasswd='reset-pending',user_actkey='pending-token' WHERE user_id=2");
  ats_check(pp_run($scenario)===true,'Handle existing reset token '.$scenario);$row=pp_rows('SELECT user_newpasswd,user_actkey FROM fixture_users WHERE user_id=2')[0];
  if($scenario==='edit'){ats_check($row['user_newpasswd']==='reset-pending'&&$row['user_actkey']==='pending-token','Ordinary edit preserves current pending reset');}
  else{ats_check($row['user_newpasswd']===''&&($scenario==='password'?$row['user_actkey']==='':$row['user_actkey']!==''&&$row['user_actkey']!=='pending-token'),'Credential change cancels reset marker without repurposing activation token');}$cases++;
 }
 foreach(array('rename','reactivate') as $scenario){pp_reset($scenario);pp_sql($scenario==='rename'?"UPDATE fixture_users SET username='renamed' WHERE user_id=7":"UPDATE fixture_users SET user_email='new@example.invalid' WHERE user_id=7");$before=pp_snap();ats_check(pp_run($scenario)==='error'&&pp_snap()===$before&&!$pp_cookies,'Revalidate duplicate identity under writer lock');$cases++;}
 foreach(array("ALTER TABLE fixture_sessions ROW_FORMAT=COMPACT","ALTER TABLE fixture_sessions CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci") as $ddl){pp_reset();pp_sql($ddl);$before=pp_snap();ats_check(pp_run('edit')==='error'&&pp_snap()===$before,'Reject legacy row/text format');pp_sql('ALTER TABLE fixture_sessions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, ROW_FORMAT=DYNAMIC');$cases++;}
 pp_reset();pp_sql("UPDATE fixture_users SET user_avatar='before.png',user_avatar_type=1 WHERE user_id=7");ats_check(pp_run('avatar')===true&&is_file($pp_files.'/avatars/before.png'),'Committed shared avatar remains');$cases++;
 foreach(array('0','x',"Grüße \\ notes &amp; ' 😀") as $raw){
  pp_reset();$GLOBALS['pp_text_input']=$raw;ats_check(pp_run('edit')===true,'Prepared free text commits');
  $row=pp_rows('SELECT user_from,user_occ,user_interests FROM fixture_users WHERE user_id=2')[0];
  foreach($row as $value){ats_check($value===htmlspecialchars($raw),'Exact prepared profile storage');}
  unset($GLOBALS['pp_text_input']);$cases++;
 }
 foreach(array(0,1,2,3) as $type){foreach(array('','0',"Grüße \\ &amp; ' 😀") as $raw){
  pp_reset();$encoded=htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  pp_sql("UPDATE fixture_profile_fields SET field_type=".$type.",text_area_maxlen=60000,radio_button_values='0,".$peer->sql_escape($encoded)."',checkbox_values='0,".$peer->sql_escape($encoded)."' WHERE field_id=1");
  pp_sql("UPDATE fixture_users SET user_custom_fixture='previous value' WHERE user_id=2");
  $GLOBALS['pp_custom_input']=$type===3?($raw===''?array():array($raw)):$raw;
  ats_check(pp_run('edit')===true,'Custom field saves '.$type);
  ats_check(pp_rows('SELECT user_custom_fixture FROM fixture_users WHERE user_id=2')[0]['user_custom_fixture']===$encoded,'Exact custom value/clear persisted '.$type);
  unset($GLOBALS['pp_custom_input']);$cases++;
 }}
 echo 'Native public profile: '.$cases." failure/authority/transaction cases passed.\n";
} finally {
 if($public_avatar_scope){$public_avatar_scope->release();}$pp_hook=$pp_after=null;$pp_main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned profile schema');$control->sql_close();
 foreach(array('avatars/before.png','avatars/after.png','cache/cg_users.cache','cache/arcade_best_player.cache','cache/arcade_best_at_player.cache') as $file){if(is_file($pp_files.'/'.$file)){unlink($pp_files.'/'.$file);}}
 rmdir($pp_files.'/avatars');rmdir($pp_files.'/cache');rmdir($pp_files);restore_error_handler();
}
