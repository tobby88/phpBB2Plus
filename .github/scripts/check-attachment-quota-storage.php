<?php
// Reuse exact ACP helpers/readers/languages, but not the other native fixture.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
define('GROUPS_TABLE','fixture_groups');require $ats_source.'attach_mod/includes/functions_quota_storage.php';
require $ats_source.'attach_mod/includes/functions_includes.php';
$qform=array('quota_change_list'=>array('1','2'),'quota_desc_list'=>array(addslashes("Ä & ' 😀"),'remove'),
 'max_filesize_list'=>array('1.5','2'),'size_select_list'=>array('kb','kb'),'quota_id_list'=>array('2'),
 'add_quota_check'=>'on','quota_description'=>addslashes(str_repeat('Ä',25)),'add_max_filesize'=>'1.00000095367431640625','add_size_select'=>'mb');
$parsed=phpbb_attach_quota_form($qform);ats_check($parsed['changes'][1]['bytes']==='1536'&&$parsed['add']['bytes']==='1048577','Fractional limits preserve bytes');
ats_check($parsed['changes'][1]['description']==="Ä & ' 😀"&&$parsed['add']['description']===str_repeat('Ä',25),'UTF-8 names stored raw');
$bad=array(array('quota_change_list'=>array('1','1')),array('quota_change_list'=>array('0','2')),array('quota_desc_list'=>array('only one')),
 array('quota_desc_list'=>array(str_repeat('😀',26),'b')),array('quota_desc_list'=>array("a\0b",'b')),array('max_filesize_list'=>array('1e3','2')),
 array('max_filesize_list'=>array('9007199254740992','2')),array('size_select_list'=>array('gb','kb')),array('quota_id_list'=>array('3')),
 array('quota_id_list'=>array('2','2')),array('quota_change_list'=>array(1=>'1',3=>'2')),array('quota_change_list'=>array(array('1'),'2')),
 array('add_quota_check'=>array('on')),array('quota_description'=>''),array('mode'=>'manage'),array('attach_version'=>'forged'));
foreach($bad as $patch){$denied=false;try{phpbb_attach_quota_form(array_merge($qform,$patch));}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,'Malformed complete quota form rejected');}
foreach(array('0','1','1572865','9007199254740991') as $bytes){foreach(array('b','kb','mb') as $unit){ats_check(phpbb_attach_quota_bytes(phpbb_attach_settings_display_size($bytes,$unit),$unit)===$bytes,'Exact quota size round trip');}}
ats_check(phpbb_attach_quota_assignment_fields('user',array('username'=>'unrelated','user_pm_quota'=>'0'))===array(QUOTA_PM_LIMIT=>'0'),'Unsubmitted assignments preserved');
$updater=dirname(dirname(__DIR__)).'/update/update_from_153a.php';$updater_source=file_get_contents($updater);
ats_check(strpos($updater_source,"update_queue_text_widths(\$operations, \$connection, \$dbname, \$table_prefix . 'quota_limits', array('quota_desc' => 25));")!==false,'Actual updater invokes quota widening');
$tpl=file_get_contents($ats_source.'templates/fisubsilversh/admin/attach_quota_body.tpl');
ats_check(substr_count($tpl,'maxlength="40"')===2,'Full-precision size fields');
echo "Quota input/size/assignment controls passed\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_QUOTA_NATIVE')!=='1'){return;}
require $ats_source.'db/mysqli.php';$port=getenv('PHPBB_QUOTA_PORT')?:'3306';$password=getenv('PHPBB_QUOTA_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);$fixture='codex_quota_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class QuotaConnection {
 var $connection;var $db_connect_id;
 function __construct($db){$this->connection=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->connection,$method),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['qqueries'][]=$sql;if(is_callable($GLOBALS['qhook'])){call_user_func($GLOBALS['qhook'],$sql);}
  if(preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)&&++$GLOBALS['qwrite']===$GLOBALS['qfailWrite']){return false;}
  if($sql==='COMMIT'&&$GLOBALS['qcommit']==='fail'){return false;}
  $r=$this->connection->sql_query($sql,$transaction);return $sql==='COMMIT'&&$GLOBALS['qcommit']==='ack'?false:$r;
 }
}
class QuotaDatabase extends sql_db {function sql_dedicated_connection(){return new QuotaConnection(parent::sql_dedicated_connection());}}
class QuotaTemplate {
 var $vars=array();var $blocks=array();
 function assign_var($k,$v){$this->vars[$k]=$v;}
 function assign_vars($v){$this->vars=array_merge($this->vars,$v);}
 function assign_block_vars($k,$v){$this->blocks[$k][]=$v;}
 function set_filenames($v){}
 function pparse($name){throw new AttachSettingsExit('rendered');}
}
$db=new QuotaDatabase($host,'root',$password,$fixture,false);unset($db->password);$peer=new sql_db($host,'root',$password,$fixture,false);
$qqueries=array();$qhook=null;$qwrite=0;$qfailWrite=0;$qcommit='';
function qsql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Fixture SQL');return $r;}
function qsnap(){return array('limits'=>phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM '.QUOTA_LIMITS_TABLE.' ORDER BY quota_limit_id'),
 'assign'=>phpbb_acl_rows($GLOBALS['peer'],'SELECT * FROM '.QUOTA_TABLE.' ORDER BY user_id,group_id,quota_type'),'defaults'=>phpbb_attach_settings_read($GLOBALS['peer']));}
function qreset($actor='root',$route='quota'){
 global $qqueries,$qhook,$qwrite,$qfailWrite,$qcommit,$userdata,$attach_config,$board_config;
 $qqueries=array();$qhook=null;$qwrite=$qfailWrite=0;$qcommit='';
 foreach(array(ATTACH_CONFIG_TABLE,QUOTA_LIMITS_TABLE,QUOTA_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE) as $t){qsql('DELETE FROM '.$t);}
 qsql('ALTER TABLE '.QUOTA_LIMITS_TABLE.' AUTO_INCREMENT=1');
 foreach($GLOBALS['ats_defaults'] as $k=>$v){if($k==='default_upload_quota'){$v='2';}qsql("INSERT INTO ".ATTACH_CONFIG_TABLE." VALUES ('".$k."','".$v."')");}
 qsql("INSERT INTO ".QUOTA_LIMITS_TABLE." VALUES (1,'first',1536),(2,'remove',2048),(3,'unsubmitted',4096)");
 qsql('INSERT INTO '.QUOTA_TABLE.' VALUES (2,0,1,2),(0,10,2,2),(2,0,2,1)');
 qsql("INSERT INTO fixture_users VALUES (1,".($actor==='root'?1:0).",1,'actor'),(2,0,1,'target'),(3,1,1,'admin')");
 qsql("INSERT INTO fixture_groups (group_id,group_single_user,group_name) VALUES (10,0,'group')");qsql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 if($actor!=='root'){$path=$route==='quota'?'admin_attachments.php?mode=quota':($route==='wrong'?'admin_attachments.php?mode=manage':'admin_'.$route.'s.php');$hash=array_search($path,jr_admin_authorization_routes(),true);ats_check($hash!==false,'Exact registered route');qsql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?1:0,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin');$attach_config=get_config();$_SERVER['REQUEST_METHOD']='POST';
}
function qrun($request,$get=array()){
 global $db,$lang,$phpEx,$phpbb_root_path,$userdata,$attach_config,$board_config,$template,$HTTP_POST_VARS,$HTTP_GET_VARS,$table_prefix;
 $template=new QuotaTemplate();$_POST=$request===null?array():array_merge(array('sid'=>'fixture-admin','submit'=>'Save'),$request);$_GET=array_merge(array('mode'=>'quota'),$get);
 $GLOBALS['HTTP_POST_VARS']=$_POST;$GLOBALS['HTTP_GET_VARS']=$_GET;$_SERVER['REQUEST_METHOD']=$request===null?'GET':'POST';
 try{include $GLOBALS['ats_source'].'admin/admin_attachments.php';throw new RuntimeException('Controller did not stop');}catch(AttachSettingsExit $e){return strpos($e->getMessage(),'saved')===0?'saved':$e->getMessage();}
}
function qassignment($mode,$request,$resolved_user_id=null){
 $_POST=array_merge(array('sid'=>'fixture-admin','submit'=>'Save','id'=>'2'),$request);$GLOBALS['HTTP_POST_VARS']=$_POST;$GLOBALS['HTTP_GET_VARS']=array();$_SERVER['REQUEST_METHOD']='POST';
 try{if($mode==='user'){attachment_quota_settings('user',true,'save',$resolved_user_id);}else{attachment_quota_save_form('group',10);}return 'saved';}catch(AttachSettingsExit $e){return $e->getMessage();}
}
function qboundary($sql){return preg_match('/^(UPDATE|DELETE|INSERT)\b/',$sql)||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function qrevoke($kind){$queries=array('role'=>'UPDATE fixture_users SET user_level=0 WHERE user_id=1','inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=1','grant'=>'DELETE FROM fixture_jr',
 'missing'=>'DELETE FROM fixture_sessions','admin-off'=>'UPDATE fixture_sessions SET session_admin=0','foreign'=>'UPDATE fixture_sessions SET session_user_id=99','logout'=>'UPDATE fixture_sessions SET session_logged_in=0','case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'");return $queries[$kind];}
$root=sys_get_temp_dir().'/'.$fixture;$previous=getcwd();$files=$dirs=array();
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 foreach(array('','admin','includes','attach_mod','attach_mod/includes') as $dir){$p=$root.($dir===''?'':'/'.$dir);mkdir($p,0700);$dirs[]=$p;}
 $files['extension.inc']="<?php \$phpEx='php';";$files['admin/pagestart.php']="<?php // Deliberately cached authority; writer checks current native state.\n";
 foreach(array('includes/functions_admin.php','attach_mod/includes/constants.php','attach_mod/includes/functions_attach.php','attach_mod/includes/functions_selects.php','attach_mod/includes/functions_admin.php','attach_mod/includes/functions_shadow.php','attach_mod/includes/functions_settings_storage.php','attach_mod/includes/functions_quota_storage.php') as $f){$files[$f]='<?php require_once '.var_export($ats_source.$f,true).';';}
 foreach($files as $f=>$body){file_put_contents($root.'/'.$f,$body);}chdir($root.'/admin');
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');$ddl=array();
 foreach(array('attachments_config','quota_limits','attach_quota','extension_groups') as $suffix){ats_check(preg_match('/CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical DDL');$ddl[$suffix]=str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]);qsql($ddl[$suffix]);}
 foreach(array('fixture_users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT,username VARCHAR(255)','fixture_sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT','fixture_jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT','fixture_groups'=>'group_id INT PRIMARY KEY,group_single_user INT,group_name VARCHAR(40)') as $t=>$cols){qsql('CREATE TABLE '.$t.' ('.$cols.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 qsql('SET SESSION innodb_lock_wait_timeout=1');
 $cases=$serialized=0;
 foreach(array('root','delegated') as $actor){
  qreset($actor);ats_check(qrun($qform)==='saved','Actual combined quota save');$after=qsnap();$boundaries=array_values(array_filter($qqueries,'qboundary'));$writes=$qwrite;
  ats_check(count($after['limits'])===3&&$after['limits'][0]['quota_limit']==1536&&$after['limits'][2]['quota_limit']==1048577,'Update/delete/add exact limits');
  ats_check($after['defaults']['default_upload_quota']==='0'&&count($after['assign'])===1,'Deleted quota defaults and both owner types removed');
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){qreset($actor);qsql(qrevoke($kind));$before=qsnap();ats_check(qrun($qform)==='Not_Authorised'&&qsnap()===$before,'Entry revocation preserves all tables');$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($boundaries);$nth++){
   qreset($actor);$before=qsnap();$seen=0;$reached=$blocked=false;
   $qhook=function($sql)use($nth,$kind,&$seen,&$reached,&$blocked){if(!qboundary($sql)||++$seen!==$nth){return;}$GLOBALS['qhook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(qrevoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Only native timeout serializes revocation');$blocked=true;}};
   $outcome=qrun($qform);ats_check($reached,'Every write/commit exercised');if($blocked){ats_check($outcome==='saved','Serialized revocation follows commit');qsql(qrevoke($kind));$serialized++;}else{ats_check($outcome==='Not_Authorised'&&qsnap()===$before,'Revocation rolls back all quota/default/assignment changes');}
   ats_check(qrun($qform)==='Not_Authorised','Following request denied');$cases++;
  }}
  for($nth=1;$nth<=$writes;$nth++){qreset($actor);$before=qsnap();$qfailWrite=$nth;ats_check(qrun($qform)==='storage'&&qsnap()===$before,'Every write failure rolls back full form');$cases++;}
  foreach(array('fail','ack') as $fault){qreset($actor);$before=qsnap();$qcommit=$fault;ats_check(qrun($qform)==='storage','Commit uncertainty reported');ats_check($fault==='fail'?qsnap()===$before:qsnap()!==$before,'Known rollback versus already committed acknowledgement loss');$cases++;}
  echo $actor." actual quota controller and concurrent revocation passed\n";
 }
 foreach($bad as $patch){qreset();$before=qsnap();ats_check(qrun(array_merge($qform,$patch))!=='saved'&&qsnap()===$before&&$qwrite===0,'Whole form rejects before writes');}
 qreset('delegated','wrong');$before=qsnap();ats_check(qrun($qform)==='Not_Authorised'&&qsnap()===$before,'Manage grant is not quota grant');
 qreset();qsql('DELETE FROM '.QUOTA_LIMITS_TABLE.' WHERE quota_limit_id=2');$before=qsnap();ats_check(qrun($qform)==='invalid'&&qsnap()===$before,'Missing edited definition does not silently save');
 qreset();$qhook=function($sql){if(strpos($sql,'UPDATE '.QUOTA_LIMITS_TABLE)===0){$GLOBALS['qhook']=null;qsql("UPDATE ".QUOTA_LIMITS_TABLE." SET quota_desc='concurrent' WHERE quota_limit_id=3");}};
 ats_check(qrun($qform)==='saved'&&qsnap()['limits'][1]['quota_desc']==='concurrent','Unsubmitted concurrent definition preserved');
 qreset();$locked=false;$qhook=function($sql)use(&$locked){if(strpos($sql,'UPDATE '.QUOTA_LIMITS_TABLE)===0){$GLOBALS['qhook']=null;$other=new attach_mutation_lock($GLOBALS['peer'],false);$locked=!$other->acquired;$other->release();}};
 ats_check(qrun($qform)==='saved'&&$locked,'Definition writer holds actual group/deletion mutation mutex');$other=new attach_mutation_lock($peer,false);ats_check($other->acquired,'Mutex released after save');$other->release();
 foreach(array('user','group') as $mode){foreach(array('root','delegated') as $actor){
  $request=array($mode.'_upload_quota'=>'1',$mode.'_pm_quota'=>'3');qreset($actor,$mode);ats_check(qassignment($mode,$request)==='saved','Actual assignment entry point');$aboundaries=array_values(array_filter($qqueries,'qboundary'));
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($nth=1;$nth<=count($aboundaries);$nth++){
   qreset($actor,$mode);$before=qsnap();$seen=0;$blocked=$reached=false;
   $qhook=function($sql)use($nth,$kind,&$seen,&$blocked,&$reached){if(!qboundary($sql)||++$seen!==$nth){return;}$GLOBALS['qhook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(qrevoke($kind))){$e=$GLOBALS['peer']->sql_error();ats_check((int)$e['code']===1205,'Assignment revocation native timeout');$blocked=true;}};
   $outcome=qassignment($mode,$request);ats_check($reached,'Every assignment boundary reached');if($blocked){ats_check($outcome==='saved','Assignment commits before serialized revocation');qsql(qrevoke($kind));$serialized++;}else{ats_check($outcome==='Not_Authorised'&&qsnap()===$before,'Revocation rolls back both assignments');}
   ats_check(qassignment($mode,$request)==='Not_Authorised','Next assignment request denied');$cases++;
  }}
  foreach(array('inactive','missing',$actor==='root'?'role':'grant') as $kind){qreset($actor,$mode);qsql(qrevoke($kind));$before=qsnap();ats_check(qassignment($mode,$request)==='Not_Authorised'&&qsnap()===$before,'Assignment current authority');}
  qreset($actor,$mode);$before=qsnap();$qfailWrite=3;ats_check(qassignment($mode,$request)==='storage'&&qsnap()===$before,'Both assignment types roll back together');
  qreset($actor,$mode);$before=qsnap();ats_check(qassignment($mode,array($mode.'_upload_quota'=>'999'))==='invalid'&&qsnap()===$before,'No orphan assignment');
  qreset($actor,$mode);$before=qsnap();ats_check(qassignment($mode,array($mode.'_upload_quota'=>array('1')))==='invalid'&&qsnap()===$before,'Nested assignment rejected');
  qreset($actor,$mode);$before=qsnap();ats_check(qassignment($mode,array())==='saved'&&qsnap()===$before,'Omitted assignment controls do not clear data');
 }}
 qreset();qsql('UPDATE fixture_users SET user_level=0,user_active=0 WHERE user_id=3');$oldAssignments=qsnap()['assign'];
 ats_check(qassignment('user',array('id'=>'2','u'=>'999','new_user'=>'1','user_upload_quota'=>'1'),3)==='saved','Newly created inactive account receives its quota');
 $newRows=phpbb_acl_rows($peer,'SELECT * FROM '.QUOTA_TABLE.' WHERE user_id=3');ats_check(count($newRows)===1&&(int)$newRows[0]['quota_limit_id']===1,'Allocated account is actual assignment owner');
 ats_check(phpbb_acl_rows($peer,'SELECT * FROM '.QUOTA_TABLE.' WHERE user_id<>3 ORDER BY user_id,group_id,quota_type')===$oldAssignments,'Reference account assignments remain untouched');
 qreset('delegated','wrong');$before=qsnap();ats_check(qassignment('user',array('user_upload_quota'=>'1'))==='Not_Authorised'&&qsnap()===$before,'Wrong module cannot assign user quota');
 qreset('delegated','user');$before=qsnap();$denied=false;try{phpbb_attach_quota_assign($db,'user',3,array(1=>'1'),array('sid'=>'fixture-admin'));}catch(PhpbbAclException $e){$denied=$e->getMessage()==='Not_Authorised';}ats_check($denied&&qsnap()===$before,'Delegated user admin cannot change administrator quota');
 qreset();qsql('UPDATE fixture_users SET user_level=1 WHERE user_id=2');$userdata['user_id']=2;qsql('UPDATE fixture_sessions SET session_user_id=2');$denied=false;try{phpbb_attach_quota_assign($db,'user',1,array(1=>'1'),array('sid'=>'fixture-admin'));}catch(PhpbbAclException $e){$denied=$e->getMessage()==='Not_Authorised';}ats_check($denied,'First administrator protection applies before quota changes');
 // Actual management/default writer and quota deletion use compatible row locks.
 qreset();$defaultBlocked=false;$qhook=function($sql)use(&$defaultBlocked){if(strpos($sql,'DELETE FROM '.QUOTA_TABLE)===0){$GLOBALS['qhook']=null;if(!$GLOBALS['peer']->sql_query("UPDATE ".ATTACH_CONFIG_TABLE." SET config_value='2' WHERE config_name='default_upload_quota'")){$e=$GLOBALS['peer']->sql_error();$defaultBlocked=(int)$e['code']===1205;}}};
 ats_check(qrun($qform)==='saved'&&$defaultBlocked,'Concurrent default update waits for quota deletion');$denied=false;try{phpbb_attach_settings_save($db,array('sid'=>'fixture-admin','default_upload_quota'=>'2'),'manage');}catch(PhpbbAclException $e){$denied=$e->getMessage()==='invalid';}ats_check($denied,'Management cannot restore a deleted quota reference');
 qreset();$legacy=false;$_POST=array('sid'=>'fixture-admin');try{process_quota_settings('user',2,QUOTA_UPLOAD_LIMIT,999);}catch(AttachSettingsExit $e){$legacy=$e->getMessage()==='invalid';}ats_check($legacy,'Legacy assignment API uses guarded reference validation');
 // Execute the distinct production group-admin writer, not just its lock class.
 foreach(array('USER'=>0,'MOD'=>2,'GROUP_OPEN'=>0,'GROUP_CLOSED'=>1,'GROUP_HIDDEN'=>2,'POST_GROUPS_URL'=>'g','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth','FORUMS_TABLE'=>'fixture_forums') as $key=>$value){if(!defined($key)){define($key,$value);}}
 require $ats_source.'includes/functions_group_admin_storage.php';
 qsql('ALTER TABLE fixture_groups ADD group_type INT DEFAULT 0, ADD group_moderator INT DEFAULT 0, ADD group_description VARCHAR(255) DEFAULT NULL');
 qsql('CREATE TABLE fixture_user_group (group_id INT,user_id INT,user_pending INT) ENGINE=InnoDB');qsql('CREATE TABLE fixture_auth (group_id INT,forum_id INT,auth_mod INT) ENGINE=InnoDB');qsql('CREATE TABLE fixture_forums (forum_id INT) ENGINE=InnoDB');
 qreset();qsql('UPDATE fixture_groups SET group_moderator=2');qsql('INSERT INTO fixture_user_group VALUES (10,2,0)');
 $gpost=array('mode'=>'editgroup','g'=>'10','sid'=>'fixture-admin','group_name'=>'group','group_description'=>'description','username'=>'target','group_type'=>'0','group_upload_quota'=>'2','group_pm_quota'=>'1');
 $blockedByGroup=false;$qhook=function($sql)use(&$blockedByGroup){if(strpos($sql,'INSERT INTO '.QUOTA_TABLE)===0){$GLOBALS['qhook']=null;try{phpbb_attach_quota_save($GLOBALS['peer'],array_merge(array('sid'=>'fixture-admin'),$GLOBALS['qform']));}catch(PhpbbAclException $e){$blockedByGroup=$e->getMessage()===$GLOBALS['lang']['Attachment_storage_busy'];}}};
 ats_check(phpbb_group_admin_save($db,$gpost)==='Updated_group'&&$blockedByGroup,'Actual group save excludes concurrent quota deletion');
 ats_check(qrun($qform)==='saved','Quota deletion follows completed group assignment');$before=qsnap();$missing=false;try{phpbb_group_admin_save($db,$gpost);}catch(PhpbbGroupException $e){$missing=true;}ats_check($missing&&qsnap()===$before,'Actual group save cannot recreate deleted quota references');
 qreset();ats_check(qrun(null)==='rendered','Actual quota render');ats_check($template->blocks['limit_row'][2]['MAX_FILESIZE']==='1.5','Exact fractional display');
 qsql("UPDATE ".QUOTA_LIMITS_TABLE." SET quota_desc='<quota &>' WHERE quota_limit_id=1");ats_check(qrun(null)==='rendered'&&$template->blocks['limit_row'][2]['QUOTA_NAME']==='&lt;quota &amp;&gt;','Raw name escaped at render');
 ats_check(qrun(null,array('e_mode'=>'view_quota','quota_id'=>'999'))==='invalid','Missing viewed quota handled');
 // Exercise exact real migration planner, including idempotency and custom width.
 foreach(array('update_quote_identifier','update_query_or_fail','update_scalar','update_table_exists','update_column_max_length','update_queue_text_widths') as $function){ats_load_function($updater,$function);}
 qreset();qsql('ALTER TABLE '.QUOTA_LIMITS_TABLE." MODIFY quota_desc VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'preserve'");$before=qsnap();
 ats_check(qrun($qform)==='storage'&&qsnap()===$before,'Old width requires updater before any write');$ops=array();update_queue_text_widths($ops,$peer->db_connect_id,$fixture,QUOTA_LIMITS_TABLE,array('quota_desc'=>25));ats_check(count($ops)===1,'Legacy width migration planned');qsql($ops[0]);ats_check(qsnap()===$before,'Widening preserves every value');
 $ops=array();update_queue_text_widths($ops,$peer->db_connect_id,$fixture,QUOTA_LIMITS_TABLE,array('quota_desc'=>25));ats_check(!$ops,'Idempotent width plan');
 $meta=phpbb_acl_rows($peer,"SELECT COLUMN_COMMENT,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".QUOTA_LIMITS_TABLE."' AND COLUMN_NAME='quota_desc'");ats_check($meta[0]['COLUMN_COMMENT']==='preserve'&&$meta[0]['COLLATION_NAME']==='utf8mb4_unicode_ci','Width migration preserves metadata');
 ats_check(qrun($qform)==='saved','25 character names work after migration');qsql('ALTER TABLE '.QUOTA_LIMITS_TABLE.' MODIFY quota_desc VARCHAR(60)');$ops=array();update_queue_text_widths($ops,$peer->db_connect_id,$fixture,QUOTA_LIMITS_TABLE,array('quota_desc'=>25));ats_check(!$ops,'Custom wider names never shrunk');
 foreach(array(QUOTA_LIMITS_TABLE,QUOTA_TABLE,ATTACH_CONFIG_TABLE) as $t){qreset();qsql('ALTER TABLE '.$t.' ENGINE=MyISAM');$before=qsnap();ats_check(qrun($qform)==='storage'&&qsnap()===$before,'Reject nontransactional participant');qsql('ALTER TABLE '.$t.' ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 echo 'Native quota storage: '.$cases.' boundary/failure cases; '.$serialized." serialized revocations; assignment/render/migration checks passed\n";
}finally{
 $qhook=null;$qfailWrite=0;$qcommit='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($previous);foreach($files as $f=>$body){unlink($root.'/'.$f);}foreach(array_reverse($dirs) as $dir){rmdir($dir);}restore_error_handler();
}
