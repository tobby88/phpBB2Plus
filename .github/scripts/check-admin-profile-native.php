<?php
// Actual controller fragments + production storage/driver, owned native DB only.
function phpbb_setcookie($name,$value,$expires,$path,$domain,$secure){$GLOBALS['ap_cookies'][]=func_get_args();return true;}
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
require_once __DIR__.'/profile-request-fixture.php';
foreach(array('USER'=>0,'MOD'=>2,'USER_AVATAR_NONE'=>0,'USER_AVATAR_UPLOAD'=>1,'POST_USERS_URL'=>'u','BEGIN_TRANSACTION'=>1,'END_TRANSACTION'=>2,
 'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','BANLIST_TABLE'=>'fixture_banlist',
 'CONFIG_TABLE'=>'fixture_config','DISALLOW_TABLE'=>'fixture_disallow','WORDS_TABLE'=>'fixture_words','PROFILE_FIELDS_TABLE'=>'fixture_profile_fields','THEMES_TABLE'=>'fixture_themes',
 'iNA_GAMES_COMMENT'=>'fixture_ina_comment','iNA_AT_SCORES'=>'fixture_ina_at_scores','iNA_HIGHSCORES'=>'fixture_ina_highscore','SHOUTBOX_TABLE'=>'fixture_shout') as $k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_admin_profile_storage.php';
require $ats_source.'includes/functions_validate.php';
foreach(array('phpbb_clean_username','phpbb_rtrim','phpbb_ltrim') as $name){ats_load_function($ats_source.'includes/functions.php',$name);}
foreach(array('admin_user_sql_value'=>'admin/admin_users.php','session_reset_keys'=>'includes/sessions.php','phpbb_session_publish_reset_cookie'=>'includes/sessions.php',
 'phpbb_sync_username_references'=>'includes/functions.php','user_avatar_delete'=>'includes/usercp_avatar.php') as $name=>$file){ats_load_function($ats_source.$file,$name);}
ats_load_function($ats_source.'admin/admin_users.php','admin_user_post_string');
if(PHP_SAPI!=='cli'||getenv('PHPBB_ADMIN_PROFILE_NATIVE')!=='1'){echo "Native ACP profile checks require an explicitly enabled disposable MySQL/MariaDB fixture.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_ADMIN_PROFILE_PORT')?:'3306';$password=getenv('PHPBB_ADMIN_PROFILE_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$fixture='codex_admin_profile_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class ApConnection {
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($m,$args){return call_user_func_array(array($this->db,$m),$args);}
 function sql_query($sql,$tx=false){
  $GLOBALS['ap_queries'][]=$sql;if(is_callable($GLOBALS['ap_hook'])){call_user_func($GLOBALS['ap_hook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['ap_write']===$GLOBALS['ap_fail']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['ap_commit']==='fail'){return false;}
  $r=$this->db->sql_query($sql,$tx);if(!$r){$GLOBALS['ap_sql_error']=$this->db->sql_error();}
  if($sql==='COMMIT'&&$GLOBALS['ap_commit']==='ack'){return false;}
  if($sql==='COMMIT'&&$r&&is_callable($GLOBALS['ap_after_commit'])){call_user_func($GLOBALS['ap_after_commit'],$this);}
  return $r;
 }
}
class ApDatabase extends sql_db {function sql_dedicated_connection(){return new ApConnection(parent::sql_dedicated_connection());}}
class ApTemplate {var $vars=array();function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}}
$ap_main=new ApDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$ap_main;
$ap_tables=array('users','sessions','sessions_keys','jr_admin_users','groups','user_group','banlist','attach_quota','quota_limits','album','album_comment','ina_comment','ina_at_scores','ina_highscore','shout','config','disallow','words','profile_fields','themes');
$ap_hook=null;$ap_write=$ap_fail=0;$ap_commit='';$ap_queries=$ap_cookies=array();$ap_after_commit=null;
$ap_files=sys_get_temp_dir().'/phpbb-profile-'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));ats_check(mkdir($ap_files),'Owned avatar fixture directory');
ats_check(mkdir($ap_files.'/cache'),'Owned name-cache fixture directory');
function user_avatar_storage_directory(){return $GLOBALS['ap_files'];}
function ap_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Native fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function ap_rows($sql){$rows=phpbb_acl_rows($GLOBALS['peer'],$sql);foreach($rows as &$row){foreach(array_keys($row) as $key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function ap_snap(){
 $out=array();foreach($GLOBALS['ap_tables'] as $s){$name=$s==='jr_admin_users'?'jr':$s;$rows=ap_rows('SELECT * FROM fixture_'.$name);usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});$out[$s]=$rows;}return $out;
}
// Compare separate successful executions without equating their fresh tokens/times.
// Rollback assertions below always compare the full, unmodified snapshots.
function ap_committed($snap){
 foreach($snap['users'] as &$r){foreach(array('user_regdate','user_blocktime') as $k){if((int)$r[$k]>0){$r[$k]='fresh';}}}unset($r);
 foreach($snap['sessions_keys'] as &$r){if($r['key_id']!==md5('old-key-'.$r['user_id'])){$r['key_id']='fresh';$r['last_login']='fresh';}}unset($r);
 foreach($snap as &$rows){usort($rows,function($a,$b){return strcmp(json_encode($a),json_encode($b));});}unset($rows);
 return $snap;
}
function ap_diff($expected,$actual){
 $out=array();foreach($expected as $table=>$rows){foreach($rows as $i=>$row){foreach($row as $key=>$value){if(!isset($actual[$table][$i])||!array_key_exists($key,$actual[$table][$i])||$actual[$table][$i][$key]!==$value){$out[]=$table.'.'.$i.'.'.$key.': '.json_encode(array($value,isset($actual[$table][$i][$key])?$actual[$table][$i][$key]:null));}}}}return implode('; ',$out);
}
function ap_insert($table,$values){
 foreach(ap_rows('SHOW COLUMNS FROM '.$table) as $c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}
 $encoded=array();foreach($values as $v){$encoded[]="'".$GLOBALS['peer']->sql_escape((string)$v)."'";}ap_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');
}
function ap_reset($actor='root',$scenario='edit'){
 global $db,$userdata,$ap_hook,$ap_write,$ap_fail,$ap_commit,$ap_queries,$ap_cookies,$admin_profile_scope,$board_config,$ap_actor,$ap_target;
 $db=$GLOBALS['ap_main'];$admin_profile_scope=null;$ap_hook=null;$ap_write=$ap_fail=0;$ap_commit='';$ap_queries=$ap_cookies=array();$GLOBALS['ap_sql_error']=null;
 $GLOBALS['ap_after_commit']=null;
 ap_sql('START TRANSACTION');foreach($GLOBALS['ap_tables'] as $s){ap_sql('DELETE FROM fixture_'.($s==='jr_admin_users'?'jr':$s));}ap_sql('COMMIT');
 ap_sql('ALTER TABLE fixture_groups AUTO_INCREMENT=1');ap_sql('ALTER TABLE fixture_banlist AUTO_INCREMENT=1');ap_sql('START TRANSACTION');
 foreach(array(1,2,5) as $id){ap_insert('fixture_users',array('user_id'=>$id,'username'=>'fixture-'.$id,'user_level'=>$id===1?1:0,'user_active'=>1,'user_password'=>'fixture-before','user_avatar'=>$id===2?'before.png':'','user_avatar_type'=>$id===2?1:0));}
 ap_insert('fixture_groups',array('group_id'=>10,'group_name'=>'personal','group_description'=>'','group_single_user'=>1,'group_moderator'=>0));ap_insert('fixture_user_group',array('user_id'=>2,'group_id'=>10,'user_pending'=>0));
 $ap_actor=$actor==='root'?1:5;$ap_target=$scenario==='new'?42:($scenario==='self'? $ap_actor:2);
 foreach(array('fixture-admin'=>$ap_actor,'target-session'=>2,'other-self'=>$ap_actor,'unrelated'=>99) as $sid=>$id){ap_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>$sid==='fixture-admin'?1:0));}
 foreach(array(1,2,5) as $id){ap_insert('fixture_sessions_keys',array('key_id'=>md5('old-key-'.$id),'user_id'=>$id,'last_ip'=>'7f000001','last_login'=>1));}
 if($actor==='junior'){ap_insert('fixture_jr',array('user_id'=>5,'user_jr_admin'=>md5('UsersManageadmin_users.php')));}
 ap_insert('fixture_quota_limits',array('quota_limit_id'=>1,'quota_desc'=>'fixture','quota_limit'=>1024));ap_insert('fixture_attach_quota',array('user_id'=>2,'group_id'=>0,'quota_type'=>1,'quota_limit_id'=>1));
 $userdata=array('user_id'=>$ap_actor,'username'=>'fixture-'.$ap_actor,'user_level'=>1,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin','session_key'=>'old-key-'.$ap_actor);
 $board_config=array('block_time'=>5,'max_user_bancard'=>10,'cookie_name'=>'fixture','cookie_path'=>'/','cookie_domain'=>'','cookie_secure'=>0);
 $basic=file_get_contents($GLOBALS['ats_source'].'install/schemas/mysql_basic.sql');
 foreach(PhpbbAdminProfileScope::policy_keys() as $key){if($key==='default_lang'){$m=array('','english');}else{ats_check(preg_match("/VALUES\\s*\\('".preg_quote($key,'/')."'\\s*,\\s*'([^']*)'\\)/",$basic,$m)===1,'Installer profile policy '.$key);}if(!isset($board_config[$key])){$board_config[$key]=$m[1];}ap_insert('fixture_config',array('config_name'=>$key,'config_value'=>$board_config[$key]));}
 ap_insert('fixture_themes',array('themes_id'=>1,'template_name'=>'fisubsilversh'));
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'fixture-admin','id'=>'2','u'=>'999','new_user'=>$scenario==='new'?'1':'0','submit'=>'Save','user_status'=>'1','user_upload_quota'=>'1','user_pm_quota'=>'0');
 if($scenario==='block'){$_POST['block_account']='1';}if($scenario==='unblock'){$_POST['unblock_account']='1';ap_sql('UPDATE fixture_users SET user_blocktime=123,user_badlogin=3 WHERE user_id=2');}
 ap_sql('COMMIT');
 foreach(array('before.png','after.png') as $file){file_put_contents($GLOBALS['ap_files'].'/'.$file,'owned-'.$file);}
}
function ap_fragment($source,$start,$end){$a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);ats_check($a!==false&&$b>$a,'Actual profile fragment '.$start);return substr($source,$a,$b-$a);}
$ap_controller=file_get_contents($ats_source.'admin/admin_users.php');
$ap_projection=ap_fragment($ap_controller,'$admin_profile_scope->validate_identity(',"\n\t\t\tif( \$result = \$db->sql_query(\$sql) )");
$ap_creation=ap_fragment($ap_controller,'list($profile_columns, $profile_values) = $admin_profile_scope->profile_insert_parts();',"\t\t\$_POST[POST_USERS_URL] = \$user_id;");
$ap_block=ap_fragment($ap_controller,'// Start add - Protect user account MOD','// End add - Protect user account MOD');
$ap_ban=ap_fragment($ap_controller,"if ($".'user_ycard>$board_config',"\t\t\t// Core and custom profile data");
$ap_disable=ap_fragment($ap_controller,'if (!$user_status)',"\t\t\t\t// We remove all stored login keys");
function ap_run($scenario){
 global $db,$admin_profile_scope,$board_config,$ap_cookies,$userdata,$phpbb_root_path;
 $source_root=$phpbb_root_path;$phpbb_root_path=$GLOBALS['ap_files'].'/';
 $id=$GLOBALS['ap_target'];$user_id=$id;$user_ip='7f000001';
 try{
  $admin_profile_scope=new PhpbbAdminProfileScope($db,$id,$scenario==='new',$_POST);$db=$admin_profile_scope;
  if($scenario==='new'){eval($GLOBALS['ap_creation']);}
  eval($GLOBALS['ap_block']);$admin_profile_scope->assign_quotas($_POST);
  $profile_assignments=array("user_custom_fixture='".$db->sql_escape("Grüße ' 😀")."'");$username='renamed';$this_userdata=array('username'=>'fixture-'.$id);$username_sql=in_array($scenario,array('rename','new'),true)?"username='renamed', ":'';$passwd_sql=in_array($scenario,array('password','self','new'),true)?"user_password='fixture-after', ":'';$email='fixture@example.invalid';
  $user_style=1;$user_timezone=0;$user_dateformat='Y-m-d';$user_lang='german';
  $icq=$website=$occupation=$location=$user_flag=$interests=$user_absence_text=$signature=$signature_bbcode_uid=$aim=$yim=$msn='';
  $fb=$ig=$pt=$twr=$skp=$tg=$li=$tt=$dc=$signal=$threema='';
  $user_absence_mode=$user_absence=$viewemail=$attachsig=$setbm=$allowsmilies=$allowhtml=$allowbbcode=$allowviewonline=$notifyreply=$notifypm=$games_block_pm=$gender=0;
  $avatar_sql=$force_new_passwd_sql='';$birthday=999999;$next_birthday_greeting=0;$user_status=1;$user_ycard=$user_rank=$user_allowavatar=$user_allowpm=$popuppm=0;
  if(isset($GLOBALS['ap_text_input'])){
   $raw=$GLOBALS['ap_text_input'];profile_fixture_request($_POST+array('location'=>$raw,'occupation'=>$raw,'interests'=>$raw,'fb'=>$raw,'user_absence_text'=>$raw));
   foreach(array('location','occupation','interests','fb','user_absence_text') as $key){
    ats_check(preg_match('/^\t\t\$'. $key .' = [^\r\n]+;/m',$GLOBALS['ap_controller'],$m)===1,'Actual ACP text parser '.$key);eval($m[0]);
   }
  }
  if($scenario==='ban'){$user_ycard=11;}
  if($scenario==='disable'){$user_status=0;}
  eval($GLOBALS['ap_ban']);
  if($scenario==='avatar'){$admin_profile_scope->remember_avatar('after.png',true);user_avatar_delete(USER_AVATAR_UPLOAD,'before.png');$avatar_sql=",user_avatar='after.png',user_avatar_type=1";}
  eval($GLOBALS['ap_projection']);$db->sql_query($sql);
  eval($GLOBALS['ap_disable']);
  if(in_array($scenario,array('rename','new'),true)){phpbb_sync_username_references($id,$scenario==='new'?'new_user':'fixture-'.$id,'renamed');$admin_profile_scope->rename_cache_needed=true;}
  if($passwd_sql!==''){$admin_profile_scope->login_cookie=session_reset_keys($id,$user_ip,true);}
  ats_check(!$ap_cookies,'No cookie before commit');$admin_profile_scope->finish();return true;
 }catch(PhpbbAclException $e){return 'error';}catch(AttachSettingsExit $e){return 'error';}
 finally{if($admin_profile_scope!==null){$admin_profile_scope->release();}$phpbb_root_path=$source_root;}
}
function ap_boundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function ap_revoke($kind){
 $id=$GLOBALS['ap_actor'];$map=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id='.$id,'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id='.$id,
 'delegation'=>'DELETE FROM fixture_jr WHERE user_id='.$id,'missing'=>"DELETE FROM fixture_sessions WHERE session_id='fixture-admin'",
 'foreign'=>"UPDATE fixture_sessions SET session_user_id=99 WHERE session_id='fixture-admin'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='fixture-admin'",
 'acp'=>"UPDATE fixture_sessions SET session_admin=0 WHERE session_id='fixture-admin'",'case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN' WHERE session_id='fixture-admin'");return $map[$kind];
}
function ap_without_authority($snap){
 $id=$GLOBALS['ap_actor'];$snap['users']=array_values(array_filter($snap['users'],function($r)use($id){return (int)$r['user_id']!==$id;}));
 $snap['sessions']=array_values(array_filter($snap['sessions'],function($r){return strtolower($r['session_id'])!=='fixture-admin';}));
 $snap['jr_admin_users']=array_values(array_filter($snap['jr_admin_users'],function($r)use($id){return (int)$r['user_id']!==$id;}));return $snap;
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');
 foreach($ap_tables as $s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical table '.$s);$name='fixture_'.($s==='jr_admin_users'?'jr':$s);ap_sql(str_replace('phpbb_'.$s,$name,$m[0]));preg_match_all('/ALTER TABLE phpbb_'.$s.'\s+[\s\S]*?;/',$schema,$extra);foreach($extra[0] as $ddl){ap_sql(str_replace('phpbb_'.$s,$name,$ddl));}}
 ap_sql('ALTER TABLE fixture_users ADD user_custom_fixture VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
 // Keep fixture DDL tolerant of short internal InnoDB background locks.
 // Only a synchronous peer contention probe needs an immediate1205.
 ap_sql('SET SESSION innodb_lock_wait_timeout=1');$cases=$serialized=0;
 foreach(array('root','junior') as $actor){foreach(array('edit','new','password','self','rename','block','unblock','avatar','ban','disable') as $scenario){
  ap_reset($actor,$scenario);$before=ap_snap();$ok=ap_run($scenario);ats_check($ok===true,'Authorized '.$actor.' '.$scenario.' '.json_encode(isset($ap_sql_error)?$ap_sql_error:null));$after=ap_snap();$writes=$ap_write;$boundaries=array_values(array_filter($ap_queries,'ap_boundary'));
  ats_check($db===$ap_main,'Restore main connection');
  ats_check(ap_rows('SELECT user_custom_fixture FROM fixture_users WHERE user_id='.$ap_target)[0]['user_custom_fixture']==="Grüße ' 😀",'Core/custom profile save remains UTF-8 byte exact');
  ats_check(count($ap_cookies)===($scenario==='self'?1:0),'Only committed self key rotation publishes cookie');
  if($scenario==='avatar'){ats_check(!is_file($ap_files.'/before.png')&&is_file($ap_files.'/after.png'),'Committed replacement retires only old avatar');}
  if($scenario==='ban'){ats_check(count(ap_rows('SELECT * FROM fixture_banlist WHERE ban_userid=2'))===1,'Warning threshold inserts ban');}
  if($scenario==='disable'){ats_check(!ap_rows('SELECT * FROM fixture_sessions WHERE session_user_id=2'),'Disabled target loses sessions');}
  if($scenario==='new'){ats_check(count(ap_rows('SELECT * FROM fixture_users WHERE user_id=42 AND user_active=1'))===1&&count(ap_rows('SELECT * FROM fixture_user_group WHERE user_id=42'))===1,'New account and personal membership publish together');}
  foreach(array('missing',$actor==='root'?'role':'delegation','disconnect') as $kind){
   ap_reset($actor,$scenario);$committed=null;
   $ap_after_commit=function($connection)use($kind,&$committed){
    $committed=ap_snap();if($kind==='disconnect'){ap_sql('KILL CONNECTION '.(int)mysqli_thread_id($connection->db_connect_id));}
    else{ap_sql(ap_revoke($kind));}
   };
   ats_check(ap_run($scenario)===true,'Confirmed profile survives post-commit '.$kind.' '.$actor.' '.$scenario);
   ats_check(ap_committed($committed)===ap_committed($after),'Complete profile was committed before later authority change');
   ats_check($db===$ap_main&&count($ap_cookies)===($scenario==='self'?1:0),'Confirmed profile restores ordinary connection and publishes self cookie');
   if($scenario==='avatar'){ats_check(!is_file($ap_files.'/before.png')&&is_file($ap_files.'/after.png'),'Confirmed avatar cleanup survives writer disconnect/revocation');}
   // KILL CONNECTION/COM_QUIT cleanup is asynchronous on the server. Use the
   // normal writer's bounded wait, not a racy zero-time observation. A leaked
   // owner still fails this check; native cleanup cannot retain its mutex.
   $other=new attach_mutation_lock($peer);ats_check($other->acquired,'Confirmed profile releases attachment mutex within the normal writer wait');$other->release();$cases++;
  }
  for($n=1;$n<=$writes;$n++){ap_reset($actor,$scenario);$before=ap_snap();$ap_fail=$n;ats_check(ap_run($scenario)==='error'&&ap_snap()===$before&&!$ap_cookies,'Whole profile rollback at write '.$n.' '.$scenario);ats_check(is_file($ap_files.'/before.png'),'Failure preserves old avatar');if($scenario==='avatar'&&$admin_profile_scope->new_avatars){ats_check(!is_file($ap_files.'/after.png'),'Confirmed rollback removes staged unreferenced avatar');}$cases++;}
  foreach(array('fail','ack') as $kind){ap_reset($actor,$scenario);$before=ap_snap();$ap_commit=$kind;
   if(in_array($scenario,array('rename','new'),true)){$ap_hook=function($sql){if($sql==='COMMIT'){file_put_contents($GLOBALS['ap_files'].'/cache/cg_users.cache','reader-filled-before-commit');}};}
   ats_check(ap_run($scenario)==='error'&&!$ap_cookies,'Uncertain COMMIT not acknowledged/published');$expected=$kind==='fail'?$before:ap_committed($after);$actual=$kind==='fail'?ap_snap():ap_committed(ap_snap());ats_check($actual===$expected,'Whole profile outcome on failed/lost commit '.$scenario.' '.$kind.' '.ap_diff($expected,$actual));ats_check(is_file($ap_files.'/before.png'),'Uncertain commit preserves avatar source');ats_check(!is_file($ap_files.'/cache/cg_users.cache'),'Uncertain rename commit invalidates refilled cache');$cases++;}
  echo $actor.' '.$scenario." native profile rollback passed\n";
  $authority=$actor==='root'?'role':'delegation';
  foreach(array('inactive','missing','foreign','logout','acp','case',$authority) as $kind){ap_reset($actor,$scenario);ap_sql(ap_revoke($kind));$before=ap_snap();ats_check(ap_run($scenario)==='error'&&ap_snap()===$before&&!$ap_cookies,'Entry authority '.$kind.' '.$scenario);$cases++;}
  if(in_array($scenario,array('edit','new','avatar'),true)){
   foreach(array('missing',$authority) as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
    ap_reset($actor,$scenario);$before=ap_snap();$seen=0;$reached=$blocked=false;
    $ap_hook=function($sql)use($kind,$nth,&$seen,&$reached,&$blocked){if(!ap_boundary($sql)||++$seen!==$nth){return;}$GLOBALS['ap_hook']=null;$reached=true;
     ap_sql('SET SESSION innodb_lock_wait_timeout=0');
     try{if(!$GLOBALS['peer']->sql_query(ap_revoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Real peer lock timeout');$blocked=true;}}
     finally{ap_sql('SET SESSION innodb_lock_wait_timeout=1');}};
    $result=ap_run($scenario);ats_check($reached,'Every write/commit/authority-lock boundary reached');
    if($blocked){ats_check($result===true&&ap_committed(ap_snap())===ap_committed($after),'Revocation serializes after complete profile');ap_sql(ap_revoke($kind));$serialized++;}
    else{ats_check($result==='error'&&ap_without_authority(ap_snap())===ap_without_authority($before)&&!$ap_cookies,'Effective revocation aborts complete profile');}$cases++;
   }}
  }
 }}
 foreach($ap_tables as $s){ap_reset();$table='fixture_'.($s==='jr_admin_users'?'jr':$s);ap_sql('ALTER TABLE '.$table.' ENGINE=MyISAM');$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before,'Reject nontransactional participant '.$s);ap_sql('ALTER TABLE '.$table.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 foreach(array('id','sid','quota','self-disable','self-block','self-ban','founder','admin-target','collision') as $bad){
  ap_reset($bad==='admin-target'?'junior':'root');
  if($bad==='id'){$ap_target='2junk';}if($bad==='sid'){$_POST['sid']='FIXTURE-ADMIN';}if($bad==='quota'){$_POST['user_upload_quota']='999';}
  if($bad==='self-disable'||$bad==='self-block'){$ap_target=1;$_POST[$bad==='self-disable'?'user_status':'block_account']=$bad==='self-disable'?'0':'1';}
  if($bad==='self-ban'){$ap_target=1;$_POST['user_ycard']='11';}
  if($bad==='founder'){ap_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=5');$userdata['user_id']=5;ap_sql("UPDATE fixture_sessions SET session_user_id=5 WHERE session_id='fixture-admin'");$ap_target=1;}
  if($bad==='admin-target'){ap_sql('UPDATE fixture_users SET user_level=1 WHERE user_id=2');}
  $before=ap_snap();ats_check(ap_run($bad==='collision'?'new':'edit')==='error'&&ap_snap()===$before&&!$ap_cookies,'Rejected profile '.$bad);
 }
 ap_reset();ap_sql("UPDATE fixture_users SET user_avatar='before.png',user_avatar_type=1 WHERE user_id=5");ats_check(ap_run('avatar')===true&&is_file($ap_files.'/before.png'),'Shared old avatar retained');
 ap_reset();$before=ap_snap();$admin_profile_scope=new PhpbbAdminProfileScope($db,2,false,$_POST);$db=$admin_profile_scope;$db->sql_query("UPDATE fixture_users SET username='uncommitted' WHERE user_id=2");$admin_profile_scope->release();ats_check(ap_snap()===$before,'Validation exit without finish rolls back');
 foreach(array('COMMIT','START TRANSACTION','SET autocommit=1','ALTER TABLE fixture_users ENGINE=MyISAM','TRUNCATE fixture_banlist') as $sql){
  ap_reset();$before=ap_snap();$admin_profile_scope=new PhpbbAdminProfileScope($db,2,false,$_POST);$db=$admin_profile_scope;$rejected=false;
  try{$db->sql_query($sql);}catch(PhpbbAclException $e){$rejected=true;}finally{$admin_profile_scope->release();}
  ats_check($rejected&&ap_snap()===$before,'Profile helpers cannot escape transaction');
 }
 ap_reset('root','self');session_reset_keys(1,'7f000001');ats_check(count($ap_cookies)===1&&$userdata['session_key']!=='old-key-1','Non-ACP default cookie behavior preserved');
 ap_reset();$template=new ApTemplate();$attach_config=array('default_upload_quota'=>0,'default_pm_quota'=>0);$before=ap_snap();
 phpbb_admin_profile_quota_controls(2,array('submit'=>'Save','user_upload_quota'=>'0','user_pm_quota'=>'1'));
 ats_check(ap_snap()===$before&&strpos($template->vars['S_SELECT_UPLOAD_QUOTA'],'value="0" selected="selected"')!==false&&strpos($template->vars['S_SELECT_PM_QUOTA'],'value="1" selected="selected"')!==false,'Validation rendering preserves submitted controls without writes');
 foreach(array('0','C:\\notes\\draft',"Grüße \\ &amp; ' 😀", "',user_level=1 --") as $raw){
  ap_reset();$GLOBALS['ap_text_input']=$raw;ats_check(ap_run('edit')===true,'Bootstrapped ACP text commits');
  $row=ap_rows('SELECT user_from,user_occ,user_interests,user_fb,user_absence_text,user_level FROM fixture_users WHERE user_id=2')[0];
  ats_check((int)$row['user_level']===0,'Text cannot assign privilege columns');unset($row['user_level']);
  foreach($row as $value){ats_check($value===$raw,'Exact ACP text storage');}unset($GLOBALS['ap_text_input']);$cases++;
 }
 echo 'Native ACP profile: '.$cases.' boundary/failure cases, '.$serialized." serialized revocations passed.\n";
}finally{
 if(isset($admin_profile_scope)&&$admin_profile_scope!==null){$admin_profile_scope->release();}
 $ap_hook=null;$ap_fail=0;$ap_commit='';$ap_main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();
 foreach(array('before.png','after.png','cache/cg_users.cache') as $file){if(is_file($ap_files.'/'.$file)){unlink($ap_files.'/'.$file);}}rmdir($ap_files.'/cache');rmdir($ap_files);restore_error_handler();
}
