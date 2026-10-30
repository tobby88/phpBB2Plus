<?php
require_once $phpbb_root_path.'pafiledb/includes/functions_file_actions.php';
$pm_before=$pc_checks;$pm_admin=defined('IN_ADMIN')&&IN_ADMIN;
function pm_reset($id){
 global$db,$source,$pafiledb_config,$lang;
 pa_reset($id,defined('IN_ADMIN')&&IN_ADMIN?'files':'public');$db=$source;$_SERVER['REQUEST_METHOD']='POST';$_POST=array('sid'=>$GLOBALS['userdata']['session_id']);
 $pafiledb_config=array('auth_search'=>AUTH_ALL,'auth_stats'=>AUTH_ALL,'auth_toplist'=>AUTH_ALL,'auth_viewall'=>AUTH_ALL);$lang=array();require$GLOBALS['phpbb_root_path'].'language/lang_english/lang_main.php';require$GLOBALS['phpbb_root_path'].'language/lang_english/lang_pafiledb.php';
 foreach(array(43=>9,44=>10)as$file=>$cat){pa_insert('fixture_pa_files',array('file_id'=>$file,'file_catid'=>$cat,'file_name'=>'Synthetic '.$file,'file_dir'=>'downloads/','file_desc'=>'Description','file_longdesc'=>'Long description','file_approved'=>0,'user_id'=>8));}
}
function pm_snapshot(){return array(pc_rows('SELECT * FROM fixture_pa_files ORDER BY file_id'),pc_rows('SELECT * FROM fixture_pa_cat ORDER BY cat_id'));}
function pm_approve($ids,$mode='do_approve'){$model=new pafiledb();$result=$model->file_approve($mode,$ids);return array($result,$model);}
foreach($pm_admin?array(7,11):array(7,9)as$actor){
 pm_reset($actor);$original_db=$db;$original_user=$userdata;$original_config=$pafiledb_config;list($ok,$model)=pm_approve(array(42,43));$rows=pc_rows('SELECT file_id,file_approved FROM fixture_pa_files ORDER BY file_id');
 pc_check($ok===true&&empty($model->error)&&$rows[0]['file_approved']==='1'&&$rows[1]['file_approved']==='1'&&$rows[2]['file_approved']==='0','Actual complete approval batch changes exactly selected files');
 pc_check($db===$original_db&&$userdata===$original_user&&$pafiledb_config===$original_config&&!$model->modified,'Actual metadata action restores caller context and suppresses later writes');
 list($ok,$model)=pm_approve(array(42,43),'do_unapprove');$rows=pc_rows('SELECT file_approved FROM fixture_pa_files WHERE file_id IN(42,43) ORDER BY file_id');pc_check($ok===true&&$rows[0]['file_approved']==='0'&&$rows[1]['file_approved']==='0','Actual complete unapproval batch succeeds');
 $before=pm_snapshot();$source->on_query=function($sql){if(strpos($sql,'UPDATE fixture_pa_cat')===0){return false;}};list($ok,$model)=pm_approve(array(42,43));$source->on_query=null;pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'Cache failure rolls back all selected approvals');
 foreach(array(array(42,999),array(42,42),array(42,'43x'),array(),array_fill(0,501,42))as$invalid){list($ok,$model)=pm_approve($invalid);pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'Missing, duplicate, malformed, empty or oversized approval batches are indivisible');}
 list($ok,$model)=pm_approve(array(42,43),'unknown');pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'Unknown approval action never changes file or cache metadata');
 if($actor===9){list($ok,$model)=pm_approve(array(42,44));pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'One unauthorized category prevents approval of the entire batch');pc_sql("UPDATE fixture_pa_config SET config_value='validator_admin' WHERE config_name='validator'");list($ok,$model)=pm_approve(array(42,43));pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'Current admin-only validator setting overrides stale moderator permissions');}
}
pm_reset(8);$before=pm_snapshot();list($ok,$model)=pm_approve(array(42,43));pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'File ownership grants no approval authority');
pm_reset($pm_admin?11:9);$before=pm_snapshot();pc_sql($pm_admin?'DELETE FROM fixture_jr_admin_users':'DELETE FROM fixture_pa_auth');list($ok,$model)=pm_approve(array(42,43));pc_check($ok===null&&!empty($model->error)&&pm_snapshot()===$before,'Current delegated module or moderator revocation rejects the whole action');
pm_reset(7);$source->lost=1;list($ok,$model)=pm_approve(array(42,43));$rows=pc_rows('SELECT file_approved FROM fixture_pa_files WHERE file_id IN(42,43)');pc_check($ok===null&&!empty($model->error)&&$rows[0]['file_approved']==='1'&&$rows[1]['file_approved']==='1','Lost actual metadata COMMIT acknowledgement preserves the committed complete batch and reports uncertainty');
echo 'Native download metadata actions: ',$pc_checks-$pm_before," actual batch/ACP/moderator/current-validator/rollback/target/lost-ack checks PASS (",$pm_admin?'ACP':'public',").\n";
