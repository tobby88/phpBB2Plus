<?php
require_once $phpbb_root_path.'pafiledb/includes/functions_license_storage.php';
$pl_before=$pc_checks;
function pl_reset($id=7){
 global$db,$source;
 pa_reset($id,'licenses');pc_sql('DELETE FROM fixture_pa_license');
 foreach(array(4,5,6)as$license){pa_insert('fixture_pa_license',array('license_id'=>$license,'license_name'=>'Original '.$license,'license_text'=>'Preserved '.$license));}
 pc_sql('UPDATE fixture_pa_files SET file_license=4 WHERE file_id=42');
 foreach(array(43=>5,44=>6)as$file=>$license){pa_insert('fixture_pa_files',array('file_id'=>$file,'file_catid'=>9,'user_id'=>8,'file_license'=>$license,'file_name'=>'Preserved '.$file));}
 $routes=jr_admin_authorization_routes();$route=array_search('admin_pa_license.php',$routes,true);pc_check($route!==false,'Exact license ACP route registered');pc_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$GLOBALS['peer']->sql_escape($route)."' WHERE user_id=11");
 $db=$source;$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>'files-'.$id);
}
function pl_snapshot(){return array(pc_rows('SELECT * FROM fixture_pa_license ORDER BY license_id'),pc_rows('SELECT * FROM fixture_pa_files ORDER BY file_id'));}
function pl_save($action,$values){$_POST=array_merge($_POST,$values);if(defined('PHPBB_LEGACY_REQUEST_ESCAPED')){$post=phpbb_addslashes_recursive($_POST);}else{$post=$_POST;}return pafiledb_license_save($action,$post);}
function pl_denied($action,$values,$message){$before=pl_snapshot();$denied=false;try{pl_save($action,$values);}catch(Exception$error){$denied=true;}pc_check($denied&&pl_snapshot()===$before,$message);}
$pl_form=array('name'=>"O'Reilly \\ &amp; Grüße 😀",'text'=>"First line\nLiteral \\ path &amp; Grüße 😀");
pl_reset();$boundary=new PaFileDbFileCoordinator($source,'fixture_',0);$boundary->begin_licenses();$boundary->guard_commit(function(){return true;});
foreach(array('UPDATE fixture_pa_files SET file_dls=999 WHERE file_id=42',"UPDATE fixture_pa_config SET config_value='forged' WHERE config_name='upload_dir'")as$sql){pc_denied(function()use($boundary,$sql){$boundary->sql_query($sql);},'License channel cannot change unrelated data');}
pc_denied(function()use($boundary){$boundary->durable_begin();},'License channel cannot prepare uploads');pc_denied(function()use($source){$other=new PaFileDbFileCoordinator($source,'fixture_',0);$other->release();},'License operations retain the shared file namespace');$boundary->release();
$boundary=new PaFileDbFileCoordinator($source,'fixture_',0);$boundary->begin();$boundary->guard_commit(function(){return true;});pc_denied(function()use($boundary){$boundary->sql_query('DELETE FROM fixture_pa_license WHERE license_id=4');},'Ordinary file producers cannot delete licenses');$boundary->release();
foreach(array(7,11)as$actor){
 pl_reset($actor);$id=pl_save('add',array('form'=>$pl_form));$row=pc_rows('SELECT license_name,license_text FROM fixture_pa_license WHERE license_id='.$id)[0];pc_check($id>6&&$row['license_name']===$pl_form['name']&&$row['license_text']===$pl_form['text'],'Root and exact delegate create exact Unicode, slashes and literal entities');
 pc_check(pl_save('edit',array('id'=>(string)$id,'form'=>$pl_form))===$id,'No-op edits remain valid');
 $changed=array('name'=>'0','text'=>'0');pl_save('edit',array('id'=>(string)$id,'form'=>$changed));$row=pc_rows('SELECT license_name,license_text FROM fixture_pa_license WHERE license_id='.$id)[0];pc_check($row['license_name']==='0'&&$row['license_text']==='0','Literal zero license fields survive editing');
 pl_save('delete',array('select'=>array(4=>'yes',5=>'yes')));$rows=pc_rows('SELECT file_id,file_license,file_name FROM fixture_pa_files ORDER BY file_id');pc_check(count(pc_rows('SELECT * FROM fixture_pa_license'))===2&&$rows[0]['file_license']==='0'&&$rows[1]['file_license']==='0'&&$rows[2]['file_license']==='6'&&$rows[0]['file_name']==='Preserved Grüße 😀','Complete batch removes only selected licenses and clears their file references');
}
pl_reset();
foreach(array(array('form'=>array('name'=>'','text'=>'Keep')),array('form'=>array('name'=>array('bad'),'text'=>'')),array('form'=>array('name'=>"bad\xff",'text'=>'')),array('form'=>array('name'=>'Valid','text'=>str_repeat('😀',16384))))as$bad){pl_denied('add',$bad,'Invalid or overlong field rejects the whole license');}
foreach(array('4x','04','-4','2147483648',array(4),999)as$id){pl_denied('edit',array('id'=>$id,'form'=>$pl_form),'Malformed or vanished edit target cannot report success');}
foreach(array(array(),array('4x'=>'yes'),array(4=>array('yes')),array(4=>'no'),array(4=>'yes',999=>'yes'))as$select){pl_denied('delete',array('select'=>$select),'Entire selection is validated before removing a single reference');}
foreach(array(8,9,-1)as$id){pl_reset($id);pl_denied('delete',array('select'=>array(4=>'yes')),'Guest, ordinary user and moderator cannot inherit ACP rights');}
pl_reset(11);$routes=jr_admin_authorization_routes();$wrong=array_search('admin_pa_file.php',$routes,true);pc_sql("UPDATE fixture_jr_admin_users SET user_jr_admin='".$peer->sql_escape($wrong)."' WHERE user_id=11");pl_denied('add',array('form'=>$pl_form),'File delegation cannot grant license administration');
pl_reset();$_POST['sid']='wrong';pl_denied('add',array('form'=>$pl_form),'Invalid form token cannot save licenses');pl_reset();$_SERVER['REQUEST_METHOD']='GET';pl_denied('delete',array('select'=>array(4=>'yes')),'GET cannot delete licenses');
foreach(array('session','role','inactive','delegation')as$kind){
 pl_reset($kind==='delegation'?11:7);$source->on_query=function($sql)use($kind){if(strpos($sql,'SELECT config_value FROM fixture_config')===0){$GLOBALS['source']->on_query=null;$change=$kind==='session'?"DELETE FROM fixture_sessions WHERE session_id='files-7'":($kind==='role'?'UPDATE fixture_users SET user_level=0 WHERE user_id=7':($kind==='inactive'?'UPDATE fixture_users SET user_active=0 WHERE user_id=7':'DELETE FROM fixture_jr_admin_users WHERE user_id=11'));pc_sql($change);}return null;};
 pl_denied('delete',array('select'=>array(4=>'yes')),'Current session, active account, role and exact module override cached authority');$source->on_query=null;
}
foreach(array('UPDATE fixture_pa_files SET file_license=0','DELETE FROM fixture_pa_license')as$prefix){pl_reset();$source->on_query=function($sql)use($prefix){return strpos($sql,$prefix)===0?false:null;};pl_denied('delete',array('select'=>array(4=>'yes',5=>'yes')),'Failure of either reference update or license deletion rolls back the complete batch');$source->on_query=null;}
pl_reset();$source->on_query=function($sql){if(strpos($sql,'UPDATE fixture_pa_files SET file_license=0')===0){$GLOBALS['userdata']['session_admin']=false;}return null;};pl_denied('delete',array('select'=>array(4=>'yes')),'Lost request authority after first write rolls back references and license together');$source->on_query=null;
foreach(array('INSERT INTO fixture_pa_license','UPDATE fixture_pa_license')as$prefix){pl_reset();$source->on_query=function($sql)use($prefix){return strpos($sql,$prefix)===0?false:null;};pl_denied(strpos($prefix,'INSERT')===0?'add':'edit',array('id'=>'4','form'=>$pl_form),'Injected create or edit failure preserves license and file state');$source->on_query=null;}
pl_reset();pc_sql('DELETE FROM fixture_pa_comments WHERE comments_id=990');pc_check($source->sql_query('START TRANSACTION')!==false&&$source->sql_query("INSERT INTO fixture_pa_comments(comments_id,file_id,comments_text,comments_title)VALUES(990,42,'Caller pending','Fixture')")!==false,'Caller owns an actual unrelated pending insert');
pl_save('delete',array('select'=>array(4=>'yes')));pc_check(!pc_rows('SELECT comments_id FROM fixture_pa_comments WHERE comments_id=990'),'License operation never commits caller pending work');$source->sql_query('ROLLBACK');pc_check(!pc_rows('SELECT comments_id FROM fixture_pa_comments WHERE comments_id=990'),'Caller retains its own rollback');
pl_reset();$source->lost=1;$uncertain=false;try{pl_save('delete',array('select'=>array(4=>'yes')));}catch(Exception$error){$uncertain=true;}pc_check($uncertain&&!pc_rows('SELECT * FROM fixture_pa_license WHERE license_id=4')&&pc_rows('SELECT file_license FROM fixture_pa_files WHERE file_id=42')[0]['file_license']==='0','Lost commit acknowledgement preserves the actual atomic committed result');
echo 'Native download licenses: ',$pc_checks-$pl_before,' actual root/delegate/Unicode/batch-reference/rollback/current-authority/caller-TX/lost-ack checks PASS (',defined('PHPBB_LEGACY_REQUEST_ESCAPED')?'legacy':'raw',").\n";
