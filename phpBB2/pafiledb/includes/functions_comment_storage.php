<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_mutation_storage.php';
require_once dirname(__FILE__).'/functions_comment_text.php';

class PaFileDbCommentFailure extends PaFileDbMutationFailure
{
 public $limit;
 function __construct($key,$limit=null){$this->limit=$limit;parent::__construct($key);}
}
class PaFileDbCommentStore extends PaFileDbMutationStore
{
 private $mode;
 private $comment_id;
 function __construct($database,$actor,$file_id,$mode,$comment_id){$this->mode=$mode;$this->comment_id=$comment_id;parent::__construct($database,$actor,$file_id);}
 protected function storage_tables(){return array(PA_FILES_TABLE,PA_COMMENTS_TABLE,PA_CONFIG_TABLE,PA_CATEGORY_TABLE,PA_AUTH_ACCESS_TABLE,USERS_TABLE,SESSIONS_TABLE,USER_GROUP_TABLE,GROUPS_TABLE,CONFIG_TABLE);}
 function current()
 {
  $context=$this->current_context();$cat=$context['category'];$mod=$this->actor['logged']&&$this->authorized(AUTH_MOD,'auth_mod',$context['access'],$context['user']);
  if($this->mode==='post'){
   if((string)$cat['cat_allow_comments']!=='1'||!$this->authorized($cat['auth_post_comment'],'auth_post_comment',$context['access'],$context['user'])){pafiledb_mutation_fail('Not_Authorised');}
  }else{
   $comments=$this->rows('SELECT * FROM '.PA_COMMENTS_TABLE.' WHERE comments_id='.$this->comment_id.' AND file_id='.$this->file_id.' FOR UPDATE');if(count($comments)!==1){pafiledb_mutation_fail('File_not_exist');}$comment=$comments[0];
   $own=$this->actor['logged']&&(int)$comment['poster_id']===$this->actor['id']&&$this->authorized($cat['auth_delete_comment'],'auth_delete_comment',$context['access'],$context['user']);
   if(!$mod&&!$own){pafiledb_mutation_fail('Not_Authorised');}$context['comment']=$comment;
  }
  return$context;
 }
 private function policy()
 {
  $settings=array();foreach($this->rows("SELECT config_name,config_value FROM ".PA_CONFIG_TABLE." WHERE config_name IN('allow_html','allow_bbcode','allow_smilies','max_comment_chars') ORDER BY config_name FOR UPDATE")as$row){$settings[$row['config_name']]=$row['config_value'];}
  if(count($settings)!==4){pafiledb_mutation_fail();}
  $tags=$this->rows("SELECT config_value FROM ".CONFIG_TABLE." WHERE config_name='allow_html_tags' FOR UPDATE");if(count($tags)!==1){pafiledb_mutation_fail();}$settings['_html_tags']=$tags[0]['config_value'];return$settings;
 }
 function save($title,$message)
 {
  $context=$this->current();$settings=$this->policy();$capacities=pafiledb_comment_capacities($this);$prepared=pafiledb_comment_prepare($title,$message,$settings,$context['user'],$capacities);$this->current();
  $now=time();$this->query('INSERT INTO '.PA_COMMENTS_TABLE.' (file_id,comments_text,comments_title,comments_time,comment_bbcode_uid,poster_id) VALUES('.$this->file_id.",'".$this->escape($prepared['text'])."','".$this->escape($prepared['title'])."',".$now.",'".$this->escape($prepared['uid'])."',".$this->actor['id'].')');
  if((int)$this->connection->sql_affectedrows()!==1){pafiledb_mutation_fail();}$id=$this->connection->sql_nextid();if(!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)||(float)$id>2147483647){pafiledb_mutation_fail();}
  $stored=$this->rows('SELECT * FROM '.PA_COMMENTS_TABLE.' WHERE comments_id='.(int)$id);
  if(count($stored)!==1||(int)$stored[0]['file_id']!==$this->file_id||(int)$stored[0]['poster_id']!==$this->actor['id']||(int)$stored[0]['comments_time']!==$now||$stored[0]['comments_text']!==$prepared['text']||$stored[0]['comments_title']!==$prepared['title']||$stored[0]['comment_bbcode_uid']!==$prepared['uid']){pafiledb_mutation_fail();}
  $this->current();$this->commit();return array('comment_id'=>(int)$id,'file_id'=>$this->file_id);
 }
 function delete()
 {
  $this->current();$this->query('DELETE FROM '.PA_COMMENTS_TABLE.' WHERE comments_id='.$this->comment_id.' AND file_id='.$this->file_id);
  if((int)$this->connection->sql_affectedrows()!==1||$this->rows('SELECT comments_id FROM '.PA_COMMENTS_TABLE.' WHERE comments_id='.$this->comment_id)){pafiledb_mutation_fail();}
  // The deleted row is intentionally absent; the file/session/category rows
  // remain locked until COMMIT. Do not re-authorize against a missing target.
  $this->current_context();$this->commit();return array('comment_id'=>$this->comment_id,'file_id'=>$this->file_id);
 }
}
function pafiledb_comment_write($database,$mode,$file_id,$comment_id=0,$title='',$message='')
{
 $owner=null;
 try{
  if(!in_array($mode,array('post','delete'),true)){pafiledb_mutation_fail();}$file_id=pafiledb_mutation_id($file_id);$comment_id=$mode==='delete'?pafiledb_mutation_id($comment_id):0;$actor=pafiledb_mutation_actor();
  $owner=new PaFileDbCommentStore($database,$actor,$file_id,$mode,$comment_id);return$mode==='post'?$owner->save($title,$message):$owner->delete();
 }catch(PaFileDbCommentFailure$error){throw$error;}
 catch(PaFileDbCommentTextFailure$error){throw new PaFileDbCommentFailure($error->getMessage(),$error->limit);}
 catch(PaFileDbMutationFailure$error){$key=$error->getMessage();throw new PaFileDbCommentFailure($key==='PA_storage_failed'?'PA_comment_failed':($key==='PA_storage_upgrade'?'PA_comment_upgrade':$key));}
 catch(Exception$error){throw new PaFileDbCommentFailure('PA_comment_failed');}catch(Error$error){throw new PaFileDbCommentFailure('PA_comment_failed');}
 finally{if($owner){$owner->release();}}
}
function pafiledb_comment_error_message($error)
{
 global $lang;$key=$error->getMessage();$message=isset($lang[$key])?$lang[$key]:$lang['PA_comment_failed'];
 return$key==='PA_comment_too_long'&&isset($error->limit)?sprintf($message,$error->limit):$message;
}
