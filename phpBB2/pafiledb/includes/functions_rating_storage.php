<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_mutation_storage.php';

class PaFileDbRatingFailure extends PaFileDbMutationFailure {}
function pafiledb_rating_fail($key='PA_rating_failed'){throw new PaFileDbRatingFailure($key);}

class PaFileDbRatingStore extends PaFileDbMutationStore
{
 protected function storage_tables(){return array(PA_FILES_TABLE,PA_VOTES_TABLE,PA_CATEGORY_TABLE,PA_AUTH_ACCESS_TABLE,USERS_TABLE,SESSIONS_TABLE,USER_GROUP_TABLE,GROUPS_TABLE,CONFIG_TABLE);}
 function current()
 {
  $context=$this->current_context();$cat=$context['category'];
  if((string)$cat['cat_allow_ratings']!=='1'||!$this->authorized($cat['auth_rate'],'auth_rate',$context['access'],$context['user'])){pafiledb_rating_fail('Not_Authorised');}
  return$context['file'];
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
  $file=$this->current();$this->commit();
  return array('file_name'=>$file['file_name'],'average'=>$totals[0]['average'],'total'=>$totals[0]['total'],'rating'=>$rating);
 }
}

function pafiledb_submit_rating($database,$file_id,$rating,$metadata)
{
 global $userdata,$user_ip;
 if(!(is_int($file_id)||is_string($file_id))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$file_id)||(float)$file_id>2147483647||!is_int($rating)||$rating<1||$rating>10){pafiledb_rating_fail('PA_rating_invalid');}
 if(!is_string($user_ip)||!preg_match('/^(?:[a-f0-9]{8}|[a-f0-9]{32})$/Di',$user_ip)||!is_array($metadata)||count($metadata)!==3){pafiledb_rating_fail();}
 $bounded=array();foreach(array_values($metadata)as$i=>$value){if(!is_string($value)||preg_match('//u',$value)!==1){$value='';}preg_match_all('/./us',$value,$chars);$bounded[]=implode('',array_slice($chars[0],0,$i===2?8:255));}
 $owner=null;
 try{$actor=pafiledb_mutation_actor();$owner=new PaFileDbRatingStore($database,$actor,(int)$file_id);return$owner->vote($rating,strtolower($user_ip),$bounded);}
 catch(PaFileDbRatingFailure$error){throw$error;}catch(PaFileDbMutationFailure$error){$key=$error->getMessage();pafiledb_rating_fail($key==='PA_storage_failed'?'PA_rating_failed':($key==='PA_storage_upgrade'?'PA_rating_upgrade':$key));}catch(Exception$error){pafiledb_rating_fail();}catch(Error$error){pafiledb_rating_fail();}
 finally{if($owner){$owner->release();}}
}
