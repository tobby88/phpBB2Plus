<?php
require_once $phpbb_root_path.'pafiledb/includes/functions.php';
require_once $phpbb_root_path.'pafiledb/includes/functions_file_actions.php';
$ps_before=$pc_checks;$ps_real_root=$phpbb_root_path;
$ps_base=sys_get_temp_dir().'/codex_pa_save_'.bin2hex(phpbb_random_bytes(8));$ps_made=false;
function ps_reset($id=8){
 global$db,$source,$pafiledb_functions,$pafiledb_config,$board_config,$user_ip,$lang,$phpbb_root_path,$ps_base;
 pa_reset($id,'public');pc_sql('DELETE FROM fixture_pa_license');$db=$source;$phpbb_root_path=$ps_base.'/';$_SERVER['REQUEST_METHOD']='POST';$user_ip='7f000001';
 $board_config=array('server_name'=>'board.example.invalid','cookie_secure'=>1,'server_port'=>443,'script_path'=>'/forum');
 $pafiledb_config=array();foreach(pc_rows('SELECT * FROM fixture_pa_config')as$row){$pafiledb_config[$row['config_name']]=$row['config_value'];}
 foreach(array('auth_search','auth_stats','auth_toplist','auth_viewall')as$key){$pafiledb_config[$key]=AUTH_ALL;}
 $pafiledb_functions=new pafiledb_functions();$lang=array();require$GLOBALS['ps_real_root'].'language/lang_english/lang_main.php';require$GLOBALS['ps_real_root'].'language/lang_english/lang_pafiledb.php';
 pc_sql('DELETE FROM fixture_pa_customdata');pc_sql('DELETE FROM fixture_pa_custom');pa_insert('fixture_pa_custom',array('custom_id'=>1,'custom_name'=>'Text','custom_description'=>'Description','data'=>'','field_type'=>INPUT,'regex'=>'','field_order'=>1));
 pc_sql("INSERT INTO fixture_pa_customdata(customdata_file,customdata_custom,data)VALUES(42,1,'Original field'),(43,1,'Unrelated preserved field')");
 $_FILES=array();$_POST=ps_post(array());
}
function ps_post($changes){
 $values=array_merge(array('sid'=>$GLOBALS['userdata']['session_id'],'cat_id'=>'9','name'=>"O'Reilly \\ literal &amp; Grüße 😀",'long_desc'=>'Beschreibung mit Umlauten 😀','author'=>'Synthetic author','download_url'=>'https://downloads.example.invalid/file.zip','field'=>array(1=>'Custom &amp; Grüße 😀')),$changes);
 return defined('PHPBB_LEGACY_REQUEST_ESCAPED')?phpbb_addslashes_recursive($values):$values;
}
function ps_save($id=false){$model=new pafiledb();$result=$model->update_add_file($id,new custom_field());return array($result,$model);}
function ps_snapshot(){return array(pc_rows('SELECT * FROM fixture_pa_files ORDER BY file_id'),pc_rows('SELECT * FROM fixture_pa_customdata ORDER BY customdata_file,customdata_custom'),pc_rows('SELECT * FROM fixture_pa_cat ORDER BY cat_id'));}
try{
 pc_check(mkdir($ps_base,0700),'Own save root');$ps_made=true;mkdir($ps_base.'/downloads',0700);mkdir($ps_base.'/screenshots',0700);
 foreach(array('ordinary','no-backslash')as$mode){
  ps_reset();$source->kind=$mode==='no-backslash'?'no-backslash':'';$original_post=$_POST;$original_db=$db;$original_user=$userdata;$original_functions=$pafiledb_functions;$original_config=$pafiledb_config;
  pa_insert('fixture_pa_license',array('license_id'=>4,'license_name'=>'Current license','license_text'=>'Preserved'));$_POST=ps_post(array('license'=>'4'));$original_post=$_POST;
  list($id,$model)=ps_save();pc_check(is_int($id)&&$id!==42&&empty($model->error),'Actual remote create succeeds: '.$mode.' '.implode(';',$model->error));$rows=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id='.$id);
  pc_check($rows[0]['file_license']==='4','Actual save retains the selected existing license');
  $before=ps_snapshot();$_POST=ps_post(array('license'=>'999'));list($missing,$rejected)=ps_save(42);pc_check($missing===null&&!empty($rejected->error)&&ps_snapshot()===$before,'A license removed since rendering cannot leave a dangling file reference');$_POST=$original_post;
  $raw=phpbb_request_raw_value($_POST['name']);pc_check(count($rows)===1&&$rows[0]['file_name']===$raw&&$rows[0]['file_approved']==='0'&&$rows[0]['user_id']==='8','Actual save preserves raw name/owner/current approval policy');
  $custom=pc_rows('SELECT data FROM fixture_pa_customdata WHERE customdata_file='.$id);pc_check(count($custom)===1&&$custom[0]['data']===htmlspecialchars(phpbb_request_raw_value($_POST['field'][1]),ENT_QUOTES,'UTF-8'),'File and actual custom-field data commit together');
  pc_check($_POST===$original_post&&$db===$original_db&&$userdata===$original_user&&$pafiledb_functions===$original_functions&&$pafiledb_config===$original_config&&!$model->modified,'Globals restored and deferred writes suppressed after success');
  pc_sql("UPDATE fixture_pa_files SET file_size=456,file_dir='downloads/',file_dls=12,file_pin=1,unique_name='old.zip',real_name='Original.zip' WHERE file_id=42");
  $_POST=ps_post(array('name'=>'0','long_desc'=>'0','download_url'=>'','file_download'=>'999','pin'=>'0','approved'=>'1','field'=>array(1=>'0')));list($id,$model)=ps_save(42);$rows=pc_rows('SELECT * FROM fixture_pa_files WHERE file_id=42');
  pc_check($id===42&&empty($model->error)&&$rows[0]['file_name']==='0'&&$rows[0]['file_longdesc']==='0','Actual edit accepts a literal zero: '.implode(';',$model->error));
  pc_check($rows[0]['file_size']==='456'&&$rows[0]['unique_name']==='old.zip'&&$rows[0]['file_dir']==='downloads/'&&$rows[0]['file_dls']==='12'&&$rows[0]['file_pin']==='1'&&$rows[0]['file_approved']==='0','Public metadata edits preserve actual bytes, physical directory, counters, pin and approval');
  pc_check(pc_rows('SELECT data FROM fixture_pa_customdata WHERE customdata_file=42')[0]['data']==='0','Actual edited zero custom field persists');
  $_POST=ps_post(array('cat_id'=>'10','download_url'=>''));list($id,$model)=ps_save(42);pc_check($id===42&&empty($model->error)&&pc_rows('SELECT file_catid FROM fixture_pa_files WHERE file_id=42')[0]['file_catid']==='10','Actual authorized file move uses current destination');
  $before=ps_snapshot();$source->on_query=function($sql){if(strpos($sql,'INSERT INTO fixture_pa_customdata')===0){return false;}};$_POST=ps_post(array('name'=>'Rollback name'));list($id,$model)=ps_save(42);$source->on_query=null;
  pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Custom write failure rolls back metadata and category cache atomically');
  $_POST=ps_post(array('name'=>'Failed cache'));$source->on_query=function($sql){if(strpos($sql,'UPDATE fixture_pa_cat')===0){return false;}};list($id,$model)=ps_save(42);$source->on_query=null;
  pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Category sync failure rolls back file and custom changes together');
  $_POST=ps_post(array('field'=>array(1=>array('invalid'))));list($id,$model)=ps_save(42);pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Invalid custom draft never creates partial file metadata');
  $_POST=ps_post(array('sid'=>'different-session'));list($id,$model)=ps_save(42);pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Actual save rejects a different form session');
  $_POST=ps_post(array('cat_id'=>'99'));list($id,$model)=ps_save(42);pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Missing current destination rejects actual save without metadata changes');
 }
 ps_reset();$source->lost=1;list($id,$model)=ps_save();pc_check($id===null&&!empty($model->error)&&count(pc_rows('SELECT * FROM fixture_pa_files'))===2&&count(pc_rows('SELECT * FROM fixture_pa_customdata'))===3,'Lost metadata commit acknowledgement reports uncertainty while preserving actually committed file and custom data');
 ps_reset(10);$before=ps_snapshot();list($id,$model)=ps_save(42);pc_check($id===null&&!empty($model->error)&&ps_snapshot()===$before,'Actual save rechecks current owner instead of trusting cached form permissions');
 echo 'Native download file save: ',$pc_checks-$ps_before," actual model/custom-field/cache/rollback/zero/Unicode/session/owner/lost-ack checks PASS (",defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{
 $phpbb_root_path=$ps_real_root;$source->on_query=null;
 if($ps_made){$resolved=realpath($ps_base);if($resolved===false||dirname($resolved)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_pa_save_[a-f0-9]{16}$/D',basename($resolved))||is_link($ps_base)){throw new RuntimeException('Own save root required');}rmdir($ps_base.'/downloads');rmdir($ps_base.'/screenshots');rmdir($ps_base);}
}
