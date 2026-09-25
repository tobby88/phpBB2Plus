<?php
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_MESSAGE',1);define('GENERAL_ERROR',2);define('CRITICAL_ERROR',3);define('END_TRANSACTION',2);
define('CONFIG_TABLE','fixture_config');define('USERS_TABLE','fixture_users');define('SESSIONS_TABLE','fixture_sessions');define('JR_ADMIN_TABLE','fixture_jr');
define('CTRACKER_BACKUP','fixture_backup');define('CTRACKER_CONFIG','fixture_ct_config');
define('THEMES_TABLE','fixture_themes');
class BoardConfigExit extends RuntimeException {}
function message_die($level,$message){throw new BoardConfigExit($message);}
function bc_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function append_sid($url){return $url;}
$bc_source=dirname(dirname(__DIR__)).'/phpBB2/';
require $bc_source.'includes/php_compat.php';
require $bc_source.'includes/functions_board_config.php';
require $bc_source.'includes/functions_jr_admin.php';
// Real POST/SID and decoding helpers; cached bootstrap identity is supplied by
// each fixture. No production controller/SQL code is rewritten or extracted.
$bootstrap=file_get_contents($bc_source.'admin/pagestart.php');
$a=strpos($bootstrap,"if (!function_exists('phpbb_admin_post_session_valid'))");
$b=strpos($bootstrap,'if (empty($no_page_header))',$a);
bc_check($a!==false&&$b>$a,'Actual ACP form helpers');eval(substr($bootstrap,$a,$b-$a));
$phpEx='php';$lang=array('Board_config_invalid'=>'invalid','Board_config_failed'=>'storage','Password_minimum_invalid'=>'password',
 'Config_updated'=>'saved','Click_return_config'=>'%sconfig%s','Click_return_admin_index'=>'%sadmin%s',
 'ctracker_error_database_op'=>'database','ctracker_error_loading_config'=>'load','ctracker_recovery_busy'=>'busy',
 'ctracker_error_storage_migration'=>'migration','ctracker_rec_empty_source'=>'empty','ctracker_gmb_pu_1'=>'port','ctracker_gmb_pu_2'=>'session length');
$ctracker_config=(object)array('settings'=>array('auto_recovery'=>0,'detect_misconfiguration'=>0));
$template=file_get_contents($bc_source.'templates/fisubsilversh/admin/board_config_body.tpl');
preg_match_all('/\bname="([a-z0-9_]+)"/',$template,$m);
$rendered=array_unique(array_merge(array_diff($m[1],array('submit')),array('default_style','default_lang','default_dateformat','board_timezone','report_forum')));
$fields=phpbb_board_config_fields();sort($fields);sort($rendered);
bc_check($fields===array_values($rendered),'Exact editable-field inventory matches template and generated selects');
$current=array_fill_keys($fields,'0');$current['server_name']='forum.example';$current['server_port']='443';$current['script_path']='/';
$values=phpbb_board_config_values(array('site_desc'=>addslashes("Änderung ' \\ 😀"),'smtp_password'=>addslashes(" p'\\<&😀 ")),$current);
bc_check($values===array('site_desc'=>"Änderung ' \\ 😀",'smtp_password'=>" p'\\<&😀 "),'UTF-8 and exact one-time decoding, including SMTP secret');
$values=phpbb_board_config_values(array('server_name'=>'https://EXAMPLE.org','server_port'=>'443','script_path'=>'forum','cookie_name'=>'a.b'),$current);
bc_check($values['server_name']==='example.org'&&$values['server_port']==='443'&&$values['script_path']==='/forum/'&&$values['cookie_name']==='a_b','Existing host/port/path/cookie normalization');
foreach(array(array('version'=>'forged'),array('site_desc'=>array('bad')),array('site_desc'=>str_repeat('x',256)),array('site_desc'=>"\xc3"),
 array('site_desc'=>addslashes("a\0b")),array('min_password_len'=>'73'),array('posts_per_page'=>'0'),array('allow_html'=>'2'),array('board_timezone'=>'99')) as $request){
 $denied=false;try{phpbb_board_config_values($request,$current);}catch(PhpbbAclException $e){$denied=true;}bc_check($denied,'Invalid complete request rejected');
}
echo "Board configuration field inventory and input checks passed\n";
class BoardConfigFailingFactory {function sql_dedicated_connection(){throw new RuntimeException('Fixture connection failure');}}
$denied=false;try{new PhpbbBoardConfigWriter(new BoardConfigFailingFactory());}catch(PhpbbAclException $e){$denied=$e->getMessage()==='storage';}
bc_check($denied,'Connection factory failure is a controlled configuration error');
if(PHP_SAPI!=='cli'||getenv('PHPBB_BOARD_CONFIG_NATIVE')!=='1'){return;}
require $bc_source.'db/mysqli.php';
$port=getenv('PHPBB_BOARD_CONFIG_PORT')?:'3306';$password=getenv('PHPBB_BOARD_CONFIG_PASSWORD')?:'';
bc_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);
$fixture='codex_board_config_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
bc_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Own native schema');
class BoardConfigConnection {
 var $connection;var $db_connect_id;
 function __construct($db){$this->connection=$db;$this->db_connect_id=$db->db_connect_id;}
 function __call($method,$args){return call_user_func_array(array($this->connection,$method),$args);}
 function sql_query($sql,$transaction=false){
  $GLOBALS['bc_queries'][]=$sql;
  if(is_callable($GLOBALS['bc_hook'])){call_user_func($GLOBALS['bc_hook'],$sql);}
  if($GLOBALS['bc_failure']!==''&&strpos($sql,$GLOBALS['bc_failure'])===0){return false;}
  $result=$this->connection->sql_query($sql,$transaction);
  if($sql==='COMMIT'&&$GLOBALS['bc_failure']==='commit-ack'){return false;}
  return $result;
 }
}
class BoardConfigDatabase extends sql_db {function sql_dedicated_connection(){return new BoardConfigConnection(parent::sql_dedicated_connection());}}
$db=new BoardConfigDatabase($host,'root',$password,$fixture,false);unset($db->password);
$peer=new sql_db($host,'root',$password,$fixture,false);
$bc_queries=array();$bc_hook=null;$bc_failure='';$board_config=array();
function bc_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);bc_check($r,'Fixture SQL');return $r;}
function bc_snapshot(){return phpbb_board_config_read($GLOBALS['peer']);}
function bc_reset($actor){
 global $userdata,$bc_hook,$bc_queries,$bc_failure,$ctracker_config;
 $bc_hook=null;$bc_queries=array();$bc_failure='';$ctracker_config->settings=array('auto_recovery'=>0,'detect_misconfiguration'=>0);
 foreach(array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,CTRACKER_BACKUP,THEMES_TABLE) as $table){bc_sql('DELETE FROM '.$table);}
 bc_sql("INSERT INTO fixture_themes (themes_id,template_name,style_name,theme_public) VALUES (1,'fisubsilversh','Default',1),(2,'fisubsilversh','Private',0),(3,'retired','Old template',1),(4,'fisubsilversh','Invalid flag',2)");
 foreach(phpbb_board_config_fields() as $key){bc_sql("INSERT INTO fixture_config VALUES ('".$key."','0')");}
 bc_sql("UPDATE fixture_config SET config_value='20' WHERE config_name IN ('posts_per_page','topics_per_page')");
 bc_sql("UPDATE fixture_config SET config_value='1' WHERE config_name='default_style'");
 bc_sql("UPDATE fixture_config SET config_value='3600' WHERE config_name='session_length'");
 bc_sql("UPDATE fixture_config SET config_value='before' WHERE config_name='site_desc'");
 bc_sql("UPDATE fixture_config SET config_value='forum.example' WHERE config_name='server_name'");
 bc_sql("UPDATE fixture_config SET config_value='443' WHERE config_name='server_port'");
 bc_sql("UPDATE fixture_config SET config_value='/' WHERE config_name='script_path'");
 bc_sql("INSERT INTO fixture_config VALUES ('version','.0.23'),('dbmtnc_rebuild_pos','1'),('ct_last_backup','123')");
 bc_sql('INSERT INTO fixture_users VALUES (1,'.($actor==='root'?1:0).',1)');
 bc_sql("INSERT INTO fixture_sessions VALUES ('fixture-admin',1,1,1)");
 if($actor==='delegated'){$hash=array_search('admin_board.php',jr_admin_authorization_routes(),true);bc_check($hash!==false,'Registered board module');bc_sql("INSERT INTO fixture_jr VALUES (1,'".$hash."')");}
 $userdata=array('user_id'=>1,'user_level'=>$actor==='root'?1:0,'session_id'=>'fixture-admin','session_logged_in'=>1,'session_admin'=>1);
 $_SERVER['REQUEST_METHOD']='POST';
}
function bc_run($request){
 global $db,$lang,$phpEx,$phpbb_root_path,$userdata,$ctracker_config,$board_config;
 $_POST=array_merge(array('sid'=>'fixture-admin','submit'=>'Save'),$request);
 try{include $GLOBALS['bc_source'].'admin/admin_board.php';throw new RuntimeException('Controller did not terminate');}
 catch(BoardConfigExit $e){return $e->getMessage();}
}
function bc_boundary($sql){return strpos($sql,'UPDATE fixture_config ')===0||$sql==='COMMIT'||strpos($sql,' LOCK IN SHARE MODE')!==false;}
function bc_revocation($kind){
 $sql=array('inactive'=>'UPDATE fixture_users SET user_active=0','role'=>'UPDATE fixture_users SET user_level=0','grant'=>'DELETE FROM fixture_jr',
 'missing'=>'DELETE FROM fixture_sessions','admin-off'=>'UPDATE fixture_sessions SET session_admin=0','foreign'=>'UPDATE fixture_sessions SET session_user_id=99',
 'logout'=>'UPDATE fixture_sessions SET session_logged_in=0','case'=>"UPDATE fixture_sessions SET session_id='FIXTURE-ADMIN'");return $sql[$kind];
}
$bc_root=sys_get_temp_dir().'/'.$fixture;$bc_previous=getcwd();$bc_files=array();$bc_dirs=array();
function bc_fixture_file($name,$body){$path=$GLOBALS['bc_root'].'/'.$name;file_put_contents($path,$body);$GLOBALS['bc_files'][]=$path;}
try{
 set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
 foreach(array('','admin','includes','ctracker','ctracker/classes','cache','images','images/avatars') as $dir){$path=$bc_root.($dir===''?'':'/'.$dir);mkdir($path,0700);$bc_dirs[]=$path;}
 bc_fixture_file('extension.inc',"<?php \$phpEx='php';");
 bc_fixture_file('admin/pagestart.php',"<?php // Native fixture supplies the request-entry identity; writer must revalidate it.\n");
 foreach(array('includes/functions_selects.php','includes/functions_board_config.php','ctracker/classes/class_ct_adminfunctions.php') as $file){bc_fixture_file($file,'<?php require_once '.var_export($bc_source.$file,true).';');}
 chdir($bc_root.'/admin');
 $schema=file_get_contents($bc_source.'install/schemas/mysql_schema.sql');bc_check(preg_match('/CREATE TABLE `?phpbb_config`?\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical configuration schema');
 bc_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));bc_sql('CREATE TABLE fixture_backup LIKE fixture_config');
 bc_check(preg_match('/CREATE TABLE phpbb_themes\s*\([\s\S]*?;/',$schema,$theme_definition)===1,'Canonical theme schema');bc_sql(str_replace('phpbb_themes',THEMES_TABLE,$theme_definition[0]));
 foreach(array('fixture_users'=>'user_id INT PRIMARY KEY,user_level INT,user_active INT','fixture_sessions'=>'session_id VARCHAR(32) PRIMARY KEY,session_user_id INT,session_logged_in INT,session_admin INT','fixture_jr'=>'user_id INT PRIMARY KEY,user_jr_admin TEXT') as $table=>$columns){bc_sql('CREATE TABLE '.$table.' ('.$columns.') ENGINE=InnoDB ROW_FORMAT=DYNAMIC');}
 bc_sql('SET SESSION innodb_lock_wait_timeout=1');
 $cases=0;$serialized=0;
 foreach(array('root','delegated') as $actor){
  bc_reset($actor);$before=bc_snapshot();$outcome=bc_run(array('site_desc'=>addslashes("Änderung ' \\ 😀"),'smtp_password'=>addslashes(" p'\\<&😀 ")));
  bc_check(strpos($outcome,'saved')===0,'Actual controller succeeds for '.$actor);$after=bc_snapshot();
  bc_check($after['site_desc']==="Änderung ' \\ 😀"&&$after['smtp_password']===" p'\\<&😀 "&&$after['version']===$before['version'],'Exact values and internal metadata');
  $writes=array_values(array_filter($bc_queries,'bc_boundary'));
  foreach(array('inactive','missing','admin-off','foreign','logout','case',$actor==='root'?'role':'grant') as $kind){bc_reset($actor);bc_sql(bc_revocation($kind));$before=bc_snapshot();bc_check(bc_run(array('site_desc'=>'after'))==='Not_Authorised'&&bc_snapshot()===$before,'Entry revocation leaves config intact');$cases++;}
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($writes);$boundary++){
   bc_reset($actor);$before=bc_snapshot();$seen=0;$reached=false;$blocked=false;
   $bc_hook=function($sql)use($boundary,$kind,&$seen,&$reached,&$blocked){if(!bc_boundary($sql)||++$seen!==$boundary){return;}$GLOBALS['bc_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(bc_revocation($kind))){$error=$GLOBALS['peer']->sql_error();bc_check((int)$error['code']===1205,'Only real lock timeout serializes');$blocked=true;}};
   $outcome=bc_run(array('site_desc'=>'after','smtp_password'=>'new'));bc_check($reached,'Every boundary exercised');
   if($blocked){bc_check(strpos($outcome,'saved')===0,'Save precedes serialized revocation');bc_sql(bc_revocation($kind));$serialized++;}
   else{bc_check($outcome==='Not_Authorised'&&bc_snapshot()===$before,'Revocation rolls back complete save: '.$actor.' '.$kind.' #'.$boundary.' '.$outcome);}
   bc_check(bc_run(array('site_desc'=>'forbidden'))==='Not_Authorised','Following request denied');$cases++;
  }}
  foreach(array(array('site_desc'=>'after','version'=>'forged'),array('site_desc'=>'after','smtp_password'=>str_repeat('x',256)),array('site_desc'=>array('bad')),array('site_desc'=>'after','sid'=>array('bad'))) as $bad){bc_reset($actor);$before=bc_snapshot();$ctracker_config->settings['auto_recovery']=1;$outcome=bc_run($bad);bc_check(strpos($outcome,'saved')!==0&&bc_snapshot()===$before,'Invalid full form leaves data intact');bc_check(!array_filter($bc_queries,function($sql){return preg_match('/^(INSERT|UPDATE|DELETE|CREATE|DROP|COMMIT)\b/',$sql);}), 'No config or automatic backup writes before complete validation');$cases++;}
  bc_reset($actor);$before=bc_snapshot();$bc_failure="UPDATE fixture_config SET config_value='new'";
  bc_check(bc_run(array('site_desc'=>'after','smtp_password'=>'new'))==='storage'&&bc_snapshot()===$before,'Second write failure rolls back first');$cases++;
  bc_reset($actor);$bc_hook=function($sql){if(strpos($sql,'UPDATE fixture_config ')===0){$GLOBALS['bc_hook']=null;bc_sql("UPDATE fixture_config SET config_value='concurrent' WHERE config_name IN ('version','dbmtnc_rebuild_pos')");}};
  bc_check(strpos(bc_run(array('site_desc'=>'after')),'saved')===0,'Concurrent metadata save succeeds');$after=bc_snapshot();bc_check($after['version']==='concurrent'&&$after['dbmtnc_rebuild_pos']==='concurrent','Unsubmitted internal concurrent values preserved');$cases++;
  bc_reset($actor);$ctracker_config->settings['auto_recovery']=1;
  bc_check(strpos(bc_run(array('site_desc'=>'after')),'saved')===0,'Automatic backup with exact board grant');$rows=$peer->sql_fetchrowset(bc_sql("SELECT config_value FROM fixture_backup WHERE config_name='site_desc'"));bc_check($rows[0]['config_value']==='before'&&bc_snapshot()['site_desc']==='after','Backup precedes new configuration');$cases++;
  bc_reset($actor);$before=bc_snapshot();$bc_failure='COMMIT';bc_check(bc_run(array('site_desc'=>'after'))==='storage'&&bc_snapshot()===$before,'Commit failure rolls back');$cases++;
  bc_reset($actor);$bc_failure='commit-ack';bc_check(bc_run(array('site_desc'=>'after'))==='storage'&&bc_snapshot()['site_desc']==='after','Lost acknowledgement is not reported as successful or as a rollback');$cases++;
  echo $actor." actual configuration controller/native authority passed\n";
 }
 bc_reset('root');$full=bc_snapshot();foreach(array('version','dbmtnc_rebuild_pos','ct_last_backup') as $key){unset($full[$key]);}
 $full['avatar_path']='images/avatars';bc_check(strpos(bc_run(array_map('addslashes',$full)),'saved')===0,'Complete rendered form supported');
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1','MODIFY config_value VARCHAR(255) CHARACTER SET latin1 NOT NULL') as $legacy){
  bc_reset('root');bc_sql('ALTER TABLE fixture_config '.$legacy);$before=bc_snapshot();bc_check(bc_run(array('site_desc'=>'after'))==='storage'&&bc_snapshot()===$before,'Legacy table/column cannot bypass migration requirements');
  bc_sql('DROP TABLE fixture_config');bc_sql(str_replace('phpbb_config',CONFIG_TABLE,$m[0]));
 }
 bc_reset('root');bc_sql("DELETE FROM fixture_config WHERE config_name='site_desc'");$before=bc_snapshot();bc_check(bc_run(array('site_desc'=>'after'))==='storage'&&bc_snapshot()===$before,'Missing controls require migration, not implicit creation');
 foreach(array('root','delegated') as $actor){
  foreach(array('3','4','999','16777216') as $invalid){bc_reset($actor);$before=bc_snapshot();$ctracker_config->settings['auto_recovery']=1;
   bc_check(bc_run(array('default_style'=>$invalid,'site_desc'=>'after'))==='invalid'&&bc_snapshot()===$before,'Invalid default rejected before partial settings save');
   bc_check(!array_filter($bc_queries,function($sql){return preg_match('/^(INSERT|UPDATE|DELETE|CREATE|DROP|COMMIT)\b/',$sql);}), 'Invalid style does not refresh automatic backup');$cases++;
  }
  bc_reset($actor);bc_check(strpos(bc_run(array('default_style'=>'0002','site_desc'=>'after')),'saved')===0,'Private standard style can become default atomically');
  $theme=phpbb_acl_rows($peer,'SELECT theme_public FROM fixture_themes WHERE themes_id=2');bc_check($theme[0]['theme_public']==='1'&&bc_snapshot()['default_style']==='2'&&$board_config['default_style']==='2','Canonical ID and public default persisted');$cases++;
  foreach(array('UPDATE fixture_themes ','COMMIT','commit-ack') as $failure){bc_reset($actor);$before=bc_snapshot();$bc_failure=$failure;
   file_put_contents($bc_root.'/cache/config_data.cache','old');file_put_contents($bc_root.'/cache/themes.cache','old');
   bc_check(bc_run(array('default_style'=>'2','site_desc'=>'after'))==='storage','Style/config failure reported');$theme=phpbb_acl_rows($peer,'SELECT theme_public FROM fixture_themes WHERE themes_id=2');
   bc_check($failure==='commit-ack'?(bc_snapshot()['default_style']==='2'&&$theme[0]['theme_public']==='1'):(bc_snapshot()===$before&&$theme[0]['theme_public']==='0'),'Both rows commit or rollback together');
   bc_check(!is_file($bc_root.'/cache/config_data.cache')&&!is_file($bc_root.'/cache/themes.cache'),'Both caches invalidated on failed/uncertain writes');$cases++;
  }
  bc_reset($actor);bc_run(array('default_style'=>'2'));$writes=array_values(array_filter($bc_queries,function($sql){return bc_boundary($sql)||strpos($sql,'UPDATE fixture_themes ')===0;}));
  foreach(array('missing',$actor==='root'?'role':'grant') as $kind){for($boundary=1;$boundary<=count($writes);$boundary++){
   bc_reset($actor);$before=bc_snapshot();$seen=0;$blocked=false;$reached=false;
   $bc_hook=function($sql)use($boundary,$kind,&$seen,&$blocked,&$reached){if(!(bc_boundary($sql)||strpos($sql,'UPDATE fixture_themes ')===0)||++$seen!==$boundary){return;}$GLOBALS['bc_hook']=null;$reached=true;if(!$GLOBALS['peer']->sql_query(bc_revocation($kind))){$e=$GLOBALS['peer']->sql_error();bc_check((int)$e['code']===1205,'Native authority lock timeout only');$blocked=true;}};
   $result=bc_run(array('default_style'=>'2'));$theme=phpbb_acl_rows($peer,'SELECT theme_public FROM fixture_themes WHERE themes_id=2');bc_check($reached,'Every coupled-style write/commit boundary visited');
   bc_check($blocked?(strpos($result,'saved')===0&&$theme[0]['theme_public']==='1'):(strpos($result,'saved')!==0&&bc_snapshot()===$before&&$theme[0]['theme_public']==='0'),'Revocation cannot split default and visibility');$cases++;
  }}
 }
 foreach(array('config_data.cache','themes.cache') as $blocked){bc_reset('root');mkdir($bc_root.'/cache/'.$blocked);
  try{bc_check(bc_run(array('default_style'=>'2'))==='storage','Cache failure not falsely successful');}finally{rmdir($bc_root.'/cache/'.$blocked);}
  file_put_contents($bc_root.'/cache/'.$blocked,'stale');bc_check(strpos(bc_run(array('default_style'=>'2')),'saved')===0&&!is_file($bc_root.'/cache/'.$blocked),'No-op retry repairs remaining cache');$cases++;
 }
 foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET latin1') as $legacy){bc_reset('root');bc_sql('ALTER TABLE fixture_themes '.$legacy);$before=bc_snapshot();bc_check(bc_run(array('default_style'=>'2'))==='storage'&&bc_snapshot()===$before,'Theme storage validated before coupled save');bc_sql('ALTER TABLE fixture_themes ENGINE=InnoDB ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;}
 echo 'Native board configuration checks: '.$cases.' cases; '.$serialized." serialized before revocation\n";
}finally{
 $bc_hook=null;$bc_failure='';$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();chdir($bc_previous);
 foreach(array('config_data.cache','themes.cache') as $file){if(is_file($bc_root.'/cache/'.$file)){unlink($bc_root.'/cache/'.$file);}}
 foreach(array_reverse($bc_files) as $file){unlink($file);}foreach(array_reverse($bc_dirs) as $dir){rmdir($dir);}
 restore_error_handler();
}
