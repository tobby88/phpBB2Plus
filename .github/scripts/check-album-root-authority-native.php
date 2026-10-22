<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_AUTHORITY_NATIVE')!=='1'){echo "Native Album root authority fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');define('USER_GROUP_TABLE','fixture_members');$table_prefix='fixture_';
$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'db/mysqli.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_functions.php';require $source.'album_mod/album_hierarchy_auth.php';require $source.'album_mod/album_hierarchy_sql.php';require __DIR__.'/album-native-observer.php';
function aa_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($type,$message){throw new RuntimeException($message);}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
$body=file_get_contents($source.'includes/functions.php');aa_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms',$body,$m)===1,'Actual stored group-list parser');eval($m[0]);
function album_sql_id_list($ids){return phpbb_sql_id_list($ids);}
class AlbumAuthoritySource extends sql_db{
 var$queries=array();var$failure='';var$hook=null;var$lockedHook=null;
 function reset_case($failure=''){$this->queries=array();$this->failure=$failure;$this->hook=null;$this->lockedHook=null;}
 function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
 function observed_query($database,$sql,$transaction=false){
  $this->queries[]=$sql;
  if($this->failure==='actor'&&strpos($sql,'SELECT user_id, user_level, user_active FROM ')===0){return false;}
  if(strpos($sql,'INSERT INTO fixture_album_cat')===0&&is_callable($this->hook)){$hook=$this->hook;$this->hook=null;call_user_func($hook);}
  $result=$database->sql_query($sql,$transaction);
  if(strpos($sql,'SELECT user_id FROM fixture_users WHERE ')===0&&strpos($sql,' FOR UPDATE')!==false&&is_callable($this->lockedHook)){$hook=$this->lockedHook;$this->lockedHook=null;call_user_func($hook);}
  return $result;
 }
}
$port=getenv('PHPBB_ALBUM_AUTHORITY_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_AUTHORITY_PASSWORD')?:'';
aa_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');$host='127.0.0.1:'.$port;
$control=new sql_db($host,'root',$password,'',false);aa_check($control->db_connect_id,'Owned native control');$schema='codex_album_actor_'.bin2hex(phpbb_random_bytes(8));$created=false;$db=null;$cases=0;
function aa_sql($sql){$r=$GLOBALS['control']->sql_query($sql);aa_check($r!==false,'Owned fixture SQL');return$r;}
function aa_rows($sql){$r=aa_sql($sql);$rows=$GLOBALS['control']->sql_fetchrowset($r);$GLOBALS['control']->sql_freeresult($r);return$rows;}
function aa_count(){return(int)aa_rows('SELECT COUNT(*) AS n FROM fixture_album_cat')[0]['n'];}
function aa_value($value){return$value===null?'NULL':(string)(int)$value;}
function aa_reset($snapshotRole,$storedRole,$active){
 global$db,$userdata,$album_data,$album_config,$lang;
 aa_sql('DELETE FROM fixture_album_cat');aa_sql('DELETE FROM fixture_members');aa_sql('DELETE FROM fixture_users');
 aa_sql("INSERT INTO fixture_users(user_id,username,user_level,user_active) VALUES(7,'Actor',".aa_value($snapshotRole==='missing'?0:$snapshotRole).",1),(8,'Other owner',0,1),(9,'Next owner',0,1)");
 $r=$db->sql_query('SELECT user_id,username,user_level,user_active FROM fixture_users WHERE user_id=7');$userdata=$db->sql_fetchrow($r);$db->sql_freeresult($r);$userdata['session_logged_in']=true;$userdata['session_id']=str_repeat('a',32);
 if($snapshotRole==='missing'){unset($userdata['user_level']);}
 // The actual request snapshot precedes a change on a second native connection.
 aa_sql('UPDATE fixture_users SET user_level='.aa_value($storedRole).',user_active='.aa_value($active).' WHERE user_id=7');
 $album_config=array('personal_gallery'=>ALBUM_USER,'personal_gallery_private'=>'10');$album_data=array();$lang=array('Personal_Gallery_Of_User'=>'Gallery of %s');$db->reset_case();
}
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 aa_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;aa_sql('USE '.$schema);
 $ddl=file_get_contents($source.'install/schemas/mysql_schema.sql');aa_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/',$ddl,$m)===1,'Canonical category table');aa_sql(str_replace('phpbb_album_cat','fixture_album_cat',$m[0]));
 aa_check(preg_match('/CREATE TABLE phpbb_users\s*\([\s\S]*?;/',$ddl,$m)===1,'Canonical account table');$columns=array();
 foreach(array('user_id','username','user_level','user_active')as$column){aa_check(preg_match('/^\s+'.$column.'\s+([^\r\n]+),\s*$/m',$m[0],$c)===1,'Canonical nullable account field');$columns[]=$column.' '.$c[1];}
 aa_sql('CREATE TABLE fixture_users('.implode(',',$columns).',PRIMARY KEY(user_id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 aa_sql('CREATE TABLE fixture_members(user_id MEDIUMINT,group_id MEDIUMINT,user_pending TINYINT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 $db=new AlbumAuthoritySource($host,'root',$password,$schema,false);$original=$db;
 $matrix=array(
  array(1,0,1,8,ALBUM_USER,false,0,false),array(0,0,1,7,ALBUM_USER,false,0,true),
  array(1,1,1,8,ALBUM_ADMIN,false,0,true),array(0,1,1,8,ALBUM_ADMIN,false,0,true),
  array(0,0,0,7,ALBUM_USER,false,0,false),array(1,1,0,8,ALBUM_USER,false,0,false),
  array(1,null,1,8,ALBUM_USER,false,0,false),array(1,null,1,7,ALBUM_USER,false,0,true),
  array(1,0,1,7,ALBUM_PRIVATE,false,0,false),array(1,0,1,7,ALBUM_PRIVATE,true,0,true),
  array(1,0,1,7,ALBUM_PRIVATE,true,1,false),array(0,2,1,7,ALBUM_PRIVATE,false,0,false),
  array(0,0,1,7,ALBUM_PRIVATE,true,0,true),array(1,1,null,8,ALBUM_USER,false,0,false),
  array('missing',0,1,7,ALBUM_PRIVATE,true,0,true)
 );
 foreach($matrix as$case){
  aa_reset($case[0],$case[1],$case[2]);$album_config['personal_gallery']=$case[4];if($case[5]){aa_sql('INSERT INTO fixture_members VALUES(7,10,'.$case[6].')');}
  $snapshot=$userdata;$beforeActor=aa_rows('SELECT user_level,user_active FROM fixture_users WHERE user_id=7')[0];$ok=album_create_personal_gallery($case[3],ALBUM_USER,ALBUM_PRIVATE);
  aa_check($ok===$case[7]&&aa_count()===($case[7]?1:0),'Creation uses current active role and actual approved group grant');
  aa_check($userdata===$snapshot&&$GLOBALS['db']===$original,'Request identity/role shape and caller connection restored');
  $row=aa_rows('SELECT user_level,user_active FROM fixture_users WHERE user_id=7')[0];aa_check($row===$beforeActor,'No account role/status rewriting');
  if($case[7]&&$case[3]===8){aa_check(in_array('SELECT user_id FROM fixture_users WHERE user_id IN(7,8) ORDER BY user_id FOR UPDATE',$db->queries,true),'Actor and target locked in consistent primary-key order');}$cases++;
 }
 aa_reset(1,1,1);$snapshot=$userdata;aa_sql('DELETE FROM fixture_users WHERE user_id=7');aa_check(album_create_personal_gallery(8,ALBUM_USER,ALBUM_PRIVATE)===false&&aa_count()===0&&$userdata===$snapshot,'Deleted actor cannot use earlier admin snapshot');$cases++;
 aa_reset(1,1,1);$snapshot=$userdata;aa_sql('DELETE FROM fixture_users WHERE user_id=7');aa_sql('SET SESSION innodb_lock_wait_timeout=1');$recreated=false;
 $db->lockedHook=function()use(&$recreated){aa_sql("INSERT INTO fixture_users(user_id,username,user_level,user_active) VALUES(7,'Replacement actor',1,1)");$recreated=true;};
 aa_check(album_create_personal_gallery(8,ALBUM_USER,ALBUM_PRIVATE)===false&&$recreated&&aa_count()===0&&$userdata===$snapshot&&$GLOBALS['db']===$original,'Actor absent from the actual locked rowset cannot be replaced by a later account with the same ID');$cases++;
 aa_reset(0,0,1);$snapshot=$userdata;$db->reset_case('actor');aa_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===false&&aa_count()===0&&$userdata===$snapshot&&$GLOBALS['db']===$original,'Current actor query failure restores context and aborts before INSERT');$cases++;
 aa_reset(1,1,1);aa_sql('SET SESSION innodb_lock_wait_timeout=1');$blocked=false;$db->hook=function()use(&$blocked){$r=$GLOBALS['control']->sql_query('UPDATE fixture_users SET user_level=0 WHERE user_id=7');$blocked=$r===false;aa_check($blocked,'Concurrent role revocation waits for locked creation authority');};
 aa_check(album_create_personal_gallery(8,ALBUM_USER,ALBUM_PRIVATE)===true&&$blocked&&aa_count()===1,'Authority stays locked through the category INSERT/COMMIT');$cases++;
 aa_sql('UPDATE fixture_users SET user_level=0 WHERE user_id=7');$before=aa_rows('SELECT * FROM fixture_album_cat');aa_check(album_create_personal_gallery(9,ALBUM_USER,ALBUM_PRIVATE)===false&&aa_rows('SELECT * FROM fixture_album_cat')===$before,'Next operation sees committed revocation despite same request snapshot');$cases++;
 aa_reset(1,0,1);$album_config['personal_gallery']=ALBUM_PRIVATE;aa_sql('INSERT INTO fixture_members VALUES(7,10,0)');$snapshot=$userdata;$blocked=false;
 $db->hook=function()use(&$blocked){$r=$GLOBALS['control']->sql_query('DELETE FROM fixture_members WHERE user_id=7 AND group_id=10');$blocked=$r===false;aa_check($blocked,'Approved group grant remains share-locked through root commit');};
 aa_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===true&&$blocked&&$userdata===$snapshot,'Current nonadmin approved grant retained without leaking temporary role');$cases++;
 aa_sql('DELETE FROM fixture_members WHERE user_id=7 AND group_id=10');aa_sql('DELETE FROM fixture_album_cat');aa_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===false&&aa_count()===0&&$userdata===$snapshot,'Next creation honors group removal, not stale admin shortcut');$cases++;
 aa_sql('ALTER TABLE fixture_users MODIFY user_id MEDIUMINT(8) UNSIGNED ZEROFILL NOT NULL DEFAULT 0');aa_reset(0,0,1);$snapshot=$userdata;
 aa_check(album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE)===true&&aa_count()===1&&$userdata===$snapshot,'Canonical numeric identities also match native zero-padded integer results');$cases++;
 echo 'Native Album root authority: '.$cases." actual role/active/null/deletion/group/revocation/read-failure/lock/context cases PASS; own native schemas only, no live HTTP/data.\n";
}finally{if($db){$db->sql_close();}if($created){$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();restore_error_handler();error_reporting($reporting);}
