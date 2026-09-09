<?php
// Real controller SQL, isolated databases only. Native mode additionally checks
// actual MyISAM/InnoDB statement failure semantics, not simulated rollback.
namespace AccountPublicationFixture;
$root=dirname(dirname(__DIR__)).'/phpBB2/';
define('USERS_TABLE','fixture_users'); define('GROUPS_TABLE','fixture_groups');
define('USER_GROUP_TABLE','fixture_user_group'); define('BEGIN_TRANSACTION',1); define('END_TRANSACTION',2);
define('GENERAL_ERROR',1); define('USER_ACTIVATION_SELF',1); define('USER_ACTIVATION_ADMIN',2);
define('ALLOW_VIEW',1); define('CHECKBOX',3); define('RADIO',2); define('TEXTAREA',1);
define('TEXT_FIELD_MAXLENGTH',255); define('TEXTAREA_MAXLENGTH',60000);
require_once $root.'includes/functions_profile_fields.php';
function check($ok,$message){if(!$ok){throw new \RuntimeException($message);}}
class QueryFailure extends \RuntimeException {}
function message_die($type,$message){throw new QueryFailure($message);}
function gen_rand_string($strong){return '1234567890abcdef1234567890abcdef';}
function usercp_sql_value($v){return $GLOBALS['db']->sql_escape((string)$v);}
function admin_user_sql_value($v){return usercp_sql_value($v);}
// The real dedicated-connection/contender behavior is covered by the native
// user-ID suite; this fixture isolates the actual controller statement order.
function phpbb_user_write_begin(&$db){return $db;}
function phpbb_user_write_end(&$db,$scope){check($db===$scope,'Publication releases its writer scope');}
function fragment($source,$begin,$end){$start=strpos($source,$begin);$stop=$start===false?false:strpos($source,$end,$start);check($start!==false&&$stop>$start,'Actual publication fragment located');return 'namespace AccountPublicationFixture;'.substr($source,$start,$stop-$start);}
class Database {
 public $pdo; public $fail; public $queries=array(); public $native; public $next_id=0;
 function __construct($pdo,$fail,$native){$this->pdo=$pdo;$this->fail=$fail;$this->native=$native;}
 function sql_escape($v){return substr($this->pdo->quote((string)$v),1,-1);}
 function sql_nextid(){return $this->next_id;}
 function sql_query($sql,$transaction=false){
  $this->queries[]=$sql;
  // The shipped adapter treats legacy BEGIN/END flags as no-ops. Keep native
  // autocommit here too: passing by simulated rollback would hide this bug.
  if($this->fail!=='' && strpos($sql,'INSERT INTO '.$this->fail)===0){return false;}
  try{$result=$this->pdo->query($sql);}catch(\PDOException $e){return false;}
  // Diagnostic SELECTs below must not clobber the controller's INSERT ID.
  $this->next_id=(int)$this->pdo->lastInsertId();
  $broken=$this->pdo->query('SELECT COUNT(*) FROM fixture_users u WHERE user_active=1 AND NOT EXISTS (SELECT 1 FROM fixture_user_group ug JOIN fixture_groups g ON g.group_id=ug.group_id WHERE ug.user_id=u.user_id AND ug.user_pending=0 AND g.group_single_user=1)')->fetchColumn();
  check((int)$broken===0,'No statement exposes an active account without its personal group');
  return $result;
 }
}
$quick=str_replace("\r\n","\n",file_get_contents($root.'admin/admin_user_register.php'));
$public=str_replace("\r\n","\n",file_get_contents($root.'includes/usercp_register.php'));
$admin=str_replace("\r\n","\n",file_get_contents($root.'admin/admin_users.php'));
$quick_body=fragment($quick,'$account_created_at = time();',"\t\t\$message = \$lang['Account_added'];");
$public_body=fragment($public,'// Registration IP 1.1.2 (adapted):',"\t\t\tif ( \$coppa )");
$admin_body=fragment($admin,'$account_profile_sql =',"\n\t\t\tif( \$result = \$db->sql_query(\$sql) )");
check(strpos(substr($admin,strpos($admin,'$account_profile_sql =')), 'SET " . implode(\', \', $profile_assignments)')===false,'No second profile write after account activation');
$dsn=getenv('PHPBB_CREATION_TEST_DSN'); $native=$dsn!==false&&$dsn!=='';
if($native){check(preg_match('/^mysql:host=127\.0\.0\.1;port=33119;dbname=codex_creation_[a-f0-9]{16};charset=utf8mb4$/D',$dsn)===1,'Native database must be an owned local fixture');}
$pdo=new \PDO($native?$dsn:'sqlite::memory:',$native?'root':null,$native?'':null,array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
// Include the actual INSERT columns and ACP UPDATE fields, but never real data.
preg_match_all('/\b(?:user_[a-z_]+|username|ct_last_pw_change|games_block_pm)\b/',$public_body.$admin_body,$matches);
$columns=array_unique(array_merge($matches[0],array('fixture_profile')));
foreach($native?array('MyISAM','InnoDB'):array('SQLite nontransactional') as $engine){
 foreach(array('quick','public','admin') as $route){
  foreach(array('',GROUPS_TABLE,USER_GROUP_TABLE,USERS_TABLE,'missing_profile') as $failure){
   if($route==='admin'&&in_array($failure,array(GROUPS_TABLE,USER_GROUP_TABLE,USERS_TABLE),true)){continue;}
   if($route==='quick'&&$failure==='missing_profile'){continue;}
   foreach($route==='public'?array(array(0,0),array(1,0),array(2,0),array(0,1)):array(array(0,0)) as $activation){
    if($native&&$pdo->inTransaction()){$pdo->rollBack();}
    foreach(array(USER_GROUP_TABLE,GROUPS_TABLE,USERS_TABLE) as $table){$pdo->exec('DROP TABLE IF EXISTS '.$table);}
    $defs=array('user_id INTEGER PRIMARY KEY');
    foreach($columns as $column){if($column==='user_id'||($column==='fixture_profile'&&$failure==='missing_profile')){continue;}$defs[]=$column.' '.(in_array($column,array('user_active','user_level','user_regdate','ct_last_pw_change','user_passwd_change'),true)?'INTEGER DEFAULT 0':"VARCHAR(255) DEFAULT ''");}
    $suffix=$native?' ENGINE='.$engine.' DEFAULT CHARSET=utf8mb4':'';
    $pdo->exec('CREATE TABLE fixture_users ('.implode(',',$defs).')'.$suffix);
    $pdo->exec('CREATE TABLE fixture_groups (group_id INTEGER PRIMARY KEY '.($native?'AUTO_INCREMENT':'AUTOINCREMENT').',group_name VARCHAR(50),group_description VARCHAR(100),group_single_user INTEGER,group_moderator INTEGER)'.$suffix);
    $pdo->exec('CREATE TABLE fixture_user_group (user_id INTEGER,group_id INTEGER,user_pending INTEGER)'.$suffix);
    $pdo->exec("INSERT INTO fixture_users (user_id,username,user_active,user_password) VALUES (99,'Existing member',1,'existing-hash')");
    $pdo->exec("INSERT INTO fixture_groups VALUES (100,'','Existing personal group',1,0)");
    $pdo->exec('INSERT INTO fixture_user_group VALUES (99,100,0)');
    $existing=$pdo->query('SELECT * FROM fixture_users WHERE user_id=99')->fetch(\PDO::FETCH_ASSOC);
    $db=new Database($pdo,$failure,$native);$GLOBALS['db']=$db;
    $user_id=42;$username="Fixture O'Connor 😀";$new_password=md5('fixture-only');$email='fixture@example.invalid';
    $user_style=1;$user_timezone=0;$user_dateformat='Y-m-d';$user_lang='german';
    $icq=$website=$occupation=$location=$user_flag=$interests=$user_absence_text=$signature=$signature_bbcode_uid=$aim=$yim=$msn='';
    $fb=$ig=$pt=$twr=$skp=$tg=$li=$tt=$dc=$signal=$threema='';
    $user_absence_mode=$user_absence=$viewemail=$attachsig=$setbm=$allowsmilies=$allowhtml=$allowbbcode=$allowviewonline=$notifyreply=$notifypm=$games_block_pm=$popup_pm=$gender=0;
    $avatar_sql=$route==='public'?"'', 0":'';$birthday=999999;$next_birthday_greeting=0;
    $board_config=array('require_activation'=>$activation[0]);$coppa=$activation[1];$_SERVER['REMOTE_ADDR']='2001:db8::123';
    $profile_data=array(array('field_name'=>'fixture_profile','field_type'=>0,'text_field_maxlen'=>200));
    $HTTP_POST_VARS=array('fixture_profile'=>"Grüße O'Connor, 😀");$expected_profile=htmlspecialchars($HTTP_POST_VARS['fixture_profile'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if($route==='admin'){
     $pdo->exec("INSERT INTO fixture_users (user_id,username,user_active,user_password) VALUES (42,'new_user',0,'')");
     $pdo->exec("INSERT INTO fixture_groups VALUES (1,'','Personal User',1,0)");$pdo->exec('INSERT INTO fixture_user_group VALUES (42,1,0)');
     $profile_names=array();$profile_assignments=\phpbb_profile_field_assignments($profile_data,$HTTP_POST_VARS,$profile_names);
     $username_sql="username = '".$db->sql_escape($username)."', ";$passwd_sql="user_password = '".$new_password."', ";
     $user_status=1;$user_ycard=$user_rank=$user_allowavatar=$user_allowpm=$popuppm=0;$force_new_passwd_sql='';
    }
    $caught=false;
    try{eval($route==='quick'?$quick_body:($route==='public'?$public_body:$admin_body));if($route==='admin'&&!$db->sql_query($sql)){throw new QueryFailure('final profile statement failed');}}
    catch(QueryFailure $e){$caught=true;}
    check($caught===($failure!==''),'Expected controlled query failure for '.$route.'/'.$failure);
    check($existing===$pdo->query('SELECT * FROM fixture_users WHERE user_id=99')->fetch(\PDO::FETCH_ASSOC),'Unrelated existing account remains unchanged');
    $rows=$pdo->query('SELECT * FROM fixture_users WHERE user_id=42')->fetchAll(\PDO::FETCH_ASSOC);
    if($failure!==''){
     check($route==='admin'?count($rows)===1&&(int)$rows[0]['user_active']===0&&$rows[0]['user_password']==='':count($rows)===0,'Failure never publishes credentials or an active partial account');
    }else{
     check(count($rows)===1&&$rows[0]['username']===$username&&$rows[0]['user_password']===$new_password,'Successful identity and password unchanged');
     check((int)$pdo->query('SELECT COUNT(*) FROM fixture_user_group WHERE user_id=42 AND user_pending=0')->fetchColumn()===1,'Personal membership exists at publication');
     check((int)$rows[0]['user_active']===($route==='public'&&($activation[0]||$coppa)?0:1),'Self/admin/COPPA activation semantics preserved');
     if($route!=='quick'){check($rows[0]['fixture_profile']===$expected_profile,'Unicode custom profile stored together with core fields');}
     if($route==='public'){check($rows[0]['user_reg_ip']==='2001:db8::123'&&(int)$rows[0]['ct_last_pw_change']>time()-10,'Registration IP and CrackerTracker password date are part of publication');check(($rows[0]['user_actkey']!=='')===((bool)($activation[0]||$coppa)),'Activation key preserved');}
    }
   }
  }
 }
 echo $engine.": real quick-add/public/ACP publication, injected failures, Unicode profiles and activation checks passed.\n";
}
if($native&&$pdo->inTransaction()){$pdo->rollBack();}
