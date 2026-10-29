<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pafiledb.php';

class PaFileDbRatingFailure extends RuntimeException {}
function pafiledb_rating_fail($key='PA_rating_failed') { throw new PaFileDbRatingFailure($key); }

// A separate, short-lived writer owns both its lease and transaction. Cached
// module permissions never authorize a vote after the file or account changes.
class PaFileDbRatingStore
{
 private $connection=null;
 private $identity;
 private $lock;
 private $held=false;
 private $transaction=false;
 private $failed=false;
 private $actor;
 private $file_id;
 private $finished=false;

 function __construct($database,$actor,$file_id)
 {
  $this->actor=$actor;$this->file_id=$file_id;$candidate=null;
  try {
   if(!is_object($database)||!method_exists($database,'sql_dedicated_connection')){pafiledb_rating_fail();}
   $source=$this->identity($database);$candidate=$database->sql_dedicated_connection();
   if(!is_object($candidate)||$candidate===$database||!isset($candidate->db_connect_id)||$candidate->db_connect_id===$database->db_connect_id){$candidate=null;pafiledb_rating_fail();}
   $own=$this->identity($candidate);if($own['schema_name']!==$source['schema_name']||$own['id']===$source['id']){$candidate=null;pafiledb_rating_fail();}
   $this->lock='attachment:'.md5($source['schema_name']."\0".ATTACHMENTS_TABLE);
   $owner=$this->direct_rows($candidate,"SELECT IS_USED_LOCK('".$this->lock."') owner");
   if(count($owner)!==1){pafiledb_rating_fail();}
   if($owner[0]['owner']!==null&&(string)$owner[0]['owner']===$own['id']){$candidate=null;pafiledb_rating_fail();}
   $this->connection=$candidate;$candidate=null;$this->identity=$own['id'];
   $lock=$this->direct_rows($this->connection,"SELECT GET_LOCK('".$this->lock."',3) held");
   if(count($lock)!==1||(string)$lock[0]['held']!=='1'){pafiledb_rating_fail();}$this->held=true;
   // The one-shot SET rejects an already active foreign transaction.
   $this->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
   $this->query('START TRANSACTION READ ONLY');$this->query('COMMIT');
   $this->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
   $this->query("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES','NO_ENGINE_SUBSTITUTION')");
   $this->query('SET SESSION innodb_lock_wait_timeout=5');
   $this->query('START TRANSACTION');$this->transaction=true;
   $this->pin_tables();
  } catch(Exception$error){if($candidate){$candidate->sql_close();}$this->release();throw$error;}
  catch(Error$error){if($candidate){$candidate->sql_close();}$this->release();pafiledb_rating_fail();}
 }
 private function direct_rows($database,$sql)
 {
  $r=$database->sql_query($sql);if(!$r){pafiledb_rating_fail();}
  try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}
  if(!is_array($rows)){pafiledb_rating_fail();}return$rows;
 }
 private function identity($database)
 {
  $rows=$this->direct_rows($database,'SELECT CONNECTION_ID() id,DATABASE() schema_name');
  if(count($rows)!==1||!is_string($rows[0]['schema_name'])||$rows[0]['schema_name']===''||!preg_match('/^[1-9][0-9]*$/D',(string)$rows[0]['id'])){pafiledb_rating_fail();}
  return array('id'=>(string)$rows[0]['id'],'schema_name'=>$rows[0]['schema_name']);
 }
 private function query($sql)
 {
  if(!$this->connection||$this->finished||$this->failed){pafiledb_rating_fail();}
  try{
   $state=$this->direct_rows($this->connection,"SELECT CONNECTION_ID() id,IS_USED_LOCK('".$this->lock."') owner");
   if(count($state)!==1||(string)$state[0]['id']!==$this->identity||(string)$state[0]['owner']!==$this->identity){pafiledb_rating_fail();}
   $r=$this->connection->sql_query($sql);if(!$r){pafiledb_rating_fail();}return$r;
  }catch(Exception$error){$this->failed=true;throw$error;}catch(Error$error){$this->failed=true;pafiledb_rating_fail();}
 }
 function rows($sql)
 {
  $r=$this->query($sql);try{$rows=$this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}
  if(!is_array($rows)){$this->failed=true;pafiledb_rating_fail();}return$rows;
 }
 private function escape($value){return$this->connection->sql_escape($value);}
 private function pin_tables()
 {
  foreach(array(PA_FILES_TABLE,PA_VOTES_TABLE,PA_CATEGORY_TABLE,PA_AUTH_ACCESS_TABLE,USERS_TABLE,SESSIONS_TABLE,USER_GROUP_TABLE,GROUPS_TABLE,CONFIG_TABLE)as$table){
   if(!is_string($table)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)){pafiledb_rating_fail();}
   $r=$this->query('SELECT * FROM '.$table.' LIMIT 0');$this->connection->sql_freeresult($r);$name=$this->escape($table);
   $meta=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND TABLE_TYPE='BASE TABLE'");
   $legacy=$this->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('".$name."') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci')");
   if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'||$legacy){pafiledb_rating_fail('PA_rating_upgrade');}
  }
 }
 private function authorized($level,$field,$grants,$user)
 {
  if(!in_array((string)$level,array((string)AUTH_ALL,(string)AUTH_REG,(string)AUTH_ACL,(string)AUTH_MOD,(string)AUTH_ADMIN),true)){pafiledb_rating_fail();}
  if((int)$level===AUTH_ALL){return true;}
  if(!$this->actor['logged']){return false;}
  if((int)$level===AUTH_REG){return true;}
  $policy=new pafiledb();
  return(bool)$policy->auth_check_user((int)$level,(int)$level===AUTH_MOD?'auth_mod':$field,$grants,(int)$user['user_level']===ADMIN);
 }
 function current()
 {
  $id=$this->actor['id'];$sid=$this->escape($this->actor['sid']);
  $ttl=$this->rows("SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name='session_length' FOR UPDATE");
  if(count($ttl)!==1||!preg_match('/^[1-9][0-9]{0,9}$/D',$ttl[0]['config_value'])||(float)$ttl[0]['config_value']>2147483647){pafiledb_rating_fail();}
  $session=$this->rows('SELECT session_id,session_user_id,session_logged_in,session_time FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."') FOR UPDATE");
  if(count($session)!==1||(int)$session[0]['session_user_id']!==$id||(int)$session[0]['session_logged_in']!==($this->actor['logged']?1:0)||(int)$session[0]['session_time']<time()-(int)$ttl[0]['config_value']){pafiledb_rating_fail('Session_invalid');}
  $user=array('user_id'=>ANONYMOUS,'user_level'=>USER);
  if($this->actor['logged']){
   $found=$this->rows('SELECT user_id,user_active,user_level FROM '.USERS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
   if(count($found)!==1||(int)$found[0]['user_active']!==1||!in_array((int)$found[0]['user_level'],array(USER,MOD,ADMIN),true)){pafiledb_rating_fail('Not_Authorised');}$user=$found[0];
  }
  $file=$this->rows('SELECT file_id,file_name,file_catid,file_approved FROM '.PA_FILES_TABLE.' WHERE file_id='.$this->file_id.' FOR UPDATE');
  if(count($file)!==1||(int)$file[0]['file_approved']!==1){pafiledb_rating_fail('File_not_exist');}$file=$file[0];
  $group_ids=array();$grants=array();
  if($this->actor['logged']){
   foreach($this->rows('SELECT group_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.$id.' ORDER BY group_id FOR UPDATE')as$membership){
    if((string)$membership['user_pending']!=='0'){continue;}$group=(int)$membership['group_id'];if($group<=0){pafiledb_rating_fail();}
    if(count($this->rows('SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.$group.' FOR UPDATE'))===1){$group_ids[]=$group;}
   }
   if($group_ids){$grants=$this->rows('SELECT * FROM '.PA_AUTH_ACCESS_TABLE.' WHERE group_id IN('.implode(',',array_unique($group_ids)).') ORDER BY group_id,cat_id FOR UPDATE');}
  }
  $category=(int)$file['file_catid'];$leaf=true;$visited=array();
  while($category!==0){
   if($category<1||isset($visited[$category])){pafiledb_rating_fail();}$visited[$category]=true;
   $categories=$this->rows('SELECT * FROM '.PA_CATEGORY_TABLE.' WHERE cat_id='.$category.' FOR UPDATE');if(count($categories)!==1){pafiledb_rating_fail('File_not_exist');}$cat=$categories[0];$access=array();foreach($grants as$grant){if((int)$grant['cat_id']===$category){$access[]=$grant;}}
   foreach($leaf?array('auth_view','auth_read','auth_view_file','auth_rate'):array('auth_view','auth_read')as$field){if(!$this->authorized($cat[$field],$field,$access,$user)){pafiledb_rating_fail('Not_Authorised');}}
   if($leaf&&(string)$cat['cat_allow_ratings']!=='1'){pafiledb_rating_fail('Not_Authorised');}
   $parent=$cat['cat_parent']===null?'0':(string)$cat['cat_parent'];
   if(!preg_match('/^[0-9]+$/D',$parent)||(float)$parent>2147483647){pafiledb_rating_fail();}$category=(int)$parent;$leaf=false;
  }
  if($leaf){pafiledb_rating_fail('File_not_exist');}return$file;
 }
 function vote($rating,$ip,$metadata)
 {
  $file=$this->current();$id=$this->actor['id'];$ip=$this->escape($ip);$duplicate=$this->actor['logged']?'user_id='.$id:"votes_ip='".$ip."'";
  if($this->rows('SELECT user_id FROM '.PA_VOTES_TABLE.' WHERE votes_file='.$this->file_id.' AND '.$duplicate.' FOR UPDATE')){pafiledb_rating_fail('Rerror');}
  $values=array();foreach($metadata as$value){$values[]="'".$this->escape($value)."'";}
  $this->current();
  $this->query('INSERT INTO '.PA_VOTES_TABLE.' (user_id,votes_ip,votes_file,rate_point,voter_os,voter_browser,browser_version) VALUES('.$id.",'".$ip."',".$this->file_id.','.$rating.','.implode(',',$values).')');
  if((int)$this->connection->sql_affectedrows()!==1){pafiledb_rating_fail();}
  $stored=$this->rows('SELECT user_id,rate_point FROM '.PA_VOTES_TABLE.' WHERE votes_file='.$this->file_id.' AND '.$duplicate);
  if(count($stored)!==1||(int)$stored[0]['user_id']!==$id||(int)$stored[0]['rate_point']!==$rating){pafiledb_rating_fail();}
  $totals=$this->rows('SELECT AVG(rate_point) average,COUNT(*) total FROM '.PA_VOTES_TABLE.' WHERE votes_file='.$this->file_id);
  if(count($totals)!==1||(int)$totals[0]['total']<1){pafiledb_rating_fail();}
  $file=$this->current();$this->query('COMMIT');$this->transaction=false;$this->finished=true;
  return array('file_name'=>$file['file_name'],'average'=>$totals[0]['average'],'total'=>$totals[0]['total'],'rating'=>$rating);
 }
 function release()
 {
  $connection=$this->connection;$this->connection=null;$this->finished=true;
  if($connection){if($this->transaction){try{$connection->sql_query('ROLLBACK');}catch(Exception$ignored){}catch(Error$ignored){}}$this->transaction=false;try{$connection->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}$this->held=false;
 }
}

function pafiledb_submit_rating($database,$file_id,$rating,$metadata)
{
 global $userdata,$user_ip;
 if(!(is_int($file_id)||is_string($file_id))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$file_id)||(float)$file_id>2147483647||!is_int($rating)||$rating<1||$rating>10){pafiledb_rating_fail('PA_rating_invalid');}
 if(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!isset($userdata['session_id'])||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($userdata['session_id'],$_POST['sid'])){pafiledb_rating_fail('Session_invalid');}
 $logged=isset($userdata['session_logged_in'])&&in_array($userdata['session_logged_in'],array(true,1,'1'),true);$id=isset($userdata['user_id'])?$userdata['user_id']:null;
 if(!(is_int($id)||is_string($id))||($logged?(!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$id)||(int)$id>8388607):((string)$id!==(string)ANONYMOUS))){pafiledb_rating_fail('Not_Authorised');}
 if(!is_string($user_ip)||!preg_match('/^(?:[a-f0-9]{8}|[a-f0-9]{32})$/Di',$user_ip)||!is_array($metadata)||count($metadata)!==3){pafiledb_rating_fail();}
 $bounded=array();foreach(array_values($metadata)as$i=>$value){if(!is_string($value)||preg_match('//u',$value)!==1){$value='';}preg_match_all('/./us',$value,$chars);$bounded[]=implode('',array_slice($chars[0],0,$i===2?8:255));}
 $owner=null;
 try{$owner=new PaFileDbRatingStore($database,array('id'=>(int)$id,'sid'=>$userdata['session_id'],'logged'=>$logged),(int)$file_id);return$owner->vote($rating,strtolower($user_ip),$bounded);}
 catch(PaFileDbRatingFailure$error){throw$error;}catch(Exception$error){pafiledb_rating_fail();}catch(Error$error){pafiledb_rating_fail();}
 finally{if($owner){$owner->release();}}
}
