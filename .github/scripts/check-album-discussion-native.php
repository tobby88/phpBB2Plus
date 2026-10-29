<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ALBUM_DISCUSSION_NATIVE')!=='1'){echo "Native Album discussion fixture skipped.\n";return;}
define('IN_PHPBB',true);define('ADMIN',1);define('ANONYMOUS',-1);define('GENERAL_ERROR',202);define('GENERAL_MESSAGE',200);$table_prefix='fixture_';$phpEx='php';
foreach(array('USERS_TABLE'=>'users','SESSIONS_TABLE'=>'sessions','USER_GROUP_TABLE'=>'user_group','CONFIG_TABLE'=>'config')as$name=>$suffix){define($name,$table_prefix.$suffix);}
$root=dirname(dirname(__DIR__)).'/phpBB2/';require $root.'includes/php_compat.php';require $root.'db/mysqli.php';require $root.'album_mod/album_constants.php';require $root.'album_mod/album_functions.php';require $root.'album_mod/album_hierarchy_auth.php';require $root.'album_mod/album_picture_discussion.php';require __DIR__.'/album-native-observer.php';
function ad_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function message_die($type,$message){throw new RuntimeException($message);}
function redirect($url){throw new RuntimeException('redirect');}
function validate_username($name){return array('error'=>$name==='Reserved','error_msg'=>'Reserved guest name');}
function checkFlag($value,$flag){return($value&$flag)===$flag;}
function album_is_debug_enabled(){return false;}
$common=file_get_contents($root.'includes/functions.php');ad_check(preg_match('/^function phpbb_sql_id_list\(.*?^\}/ms',$common,$utility)===1,'Actual canonical SQL ID helper');eval($utility[0]);
function album_sql_id_list($ids){return phpbb_sql_id_list($ids);}
class AlbumDiscussionSource extends sql_db
{
 var $queries=array();var $count=0;var $failure=0;var $armed=false;var $lost=false;var $watch=false;var $before=null;var $sp_peer=false;var $cache_operation=false;
 function sql_dedicated_connection(){return new AlbumNativeObservedConnection(parent::sql_dedicated_connection(),$this);}
 function observed_query($database,$sql,$transaction=false){
  $this->queries[]=$sql;if(++$this->count===$this->failure){return false;}
  if(preg_match('/^(?:INSERT INTO fixture_album_(?:comment|rate)|UPDATE fixture_album_(?:comment|rate) SET |DELETE FROM fixture_album_comment WHERE )/',$sql)){$this->armed=true;}
  if($this->cache_operation&&strpos($sql,'SELECT pic_filename,pic_thumbnail,pic_approval FROM fixture_album WHERE')===0){$this->armed=true;}
  if($this->sp_peer&&strpos($sql,'INSERT INTO fixture_album_rate')===0){$this->sp_peer=false;$peer=$GLOBALS['control']->sql_query("UPDATE fixture_album_sp_config SET config_value='0' WHERE config_name='hon_rate_sep'");$error=$GLOBALS['control']->sql_error();ad_check($peer===false&&in_array((int)$error['code'],array(1205,1213),true),'Independent configuration writer cannot revoke the locked channel policy before commit');}
  $result=$database->sql_query($sql,$transaction);
  if($this->watch&&$this->armed&&$sql!=='COMMIT'){ad_check(ad_snapshot()===$this->before,'Independent peer sees exact old comment/vote state until commit');}
  if($sql==='COMMIT'&&$this->armed){$this->armed=false;$this->watch=false;if($this->lost){$this->lost=false;ad_check($result!==false,'Actual COMMIT before lost acknowledgement');return false;}}
  return$result;
 }
 function reset_case($failure=0){$this->queries=array();$this->count=0;$this->failure=$failure;$this->armed=false;$this->lost=false;$this->watch=false;$this->before=null;$this->sp_peer=false;$this->cache_operation=false;}
}
$port=getenv('PHPBB_ALBUM_DISCUSSION_PORT')?:'3306';$password=getenv('PHPBB_ALBUM_DISCUSSION_PASSWORD')?:'';ad_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Owned loopback port');
$host='127.0.0.1:'.$port;$schema='codex_album_discussion_'.bin2hex(phpbb_random_bytes(8));$control=new sql_db($host,'root',$password,'',false);$db=null;$created=false;$cases=0;
$sid=str_repeat('d',32);$userdata=array();$album_config=array();$album_data=array();$board_config=array('session_length'=>999999);$lang=array('Not_Authorised'=>'denied','Comment_no_text'=>'empty comment','Already_rated'=>'already rated','Pic_Locked'=>'locked','Album_discussion_failed'=>'discussion failed');
function ad_sql($sql){$r=$GLOBALS['control']->sql_query($sql);ad_check($r!==false,'Own native discussion SQL');return$r;}
function ad_rows($sql){$r=ad_sql($sql);try{$rows=$GLOBALS['control']->sql_fetchrowset($r);foreach($rows as$index=>$row){foreach($row as$key=>$value){if(is_int($key)){unset($rows[$index][$key]);}}}return$rows;}finally{$GLOBALS['control']->sql_freeresult($r);}}
function ad_literal($s){return"CONVERT(0x".bin2hex($s)." USING utf8mb4)";}
function ad_snapshot(){return array(ad_rows('SELECT * FROM fixture_album ORDER BY pic_id'),ad_rows('SELECT * FROM fixture_album_comment ORDER BY comment_id'),ad_rows('SELECT * FROM fixture_album_rate ORDER BY rate_pic_id,rate_user_id,rate_point,rate_hon_point,rate_user_ip'));}
function ad_reset(){
 foreach(array('album_file_items','album_file_jobs','album_comment','album_rate','album','album_cat','album_config','album_sp_config','config','sessions','user_group','users')as$suffix){ad_sql('DELETE FROM fixture_'.$suffix);}
 ad_sql("INSERT INTO fixture_users(user_id,username,user_password,user_active,user_level) VALUES(-1,'Anonymous','',0,0),(7,".ad_literal("Fresh's \\ Größe 😀").",'',1,0),(8,'Other author','',1,0)");ad_sql("INSERT INTO fixture_config VALUES('session_length','3600')");foreach($GLOBALS['seeds']as$sql){ad_sql($sql);}
 ad_sql("UPDATE fixture_album_config SET config_value='65535' WHERE config_name='desc_length'");
 ad_sql("INSERT INTO fixture_album_sp_config VALUES('hon_rate_users','1'),('hon_rate_times','0'),('hon_rate_sep','1'),('hon_rate_where','')");
 ad_sql("INSERT INTO fixture_sessions(session_id,session_user_id,session_logged_in,session_time,session_ip) VALUES('".$GLOBALS['sid']."',7,1,".time().",'7f000001')");
 ad_sql("INSERT INTO fixture_album_cat(cat_id,cat_title,cat_order,cat_user_id,cat_delete_level,cat_edit_level,cat_comment_level,cat_rate_level,cat_moderator_groups) VALUES(1,'Public',1,0,0,0,-1,-1,'10'),(2,'Unrelated',2,0,0,0,-1,-1,'10')");
 ad_sql("INSERT INTO fixture_album(pic_id,pic_filename,pic_title,pic_user_id,pic_time,pic_cat_id,pic_approval) VALUES(1,'one.png','Synthetic',8,1,1,1),(2,'two.png','Other',8,1,2,1)");
 ad_sql("INSERT INTO fixture_album_comment(comment_id,comment_pic_id,comment_cat_id,comment_user_id,comment_user_ip,comment_time,comment_text) VALUES(1,1,1,7,'7f000001',1,'Original comment'),(2,2,2,8,'7f000002',1,'Unrelated comment')");
 $GLOBALS['userdata']=array('user_id'=>7,'session_id'=>$GLOBALS['sid'],'session_logged_in'=>true,'user_level'=>ADMIN,'username'=>'Stale caller name');$GLOBALS['album_data']=array('auth'=>array(1=>array('comment'=>1,'rate'=>1,'delete'=>1,'edit'=>1)));$GLOBALS['album_config']=array('comment'=>1,'rate'=>1,'rate_scale'=>10,'desc_length'=>65535);$GLOBALS['db']->reset_case();
}
function ad_actor($id){ad_sql('UPDATE fixture_sessions SET session_user_id='.$id.',session_logged_in='.($id===-1?0:1));$GLOBALS['userdata']['user_id']=$id;$GLOBALS['userdata']['session_logged_in']=$id!==-1;}
function ad_action($method,$text='Changed Grüße 😀',$point=null){$writer=new AlbumPictureDiscussion($GLOBALS['db'],'fixture_');if($method==='publish'){return$writer->publish($GLOBALS['userdata'],0,1,1,$text,'',$point);}if($method==='hotornot'){return$writer->hotornot($GLOBALS['userdata'],0,1,1,$point===null?5:$point);}return$writer->change($GLOBALS['userdata'],0,1,1,1,$method==='delete'?null:$text);}
function ad_denied($callback,$message,$snapshot=true){$before=ad_snapshot();$refused=false;try{$callback();}catch(Exception$error){$refused=true;}ad_check($refused,$message);if($snapshot){ad_check(ad_snapshot()===$before,'Refused discussion action preserves every existing picture/comment/vote value');}$GLOBALS['cases']++;}
function ad_controller($mode,$text='Controller Grüße 😀',$point=null){
 global $db,$userdata,$album_config,$lang,$table_prefix;$album_user_id=0;$cat_id=1;$pic_id=1;$comment_id=1;$auth_data=array('comment'=>1,'rate'=>1,'moderator'=>0);
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>$userdata['session_id'],'comment'=>addslashes($text));if($point!==null){$_POST['rate']=$point;}
 $file=$mode==='publish'?'album_showpage.php':($mode==='hotornot'?'album_hotornot.php':($mode==='delete'?'album_comment_delete.php':'album_comment_edit.php'));
 $source=file_get_contents($GLOBALS['root'].$file);$source=str_replace("\r\n","\n",$source);
 if($mode==='publish'){$start=strpos($source,"\tif (!isset(\$_SERVER['REQUEST_METHOD'])");}
 elseif($mode==='change'){$start=strpos($source,"\tif (\$_SERVER['REQUEST_METHOD'] !== 'POST'");}
 else{$start=strpos($source,"\talbum_discussion_request('");$rated_pic=array('cat_user_id'=>0,'pic_cat_id'=>1);$rate_point=$point;}
 $end=strpos($source,"\t// --------------------------------\n\t// Complete... now send a message to user",$start);ad_check($start!==false&&$end>$start,'Actual discussion controller mutation block');eval(substr($source,$start,$end-$start));
 return isset($discussion_result)?$discussion_result:true;
}
if(isset($argv[1])&&$argv[1]==='--cache-delete-worker'){
 ad_check(count($argv)===4&&preg_match('/^codex_album_discussion_[a-f0-9]{16}$/D',$argv[2])===1&&preg_match('/^[a-f0-9]{32}$/D',$argv[3])===1,'Exact owned cache deletion worker input');
 $schema=$argv[2];$directory=sys_get_temp_dir().'/'.$schema;ad_check(is_file($directory.'/cache-owner')&&file_get_contents($directory.'/cache-owner')===$argv[3],'Exact owned cache peer namespace');
 ad_sql('USE '.$schema);$db=new AlbumDiscussionSource($host,'root',$password,$schema,false);$userdata=array('user_id'=>7,'session_id'=>$sid,'session_logged_in'=>true);require_once $root.'album_mod/album_picture_delete.php';
 try{ad_check(file_put_contents($directory.'/cache-peer-ready','ready')===5,'Owned deletion peer ready');$deadline=microtime(true)+15;while(!is_file($directory.'/cache-peer-start')){clearstatcache();if(microtime(true)>$deadline){throw new RuntimeException('Owned cache peer start deadline');}usleep(10000);}
  ad_check(file_put_contents($directory.'/cache-peer-attempt','attempt')===7,'Owned deletion peer attempting namespace');$result=(new AlbumPictureDelete($db,'fixture_',$directory))->remove($userdata,0,1,array(1));
  ad_check(file_put_contents($directory.'/cache-peer-done','done')===4,'Owned deletion peer completion');echo json_encode($result),"\n";
 }finally{$db->sql_close();$control->sql_close();}exit(0);
}
if(isset($argv[1])){
 ad_check(count($argv)===5&&$argv[1]==='--vote-worker'&&preg_match('/^codex_album_discussion_[a-f0-9]{16}$/D',$argv[2])===1&&preg_match('/^[a-f0-9]{32}$/D',$argv[3])===1&&in_array($argv[4],array('normal','hotornot'),true),'Exact owned vote worker input');
 $schema=$argv[2];$directory=sys_get_temp_dir().'/'.$schema;ad_check(is_file($directory.'/owner')&&file_get_contents($directory.'/owner')===$argv[3],'Exact owned worker namespace');
 ad_sql('USE '.$schema);$db=new AlbumDiscussionSource($host,'root',$password,$schema,false);$userdata=array('user_id'=>7,'session_id'=>$sid,'session_logged_in'=>true);
 try{$deadline=microtime(true)+15;while(!is_file($directory.'/start')){if(microtime(true)>$deadline){throw new RuntimeException('Owned worker start deadline');}usleep(10000);}
  try{ad_action($argv[4]==='normal'?'publish':'hotornot','',5);$result=array('rated'=>true);}catch(AlbumDiscussionFailure$error){if($error->language_key!=='Already_rated'){throw$error;}$result=array('rated'=>false);}
  echo json_encode($result),"\n";
 }finally{$db->sql_close();$control->sql_close();}exit(0);
}
$reporting=error_reporting(E_ALL);set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 ad_check($control->db_connect_id,'Own loopback discussion control');ad_sql('CREATE DATABASE '.$schema.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;ad_sql('USE '.$schema);
 $ddl=file_get_contents($root.'install/schemas/mysql_schema.sql');foreach(array('users','sessions','user_group','config','album','album_cat','album_comment','album_rate','album_config','album_sp_config','album_file_jobs','album_file_items')as$suffix){ad_check(preg_match('/^CREATE TABLE `?phpbb_'.$suffix.'`?\s*\([\s\S]*?;/m',$ddl,$m)===1,'Canonical discussion table');ad_sql(str_replace('phpbb_'.$suffix,'fixture_'.$suffix,$m[0]));}
 preg_match_all('/^INSERT INTO phpbb_album_config\b[^\r\n]*;/m',file_get_contents($root.'install/schemas/mysql_basic.sql'),$m);$seeds=array();foreach($m[0]as$sql){$seeds[]=str_replace('phpbb_album_config','fixture_album_config',$sql);}ad_check(count($seeds)>40,'Actual Album defaults');
 $db=new AlbumDiscussionSource($host,'root',$password,$schema,false);
 require __DIR__.'/album-discussion-native-cases.php';
 $part=getenv('PHPBB_ALBUM_DISCUSSION_PART')?:'';ad_check(in_array($part,array('','rating','cache','score-capacity'),true),'Known explicit discussion fixture scope');
 if($part==='score-capacity'){ad_score_capacity_cases();echo 'Native Album configured score capacity: '.$cases." cases PASS; actual HotOrNot controller128/255 and independent normal255.\n";return;}
 if($part==='cache'){require __DIR__.'/album-cache-publication-native-cases.php';ad_cache_cases();return;}
 if($part==='rating'){ad_hotornot_cases();ad_parallel_vote_cases();ad_rating_display_cases();}else{ad_discussion_cases();}
 echo 'Native Album discussion: '.$cases.' cases PASS; '.($part==='rating'?'targeted ratings, parallel workers and display SQL':(getenv('PHPBB_ALBUM_DISCUSSION_QUICK')==='1'?'quick comment/normal-vote functional checks only':'actual controllers, Unicode, current authority, atomic combined submission, separate channels and all SQL failure boundaries'))."; no live data.\n";
}finally{if($db){$db->sql_close();}if($created){ad_check(preg_match('/^codex_album_discussion_[a-f0-9]{16}$/D',$schema)===1,'Own discussion schema cleanup');$control->sql_query('DROP DATABASE '.$schema);}$control->sql_close();restore_error_handler();error_reporting($reporting);}
