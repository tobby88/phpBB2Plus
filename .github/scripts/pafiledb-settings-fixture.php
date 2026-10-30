<?php
require_once $phpbb_root_path.'pafiledb/includes/functions_settings_storage.php';
$ps_before=$pc_checks;$ps_original_root=$phpbb_root_path;$ps_base=sys_get_temp_dir().'/codex_pa_settings_'.bin2hex(phpbb_random_bytes(8));$ps_made=false;
function ps_rows(){ $out=array();foreach(pc_rows('SELECT config_name,config_value FROM fixture_pa_config ORDER BY config_name')as$row){$out[$row['config_name']]=$row['config_value'];}return$out; }
function ps_reset($id=7){
 global$db,$source,$phpbb_root_path,$ps_base;
 pa_reset($id,'settings');pc_sql('DELETE FROM fixture_pa_config');$basic=file_get_contents($GLOBALS['root'].'/phpBB2/install/schemas/mysql_basic.sql');preg_match_all('/^INSERT INTO phpbb_pa_config\b[^;]*;/m',$basic,$matches);foreach($matches[0]as$sql){pc_sql(str_replace('phpbb_pa_config','fixture_pa_config',$sql));}
 foreach(array('upload_dir'=>'downloads/','screenshots_dir'=>'screenshots/','max_file_size'=>'100000','tpl_php'=>'0')as$key=>$value){pc_sql("UPDATE fixture_pa_config SET config_value='".$value."' WHERE config_name='".$key."'");}
 pc_sql("INSERT INTO fixture_pa_config VALUES('custom_kept','Unchanged custom setting')");$routes=jr_admin_authorization_routes();$route=array_search('admin_pa_settings.php',$routes,true);pc_check($route!==false,'Settings ACP module registered');pc_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$GLOBALS['peer']->sql_escape($route)."' WHERE user_id=11");
 $db=$source;$phpbb_root_path=$ps_base.'/';$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'files-'.$id);$source->on_query=null;
}
function ps_post($values){
 $post=array_merge($_POST,$values);if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){foreach($post as$key=>$value){if(is_string($value)){$post[$key]=addslashes($value);}}}return$post;
}
function ps_save($values){return pafiledb_settings_save(ps_post($values));}
function ps_denied($values,$message){$before=ps_rows();$failed=false;try{ps_save($values);}catch(Exception$error){$failed=true;}pc_check($failed&&ps_rows()===$before,$message);}
try{
 pc_check(mkdir($ps_base,0700),'Own settings root');$ps_made=true;foreach(array('downloads','screenshots','next-downloads','next-screenshots')as$dir){pc_check(mkdir($ps_base.'/'.$dir,0700),'Own configured setting directory');}
 ps_reset();$ps_boundary=new PaFileDbFileCoordinator($source,'fixture_',0);$ps_boundary->begin_settings();$ps_boundary->guard_commit(function(){return true;});pc_denied(function()use($ps_boundary){$ps_boundary->sql_query('UPDATE fixture_pa_files SET file_dls=99 WHERE file_id=42');},'Settings channel cannot mutate download metadata');pc_denied(function()use($ps_boundary){$ps_boundary->durable_begin();},'Settings channel cannot prepare a file publication');$ps_boundary->release();$ps_boundary=null;
 $ps_boundary=new PaFileDbFileCoordinator($source,'fixture_',0);$ps_boundary->begin();$ps_boundary->guard_commit(function(){return true;});pc_denied(function()use($ps_boundary){$ps_boundary->sql_query("UPDATE fixture_pa_config SET config_value='forged' WHERE config_name='settings_dbname'");},'Ordinary file channel cannot change settings');$ps_boundary->release();$ps_boundary=null;
 foreach(array(7,11)as$id){
  ps_reset($id);$before=ps_rows();$text="Grüße 😀 O'Reilly \\ config";$saved=ps_save(array('settings_dbname'=>$text,'sort_order'=>'asc','max_file_size'=>'1.25','max_size'=>'mb','custom_kept'=>'forged','arbitrary'=>'forged'));
  pc_check($saved['settings_dbname']===$text&&ps_rows()['settings_dbname']===$text&&ps_rows()['max_file_size']==='1310720'&&ps_rows()['sort_order']==='ASC','Root and exact delegated admin commit complete Unicode settings together');
  pc_check(ps_rows()['custom_kept']===$before['custom_kept']&&!isset(ps_rows()['arbitrary'])&&ps_rows()['upload_dir']===$before['upload_dir'],'Unknown fields and absent current fields remain unchanged');
 }
 ps_reset();ps_denied(array('settings_dbname'=>'Must roll back','upload_dir'=>'../invalid'),'Late invalid path cannot preserve an earlier title change');
 ps_denied(array('settings_dbname'=>'Must roll back','max_file_size'=>'3000','max_size'=>'mb'),'Unsupported maximum never disables later uploads after partial save');
 foreach(array(array('settings_file_page'=>'0'),array('settings_disable'=>'2'),array('allow_html'=>array('1')),array('sort_method'=>'file_name DESC'),array('sort_order'=>'sideways'),array('auth_search'=>'999'),array('validator'=>'other'),array('max_file_size'=>'1e8'),array('max_file_size'=>'1','max_size'=>array('mb')),array('settings_dbname'=>"bad\xff"),array('settings_dbname'=>str_repeat('😀',256)),array('upload_dir'=>'missing/'),array('upload_dir'=>'downloads/./'))as$bad){ps_denied($bad,'Malformed setting rejects whole batch without changing rows');}
 foreach(array(0,1,1023,1024,100000,1048575,1048576,123456789,2147483647)as$bytes){$display=pafiledb_settings_display_size((string)$bytes);pc_check(pafiledb_settings_size($display['value'],$display['unit'])===(string)$bytes,'Display/unit round trip preserves exact original byte limit');}
 ps_reset();$text=str_repeat('😀',254)."'";ps_save(array('no_comment_link_message'=>$text));pc_check(ps_rows()['no_comment_link_message']===$text,'Canonical varchar supports 255 Unicode characters rather than only 255 bytes');
 foreach(array(8,9,-1)as$id){ps_reset($id);ps_denied(array('settings_dbname'=>'forged'),'Ordinary/moderator/guest cannot inherit a stale ACP context');}
 ps_reset(11);$ps_routes=jr_admin_authorization_routes();$ps_other=array_search('admin_pa_file.php',$ps_routes,true);pc_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$peer->sql_escape($ps_other)."' WHERE user_id=11");ps_denied(array('settings_dbname'=>'forged'),'Delegation to files alone never grants the separate settings module');
 ps_reset();$_POST['sid']='wrong-token';ps_denied(array('settings_dbname'=>'forged'),'Forged form token cannot publish settings');
 ps_reset();$_SERVER['REQUEST_METHOD']='GET';ps_denied(array('settings_dbname'=>'forged'),'GET cannot publish settings');
 foreach(array('session','role','delegation','inactive')as$kind){
  ps_reset($kind==='delegation'?11:7);$before=ps_rows();$source->on_query=function($sql,$connection)use($kind){if(strpos($sql,'SELECT config_value FROM fixture_config')===0){$GLOBALS['source']->on_query=null;$id=$kind==='delegation'?11:7;$change=$kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='files-7'":($kind==='role'?'UPDATE fixture_users SET user_level=0 WHERE user_id=7':($kind==='inactive'?'UPDATE fixture_users SET user_active=0 WHERE user_id=7':'DELETE FROM fixture_jr_admin_users WHERE user_id=11'));pc_sql($change);}return null;};
  ps_denied(array('settings_dbname'=>'forged'),'Current session/account/module authority wins over cached ACP state');$source->on_query=null;
 }
 for($fail=1;$fail<=3;$fail++){
  ps_reset();$writes=0;$source->on_query=function($sql,$connection)use($fail,&$writes){if(strpos($sql,'UPDATE fixture_pa_config SET ')===0){$writes++;if($writes===$fail){return false;}}return null;};
  ps_denied(array('settings_dbname'=>'new title','sort_order'=>'ASC','max_file_size'=>'200000'),'Every injected setting write failure rolls back the whole batch');$source->on_query=null;
 }
 ps_reset();$source->sql_query('START TRANSACTION');$source->sql_query("INSERT INTO fixture_pa_license(license_id,license_name,license_text)VALUES(99,'Caller pending','Preserved')");ps_save(array('settings_dbname'=>'Confirmed independently'));$r=$source->sql_query('SELECT license_id FROM fixture_pa_license WHERE license_id=99');pc_check(count($source->sql_fetchrowset($r))===1&&!pc_rows('SELECT license_id FROM fixture_pa_license WHERE license_id=99'),'Settings never commit caller pending work');$source->sql_freeresult($r);$source->sql_query('ROLLBACK');pc_check(!pc_rows('SELECT license_id FROM fixture_pa_license WHERE license_id=99'),'Caller still owns its rollback');
 ps_reset();$source->lost=1;$failed=false;try{ps_save(array('settings_dbname'=>'Committed lost acknowledgement'));}catch(Exception$error){$failed=true;}pc_check($failed&&ps_rows()['settings_dbname']==='Committed lost acknowledgement','Uncertain acknowledgement never reports a fictitious rollback or duplicate insert');
 ps_reset();pc_sql("INSERT INTO fixture_pa_file_journal(operation_id,item_index,operation_kind,actor_id,file_scope,file_dir,filename,file_bytes,file_sha256,binding_hash,created_at)VALUES('".str_repeat('a',32)."',0,'upload',7,'download','downloads','owned.txt',1,'".str_repeat('a',64)."','".str_repeat('a',64)."',1)");
 ps_denied(array('settings_dbname'=>'No partial root change','upload_dir'=>'next-downloads/'),'Pending download work keeps its configured recovery root');
 ps_save(array('screenshots_dir'=>'next-screenshots/'));pc_check(ps_rows()['screenshots_dir']==='next-screenshots/'&&ps_rows()['upload_dir']==='downloads/','Unrelated screenshot root may change without stranding download receipts');
 pc_sql('UPDATE fixture_pa_file_journal SET item_state=1,completed_at=2');ps_save(array('upload_dir'=>'next-downloads/'));pc_check(ps_rows()['upload_dir']==='next-downloads/','Completed receipts do not permanently prevent a root change');
 echo 'Native download settings: ',$pc_checks-$ps_before," actual atomic/late-validation/size/Unicode/current-ACP/module/rollback/caller-TX/lost-ack/pending-root checks PASS (",defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
}finally{
 if(isset($ps_boundary)&&$ps_boundary){$ps_boundary->release();}$source->on_query=null;$phpbb_root_path=$ps_original_root;
 if($ps_made){$resolved=realpath($ps_base);if($resolved===false||dirname($resolved)!==realpath(sys_get_temp_dir())||!preg_match('/^codex_pa_settings_[a-f0-9]{16}$/D',basename($resolved))||is_link($ps_base)){throw new RuntimeException('Own settings root required');}foreach(array('downloads','screenshots','next-downloads','next-screenshots')as$dir){if(is_link($ps_base.'/'.$dir)||!is_dir($ps_base.'/'.$dir)){throw new RuntimeException('Own empty settings directory required');}rmdir($ps_base.'/'.$dir);}rmdir($ps_base);}
}
