<?php
// Real removal workers and canonical MariaDB tables, not HTTP bootstrap/mail.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');require __DIR__.'/check-attachment-settings-storage.php';
foreach(array('IN_ADMIN'=>true,'USER'=>0,'MOD'=>2,'DELETED'=>-1,'ANONYMOUS'=>-1,
 'GROUPS_TABLE'=>'fixture_groups','USER_GROUP_TABLE'=>'fixture_user_group','AUTH_ACCESS_TABLE'=>'fixture_auth_access','FORUMS_TABLE'=>'fixture_forums',
 'POSTS_TABLE'=>'fixture_posts','TOPICS_TABLE'=>'fixture_topics','TOPICS_WATCH_TABLE'=>'fixture_topics_watch','BOOKMARK_TABLE'=>'fixture_bookmarks',
 'BANLIST_TABLE'=>'fixture_banlist','SESSIONS_KEYS_TABLE'=>'fixture_sessions_keys','SHOUTBOX_TABLE'=>'fixture_shout','VOTE_USERS_TABLE'=>'fixture_vote_voters',
 'PM_WRITE_RECEIPTS_TABLE'=>'fixture_pm_write_receipts','PRIVMSGS_TABLE'=>'fixture_privmsgs','PRIVMSGS_TEXT_TABLE'=>'fixture_privmsgs_text',
 'USER_REMOVALS_TABLE'=>'fixture_user_removals','USER_REMOVAL_ITEMS_TABLE'=>'fixture_user_removal_items','PA_AUTH_ACCESS_TABLE'=>'fixture_pa_auth',
 'PRIVMSGS_READ_MAIL'=>0,'PRIVMSGS_NEW_MAIL'=>1,'PRIVMSGS_SENT_MAIL'=>2,'PRIVMSGS_SAVED_IN_MAIL'=>3,'PRIVMSGS_SAVED_OUT_MAIL'=>4,'PRIVMSGS_UNREAD_MAIL'=>5,'PRIVMSGS_PENDING_SENT_MAIL'=>6)as$k=>$v){if(!defined($k)){define($k,$v);}}
require $ats_source.'includes/functions_user_prune.php';
if(PHP_SAPI!=='cli'||getenv('PHPBB_USER_BAN_LIFECYCLE_NATIVE')!=='1'){echo "Native account-ban lifecycle checks require an explicitly enabled disposable database.\n";return;}
require $ats_source.'db/mysqli.php';
$port=getenv('PHPBB_USER_BAN_LIFECYCLE_PORT')?:'3306';$password=getenv('PHPBB_USER_BAN_LIFECYCLE_PASSWORD')?:'';
ats_check(preg_match('/^[0-9]{1,5}$/D',$port)&&(int)$port>0&&(int)$port<=65535,'Loopback fixture port');
$host='127.0.0.1:'.$port;$fixture='codex_user_ban_lifecycle_'.bin2hex(phpbb_random_bytes(8));
$control=new sql_db($host,'root',$password,'',false);ats_check($control->sql_query('CREATE DATABASE '.$fixture.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'),'Owned fixture');
class UblConnection{
 var $db;var $db_connect_id;
 function __construct($db){$this->db=$db;$this->db_connect_id=$db->db_connect_id;ats_check($db->sql_query("SET SESSION sql_mode='".$db->sql_escape($GLOBALS['ubl_mode'])."'"),'Actual owner SQL mode');}
 function __call($name,$args){return call_user_func_array(array($this->db,$name),$args);}
 function sql_query($sql,$tx=false){if(is_callable($GLOBALS['ubl_hook'])){call_user_func($GLOBALS['ubl_hook'],$sql);}if($GLOBALS['ubl_failure']!==''&&strpos($sql,$GLOBALS['ubl_failure'])===0){return false;}$r=$this->db->sql_query($sql,$tx);if(!$r){$GLOBALS['ubl_failed_sql']=array($sql,$this->db->sql_error());}return $r;}
}
class UblDatabase extends sql_db{function sql_dedicated_connection(){return new UblConnection(parent::sql_dedicated_connection());}}
$main=new UblDatabase($host,'root',$password,$fixture,false);$peer=new sql_db($host,'root',$password,$fixture,false);$db=$main;
$ubl_mode='';$ubl_failure='';$ubl_hook=null;
$ubl_tables=array('users','sessions','sessions_keys','jr_admin_users','groups','user_group','auth_access','forums','posts','topics','topics_watch','bookmarks','banlist','shout','removed_author_names','vote_voters','pm_write_receipts','privmsgs','privmsgs_text','user_removals','user_removal_items','pa_auth','attach_quota','attachments','attachments_desc');
function ubl_sql($sql){$r=$GLOBALS['peer']->sql_query($sql);ats_check($r,'Fixture SQL: '.json_encode($GLOBALS['peer']->sql_error()));return $r;}
function ubl_rows($sql){$r=ubl_sql($sql);$rows=$GLOBALS['peer']->sql_fetchrowset($r);$GLOBALS['peer']->sql_freeresult($r);foreach($rows as &$row){foreach(array_keys($row)as$key){if(is_int($key)){unset($row[$key]);}}}unset($row);return $rows;}
function ubl_insert($table,$values){foreach(ubl_rows('SHOW COLUMNS FROM '.$table)as$c){if(!array_key_exists($c['Field'],$values)&&$c['Null']==='NO'&&$c['Default']===null&&strpos($c['Extra'],'auto_increment')===false){$values[$c['Field']]=preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double|decimal)/',$c['Type'])?0:'';}}$encoded=array();foreach($values as$v){$encoded[]=$v===null?'NULL':"'".$GLOBALS['peer']->sql_escape((string)$v)."'";}ubl_sql('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',$encoded).')');}
function ubl_reset(){
 global $userdata,$ubl_failure,$ubl_hook;
 $ubl_failure='';$ubl_hook=null;ubl_sql('START TRANSACTION');foreach($GLOBALS['ubl_tables']as$s){ubl_sql('DELETE FROM fixture_'.($s==='jr_admin_users'?'jr':$s));}ubl_sql('COMMIT');ubl_sql('ALTER TABLE fixture_banlist AUTO_INCREMENT=1');
 foreach(array(1,2,3)as$id){ubl_insert('fixture_users',array('user_id'=>$id,'username'=>'fixture-'.$id,'user_level'=>$id===1?1:0,'user_active'=>$id===2?0:1,'user_regdate'=>100,'user_password'=>'owned-fixture'));}
 $userdata=array('user_id'=>1,'session_id'=>'fixture-admin','session_logged_in'=>true,'session_admin'=>true);
 foreach(array('fixture-admin'=>1,'target-session'=>2,'other-session'=>3)as$sid=>$id){ubl_insert('fixture_sessions',array('session_id'=>$sid,'session_user_id'=>$id,'session_logged_in'=>1,'session_admin'=>$id===1?1:0));}
 foreach(array(2,3)as$id){ubl_insert('fixture_sessions_keys',array('key_id'=>md5('fixture-'.$id),'user_id'=>$id));}
 ubl_insert('fixture_banlist',array('ban_userid'=>2,'ban_ip'=>'7f0000ff','ban_ip_mask'=>'ffffffff','ban_email'=>'keep@example.invalid'));
 ubl_insert('fixture_banlist',array('ban_userid'=>2,'ban_ip'=>'0aff0000','ban_ip_mask'=>'ff00ffff','ban_email'=>null));
 ubl_insert('fixture_banlist',array('ban_userid'=>2,'ban_ip'=>'','ban_ip_mask'=>'','ban_email'=>'mail@example.invalid'));
 ubl_insert('fixture_banlist',array('ban_userid'=>2,'ban_ip'=>'','ban_ip_mask'=>'','ban_email'=>null));
 ubl_insert('fixture_banlist',array('ban_userid'=>3,'ban_ip'=>'7f0000ff','ban_ip_mask'=>'ffffffff','ban_email'=>'other@example.invalid'));
 $_SERVER['REQUEST_METHOD']='POST';
}
function ubl_components(){return ubl_rows("SELECT ban_id,ban_ip,ban_ip_mask,ban_email FROM fixture_banlist WHERE COALESCE(ban_ip,'')<>'' OR COALESCE(ban_email,'')<>'' ORDER BY ban_id");}
function ubl_run($workflow,$token=null){
 $post=array('sid'=>'fixture-admin');if($token!==null){$post['removal_resume']=$token;}else{$post['delete']=2;}
 if($workflow==='user'){unset($post['delete']);if($token===null){$post+=array('deleteuser'=>'on','mode'=>'save','submit'=>'Save','id'=>2);}return phpbb_admin_user_remove($GLOBALS['main'],$post);}
 if($workflow==='prune'){unset($post['delete']);if($token===null){$post['confirm']='1';}return phpbb_prune_user_remove($GLOBALS['main'],$post,$token===null?2:null,$token===null?array('mode'=>'user_id','days'=>1,'at'=>time()):null);}
 return phpbb_inactive_user_remove($GLOBALS['main'],$post);
}
function ubl_done($before){
 ats_check(ubl_components()===$before,'Exact independent IP/mask/email/ID preservation');
 ats_check(!ubl_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2')&&count(ubl_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=0'))===3&&count(ubl_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=3'))===1,'Only removed user components disappear');
 ats_check(!ubl_rows('SELECT user_id FROM fixture_users WHERE user_id=2')&&!ubl_rows('SELECT job_id FROM fixture_user_removals')&&!ubl_rows('SELECT job_id FROM fixture_user_removal_items'),'Complete actual removal clears account/recovery metadata');
 ats_check(!ubl_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')&&!ubl_rows('SELECT user_id FROM fixture_sessions_keys WHERE user_id=2')&&count(ubl_rows('SELECT user_id FROM fixture_sessions_keys WHERE user_id=3'))===1,'Only removed account credentials revoked');
 $lock=new attach_mutation_lock($GLOBALS['peer'],false);ats_check($lock->acquired,'Actual removal releases its owner');$lock->release();
}
try{
 set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
 $schema=file_get_contents($ats_source.'install/schemas/mysql_schema.sql');foreach($ubl_tables as$s){ats_check(preg_match('/CREATE TABLE phpbb_'.$s.'\s*\([\s\S]*?;/',$schema,$m)===1,'Canonical participant '.$s);$name='fixture_'.($s==='jr_admin_users'?'jr':$s);ubl_sql(str_replace('phpbb_'.$s,$name,$m[0]));preg_match_all('/ALTER TABLE phpbb_'.$s.'\s+[\s\S]*?;/',$schema,$extra);foreach($extra[0]as$ddl){ubl_sql(str_replace('phpbb_'.$s,$name,$ddl));}}
 $cases=0;
 foreach(array('','STRICT_ALL_TABLES','ANSI_QUOTES','NO_BACKSLASH_ESCAPES')as$ubl_mode){ubl_sql("SET SESSION sql_mode='$ubl_mode'");foreach(array('inactive','user','prune')as$workflow){foreach(array('','DELETE FROM fixture_banlist','UPDATE fixture_banlist')as$failure){
  ubl_reset();$before=ubl_components();$ubl_failure=$failure;$failed=false;
  try{$result=ubl_run($workflow);}catch(PhpbbRemovalException $e){$failed=true;if($failure===''){throw new RuntimeException('Unexpected '.$workflow.' '.$ubl_mode.' failure: '.$e->getMessage().' '.json_encode(isset($ubl_failed_sql)?$ubl_failed_sql:null));}}
  if($failure!==''){ats_check($failed&&ubl_components()===$before,'Partial ban failure preserves independent components');$jobs=ubl_rows('SELECT job_id FROM fixture_user_removals');ats_check(count($jobs)===1,'Durable recovery retained');$ubl_failure='';$result=ubl_run($workflow,$jobs[0]['job_id']);}
  ats_check(($workflow==='prune'?$result['status']:$result)==='Removal_completed','Actual complete worker succeeds');ubl_done($before);$cases++;
 }}}
 foreach(array('DELETE FROM fixture_banlist','UPDATE fixture_banlist')as$boundary){
  ubl_reset();$before=ubl_components();$ubl_hook=function($sql)use($boundary){if(strpos($sql,$boundary)===0){$GLOBALS['ubl_hook']=null;ubl_insert('fixture_users',array('user_id'=>2,'username'=>'Restored','user_active'=>1));}};
  $failed=false;try{ubl_run('user');}catch(PhpbbRemovalException $e){$failed=true;}
  ats_check($failed&&ubl_components()===$before&&count(ubl_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===($boundary==='DELETE FROM fixture_banlist'?4:3),'Each actual ban write protects a restored account');$cases++;
 }
 echo 'Native removed-account mixed-ban lifecycle: '.$cases." canonical SQL-mode/recovery cases passed.\n";
}finally{$ubl_hook=null;$ubl_failure='';$main->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$fixture);$control->sql_close();restore_error_handler();}
