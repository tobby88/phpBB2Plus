<?php
// Actual Nuffload hand-off, category creator and callers; owned loopback only.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_CREATION_NATIVE')!=='1'){echo "Native Album creation guard fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('ANONYMOUS',-1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);define('USERS_TABLE','fixture_users');define('USER_GROUP_TABLE','fixture_members');
$table_prefix='fixture_';$source=dirname(dirname(__DIR__)).'/phpBB2/';
require $source.'includes/php_compat.php';require $source.'db/mysqli.php';require $source.'album_mod/album_constants.php';
require $source.'album_mod/album_functions.php';require $source.'album_mod/album_hierarchy_auth.php';require $source.'album_mod/album_hierarchy_sql.php';
function ac_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class AlbumCreationExit extends RuntimeException{}
function message_die($type,$message){throw new AlbumCreationExit($message);}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
function album_sql_id_list($ids){return phpbb_sql_id_list($ids);}
function ac_extract($body,$name){ac_check(preg_match('/^function '.preg_quote($name,'/').'\(.*?^\}/ms',$body,$m)===1,'Actual helper '.$name);eval($m[0]);}
$core=file_get_contents($source.'includes/functions.php');ac_extract($core,'phpbb_image_dimensions_safe');ac_extract($core,'phpbb_sql_id_list');
ac_extract(file_get_contents($source.'album_mod/album_hierarchy_functions.php'),'album_validate_jumpbox_selection');
require __DIR__.'/album-native-observer.php';
class AlbumCreationDatabase extends sql_db{
 var$failure='';var$queries=array();var$inserts=0;
 function reset_case($failure=''){$this->failure=$failure;$this->queries=array();$this->inserts=0;}
 function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
 function sql_query($sql='',$transaction=false){return $this->observed_query($this,$sql,$transaction);}
 function observed_query($database,$sql,$transaction=false){
  $this->queries[]=$sql;$insert=strpos($sql,'INSERT INTO fixture_album_cat')===0;if($insert){$this->inserts++;}
  if(($this->failure==='insert'&&$insert)||($this->failure==='membership'&&strpos($sql,'FROM fixture_members')!==false)){return false;}
  return $database===$this?parent::sql_query($sql,$transaction):$database->sql_query($sql,$transaction);
 }
}
$port=getenv('PHPBB_ALBUM_CREATION_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_CREATION_PASSWORD')?:'';
ac_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');
$host='127.0.0.1:'.$port;$control=new sql_db($host,'root',$password,'',false);ac_check($control->db_connect_id,'Owned native connection');
$schema='codex_album_create_'.bin2hex(phpbb_random_bytes(8));$created=false;$db=null;
$fixture=sys_get_temp_dir().'/'.$schema;$psid=str_repeat('a',32);$oldDirectory=getcwd();$cases=0;
function ac_sql($sql){$r=$GLOBALS['control']->sql_query($sql);ac_check($r!==false,'Owned fixture SQL');return$r;}
function ac_count(){ $r=ac_sql('SELECT COUNT(*) AS n FROM fixture_album_cat');$row=$GLOBALS['control']->sql_fetchrow($r);$GLOBALS['control']->sql_freeresult($r);return(int)$row['n']; }
function ac_viewer($id=100,$level=0,$logged=true){return array('user_id'=>$id,'user_level'=>$level,'session_logged_in'=>$logged,'session_id'=>str_repeat('c',32),'username'=>'0');}
function ac_run_upload($parser,$owner){
 global$db,$album_config,$userdata,$lang,$album_data,$album_user_id,$cat_id,$thiscat;
 $_POST=array('pic_title'=>'Synthetic local image','cat_id'=>-$owner,'user_id'=>$owner);$_GET=array();$upload_submitted=true;$album_data=array();
 eval($parser);
}
function ac_creation_failed($callback){$failed=false;try{$callback();}catch(AlbumCreationExit$e){$failed=$e->getMessage()==='creation-failed';}ac_check($failed,'Caller must stop immediately on failed/denied root creation');}
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 ac_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;ac_sql('USE '.$schema);$db=new AlbumCreationDatabase($host,'root',$password,$schema,false);
 $schema_source=file_get_contents($source.'install/schemas/mysql_schema.sql');
 foreach(array('album_cat','album')as$table){ac_check(preg_match('/CREATE TABLE phpbb_'.$table.'\s*\([\s\S]*?;/',$schema_source,$m)===1,'Canonical Album table');ac_sql(str_replace('phpbb_'.$table,'fixture_'.$table,$m[0]));}
 ac_sql('CREATE TABLE fixture_users(user_id MEDIUMINT NOT NULL,username VARCHAR(25) NOT NULL,PRIMARY KEY(user_id)) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 ac_sql('CREATE TABLE fixture_members(user_id MEDIUMINT NOT NULL,group_id MEDIUMINT NOT NULL,user_pending TINYINT NOT NULL) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 ac_sql("INSERT INTO fixture_users VALUES(100,'0'),(200,'Other owner')");ac_sql('INSERT INTO fixture_members VALUES(100,10,0)');
 ac_check(mkdir($fixture,0700)&&mkdir($fixture.'/uploader',0700)&&mkdir($fixture.'/uploader/tmp',0700),'Owned staged-file fixture');
 $phpbb_root_path=$fixture.'/';$phpEx='php';$userdata=ac_viewer();
 $album_config=array('path_to_bin'=>'uploader/','show_progress_bar'=>0,'close_on_finish'=>1,'max_pause'=>30,'multiple_uploads'=>0,'zip_uploads'=>0,'resize_pic'=>0,'resize_width'=>100,'resize_height'=>100,'resize_quality'=>80,'perl_uploader'=>1,'gd_version'=>2,'gif_allowed'=>1,'jpg_allowed'=>1,'png_allowed'=>1,'max_file_size'=>1024,'max_width'=>100,'max_height'=>100,'personal_gallery_view'=>ALBUM_USER,'personal_gallery'=>ALBUM_USER,'personal_gallery_private'=>'10','personal_allow_gallery_mod'=>1,'personal_allow_sub_categories'=>1,'personal_sub_category_limit'=>5,'rate'=>1,'comment'=>1);
 $lang=array('No_valid_category_selected'=>'invalid-category','No_user_id_specified'=>'missing-owner','No_such_user'=>'missing-owner','Category_not_exist'=>'missing-category','Not_Authorised'=>'denied','Personal_Gallery_Of_User'=>'Gallery of %s','Personal_gallery_creation_failed'=>'creation-failed');
 file_put_contents($fixture.'/album_nuffload.php',file_get_contents($source.'album_nuffload.php'));
 file_put_contents($fixture.'/uploader/tmp/'.$psid.'_owner',album_nuffload_owner_token($userdata));
 copy($source.'images/spacer.gif',$fixture.'/uploader/tmp/'.$psid.'_actualdata0');$imageHash=hash_file('sha256',$fixture.'/uploader/tmp/'.$psid.'_actualdata0');
 $data=array('pic_title'=>'Synthetic local image','cat_id'=>-200,'user_id'=>200,'file'=>array('field'=>array('pic_file'),'name'=>array('pixel.gif'),'size'=>array(filesize($fixture.'/uploader/tmp/'.$psid.'_actualdata0')),'tmp_name'=>array('tmp/'.$psid.'_actualdata0')));
 file_put_contents($fixture.'/uploader/tmp/'.$psid.'_qstring','&'.http_build_query($data,'','&'));
 $_GET=array('psid'=>$psid);$_POST=array();$_REQUEST=$_GET;$_SERVER['REQUEST_METHOD']='GET';$HTTP_POST_FILES=array();$album_data=array();
 $body=file_get_contents($source.'album_upload.php');$start=strpos($body,'// Nuffload album uploader');$marker=strpos($body,'| Upload Quota Check',$start);$end=strrpos(substr($body,0,$marker),'/*');
 ac_check($start!==false&&$marker!==false&&$end!==false,'Actual hand-off/category/permission controller boundary');
 chdir($fixture);ac_creation_failed(function()use($body,$start,$end){global$HTTP_POST_FILES;extract($GLOBALS,EXTR_SKIP);eval(substr($body,$start,$end-$start));});chdir($oldDirectory);
 ac_check(isset($HTTP_POST_FILES['pic_file']['type'])&&$HTTP_POST_FILES['pic_file']['type']==='image/gif','Actual session-owner/staged-image hand-off completed');
 ac_check(ac_count()===0&&$db->inserts===0,'Unrelated owner rejected before any root INSERT');$cases++;
 ac_check(hash_file('sha256',$fixture.'/uploader/tmp/'.$psid.'_actualdata0')===$imageHash,'Denied creation leaves staged image bytes intact');$cases++;
 $requestStart=strpos($body,"if( isset(\$_POST['user_id']) && is_scalar");ac_check($requestStart!==false,'Actual submitted-category parser');$parser=substr($body,$requestStart,$end-$requestStart);
 $db->reset_case();ac_run_upload($parser,100);ac_check(ac_count()===1&&$db->inserts===1,'Valid owner named zero can create and continue to real category authorization');$cases++;
 ac_sql('DELETE FROM fixture_album_cat');$db->reset_case('insert');ac_creation_failed(function()use($parser){ac_run_upload($parser,100);});
 ac_check(ac_count()===0&&$db->inserts===1,'Failed root INSERT never continues with virtual category');
 foreach($db->queries as$sql){ac_check(strpos($sql,'SELECT c.*, COUNT(p.pic_id)')!==0,'Upload cannot continue after failed root creation');}$cases++;
 $admin=file_get_contents($source.'album_personal_cat_admin.php');$adminStart=strpos($admin,'if (album_get_personal_root_id($album_user_id) == ALBUM_ROOT_CATEGORY)');$adminEnd=strpos($admin,'// Only the owner of the personal gallery',$adminStart);$adminEnd=strrpos(substr($admin,0,$adminEnd),'// ------------------------------------------------------------------------');
 ac_check($adminStart!==false&&$adminEnd!==false,'Actual personal administration creation boundary');
 $_POST=array('submit'=>'1');$album_user_id=100;$db->reset_case('insert');
 ac_creation_failed(function()use($admin,$adminStart,$adminEnd){global$album_user_id,$album_config,$lang;eval(substr($admin,$adminStart,$adminEnd-$adminStart));});
 ac_check(ac_count()===0&&$db->inserts===1,'Personal administration stops on failed root INSERT');$cases++;
 $db->reset_case();$fallbackStart=strpos($body,'if (empty($thiscat) && $album_user_id != ALBUM_PUBLIC_GALLERY)');$fallbackEnd=strpos($body,'if (empty($thiscat))',$fallbackStart+1);ac_check($fallbackStart!==false&&$fallbackEnd!==false,'Actual empty-gallery fallback boundary');
 $thiscat=false;$album_user_id=100;eval(substr($body,$fallbackStart,$fallbackEnd-$fallbackStart));ac_check(is_array($thiscat)&&$thiscat['cat_user_id']===100,'Upload form recognizes real owner named zero');$cases++;
 $thiscat=false;$album_user_id=999;$missing=false;try{eval(substr($body,$fallbackStart,$fallbackEnd-$fallbackStart));}catch(AlbumCreationExit$e){$missing=$e->getMessage()==='missing-owner';}ac_check($missing&&ac_count()===0,'Missing owner is not a localized Guest account');$cases++;
 $policyCases=array(
  array(100,0,true,100,ALBUM_USER,true),array(100,0,true,200,ALBUM_USER,false),array(100,0,true,100,ALBUM_ADMIN,false),
  array(100,ADMIN,true,200,ALBUM_USER,true),array(100,ADMIN,true,200,ALBUM_ADMIN,true),array(100,0,true,100,ALBUM_PRIVATE,true),
  array(100,0,true,200,ALBUM_PRIVATE,false),array(100,0,true,100,ALBUM_GUEST,false),array(100,ADMIN,true,200,ALBUM_GUEST,true),
  array(100,0,false,100,ALBUM_USER,false),array(100,ADMIN,false,200,ALBUM_USER,false),array(0,ADMIN,true,200,ALBUM_USER,false),
  array(-1,ADMIN,true,200,ALBUM_USER,false),array(true,ADMIN,true,200,ALBUM_USER,false),array(100.0,ADMIN,true,200,ALBUM_USER,false),array(array(100),ADMIN,true,200,ALBUM_USER,false)
 );
 foreach($policyCases as$case){
  ac_sql('DELETE FROM fixture_album_cat');$userdata=ac_viewer($case[0],$case[1],$case[2]);$album_config['personal_gallery']=$case[4];$db->reset_case();
  ac_check(album_create_personal_gallery($case[3],ALBUM_USER,ALBUM_PRIVATE)===$case[5],'Actual creation identity/admin/current-policy decision');
  ac_check(ac_count()===($case[5]?1:0)&&$db->inserts===($case[5]?1:0),'Denied creation cannot emit category INSERT');$cases++;
 }
 foreach(array(0,1)as$pending){ac_sql('DELETE FROM fixture_album_cat');ac_sql('UPDATE fixture_members SET user_pending='.$pending);$userdata=ac_viewer();$album_config['personal_gallery']=ALBUM_PRIVATE;$db->reset_case();ac_check(album_create_personal_gallery(100,ALBUM_USER,ALBUM_PRIVATE)===($pending===0)&&ac_count()===($pending===0?1:0),'Only approved private-group membership can create own root');$cases++;}
 ac_sql('DELETE FROM fixture_album_cat');$db->reset_case('membership');$failed=false;try{$failed=album_create_personal_gallery(100,ALBUM_USER,ALBUM_PRIVATE)===false;}catch(AlbumCreationExit$e){$failed=true;}ac_check($failed&&ac_count()===0&&$db->inserts===0,'Failed policy membership lookup cannot create root');$cases++;
 $phpbb_root_path=$source;
 foreach(array('english','german')as$locale){$lang=array();require $source.'language/lang_'.$locale.'/lang_main_album.php';ac_check(isset($lang['Personal_gallery_creation_failed'])&&is_string($lang['Personal_gallery_creation_failed'])&&$lang['Personal_gallery_creation_failed']!=='','Localized failure in both supported languages');$cases++;}
 echo 'Native Album creation guard: '.$cases." real hand-off/identity/policy/group/storage/caller/name-zero cases PASS; no live data or HTTP.\n";
}finally{
 chdir($oldDirectory);if($db){$db->sql_close();}if($created){$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();
 if(is_dir($fixture)){foreach(array('/album_nuffload.php','/uploader/tmp/'.$psid.'_owner','/uploader/tmp/'.$psid.'_actualdata0','/uploader/tmp/'.$psid.'_qstring')as$file){if(is_file($fixture.$file)){unlink($fixture.$file);}}rmdir($fixture.'/uploader/tmp');rmdir($fixture.'/uploader');rmdir($fixture);}restore_error_handler();error_reporting($reporting);
}
