<?php
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',1);define('GENERAL_MESSAGE',2);
define('USERS_TABLE','fixture_users');define('SESSIONS_TABLE','fixture_sessions');define('JR_ADMIN_TABLE','fixture_jr');
function ats_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class AttachSettingsExit extends RuntimeException {}
function message_die($level,$message){throw new AttachSettingsExit($message);}
function append_sid($url){return $url;}
$ats_source=dirname(dirname(__DIR__)).'/phpBB2/';$table_prefix='fixture_';$phpEx='php';$phpbb_root_path=$ats_source;
require_once $ats_source.'attach_mod/includes/constants.php';require_once $ats_source.'includes/php_compat.php';
require_once $ats_source.'attach_mod/includes/functions_settings_storage.php';require_once $ats_source.'includes/functions_jr_admin.php';
require_once $ats_source.'attach_mod/includes/functions_thumbs.php';
$bootstrap=file_get_contents($ats_source.'admin/pagestart.php');$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");$b=strpos($bootstrap,'if (empty($no_page_header))',$a);
ats_check($a!==false&&$b>$a,'Actual ACP form helpers');eval(substr($bootstrap,$a,$b-$a));
// Exact production readers, without bootstrapping a real forum/configuration.
function ats_load_function($path,$name){
 $tokens=token_get_all(file_get_contents($path));$body='';$capture=false;$opened=false;$depth=0;
 for($i=0;$i<count($tokens);$i++){
  if(is_array($tokens[$i])&&$tokens[$i][0]===T_FUNCTION){$j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}if(is_array($tokens[$j])&&$tokens[$j][1]===$name){$capture=true;}}
  if(!$capture){continue;}$t=$tokens[$i];$body.=is_array($t)?$t[1]:$t;if($t==='{'){$opened=true;$depth++;}elseif($t==='}'&&--$depth===0&&$opened){break;}
 }
 ats_check($body!==''&&$depth===0,'Actual function '.$name);eval($body);
}
ats_load_function($ats_source.'includes/functions.php','phpbb_load_config_table');ats_load_function($ats_source.'attach_mod/attachment_mod.php','get_config');
$lang=array();foreach(array('lang_main','lang_admin','lang_main_attach','lang_admin_attach') as $file){require $ats_source.'language/lang_english/'.$file.'.php';}
$lang['Board_config_invalid']='invalid';$lang['Board_config_failed']='storage';$lang['Attach_config_updated']='saved';$lang['Not_Authorised']='Not_Authorised';
$basic=file_get_contents($ats_source.'install/schemas/mysql_basic.sql');preg_match_all("/INSERT INTO phpbb_attachments_config \\(config_name, config_value\\) VALUES \\('([^']+)',\\s*'([^']*)'\\);/",$basic,$matches,PREG_SET_ORDER);
$ats_defaults=array();foreach($matches as $m){$ats_defaults[$m[1]]=$m[2];}
foreach(array('manage'=>array('file'=>'attach_manage_body.tpl','extra'=>array('default_upload_quota','default_pm_quota')),'cats'=>array('file'=>'attach_cat_body.tpl','extra'=>array())) as $mode=>$info){
 $template_source=file_get_contents($ats_source.'templates/fisubsilversh/admin/'.$info['file']);preg_match_all('/\bname="([a-z0-9_]+)"/',$template_source,$names);
 $actual=array_unique(array_merge(array_diff($names[1],array('submit','settings','cat_settings','search_imagick')),$info['extra']));$actual=array_values($actual);sort($actual);
 $expected=phpbb_attach_settings_fields($mode);sort($expected);ats_check($actual===$expected,'Exact rendered control inventory '.$mode);
 $values=array_intersect_key($ats_defaults,array_flip($expected));ats_check(count($values)===count($expected),'Every control has installer default');
 ats_check(phpbb_attach_settings_values($values,$mode,$ats_defaults)===$values,'Fresh defaults validate '.$mode);
}
$ats_secret=" pass & < > \" ' \\ 😀 ";$ats_user=" user & ' \\ 😀 ";
$values=phpbb_attach_settings_values(array('ftp_user'=>addslashes($ats_user),'ftp_pass'=>addslashes($ats_secret)),'manage',$ats_defaults);
ats_check($values===array('ftp_user'=>$ats_user,'ftp_pass'=>$ats_secret),'Exact credential bytes decoded once');
ats_check(phpbb_attach_settings_values(array('ftp_path'=>'/'),'manage',$ats_defaults)['ftp_path']==='/','Preserve FTP root path');
foreach(array('0','1','262145','1572864','2147483647','52428800','9007199254740991') as $bytes){foreach(array('b','kb','mb') as $unit){
 $display=phpbb_attach_settings_display_size($bytes,$unit);$values=phpbb_attach_settings_values(array('attachment_quota'=>$display,'quota_size'=>$unit),'manage',$ats_defaults);
 ats_check($values['attachment_quota']===$bytes,'Byte-exact unit round trip '.$bytes.' '.$unit);
}}
foreach(array(array('ftp_pass'=>array('bad')),array('ftp_pass'=>addslashes("bad\r\ncommand")),array('ftp_pass'=>"\xc3"),array('ftp_pass'=>str_repeat('😀',256)),
 array('max_filesize'=>'1e3'),array('max_filesize'=>'2147483648'),array('max_filesize'=>'1','size'=>'invalid'),array('attachment_quota'=>'9007199254740992'),
 array('max_attachments'=>'1000'),array('disable_mod'=>'2'),array('attach_version'=>'forged'),array('img_max_width'=>'1'),array('mode'=>'cats','ftp_pass'=>'bad'),array('ftp_pass'=>'x','settings'=>'Test')) as $request){
 $denied=false;try{phpbb_attach_settings_values($request,'manage',$ats_defaults);}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,'Invalid full request rejected');
}
class AttachSettingsReadFixture {
 var $rows;var $position=0;
 function __construct($rows){$this->rows=array();foreach($rows as $key=>$value){$this->rows[]=array('config_name'=>$key,'config_value'=>$value);}}
 function sql_query($sql){ats_check($sql==='SELECT config_name, config_value FROM '.ATTACH_CONFIG_TABLE,'Reader only selects config');$this->position=0;return true;}
 function sql_fetchrow($r){return isset($this->rows[$this->position])?$this->rows[$this->position++]:false;}
 function sql_freeresult($r){}
}
$board_config=array('default_lang'=>'english');$db=new AttachSettingsReadFixture(array('ftp_user'=>$ats_user,'ftp_pass'=>$ats_secret,'upload_dir'=>' files '));$loaded=get_config();
ats_check($loaded['ftp_user']===$ats_user&&$loaded['ftp_pass']===$ats_secret&&$loaded['upload_dir']==='files','Actual runtime reader preserves credentials, normalizes ordinary settings');
echo "Attachment settings inventories (22 + 9), byte-exact inputs/readers/units passed\n";
if(PHP_SAPI!=='cli'||getenv('PHPBB_ATTACH_SETTINGS_NATIVE')!=='1'){return;}
require $ats_source.'db/mysqli.php';$port=getenv('PHPBB_ATTACH_SETTINGS_PORT')?:'3306';$password=getenv('PHPBB_ATTACH_SETTINGS_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback port');$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);$fixture='codex_attach_settings_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned schema');
class AttachSettingsConnection {
 var $connection;var $db_connect_id;
 function __construct($db){$this->connection=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->connection,$method),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['ats_queries'][]=$sql;if(is_callable($GLOBALS['ats_hook'])){call_user_func($GLOBALS['ats_hook'],$sql);}
  if($GLOBALS['ats_failure']!==''&&strpos($sql,$GLOBALS['ats_failure'])===0){return false;}
  $result=$this->connection->sql_query($sql,$transaction);if($sql==='COMMIT'&&$GLOBALS['ats_failure']==='commit-ack'){return false;}return $result;
 }
}
class AttachSettingsDatabase extends sql_db {function sql_dedicated_connection(){return new AttachSettingsConnection(parent::sql_dedicated_connection());}}
class AttachSettingsTemplate {
 var $vars=array();var $files=array();
 function assign_var($key,$value){$this->vars[$key]=$value;}
 function assign_vars($vars){$this->vars=array_merge($this->vars,$vars);}
 function assign_block_vars($name,$vars){}
 function set_filenames($files){$this->files=$files;}
 function pparse($name){throw new AttachSettingsExit('rendered');}
}
$db=new AttachSettingsDatabase($host,'root',$password,$fixture,false);unset($db->password);$peer=new sql_db($host,'root',$password,$fixture,false);
$ats_queries=array();$ats_hook=null;$ats_failure='';$userdata=array();$attach_config=array();
function ats_sql($sql){$result=$GLOBALS['peer']->sql_query($sql);ats_check($result,'Fixture SQL');return $result;}
function ats_snapshot(){return array('config'=>phpbb_attach_settings_read($GLOBALS['peer']),'groups'=>phpbb_acl_rows($GLOBALS['peer'],'SELECT group_id,max_filesize FROM '.EXTENSION_GROUPS_TABLE.' ORDER BY group_id'));}
function ats_reset($actor,$mode){
 global $ats_queries,$ats_hook,$ats_failure,$userdata,$attach_config;
 $ats_hook=null;$ats_failure='';$ats_queries=array();foreach(array(ATTACH_CONFIG_TABLE,EXTENSION_GROUPS_TABLE,QUOTA_LIMITS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE) as $table){ats_sql('DELETE FROM '.$table);}
 foreach($GLOBALS['ats_defaults'] as $key=>$value){ats_sql("INSERT INTO ".ATTACH_CONFIG_TABLE." VALUES ('".$key."','".$value."')");}
 ats_sql("INSERT INTO ".EXTENSION_GROUPS_TABLE." (group_id,group_name,cat_id,max_filesize) VALUES (1,'<group &>',1,262144),(2,'same',0,262144),(3,'custom',0,4096)");
 ats_sql("INSERT INTO ".QUOTA_LIMITS_TABLE." (quota_limit_id,quota_desc,quota_limit) VALUES (1,'<quota &>',1024)");
 ats_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1)');ats_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 if($actor!=='root'){$route='admin_attachments.php?mode='.($actor==='wrong'?'sync':$mode);$hash=array_search($route,jr_admin_authorization_routes(),true);ats_check($hash!==false,'Registered exact module');ats_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?1:0,'session_logged_in'=>true,'session_admin'=>true,'session_id'=>'fixture-admin');$attach_config=get_config();$_SERVER['REQUEST_METHOD']='POST';
}
function ats_run($mode,$request,$render=false){
 global $db,$lang,$phpEx,$phpbb_root_path,$userdata,$attach_config,$board_config,$template,$HTTP_POST_VARS,$HTTP_GET_VARS,$table_prefix;
 $template=new AttachSettingsTemplate();$_POST=$render?array():array_merge(array('sid'=>'fixture-admin','submit'=>'Save'),$request);$_GET=array('mode'=>$mode);$GLOBALS['HTTP_POST_VARS']=$_POST;$GLOBALS['HTTP_GET_VARS']=$_GET;
 if($render){$_SERVER['REQUEST_METHOD']='GET';}
 try{include $GLOBALS['ats_source'].'admin/admin_attachments.php';throw new RuntimeException('Controller did not stop');}catch(AttachSettingsExit $e){return $e->getMessage();}
}
function ats_boundary($sql){return strpos($sql,'UPDATE '.ATTACH_CONFIG_TABLE.' ')===0||strpos($sql,'UPDATE '.EXTENSION_GROUPS_TABLE.' ')===0||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function ats_revoke($kind){$sql=array('inactive'=>'UPDATE fixture_users SET user_active=0','role'=>'UPDATE fixture_users SET user_level=0','grant'=>'DELETE FROM fixture_jr','missing'=>'DELETE FROM fixture_sessions','admin-off'=>'UPDATE fixture_sessions SET session_admin=0','foreign'=>'UPDATE fixture_sessions SET session_user_id=99','logout'=>'UPDATE fixture_sessions SET session_logged_in=0','case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'");return $sql[$kind];}
$ats_root=sys_get_temp_dir().'/'.$fixture;$previous=getcwd();$files=array();$dirs=array();
try{
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 foreach(array('','admin','includes','attach_mod','attach_mod/includes','cache') as $dir){$path=$ats_root.($dir===''?'':'/'.$dir);mkdir($path,0700);$dirs[]=$path;}
 $files['extension.inc']="<?php \$phpEx='php';";$files['admin/pagestart.php']="<?php // Cached entry identity only; writer revalidates current authority.\n";
 foreach(array('includes/functions_admin.php','attach_mod/includes/constants.php','attach_mod/includes/functions_attach.php','attach_mod/includes/functions_selects.php','attach_mod/includes/functions_admin.php','attach_mod/includes/functions_shadow.php','attach_mod/includes/functions_settings_storage.php') as $file){$files[$file]='<?php require_once '.var_export($ats_source.$file,true).';';}
 foreach($files as $file=>$body){file_put_contents($ats_root.'/'.$file,$body);}chdir($ats_root.'/admin');
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');$ddl=array();
 foreach(array('attachments_config','extension_groups','quota_limits') as $suffix){ats_check(preg_match('/CREATE TABLE phpbb_'.$suffix.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical '.$suffix.' DDL');$ddl[$suffix]=str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]);ats_sql($ddl[$suffix]);}
 foreach(array('fixture_users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT','fixture_sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT','fixture_jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT') as $table=>$columns){ats_sql('CREATE TABLE '.$table.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}ats_sql('SET SESSION innodb_lock_wait_timeout=1');
 $cases=0;$serialized=0;
 foreach(array('cats','manage') as $mode){foreach(array('root','delegated') as $actor){
  $payload=$mode==='manage'?array('max_filesize'=>'1.5','size'=>'kb','ftp_pass'=>addslashes($ats_secret),'ftp_user'=>addslashes($ats_user)):array('img_display_inlined'=>'0','img_max_width'=>'640');
  ats_reset($actor,$mode);$outcome=ats_run($mode,$payload);ats_check(strpos($outcome,'saved')===0,'Actual save '.$actor.' '.$mode.': '.$outcome.' queries='.count($ats_queries));$after=ats_snapshot();$boundaries=array_values(array_filter($ats_queries,'ats_boundary'));
  if($mode==='manage'){$loaded=get_config();ats_check($after['config']['ftp_pass']===$ats_secret&&$loaded['ftp_pass']===$ats_secret&&$loaded['ftp_user']===$ats_user,'Save-to-runtime exact credentials');ats_check($after['groups'][0]['max_filesize']==1536&&$after['groups'][1]['max_filesize']==1536&&$after['groups'][2]['max_filesize']==4096,'Matching limits change, custom group preserved');}
  else{ats_check($after['config']['img_max_width']==='640'&&$after['config']['ftp_pass']==='','Cats only changes image controls');}
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){ats_reset($actor,$mode);ats_sql(ats_revoke($kind));$before=ats_snapshot();ats_check(ats_run($mode,$payload)==='Not_Authorised'&&ats_snapshot()===$before,'Entry revocation preserves both tables');$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($boundaries);$boundary++){
   ats_reset($actor,$mode);$before=ats_snapshot();$seen=0;$reached=false;$blocked=false;
   $ats_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!ats_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['ats_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(ats_revoke($kind))){$error=$GLOBALS['peer']->sql_error();ats_check((int)$error['code']===1205,'Only real lock timeout serializes');$blocked=true;}};
   $outcome=ats_run($mode,$payload);ats_check($reached,'Every write/commit boundary exercised');
   if($blocked){ats_check(strpos($outcome,'saved')===0,'Save commits before serialized revocation');ats_sql(ats_revoke($kind));$serialized++;}
   else{ats_check($outcome==='Not_Authorised'&&ats_snapshot()===$before,'Revocation rolls back config and matching limits');}
   ats_check(ats_run($mode,$payload)==='Not_Authorised','Following request denied');$cases++;
  }}
  foreach(array('COMMIT','commit-ack') as $failure){ats_reset($actor,$mode);$before=ats_snapshot();$ats_failure=$failure;ats_check(ats_run($mode,$payload)==='storage','Commit uncertainty reports error');ats_check($failure==='COMMIT'?ats_snapshot()===$before:ats_snapshot()!==$before,'Known rollback versus lost acknowledgement');$cases++;}
  ats_reset($actor,$mode);$before=ats_snapshot();$ats_failure='UPDATE '.ATTACH_CONFIG_TABLE." SET config_value='".($mode==='manage'?'1536':'640')."'";
  ats_check(ats_run($mode,$payload)==='storage'&&ats_snapshot()===$before,'Failure after prior writes rolls back both tables');$cases++;
  foreach(array(array_merge($payload,array('attach_version'=>'forged')),array_merge($payload,array($mode==='manage'?'ftp_pass':'img_max_width'=>array('bad'))),array_merge($payload,array($mode==='manage'?'img_max_width':'ftp_pass'=>'bad')),array_merge($payload,array('sid'=>'wrong'))) as $request){ats_reset($actor,$mode);$before=ats_snapshot();ats_check(strpos(ats_run($mode,$request),'saved')!==0&&ats_snapshot()===$before,'Whole invalid form rejected');ats_check(!array_filter($ats_queries,function($sql){return preg_match('/^(UPDATE|INSERT|DELETE|COMMIT)\b/',$sql);}), 'No writes before whole-request validation');$cases++;}
  echo $actor.' '.$mode." actual controller/native authority passed\n";
 }}
 foreach(array('manage','cats') as $mode){ats_reset('wrong',$mode);$before=ats_snapshot();ats_check(ats_run($mode,$mode==='manage'?array('ftp_pass'=>'x'):array('img_max_width'=>'1'))==='Not_Authorised'&&ats_snapshot()===$before,'Other attachment route cannot authorize settings');}
 ats_reset('root','manage');$before=ats_snapshot();ats_check(ats_run('manage',array('default_upload_quota'=>'2'))==='invalid'&&ats_snapshot()===$before,'Missing quota reference rejected');
 ats_check(strpos(ats_run('manage',array('default_upload_quota'=>'1')),'saved')===0,'Existing quota reference accepted');
 ats_reset('root','manage');$ats_hook=function($sql){if(strpos($sql,'UPDATE '.ATTACH_CONFIG_TABLE.' ')===0){$GLOBALS['ats_hook']=null;ats_sql("UPDATE ".ATTACH_CONFIG_TABLE." SET config_value='concurrent' WHERE config_name='attach_version'");}};
 ats_check(strpos(ats_run('manage',array('ftp_pass'=>addslashes($ats_secret))),'saved')===0&&ats_snapshot()['config']['attach_version']==='concurrent','Unsubmitted concurrent config preserved');
 foreach(array('manage','cats') as $mode){ats_reset('root',$mode);$full=array_intersect_key($ats_defaults,array_flip(phpbb_attach_settings_fields($mode)));ats_check(strpos(ats_run($mode,$full),'saved')===0,'Complete actual form saves');}
 ats_reset('root','manage');ats_sql("UPDATE ".ATTACH_CONFIG_TABLE." SET config_value='".$peer->sql_escape($ats_secret)."' WHERE config_name='ftp_pass'");
 ats_check(ats_run('manage',array(),true)==='rendered','Actual manage rendering');ats_check($template->vars['FTP_PASS']===phpbb_admin_html($ats_secret),'Stored credential escaped only in HTML output');
 ats_check(strpos($template->vars['S_DEFAULT_UPLOAD_LIMIT'],'&lt;quota &amp;&gt;')!==false,'Quota description is escaped in generated select');
 ats_check(ats_run('cats',array(),true)==='rendered'&&$template->vars['S_ASSIGNED_GROUP_IMAGES']==='&lt;group &amp;&gt;','Image group names escaped');
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1','MODIFY config_value VARCHAR(255) CHARACTER SET latin1 NOT NULL') as $legacy){ats_reset('root','manage');ats_sql('ALTER TABLE '.ATTACH_CONFIG_TABLE.' '.$legacy);$before=ats_snapshot();ats_check(ats_run('manage',array('ftp_pass'=>'after'))==='storage'&&ats_snapshot()===$before,'Reject legacy storage');ats_sql('DROP TABLE '.ATTACH_CONFIG_TABLE);ats_sql($ddl['attachments_config']);}
 ats_reset('root','manage');$ats_hook=function($sql){if($sql==='START TRANSACTION'){$GLOBALS['ats_hook']=null;ats_sql("DELETE FROM ".ATTACH_CONFIG_TABLE." WHERE config_name='ftp_pass'");}};
 ats_check(ats_run('manage',array('ftp_pass'=>'after'))==='storage','Missing config row during transaction rejected');
 echo 'Native attachment settings: '.$cases.' cases; '.$serialized." serialized before revocation; render/readback checks passed\n";
}finally{
 $ats_hook=null;$ats_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($previous);
 foreach($files as $file=>$body){unlink($ats_root.'/'.$file);}foreach(array_reverse($dirs) as $dir){rmdir($dir);}restore_error_handler();
}
