<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(dirname(dirname(__FILE__))).'/includes/functions_owned_storage.php';
require_once dirname(dirname(dirname(__FILE__))).'/includes/functions_acl_storage.php';

class PaFileDbCategoryFailure extends RuntimeException{}
function pafiledb_category_fail($key='PA_category_failed'){throw new PaFileDbCategoryFailure($key);}
function pafiledb_category_number($value,$zero=false)
{
 if(!(is_int($value)||is_string($value))||!preg_match($zero?'/^(0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',(string)$value)||(float)$value>2147483647){pafiledb_category_fail('PA_category_invalid');}
 return(int)$value;
}
function pafiledb_category_values($post)
{
 $values=array();
 foreach(array('cat_name','cat_desc')as$field){
  $text=isset($post[$field])?phpbb_request_raw_value($post[$field]):'';
  if(!is_string($text)||preg_match('//u',$text)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)){pafiledb_category_fail('PA_category_invalid');}
  if($field==='cat_name'&&trim($text)===''){pafiledb_category_fail('Cat_name_missing');}
  $values[$field]=htmlspecialchars($text,ENT_QUOTES,'UTF-8');
  if(strlen($values[$field])>65535){pafiledb_category_fail('PA_category_invalid');}
 }
 $values['cat_parent']=pafiledb_category_number(isset($post['cat_parent'])?$post['cat_parent']:'0',true);
 foreach(array('cat_allow_file','cat_allow_ratings','cat_allow_comments')as$field){
  $value=isset($post[$field])?$post[$field]:'0';
  if(!(is_int($value)||is_string($value))||!in_array((string)$value,array('0','1'),true)){pafiledb_category_fail('PA_category_invalid');}
  $values[$field]=(int)$value;
 }
 return$values;
}
class PaFileDbCategoryStore extends PhpbbOwnedStore
{
 protected function storage_tables(){return array(PA_CATEGORY_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,CONFIG_TABLE);}
 protected function storage_fail($key='Owned_storage_failed'){pafiledb_category_fail(str_replace('Owned_storage_','PA_category_',$key));}
 // The shared ACP reader receives this owning connection, never the request's
 // ordinary connection. Only its SELECT interface is exposed.
 function sql_query($sql,$transaction=false){if(!is_string($sql)||!preg_match('/^\s*SELECT\b/i',$sql)){$this->storage_fail();}return$this->query($sql);}
 function sql_fetchrow($result){return$this->connection->sql_fetchrow($result);}
 function sql_fetchrowset($result){return$this->connection->sql_fetchrowset($result);}
 function sql_freeresult($result){return$this->connection->sql_freeresult($result);}
 function sql_escape($value){return$this->escape($value);}
 protected function current_actor()
 {
  global $phpEx,$userdata;
  if(!isset($userdata['user_id'],$userdata['session_id'])||(string)$userdata['user_id']!==(string)$this->actor['id']||!is_string($userdata['session_id'])||!hash_equals($this->actor['sid'],$userdata['session_id'])||empty($userdata['session_logged_in'])||empty($userdata['session_admin'])){pafiledb_category_fail('Not_Authorised');}
  $id=$this->actor['id'];$sid=$this->escape($this->actor['sid']);
  $ttl=$this->rows("SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name='session_length' FOR UPDATE");
  if(count($ttl)!==1||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$ttl[0]['config_value'])||(float)$ttl[0]['config_value']>2147483647){$this->storage_fail();}
  $sessions=$this->rows('SELECT session_id,session_user_id,session_logged_in,session_admin,session_time FROM '.SESSIONS_TABLE." WHERE HEX(session_id)=HEX('".$sid."') FOR UPDATE");
  if(count($sessions)!==1||(int)$sessions[0]['session_user_id']!==$id||(string)$sessions[0]['session_logged_in']!=='1'||(string)$sessions[0]['session_admin']!=='1'||(int)$sessions[0]['session_time']<time()-(int)$ttl[0]['config_value']){pafiledb_category_fail('Session_invalid');}
  $users=$this->rows('SELECT user_id,user_level,user_active FROM '.USERS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
  if(count($users)!==1||(string)$users[0]['user_active']!=='1'){pafiledb_category_fail('Not_Authorised');}
  $this->rows('SELECT user_id FROM '.JR_ADMIN_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
  try{phpbb_acp_actor($this,'admin_pa_category.'.$phpEx);}catch(PhpbbAclException$error){pafiledb_category_fail('Not_Authorised');}
 }
 protected function parent_id($row){return$row['cat_parent']===null?0:pafiledb_category_number($row['cat_parent'],true);}
 protected function tree($id,$values)
 {
  $rows=$this->rows('SELECT * FROM '.PA_CATEGORY_TABLE.' ORDER BY cat_id FOR UPDATE');$tree=array();
  foreach($rows as$row){$key=pafiledb_category_number($row['cat_id']);if(isset($tree[$key])){$this->storage_fail();}$tree[$key]=$row;}
  if($id&&!isset($tree[$id])){pafiledb_category_fail('PA_category_changed');}
  $parent=$values['cat_parent'];$seen=array();
  while($parent!==0){
   if($parent===$id||isset($seen[$parent])||!isset($tree[$parent])){pafiledb_category_fail('PA_category_tree_invalid');}
   $seen[$parent]=true;$parent=$this->parent_id($tree[$parent]);
  }
  $parent=$values['cat_parent'];
  if($parent&&!$tree[$parent]['cat_allow_file']&&!$values['cat_allow_file']){pafiledb_category_fail('Cat_conflict');}
  if($id&&!$values['cat_allow_file']&&!empty($tree[$id]['cat_allow_file'])){
   foreach($tree as$row){if($this->parent_id($row)===$id&&!$row['cat_allow_file']){pafiledb_category_fail('Cat_conflict');}}
  }
  return$tree;
 }
 protected function reorder($parent)
 {
  $where=$parent===0?'(cat_parent=0 OR cat_parent IS NULL)':'cat_parent='.$parent;
  $rows=$this->rows('SELECT cat_id FROM '.PA_CATEGORY_TABLE.' WHERE '.$where.' ORDER BY cat_order,cat_id FOR UPDATE');$order=10;
  foreach($rows as$row){$this->current_actor();$this->query('UPDATE '.PA_CATEGORY_TABLE.' SET cat_order='.$order.' WHERE cat_id='.(int)$row['cat_id']);$order+=10;}
 }
 function save($id,$values)
 {
  $this->current_actor();$tree=$this->tree($id,$values);$creating=$id===0;$parent=$values['cat_parent'];$old_parent=$creating?0:$this->parent_id($tree[$id]);
  $name=$this->escape($values['cat_name']);$description=$this->escape($values['cat_desc']);
  $this->current_actor();
  if($creating){
   $order=0;foreach($tree as$row){if($this->parent_id($row)===$parent){$order=max($order,(int)$row['cat_order']);}}
   if($order>2147483637){pafiledb_category_fail('PA_category_invalid');}$order+=10;
   $this->query('INSERT INTO '.PA_CATEGORY_TABLE." (cat_name,cat_desc,cat_parent,parents_data,cat_order,cat_allow_file,cat_allow_ratings,cat_allow_comments) VALUES('".$name."','".$description."',".$parent.",'',".$order.','.$values['cat_allow_file'].','.$values['cat_allow_ratings'].','.$values['cat_allow_comments'].')');
   $id=pafiledb_category_number($this->connection->sql_nextid());
  }else{
   $this->query('UPDATE '.PA_CATEGORY_TABLE." SET cat_name='".$name."',cat_desc='".$description."',cat_parent=".$parent.',cat_allow_file='.$values['cat_allow_file'].',cat_allow_ratings='.$values['cat_allow_ratings'].',cat_allow_comments='.$values['cat_allow_comments'].' WHERE cat_id='.$id);
   if($old_parent!==$parent){$this->reorder($old_parent);$this->reorder($parent);}
  }
  $this->current_actor();
  // Keep derived file metadata in the same transaction; the form controller
  // must not issue deferred writes after authority/connection release.
  $this->query('UPDATE '.PA_CATEGORY_TABLE." SET cat_files=-1,cat_last_file_id=0,cat_last_file_name='',cat_last_file_time=0");
  $stored=$this->rows('SELECT * FROM '.PA_CATEGORY_TABLE.' WHERE cat_id='.$id.' FOR UPDATE');
  if(count($stored)!==1){$this->storage_fail();}
  foreach($values as$key=>$value){if((string)$stored[0][$key]!== (string)$value){$this->storage_fail();}}
  if($creating&&(string)$stored[0]['parents_data']!==''){$this->storage_fail();}
  if(!$creating&&(string)$stored[0]['parents_data']!==(string)$tree[$id]['parents_data']){$this->storage_fail();}
  $this->current_actor();$this->commit();return$id;
 }
}
function pafiledb_category_save($database,$id,$post)
{
 global $userdata;
 if(!is_array($post)||!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||empty($userdata['session_id'])||!is_string($userdata['session_id'])||strlen($userdata['session_id'])>32||!isset($post['sid'])||!is_string($post['sid'])||!hash_equals($userdata['session_id'],$post['sid'])){pafiledb_category_fail('Session_invalid');}
 if(empty($userdata['session_logged_in'])||empty($userdata['session_admin'])){pafiledb_category_fail('Not_Authorised');}
 $actor=array('id'=>pafiledb_category_number(isset($userdata['user_id'])?$userdata['user_id']:null),'sid'=>$userdata['session_id']);
 $id=$id===false?0:pafiledb_category_number($id,true);$values=pafiledb_category_values($post);$store=null;
 try{$store=new PaFileDbCategoryStore($database,$actor);return$store->save($id,$values);}
 catch(PaFileDbCategoryFailure$error){throw$error;}
 catch(Exception$error){pafiledb_category_fail();}
 catch(Error$error){pafiledb_category_fail();}
 finally{if($store){$store->release();}}
}
