<?php
// Exercise production guards, not a SQL-rewriting approximation of a restore.
$root = dirname(dirname(__DIR__));
function policy_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class PolicyExit extends RuntimeException {}
function message_die($level, $message) { throw new PolicyExit($message); }
define('GENERAL_MESSAGE', 1); define('CRITICAL_ERROR', 2);
$legacy = array('update/update_phpbb_to_2022.php', 'update/update_attachment_221_to_243.php',
 'update/update_plus_152_to_153a.php', 'update/update_plus_153_to_153a.php',
 'update/update_phpbb_20xx_to_plus_153a.php', 'update/migrate_album_personal_galleries.php',
 'phpBB2/install/update_to_latest.php', 'phpBB2/install/upgrade.php');
foreach ($legacy as $file) {
 $source = file_get_contents($root . '/' . $file);
 policy_check(strpos($source, 'exit(1);') < 650, 'Historical guard before bootstrap');
 $pipes = array(); $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($root . '/' . $file);
 $p = proc_open($command, array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes, null, null, array('bypass_shell'=>true));
 policy_check(is_resource($p), 'Legacy subprocess'); fclose($pipes[0]);
 $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
 policy_check(proc_close($p) === 1 && $err === '' && strpos($out, 'This historical updater is disabled.') === 0, 'No bootstrap/SQL from ' . $file);
}
$admin = file_get_contents($root . '/phpBB2/admin/admin_db_utilities.php');
$start = strpos($admin, "\t\tcase 'restore':"); $end = strpos($admin, "\n\t}\n}", $start);
policy_check($start !== false && $end > $start, 'Locate actual restore controller');
$branch = substr($admin, $start, $end - $start);
$lang = array('Restore_offline_only'=>'offline only', 'ctracker_error_storage_migration'=>'migration required');
foreach (array(array(),array('restore_start'=>'1','sql'=>'DROP TABLE users;')) as $post) {
 $_POST=$post; $_FILES=array('backup_file'=>array('tmp_name'=>__FILE__));
 $stopped=false; try { eval("switch ('restore') {" . $branch . "}"); } catch (PolicyExit $e) { $stopped=$e->getMessage()==='offline only'; }
 policy_check($stopped && http_response_code()===403, 'GET and forged upload stop without SQL');
}
policy_check(strpos($admin, "\$_FILES['backup_file']") === false && strpos($admin, 'split_sql_file($sql_query') === false, 'No hidden upload executor');
$schema=file_get_contents($root.'/phpBB2/install/schemas/mysql_schema.sql');
preg_match_all('/CREATE TABLE\s+.*?;(?=\s*(?:#|CREATE|$))/s', $schema, $definitions);
policy_check(count($definitions[0])===116, 'Review inventory when adding fresh-install tables');
foreach ($definitions[0] as $sql) { policy_check(strpos($sql,'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')!==false, 'Explicit fresh storage and charset'); }
$ct=file_get_contents($root.'/phpBB2/ctracker/classes/class_ct_adminfunctions.php');
policy_check(substr_count($ct,'$this->require_modern_storage(')===6, 'All three clone sources and replacements checked');
policy_check(preg_match_all('/\bCREATE\s+TABLE\b/i',$ct,$ct_creates)===4,'Review every additional CrackerTracker table creator');
$installer=file_get_contents($root.'/phpBB2/install/install.php');
policy_check(strpos($installer,'if (!empty($upgrade) || !empty($upgrade_now))')!==false && strpos($installer,'from phpBB 1</option>')===false,'Legacy installer POST route blocked and removed from options');
// A new table creator must be reviewed, including its explicit charset. Do not
// mistake engine strings in value data/comments for an executable SQL firewall.
$creators=array('phpBB2/admin/admin_db_utilities.php','phpBB2/ctracker/classes/class_ct_adminfunctions.php','phpBB2/install/update_to_latest.php');
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/phpBB2',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file) {
 if(substr($file->getFilename(),-4)!=='.php'){continue;}
 $path=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
 $source=file_get_contents($file->getPathname());
 if(preg_match('/\bCREATE\s+(?:TEMPORARY\s+)?TABLE\b/i',$source)){policy_check(in_array($path,$creators,true),'Unreviewed table creator: '.$path);}
}
echo "Storage policy, historical entrypoints and disabled SQL-upload controller passed\n";
if(getenv('PHPBB_STORAGE_NATIVE')!=='1'){exit(0);}
mysqli_report(MYSQLI_REPORT_OFF);
$port=getenv('PHPBB_STORAGE_PORT')?(int)getenv('PHPBB_STORAGE_PORT'):3306;
$password=getenv('PHPBB_STORAGE_PASSWORD')?:'';
$raw=mysqli_connect('127.0.0.1','root',$password,'',$port);policy_check((bool)$raw,'Native connection');
$name='codex_storage_guard_'.bin2hex(function_exists('random_bytes')?random_bytes(8):openssl_random_pseudo_bytes(8));
policy_check(mysqli_query($raw,'CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned fixture database');
require $root.'/phpBB2/db/mysqli.php';
define('IN_PHPBB',true); define('CTRACKER_ACP',true);
require $root.'/phpBB2/ctracker/classes/class_ct_adminfunctions.php';
$method=new ReflectionMethod('ct_adminfunctions','require_modern_storage');if(PHP_VERSION_ID<80100){$method->setAccessible(true);}
$db=null;$dedicated=null;
try {
 $db=new sql_db('127.0.0.1:'.$port,'root',$password,$name,false);policy_check((bool)$db->db_connect_id,'Actual driver connects');
 $dedicated=$db->sql_dedicated_connection();policy_check((bool)$dedicated->db_connect_id,'Dedicated writer connects');
 foreach(array($db,$dedicated) as $connection){
  $row=$connection->sql_fetchrow($connection->sql_query('SELECT @@SESSION.default_storage_engine AS engine, @@SESSION.default_tmp_storage_engine AS tmp, @@SESSION.sql_mode AS mode, @@SESSION.innodb_strict_mode AS strict_mode, @@SESSION.character_set_connection AS charset'));
  policy_check(strcasecmp($row['engine'],'InnoDB')===0 && strcasecmp($row['tmp'],'InnoDB')===0 && strpos($row['mode'],'NO_ENGINE_SUBSTITUTION')!==false && (string)$row['strict_mode']==='1' && $row['charset']==='utf8mb4','Native session defaults and fail-closed engine mode');
 }
 policy_check($db->sql_query('CREATE TABLE normal_default (id INT, body VARCHAR(40))'),'Implicit engine create');
 policy_check($db->sql_query('CREATE TEMPORARY TABLE temp_default (id INT)'),'Implicit temporary engine create');
 foreach(array('normal_default','temp_default') as $table){$row=$db->sql_fetchrow($db->sql_query('SHOW CREATE TABLE '.$table));policy_check(strpos($row['Create Table'],'ENGINE=InnoDB')!==false,'Implicit table uses InnoDB');}
 policy_check(!$db->sql_query('CREATE TABLE no_fallback (id INT) ENGINE=NONEXISTENT_STORAGE_ENGINE'),'Unsupported engine cannot fall back');
 $admin=new ct_adminfunctions();
 $cases=array('modern'=>'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'legacy'=>'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'memory_old'=>'ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'compact_old'=>'ENGINE=InnoDB ROW_FORMAT=COMPACT DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'charset_old'=>'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=latin1',
  'column_old'=>'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 foreach($cases as $table=>$options){
  $column=$table==='column_old'?' CHARACTER SET latin1':'';
  policy_check($db->sql_query('CREATE TABLE '.$table.' (id INT, body VARCHAR(40)'.$column.') '.$options),'Create source fixture '.$table);
  $stopped=false;try{$method->invoke($admin,$db,$table);}catch(PolicyExit $e){$stopped=true;}
  policy_check($stopped===($table!=='modern'),'Reject legacy engine/format/table/column charset: '.$table);
 }
 $stopped=false;try{$method->invoke($admin,$db,'missing_table');}catch(PolicyExit $e){$stopped=true;}policy_check($stopped,'Missing metadata fails closed');
 policy_check($db->sql_query("INSERT INTO modern VALUES (1,'Grüße 😀')"),'Unicode data');
 policy_check($db->sql_query('CREATE TABLE modern_copy LIKE modern'),'Modern clone');$method->invoke($admin,$db,'modern_copy');
 policy_check($db->sql_query('INSERT INTO modern_copy SELECT * FROM modern'),'Clone contents');
 $row=$db->sql_fetchrow($db->sql_query('SELECT body FROM modern_copy'));policy_check($row['body']==='Grüße 😀','Unicode round trip');
 echo "Native driver defaults, no fallback, temporary tables and CrackerTracker legacy-source guards passed\n";
} finally {if($dedicated){$dedicated->sql_close();}if($db){$db->sql_close();}mysqli_query($raw,'DROP DATABASE `'.$name.'`');mysqli_close($raw);}
