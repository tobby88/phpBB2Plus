<?php
// Classify explicitly reviewed historical guest bylines without erasing them.
if(PHP_SAPI!=='cli'){http_response_code(404);exit(2);}
$options=getopt('',array('config:','name:','apply','backup-confirmed','maintenance-confirmed','help'));
if($options===false||isset($options['help'])){echo "Usage: php classify_removed_authors.php --name=FormerName [--name=AnotherName] [--config=/path/config.php] [--apply --backup-confirmed --maintenance-confirmed]\nPreview is the default. Run update_from_153a.php before classification.\n";exit($options===false?2:0);}
if(!isset($options['name'])){fwrite(STDERR,"Pass each explicitly reviewed former name with --name. Unclassified guests stay unchanged.\n");exit(2);}
$base=dirname(__DIR__);$phpbb_root_path=is_file($base.'/phpBB2/includes/php_compat.php')?$base.'/phpBB2/':$base.'/';$phpEx='php';
$config=isset($options['config'])?$options['config']:$phpbb_root_path.'config.php';
if(!is_string($config)||!is_file($config)){fwrite(STDERR,"One existing installation config.php is required.\n");exit(2);}
define('IN_PHPBB',true);require$phpbb_root_path.'includes/php_compat.php';require$config;
foreach(array('dbhost','dbuser','dbpasswd','dbname','table_prefix')as$key){if(!isset($$key)||!is_string($$key)){fwrite(STDERR,"Installation configuration is incomplete.\n");exit(2);}}
if(!preg_match('/^[A-Za-z0-9_]{1,40}$/D',$table_prefix)||$dbname===''||(isset($dbms)&&!in_array($dbms,array('mysql','mysql4','mysqli'),true))){fwrite(STDERR,"A configured MySQL/MariaDB installation is required.\n");exit(2);}
require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'attach_mod/includes/constants.php';require_once$phpbb_root_path.'includes/functions_post_author.php';require$phpbb_root_path.'db/mysqli.php';require$phpbb_root_path.'attach_mod/includes/functions_mutation.php';
$lang=array('Attachment_storage_busy'=>'Stop other writers and retry.','Author_privacy_unavailable'=>'Run update_from_153a.php before classification.');
function message_die($level,$message){throw new RuntimeException($message);}
$names=is_array($options['name'])?$options['name']:array($options['name']);$names=array_values(array_unique($names));
if(count($names)>50){fwrite(STDERR,"Classify at most 50 reviewed names per invocation.\n");exit(2);}
foreach($names as&$name){$name=phpbb_post_author_input($name);if($name===null||$name===''){fwrite(STDERR,"A reviewed name is invalid.\n");exit(2);}}unset($name);
$apply=isset($options['apply']);if($apply&&(!isset($options['backup-confirmed'])||!isset($options['maintenance-confirmed']))){fwrite(STDERR,"Stop all web/cron writers and confirm a fresh database backup before --apply.\n");exit(2);}
function former_cli_query($db,$sql){$r=$db->sql_query($sql);if(!$r){throw new RuntimeException('Classification storage operation failed. Check the consolidated update and database state.');}return$r;}
function former_cli_scalar($db,$sql){$r=former_cli_query($db,$sql);try{$row=$db->sql_fetchrow($r);return(int)reset($row);}finally{$db->sql_freeresult($r);}}
function former_cli_names($name){$keys=array_merge(array($name),phpbb_post_author_keys($name));$out=array();foreach(array_unique($keys)as$key){$out[]="'".strtoupper(bin2hex($key))."'";}return implode(',',$out);}
function former_cli_plan($db,$names){$out=array();foreach($names as$name){$keys=former_cli_names($name);$out[]=array('name'=>$name,'posts'=>former_cli_scalar($db,'SELECT COUNT(*) FROM '.POSTS_TABLE.' WHERE poster_id=-1 AND HEX(post_username) IN('.$keys.')'),'unclassified_posts'=>former_cli_scalar($db,'SELECT COUNT(*) FROM '.POSTS_TABLE.' WHERE poster_id=-1 AND post_author_removed=0 AND HEX(post_username) IN('.$keys.')'),'shouts'=>former_cli_scalar($db,'SELECT COUNT(*) FROM '.SHOUTBOX_TABLE.' WHERE shout_user_id=-1 AND HEX(shout_username) IN('.$keys.')'),'unclassified_shouts'=>former_cli_scalar($db,'SELECT COUNT(*) FROM '.SHOUTBOX_TABLE.' WHERE shout_user_id=-1 AND shout_author_removed=0 AND HEX(shout_username) IN('.$keys.')'),'current_named_accounts'=>former_cli_scalar($db,'SELECT COUNT(*) FROM '.USERS_TABLE.' WHERE HEX(username) IN('.$keys.')'));}return$out;}
$source=$db=$lock=null;$transaction=false;$status=0;
try{
 $source=new sql_db($dbhost,$dbuser,$dbpasswd,$dbname,false);if(!$source->db_connect_id){throw new RuntimeException('Installation database unavailable.');}$db=$source;
 if($apply){$lock=new attach_mutation_lock($source);if(!$lock->acquired){throw new RuntimeException('Forum writer is busy.');}$db=$lock->connection;former_cli_query($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');former_cli_query($db,"SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");former_cli_query($db,'SET SESSION innodb_lock_wait_timeout=30');former_cli_query($db,'START TRANSACTION');$transaction=true;}
 phpbb_removed_author_storage($db);$before=former_cli_plan($db,$names);
 if($apply){
  foreach($before as$item){if($item['current_named_accounts']){throw new RuntimeException('A reviewed name also belongs to a current account; resolve that ambiguity separately before classification.');}}
  foreach($names as$name){phpbb_record_removed_author_name($db,0,$name,'1=1');$keys=former_cli_names($name);former_cli_query($db,'UPDATE '.POSTS_TABLE.' SET post_author_removed=1 WHERE poster_id=-1 AND HEX(post_username) IN('.$keys.')');former_cli_query($db,'UPDATE '.SHOUTBOX_TABLE.' SET shout_author_removed=1 WHERE shout_user_id=-1 AND HEX(shout_username) IN('.$keys.')');}
  $after=former_cli_plan($db,$names);foreach($after as$i=>$item){if($item['posts']!==$before[$i]['posts']||$item['shouts']!==$before[$i]['shouts']||$item['unclassified_posts']||$item['unclassified_shouts']){throw new RuntimeException('Classification readback differs; transaction will be rolled back.');}}
  former_cli_query($db,'COMMIT');$transaction=false;
 }
 echo json_encode(array('mode'=>$apply?'applied':'preview','names'=>$before,'original_names_and_content'=>'PRESERVED','logged_in_display'=>'UNCHANGED','guest_label'=>'Former member')),"\n";
}catch(Exception$error){fwrite(STDERR,$error->getMessage()."\n");$status=3;}catch(Error$error){fwrite(STDERR,"Classification could not complete. Verify the update, run preview, and inspect database state before retry.\n");$status=3;}
finally{if($transaction&&$db){$db->sql_query('ROLLBACK');}if($lock){$lock->release();}if($source){$source->sql_close();}}
exit($status);
