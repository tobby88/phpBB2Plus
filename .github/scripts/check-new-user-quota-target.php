<?php
// Exact controller bindings; the real scope/assignment SQL is exercised by
// check-admin-profile-native.php. Never execute a live forum bootstrap here.
putenv('PHPBB_ATTACH_SETTINGS_NATIVE=0');ob_start();require __DIR__.'/check-attachment-settings-storage.php';ob_end_clean();
class PhpbbAdminProfileScope
{
 var $id; var $creating;
 function __construct($db,$id,$creating,$request){$this->id=$id;$this->creating=$creating;}
 function assign_quotas($request){$GLOBALS['new_quota_target']=$this->id;}
}
$controller=file_get_contents($ats_source.'admin/admin_users.php');
ats_check(strpos($controller,"attachment_quota_settings('user'")===false&&strpos($controller,'phpbb_admin_profile_quota_controls($user_id, $_POST);')!==false,'Profile rendering cannot invoke legacy request-derived quota writer');
ats_check(preg_match_all('/\\$admin_profile_scope = new PhpbbAdminProfileScope\\([^;]+;/',$controller,$calls)===2,'Actual creation and edit scope bindings');
ats_check(preg_match('/\\$admin_profile_scope->assign_quotas\\(\\$_POST\\);/',$controller,$save)===1,'Actual deferred quota binding');
foreach(array(true,false) as $new_user){
 $db=null;$user_id=5;$_POST=array('id'=>'2','u'=>'999','new_user'=>$new_user?'1':'0','submit'=>'Save','user_upload_quota'=>'1');
 eval($calls[0][$new_user?0:1]);eval($save[0]);
 ats_check((int)$new_quota_target===($new_user?5:2)&&$admin_profile_scope->creating===$new_user,'Quotas follow scope-owned allocated or selected user, never reference/URL ID');
}
echo "Actual profile-scope quota target bindings passed\n";
