<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_pafiledb.php';

class PaFileDbMutationFailure extends RuntimeException {}
function pafiledb_mutation_fail($key='PA_storage_failed'){throw new PaFileDbMutationFailure($key);}

// Own the lease/connection/transaction; each action checks its own feature
// permission against current locked account, session, file and ancestor rows.
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions_owned_storage.php';
trait PaFileDbCurrentContext
{
 protected function authorized($level,$field,$grants,$user)
 {
  if(!in_array((string)$level,array((string)AUTH_ALL,(string)AUTH_REG,(string)AUTH_ACL,(string)AUTH_MOD,(string)AUTH_ADMIN),true)){pafiledb_mutation_fail();}
  if((int)$level===AUTH_ALL){return true;}
  if(!$this->actor['logged']){return false;}
  if((int)$level===AUTH_REG){return true;}
  $policy=new pafiledb();
  return(bool)$policy->auth_check_user((int)$level,(int)$level===AUTH_MOD?'auth_mod':$field,$grants,(int)$user['user_level']===ADMIN);
 }
 protected function current_context()
 {
  $id=$this->actor['id'];$sid=$this->escape($this->actor['sid']);
  $ttl=$this->rows("SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name='session_length' FOR UPDATE");
  if(count($ttl)!==1||!preg_match('/^[1-9][0-9]{0,9}$/D',$ttl[0]['config_value'])||(float)$ttl[0]['config_value']>2147483647){pafiledb_mutation_fail();}
  $session=$this->rows('SELECT session_id,session_user_id,session_logged_in,session_time FROM '.SESSIONS_TABLE." WHERE session_id='".$sid."' AND HEX(session_id)=HEX('".$sid."') FOR UPDATE");
  if(count($session)!==1||(int)$session[0]['session_user_id']!==$id||(int)$session[0]['session_logged_in']!==($this->actor['logged']?1:0)||(int)$session[0]['session_time']<time()-(int)$ttl[0]['config_value']){pafiledb_mutation_fail('Session_invalid');}
  $found=$this->rows('SELECT user_id,user_active,user_level,user_allowhtml,user_allowbbcode,user_allowsmile FROM '.USERS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
  if(count($found)!==1||($this->actor['logged']&&((int)$found[0]['user_active']!==1||!in_array((int)$found[0]['user_level'],array(USER,MOD,ADMIN),true)))){pafiledb_mutation_fail('Not_Authorised');}
  $user=$found[0];if(!$this->actor['logged']){$user['user_level']=USER;}
  $files=$this->rows('SELECT file_id,file_name,file_catid,file_approved FROM '.PA_FILES_TABLE.' WHERE file_id='.$this->file_id.' FOR UPDATE');
  if(count($files)!==1||(int)$files[0]['file_approved']!==1){pafiledb_mutation_fail('File_not_exist');}$file=$files[0];
  $group_ids=array();$grants=array();
  if($this->actor['logged']){
   foreach($this->rows('SELECT group_id,user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.$id.' ORDER BY group_id FOR UPDATE')as$membership){
    if((string)$membership['user_pending']!=='0'){continue;}$group=(int)$membership['group_id'];if($group<=0){pafiledb_mutation_fail();}
    if(count($this->rows('SELECT group_id FROM '.GROUPS_TABLE.' WHERE group_id='.$group.' FOR UPDATE'))===1){$group_ids[]=$group;}
   }
   if($group_ids){$grants=$this->rows('SELECT * FROM '.PA_AUTH_ACCESS_TABLE.' WHERE group_id IN('.implode(',',array_unique($group_ids)).') ORDER BY group_id,cat_id FOR UPDATE');}
  }
  $category=(int)$file['file_catid'];$leaf=true;$visited=array();$leaf_cat=$leaf_access=null;
  while($category!==0){
   if($category<1||isset($visited[$category])){pafiledb_mutation_fail();}$visited[$category]=true;
   $categories=$this->rows('SELECT * FROM '.PA_CATEGORY_TABLE.' WHERE cat_id='.$category.' FOR UPDATE');if(count($categories)!==1){pafiledb_mutation_fail('File_not_exist');}$cat=$categories[0];$access=array();foreach($grants as$grant){if((int)$grant['cat_id']===$category){$access[]=$grant;}}
   foreach($leaf?array('auth_view','auth_read','auth_view_file'):array('auth_view','auth_read')as$field){if(!$this->authorized($cat[$field],$field,$access,$user)){pafiledb_mutation_fail('Not_Authorised');}}
   if($leaf){$leaf_cat=$cat;$leaf_access=$access;}
   $parent=$cat['cat_parent']===null?'0':(string)$cat['cat_parent'];if(!preg_match('/^[0-9]+$/D',$parent)||(float)$parent>2147483647){pafiledb_mutation_fail();}$category=(int)$parent;$leaf=false;
  }
  if($leaf){pafiledb_mutation_fail('File_not_exist');}
  return array('file'=>$file,'user'=>$user,'category'=>$leaf_cat,'access'=>$leaf_access);
 }
}

abstract class PaFileDbMutationStore extends PhpbbOwnedStore
{
 use PaFileDbCurrentContext;
 protected $file_id;
 function __construct($database,$actor,$file_id) { $this->file_id=$file_id; parent::__construct($database,$actor); }
 protected function storage_fail($key='Owned_storage_failed') { pafiledb_mutation_fail(str_replace('Owned_storage_','PA_storage_',$key)); }
}

function pafiledb_mutation_actor()
{
 global $userdata;
 if(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!isset($userdata['session_id'])||!is_string($userdata['session_id'])||$userdata['session_id']===''||strlen($userdata['session_id'])>32||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($userdata['session_id'],$_POST['sid'])){pafiledb_mutation_fail('Session_invalid');}
 $logged=isset($userdata['session_logged_in'])&&in_array($userdata['session_logged_in'],array(true,1,'1'),true);$id=isset($userdata['user_id'])?$userdata['user_id']:null;
 if(!(is_int($id)||is_string($id))||($logged?(!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$id)||(int)$id>8388607):((string)$id!==(string)ANONYMOUS))){pafiledb_mutation_fail('Not_Authorised');}
 return array('id'=>(int)$id,'sid'=>$userdata['session_id'],'logged'=>$logged);
}
function pafiledb_mutation_id($value)
{
 if(!(is_int($value)||is_string($value))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)||(float)$value>2147483647){pafiledb_mutation_fail('File_not_exist');}
 return(int)$value;
}
