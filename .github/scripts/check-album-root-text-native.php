<?php
// Actual creator/driver and canonical category schema; owned loopback data only.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_ROOT_NATIVE')!=='1'){echo "Native Album root text fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');define('USER_GROUP_TABLE','fixture_members');$table_prefix='fixture_';
$source=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$source;$phpEx='php';
require $source.'includes/php_compat.php';require $source.'db/mysqli.php';require $source.'album_mod/album_constants.php';require $source.'album_mod/album_functions.php';require $source.'album_mod/album_hierarchy_auth.php';require $source.'album_mod/album_hierarchy_sql.php';
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
class AlbumRootFailure extends RuntimeException {}
function message_die($type,$message){throw new AlbumRootFailure($message);}
function ar_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
require __DIR__.'/album-native-observer.php';
require __DIR__.'/album-native-session.php';
class AlbumRootDatabase extends sql_db{
 var$queries=array();var$freed=0;var$failure='';var$root_result=null;var$recording=false;var$body_results=array();
 function reset_case($failure=''){$this->queries=array();$this->freed=0;$this->failure=$failure;$this->root_result=null;$this->body_results=array();$this->recording=true;}
 function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
 function sql_query($sql='',$transaction=false){return $this->observed_query($this,$sql,$transaction);}
 function observed_query($database,$sql,$transaction=false){
  $owner=strpos($sql,'SELECT username')===0;$root=strpos($sql,'SELECT c.cat_id')===0;$insert=strpos($sql,'INSERT INTO fixture_album_cat')===0;
  if($this->recording){
   ar_check($owner||$root||$insert||preg_match('/^(SELECT |SET SESSION |START TRANSACTION$|COMMIT$|ROLLBACK$)/D',$sql),'Creator cannot emit unrelated writes/DDL');
   if($owner||$root||$insert){$this->queries[]=$sql;}
   if(($this->failure==='owner-read'&&$owner)||($this->failure==='root-read'&&$root)||($this->failure==='insert'&&$insert)){return false;}
  }
  $r=$database===$this?parent::sql_query($sql,$transaction):$database->sql_query($sql,$transaction);
  if(($owner||$root)&&is_object($r)){$this->body_results[spl_object_hash($r)]=true;}if($root){$this->root_result=$r;}return$r;
 }
 function sql_numrows($r=0){return $this->observed_numrows($this,$r);}
 function observed_numrows($database,$r){if($this->failure==='root-count'&&$r===$this->root_result){return false;}return $database===$this?parent::sql_numrows($r):$database->sql_numrows($r);}
 function sql_freeresult($r=0){return $this->observed_free($this,$r);}
 function observed_free($database,$r){$key=is_object($r)?spl_object_hash($r):'';$body=isset($this->body_results[$key]);$ok=$database===$this?parent::sql_freeresult($r):$database->sql_freeresult($r);if($ok&&$body){$this->freed++;unset($this->body_results[$key]);}return$ok;}
}
$port=getenv('PHPBB_ALBUM_ROOT_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_ROOT_PASSWORD')?:'';
ar_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');
$host='127.0.0.1:'.$port;$schema='codex_album_root_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);$db=null;$created=false;
function ar_sql($sql){$r=$GLOBALS['control']->sql_query($sql);ar_check($r!==false,'Owned fixture SQL failed');return$r;}
function ar_rows($sql){$r=ar_sql($sql);$rows=array();while($row=$GLOBALS['control']->sql_fetchrow($r)){$rows[]=$row;}$GLOBALS['control']->sql_freeresult($r);return$rows;}
function ar_categories(){return ar_rows('SELECT * FROM fixture_album_cat ORDER BY cat_id');}
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 ar_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;ar_sql('USE '.$schema);$db=new AlbumRootDatabase($host,'root',$password,$schema,false);
 $schema_source=file_get_contents($source.'install/schemas/mysql_schema.sql');
 ar_check(preg_match('/CREATE TABLE phpbb_album_cat\s*\([\s\S]*?;/',$schema_source,$match)===1,'Canonical category table');ar_sql(str_replace('phpbb_album_cat','fixture_album_cat',$match[0]));
 ar_check(preg_match('/CREATE TABLE phpbb_users\s*\([\s\S]*?;/',$schema_source,$match)===1,'Canonical user table');
 $columns=array();foreach(array('user_id','username','user_level','user_active')as$column){ar_check(preg_match('/^\s+'.$column.'\s+([^\r\n]+),\s*$/m',$match[0],$m)===1,'Canonical user column');$columns[]=$column.' '.$m[1];}
 ar_sql('CREATE TABLE fixture_users('.implode(',',$columns).',PRIMARY KEY(user_id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 album_native_session_schema($control,$source);
 $names=array(7=>'Ordinary',8=>"Legacy O'Connor",9=>"A\\B",10=>'Grüße 😀',11=>'0',12=>'O&#039;Connor',13=>"Quote\\'Name",14=>"Double\\\\Slash",15=>'&amp; ❤',17=>'FROM fixture_members');
 foreach($names as$id=>$name){ar_sql("INSERT INTO fixture_users(user_id,username) VALUES(".$id.",'".$control->sql_escape($name)."')");album_native_session_seed($control,$id,md5('album-native-session-'.$id));}
 ar_sql("INSERT INTO fixture_users(user_id,username) VALUES(16,'')");$accounts=ar_rows('SELECT user_id,username FROM fixture_users ORDER BY user_id');
 $userdata=array('user_id'=>7,'session_logged_in'=>true,'user_level'=>0,'session_id'=>md5('album-native-session-7'));$album_config=array('personal_gallery'=>ALBUM_USER);$cases=0;
 foreach(array('','NO_BACKSLASH_ESCAPES','ANSI_QUOTES')as$mode){
  ar_sql("SET SESSION sql_mode='".$mode."'");$db->recording=false;ar_check($db->sql_query("SET SESSION sql_mode='".$mode."'"),'Actual driver SQL mode');
  foreach(array('english','german','quoted-label')as$locale){
   $lang=array();require $source.'language/lang_'.($locale==='quoted-label'?'english':$locale).'/lang_main.php';
   if($locale==='quoted-label'){$lang['Personal_Gallery_Of_User']="Owner's \\ ".$lang['Personal_Gallery_Of_User'];}
   ar_sql('DELETE FROM fixture_album_cat');
   foreach($names as$id=>$name){
    $userdata['user_id']=$id;$userdata['session_id']=md5('album-native-session-'.$id);$view=$id%2?ALBUM_GUEST:ALBUM_USER;$db->reset_case();
    ar_check(album_create_personal_gallery($id,$view,ALBUM_PRIVATE)===true,'Actual root creation including historical quote/backslash/zero names');
    ar_check(count($db->queries)===3&&$db->freed===2,'Successful creator performs bounded owner/root reads and frees both results');
    $rows=ar_rows('SELECT * FROM fixture_album_cat WHERE cat_user_id='.$id.' AND cat_parent=0');ar_check(count($rows)===1,'Exactly one root created');$row=$rows[0];$expected=sprintf($lang['Personal_Gallery_Of_User'],$name);
    ar_check($row['cat_title']===$expected&&$row['cat_desc']===$expected,'Complete formatted text preserved byte-for-byte across SQL modes');
    ar_check((int)$row['cat_view_level']===$view&&(int)$row['cat_upload_level']===ALBUM_PRIVATE&&(int)$row['cat_edit_level']===ALBUM_PRIVATE&&(int)$row['cat_delete_level']===ALBUM_PRIVATE&&(int)$row['cat_rate_level']===0&&(int)$row['cat_comment_level']===0&&(int)$row['cat_approval']===0,'Existing root permission defaults retained');$cases++;
    $before=ar_categories();$db->reset_case();ar_check(album_create_personal_gallery('000'.$id,ALBUM_ADMIN,ALBUM_MOD)===true,'Existing root returns success');
    ar_check(count($db->queries)===2&&$db->freed===2&&ar_categories()===$before,'Repeat never renames, changes permissions or duplicates existing gallery');$cases++;
   }
  }
  // All previously meaningful permission values remain supported and typed.
  $userdata['user_id']=7;$userdata['session_id']=md5('album-native-session-7');
  foreach(array(ALBUM_GUEST,ALBUM_USER,ALBUM_ADMIN,ALBUM_MOD,ALBUM_PRIVATE)as$view){foreach(array(ALBUM_GUEST,ALBUM_USER,ALBUM_ADMIN,ALBUM_MOD,ALBUM_PRIVATE)as$upload){
   ar_sql('DELETE FROM fixture_album_cat');$db->reset_case();ar_check(album_create_personal_gallery(7,(string)$view,$upload)===true,'Existing permission choice accepted');$row=ar_rows('SELECT cat_view_level,cat_upload_level FROM fixture_album_cat')[0];ar_check((int)$row['cat_view_level']===$view&&(int)$row['cat_upload_level']===$upload,'Permission choices not changed by escaping');$cases++;
  }}
  ar_sql('DELETE FROM fixture_album_cat');$db->reset_case();ar_check(album_create_personal_gallery('0007','-01','03')===true,'Numeric legacy permission spellings retained');$cases++;
  foreach(array(0,'0',-1,'-1',null,false,true,7.0,array(7),'invalid','7 OR 1=1','8388608','99999999999999999999999')as$invalid){
   $before=ar_categories();$db->reset_case();ar_check(album_create_personal_gallery($invalid,ALBUM_USER,ALBUM_PRIVATE)===false&&!$db->queries&&ar_categories()===$before,'Invalid/public owner cannot create or modify categories');$cases++;
  }
  foreach(array(999,16)as$missing){$before=ar_categories();$db->reset_case();$reads=$missing===999?0:1;ar_check(album_create_personal_gallery($missing,ALBUM_USER,ALBUM_PRIVATE)===false&&count($db->queries)===$reads&&$db->freed===$reads&&ar_categories()===$before,'Missing locked owner or blank-named owner cannot get a new root');$cases++;}
  foreach(array(null,false,true,1.0,array(1),'invalid','99','-2','1 OR 1=1')as$invalid){foreach(array('view','upload')as$which){
   $before=ar_categories();$db->reset_case();ar_check(album_create_personal_gallery(7,$which==='view'?$invalid:ALBUM_USER,$which==='upload'?$invalid:ALBUM_PRIVATE)===false&&!$db->queries&&ar_categories()===$before,'Invalid permission does not become a different valid level');$cases++;
  }}
  foreach(array('owner-read','root-read','root-count','insert')as$failure){
   ar_sql('DELETE FROM fixture_album_cat');$before=ar_categories();$db->reset_case($failure);$denied=false;$returned=null;
   try{$returned=album_create_personal_gallery(7,ALBUM_USER,ALBUM_PRIVATE);}catch(AlbumRootFailure$e){$denied=true;}
   ar_check($returned===false&&!$denied,'Storage failure returns false after restoring/closing its owner, before caller rendering');ar_check(ar_categories()===$before,'Failed read/count/insert preserves all categories');$cases++;
  }
  $db->failure='';ar_check(ar_rows('SELECT user_id,username FROM fixture_users ORDER BY user_id')===$accounts,'Account names and IDs never rewritten');
 }
 echo 'Native Album root text: '.$cases." real creation/replay/permission/SQL-mode/failure cases passed; account data unchanged.\n";
}finally{
 try{if($db){$db->sql_close();}if($created){ar_check($control->sql_query('DROP DATABASE '.$schema),'Owned schema cleanup');}}
 finally{$control->sql_close();restore_error_handler();}
}
