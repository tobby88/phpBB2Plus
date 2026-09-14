<?php
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');ob_start();require __DIR__.'/check-attachment-settings-storage.php';ob_end_clean();
require $ats_source.'attach_mod/includes/functions_attach.php';
ats_load_function($ats_source.'attach_mod/includes/functions_includes.php','attachment_quota_settings');
function attachment_quota_save_form($mode,$id,$delete=false){$GLOBALS['new_quota_target']=$id;}
$phpbb_root_path=$ats_source;$attach_config=array('allow_ftp_upload'=>'0','upload_dir'=>'files');
$controller=file_get_contents($ats_source.'admin/admin_users.php');
ats_check(preg_match('/\battachment_quota_settings\([\s\S]*?\);/',$controller,$call)===1,'Actual user controller call');
foreach(array(true,false) as $new_user){
 $mode='save';$user_id=5;$_POST=array('id'=>'2','u'=>'999','new_user'=>$new_user?'1':'0','submit'=>'Save','user_upload_quota'=>'1');$_GET=array();$HTTP_POST_VARS=&$_POST;$HTTP_GET_VARS=&$_GET;
 // The allocator's trusted result is 5; both posted IDs are different. Execute
 // the real controller expression and real quota dispatch, not a copied branch.
 eval($call[0]);ats_check($new_quota_target===($new_user?5:2),'New account targets allocated ID; edit keeps selected account');
}
echo "Actual user-controller quota target checks passed\n";
