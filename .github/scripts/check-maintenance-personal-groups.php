<?php
namespace MaintenanceGroupFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
define('USERS_TABLE','fixture_users'); define('GROUPS_TABLE','fixture_groups'); define('USER_GROUP_TABLE','fixture_user_group'); define('ANONYMOUS',-1);
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class RepairFailure extends \RuntimeException {}
function throw_error($message){throw new RepairFailure($message);}
function check_mysql_version(){return true;}
class Database {
 public $pdo;public $failure='';public $affected=0;
 function __construct($pdo){$this->pdo=$pdo;}
 function sql_query($sql){
  // Compatibility only for exercising the pre-fix MySQL IsNull spelling.
  if($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'){$sql=str_replace('IsNull(group_count)','(group_count IS NULL)',$sql);}
  if($this->failure!==''&&strpos($sql,$this->failure)!==false){return false;}
  try{$r=$this->pdo->query($sql);$this->affected=$r->rowCount();return $r;}catch(\PDOException $e){return false;}
 }
 function sql_fetchrow($r){return $r->fetch(\PDO::FETCH_ASSOC);}
 function sql_freeresult($r){$r->closeCursor();}
 function sql_affectedrows(){return $this->affected;}
 function sql_nextid(){return (int)$this->pdo->lastInsertId();}
}
function fragment($source,$start,$end){$a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);check($a!==false&&$b>$a,'Actual maintenance fragment found');return 'namespace MaintenanceGroupFixture;'.substr($source,$a,$b-$a);}
$source=file_get_contents($root.'admin/admin_db_maintenance.php');
$body=fragment($source,'// Update incorrect pending information:','// Check for group moderators who do not exist');
$empty=fragment($source,'// Remove groups without any members','// Remove user-group data without a valid group');
$dsn=getenv('PHPBB_MAINTENANCE_TEST_DSN');$native=$dsn!==false&&$dsn!=='';
if($native){check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_maintenance_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Only owned local native fixtures allowed');}
$pdo=new \PDO($native?$dsn:'sqlite::memory:',$native?'root':null,$native?'':null,array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
function snapshot($pdo){$out=array();foreach(array('users'=>'user_id','groups'=>'group_id','user_group'=>'group_id,user_id,user_pending','auth_access'=>'group_id,forum_id','album_policy'=>'group_id') as $table=>$order){$out[$table]=$pdo->query('SELECT * FROM fixture_'.$table.' ORDER BY '.$order)->fetchAll(\PDO::FETCH_ASSOC);}return $out;}
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new \RuntimeException($message);}});
try{
 foreach($native?array('MyISAM','InnoDB'):array('SQLite') as $engine){
  foreach(array('english','german') as $language){
   $lang=array();$mtnc=array();$phpEx='php';include $root.'language/lang_'.$language.'/lang_dbmtnc.php';
   foreach(array('consistent','duplicate-membership','multiple','shared-personal','pending-sole','missing','missing-failure','empty-references') as $case){
    foreach(array('users','groups','user_group','auth_access','album_policy') as $table){$pdo->exec('DROP TABLE IF EXISTS fixture_'.$table);}
    $suffix=$native?' ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4':'';
    $pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY,username VARCHAR(100))'.$suffix);
    $pdo->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY '.($native?'AUTO_INCREMENT':'AUTOINCREMENT').',group_type INTEGER,group_name VARCHAR(100),group_description VARCHAR(100),group_moderator INTEGER,group_single_user INTEGER)'.$suffix);
    $pdo->exec('CREATE TABLE fixture_user_group (group_id INTEGER,user_id INTEGER,user_pending INTEGER)'.$suffix);
    $pdo->exec('CREATE TABLE fixture_auth_access (group_id INTEGER,forum_id INTEGER,auth_read INTEGER,auth_mod INTEGER)'.$suffix);
    $pdo->exec('CREATE TABLE fixture_album_policy (group_id INTEGER,permission_value INTEGER)'.$suffix);
    $pdo->exec("INSERT INTO fixture_users VALUES (1,'One'),(2,'Two')");
    $pdo->exec("INSERT INTO fixture_groups VALUES (10,1,'','Personal User',0,1),(20,1,'','Personal User',0,1)");
    $pdo->exec('INSERT INTO fixture_user_group VALUES (10,1,0),(20,2,0)');
    $pdo->exec('INSERT INTO fixture_auth_access VALUES (10,3,1,0),(20,4,1,1)');
    if($case==='duplicate-membership'){$pdo->exec('INSERT INTO fixture_user_group VALUES (10,1,0)');}
    if($case==='multiple'){$pdo->exec("INSERT INTO fixture_groups VALUES (11,1,'','Another personal group',0,1)");$pdo->exec('INSERT INTO fixture_user_group VALUES (11,1,0),(11,2,0)');$pdo->exec('INSERT INTO fixture_auth_access VALUES (11,5,1,1)');$pdo->exec('INSERT INTO fixture_album_policy VALUES (11,1)');}
    if($case==='shared-personal'){$pdo->exec('INSERT INTO fixture_user_group VALUES (10,2,0)');$pdo->exec('UPDATE fixture_user_group SET user_pending=1 WHERE user_id=1');}
    if($case==='pending-sole'){$pdo->exec('UPDATE fixture_user_group SET user_pending=1 WHERE user_id=1');}
    if($case==='missing'||$case==='missing-failure'){$pdo->exec("INSERT INTO fixture_users VALUES (3,'Missing group')");}
    if($case==='empty-references'){$pdo->exec("INSERT INTO fixture_groups VALUES (30,0,'Unused shared group','Keep',0,0),(40,1,'','Unused personal group',0,1)");$pdo->exec('INSERT INTO fixture_auth_access VALUES (30,8,1,0),(40,9,1,1)');$pdo->exec('INSERT INTO fixture_album_policy VALUES (30,1),(40,1)');}
    $db=new Database($pdo);if($case==='missing-failure'){$db->failure='INSERT INTO '.USER_GROUP_TABLE;}
    $before=snapshot($pdo);$list_open=false;$failed=false;ob_start();
    try{eval($body);eval($empty);}catch(RepairFailure $e){$failed=true;}finally{$output=ob_get_clean();}
    check($failed===($case==='missing-failure'),'Controlled failure state: '.$case);
    $after=snapshot($pdo);
    check($before['auth_access']===$after['auth_access']&&$before['album_policy']===$after['album_policy']&&$before['users']===$after['users'],'Existing accounts, ACLs and plugin group references are preserved');
    if($case==='pending-sole'){check((int)$pdo->query('SELECT user_pending FROM fixture_user_group WHERE user_id=1')->fetchColumn()===0,'Unambiguous personal membership repaired');}
    elseif($case==='missing'){check((int)$pdo->query('SELECT COUNT(*) FROM fixture_user_group ug JOIN fixture_groups g ON g.group_id=ug.group_id WHERE ug.user_id=3 AND g.group_single_user=1 AND ug.user_pending=0')->fetchColumn()===1,'Missing personal group recreated');}
    elseif($case!=='missing-failure'){check($before===$after,'No destructive guessing for '.$case);}
    if(in_array($case,array('multiple','shared-personal'),true)){check(strpos($output,$lang['Review_personal_groups'])!==false,'Ambiguity is reported instead of silently ignored');}
    if($case==='empty-references'){check(strpos($output,$lang['Review_empty_groups'])!==false,'Empty groups are reported for explicit review');}
    if(!$failed){$stable=snapshot($pdo);ob_start();try{eval($body);eval($empty);}finally{ob_end_clean();}check($stable===snapshot($pdo),'Repeated check is idempotent');}
   }
  }
  echo $engine.": actual personal-group maintenance no-op, safe repair, ambiguity, ACL preservation and idempotence checks passed.\n";
 }
}finally{restore_error_handler();}
