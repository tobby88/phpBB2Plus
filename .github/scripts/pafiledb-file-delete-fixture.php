<?php
require_once $phpbb_root_path.'pafiledb/includes/functions_file_delete.php';
$pd_before=$pc_checks;$pd_original_root=$phpbb_root_path;$pd_base=sys_get_temp_dir().'/codex_pa_delete_'.bin2hex(phpbb_random_bytes(8));$pd_made=false;$pd_admin=defined('IN_ADMIN')&&IN_ADMIN;
function pd_reset($id){
 global$db,$source,$phpbb_root_path,$board_config,$lang,$pafiledb_config;
 pa_reset($id,defined('IN_ADMIN')&&IN_ADMIN?'files':'public');foreach(array('pa_comments','pa_votes','pa_download_info','pa_customdata','pa_mirrors')as$suffix){pc_sql('DELETE FROM fixture_'.$suffix);}
 foreach(array('downloads','screenshots')as$dir){foreach(scandir($GLOBALS['pd_base'].'/'.$dir)as$name){if($name==='.'||$name==='..'){continue;}$path=$GLOBALS['pd_base'].'/'.$dir.'/'.$name;if(!is_file($path)||is_link($path)){throw new RuntimeException('Own deletion file required');}unlink($path);}}
 $db=$source;$phpbb_root_path=realpath($GLOBALS['pd_base']).'/';$board_config=array('server_name'=>'www.example.invalid','server_port'=>443,'script_path'=>'/forum','cookie_secure'=>1);$pafiledb_config=array();$lang=array('PA_file_failed'=>'Fixture operation could not be confirmed');
 $_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>$GLOBALS['userdata']['session_id']);
 foreach(array(42=>9,43=>9,44=>10)as$file=>$cat){
  if($file!==42){pa_insert('fixture_pa_files',array('file_id'=>$file,'file_catid'=>$cat,'real_name'=>'file-'.$file.'.zip','file_dir'=>'downloads/','file_name'=>'Synthetic '.$file,'user_id'=>$file===44?10:8));}
  pc_sql("UPDATE fixture_pa_files SET unique_name='file-".$file.".zip',file_dir='downloads/',file_ssurl='https://example.invalid/forum/screenshots/shot-".$file.".png' WHERE file_id=".$file);
  pa_insert('fixture_pa_mirrors',array('mirror_id'=>$file,'file_id'=>$file,'unique_name'=>'mirror-'.$file.'.zip','file_dir'=>'downloads/','file_dlurl'=>'','mirror_location'=>'Synthetic'));
  pa_insert('fixture_pa_comments',array('comments_id'=>$file,'file_id'=>$file,'comments_text'=>'Preserved comment','comments_title'=>'Title','poster_id'=>8));
  pa_insert('fixture_pa_votes',array('votes_file'=>$file,'user_id'=>8,'rate_point'=>5));pa_insert('fixture_pa_download_info',array('file_id'=>$file,'user_id'=>8));pa_insert('fixture_pa_customdata',array('customdata_file'=>$file,'customdata_custom'=>1,'data'=>'Preserved custom data'));
  foreach(array('downloads/file-'.$file.'.zip','downloads/mirror-'.$file.'.zip','screenshots/shot-'.$file.'.png')as$path){file_put_contents($GLOBALS['pd_base'].'/'.$path,'Synthetic exact bytes '.$path);}
 }
}
function pd_snapshot(){
 $out=array();foreach(array('pa_files'=>'file_id','pa_comments'=>'comments_id','pa_votes'=>'votes_file','pa_download_info'=>'file_id','pa_customdata'=>'customdata_file','pa_mirrors'=>'mirror_id','pa_cat'=>'cat_id','pa_file_journal'=>'operation_id,item_index')as$table=>$order){$out[$table]=pc_rows('SELECT * FROM fixture_'.$table.' ORDER BY '.$order);}
 foreach(array('downloads','screenshots')as$dir){foreach(scandir($GLOBALS['pd_base'].'/'.$dir)as$name){if($name!=='.'&&$name!=='..'){$out['physical'][$dir.'/'.$name]=hash_file('sha256',$GLOBALS['pd_base'].'/'.$dir.'/'.$name);}}}return$out;
}
function pd_delete($ids){$model=new pafiledb();$ok=$model->delete_files($ids);return array($ok,$model);}
try{
 pc_check(!file_exists($pd_base)&&mkdir($pd_base,0700),'Own deletion fixture root');$pd_made=true;foreach(array('downloads','screenshots')as$dir){pc_check(mkdir($pd_base.'/'.$dir,0700),'Own deletion storage directory');}
 foreach($pd_admin?array(7,11):array(8,9,7)as$actor){
  pd_reset($actor);$old_db=$db;$old_user=$userdata;$old_root=$phpbb_root_path;$before=pd_snapshot();$before_commit=false;
  $source->on_query=function($sql)use(&$before_commit){if($sql==='COMMIT'&&is_file($GLOBALS['pd_base'].'/downloads/file-42.zip')){$before_commit=true;}};
  list($ok,$model)=pd_delete(array(42,43));$source->on_query=null;
  pc_check($ok===true&&empty($model->error)&&$before_commit,'Actual complete file deletion commits before any physical cleanup');
  pc_check($db===$old_db&&$userdata===$old_user&&$phpbb_root_path===$old_root&&!$model->modified,'File deletion leaves caller context intact without deferred writes');
  foreach(array('pa_files'=>'file_id','pa_comments'=>'comments_id','pa_votes'=>'votes_file','pa_download_info'=>'file_id','pa_customdata'=>'customdata_file','pa_mirrors'=>'mirror_id')as$table=>$field){$rows=pc_rows('SELECT '.$field.' FROM fixture_'.$table);pc_check(count($rows)===1&&(int)$rows[0][$field]===44,'Complete deletion removes selected associated '.$table.' and preserves unrelated identity');}
  pc_check(!file_exists($pd_base.'/downloads/file-42.zip')&&!file_exists($pd_base.'/downloads/mirror-42.zip')&&!file_exists($pd_base.'/screenshots/shot-42.png')&&file_exists($pd_base.'/downloads/file-44.zip'),'Exact cleanup includes main file, mirror and local screenshot only');
  $rows=pc_rows('SELECT item_state FROM fixture_pa_file_journal');pc_check(count($rows)===6&&count(array_filter($rows,function($r){return$r['item_state']==='1';}))===6,'Committed completed deletion intents remain durable');
 }
 foreach(array(array(),array(42,999),array(42,42),array(42,'43x'),array(42,array()),array(42,'043'),array_fill(0,501,42))as$invalid){pd_reset($pd_admin?7:8);$before=pd_snapshot();list($ok,$model)=pd_delete($invalid);pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'Empty/missing/duplicate/malformed/oversized deletion selection rejects the entire batch');}
 pd_reset($pd_admin?11:8);$before=pd_snapshot();pc_sql($pd_admin?'DELETE FROM fixture_jr_admin_users':'UPDATE fixture_users SET user_active=0 WHERE user_id=8');list($ok,$model)=pd_delete(array(42,43));pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'Current delegated or account revocation prevents deletion');
 if(!$pd_admin){pd_reset(8);$before=pd_snapshot();list($ok,$model)=pd_delete(array(42,44));pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'One foreign owner prevents the complete public deletion batch');pd_reset(9);$before=pd_snapshot();pc_sql('DELETE FROM fixture_pa_auth');list($ok,$model)=pd_delete(42);pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'Current moderator revocation overrides stale permission');}
 foreach(array('INSERT INTO fixture_pa_file_journal','DELETE FROM fixture_pa_comments','DELETE FROM fixture_pa_files','UPDATE fixture_pa_cat')as$failure){
  pd_reset($pd_admin?7:8);$before=pd_snapshot();$source->on_query=function($sql)use($failure){if(strpos($sql,$failure)===0){return false;}};list($ok,$model)=pd_delete(array(42,43));$source->on_query=null;
  pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'Failed deletion receipt/associated metadata/file/cache write rolls back without unlinking: '.$failure);
 }
 pd_reset($pd_admin?7:8);$before=pd_snapshot();$_POST['sid']='wrong';list($ok,$model)=pd_delete(42);pc_check($ok===null&&!empty($model->error)&&pd_snapshot()===$before,'Actual owning deletion independently enforces POST session');
 pd_reset($pd_admin?7:8);$blocked=false;$lease=false;$source->on_query=function($sql)use(&$blocked,&$lease){if(strpos($sql,'DELETE FROM fixture_pa_comments')===0){$GLOBALS['source']->on_query=null;$probe=native_fixture_conflict_query($GLOBALS['peer'],'UPDATE fixture_pa_files SET file_catid=10 WHERE file_id=42');$blocked=$probe[0]===false&&(int)$probe[1]['code']===1205;$lock='attachment:'.md5($GLOBALS['schema']."\0fixture_attachments");$lease=pc_rows("SELECT GET_LOCK('".$lock."',0) held")[0]['held']==='0';}};
 list($ok,$model)=pd_delete(42);$source->on_query=null;pc_check($ok===true&&$blocked&&$lease,'Actual concurrent file move is locked until metadata, associated rows and intent commit together');
 pd_reset($pd_admin?7:8);pc_sql("UPDATE fixture_pa_files SET unique_name='file-42.zip',file_dir='downloads/' WHERE file_id=44");list($ok,$model)=pd_delete(42);pc_check($ok===true&&is_file($pd_base.'/downloads/file-42.zip'),'Another current file reference protects shared physical bytes');
 pd_reset($pd_admin?7:8);$source->lost=1;list($ok,$model)=pd_delete(array(42,43));pc_check($ok===null&&!empty($model->error)&&count(pc_rows('SELECT file_id FROM fixture_pa_files'))===1&&!file_exists($pd_base.'/downloads/file-42.zip'),'Lost actual metadata commit acknowledgement reports uncertainty while preserving committed deletion and safe cleanup');
 echo 'Native download file deletion: ',$pc_checks-$pd_before," actual whole-batch/associated metadata/current authority/physical journal/rollback/shared-reference/concurrent move/lost-ack checks PASS (",$pd_admin?'ACP':'public',").\n";
}finally{
 $source->on_query=null;$phpbb_root_path=$pd_original_root;
 if($pd_made){$resolved=realpath($pd_base);if($resolved===false||dirname($resolved)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_pa_delete_[a-f0-9]{16}$/D',basename($resolved))||is_link($pd_base)){throw new RuntimeException('Own deletion root required');}foreach(array('downloads','screenshots')as$dir){foreach(scandir($pd_base.'/'.$dir)as$name){if($name==='.'||$name==='..'){continue;}$path=$pd_base.'/'.$dir.'/'.$name;if(!is_file($path)||is_link($path)){throw new RuntimeException('Own deletion file required');}unlink($path);}rmdir($pd_base.'/'.$dir);}rmdir($pd_base);}
}
