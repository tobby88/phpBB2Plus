<?php
// Exercise the actual upload/delete bodies; only PHP's HTTP-upload transport
// is replaced for CLI. Database commit/rollback is covered by the native suite.
putenv('PHPBB_ADMIN_PROFILE_NATIVE=0');
require __DIR__ . '/check-admin-profile-native.php';
ats_load_function($ats_source.'includes/functions.php','phpbb_image_dimensions_safe');
class ProfileAvatarRecord extends PhpbbAdminProfileScope
{
 function __construct(){$this->ready=true;}
}
eval('namespace ProfileAvatarFixture; use \\PhpbbAdminProfileScope;
 function is_uploaded_file($path){return $path===$GLOBALS["pa_input"]&&is_file($path);}
 function move_uploaded_file($from,$to){return !$GLOBALS["pa_move_fail"]&&rename($from,$to);}
 '.substr(file_get_contents($ats_source.'includes/usercp_avatar.php'),5));
$pa_root=sys_get_temp_dir().'/phpbb-profile-avatar-'.bin2hex(phpbb_random_bytes(8));
ats_check(mkdir($pa_root)&&mkdir($pa_root.'/avatars'),'Owned upload fixture');
$phpbb_root_path=$pa_root.'/';$pa_input=$pa_root.'/input.png';$pa_move_fail=false;
$board_config=array('avatar_path'=>'avatars','avatar_filesize'=>4096,'avatar_max_width'=>100,'avatar_max_height'=>100);
$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4x8AAAAASUVORK5CYII=');
try{
 foreach(array('success','invalid','move-failure') as $kind){
  file_put_contents($pa_input,$bytes);file_put_contents($pa_root.'/avatars/before.png',$bytes);
  $admin_profile_scope=new ProfileAvatarRecord();$current='before.png';$type=USER_AVATAR_UPLOAD;$error=false;$message='';$pa_move_fail=$kind==='move-failure';$failed=false;
  try{$sql=ProfileAvatarFixture\user_avatar_upload('editprofile','local',$current,$type,$error,$message,$pa_input,$kind==='invalid'?'bad.php':'valid.png',1,'forged/mime');}
  catch(AttachSettingsExit $e){$failed=true;}
  ats_check(is_file($pa_root.'/avatars/before.png'),'Upload never deletes pre-commit old avatar');
  if($kind==='success'){
   ats_check(!$error&&!$failed&&count($admin_profile_scope->new_avatars)===1&&count($admin_profile_scope->old_avatars)===1,'Actual upload enlists new and old avatar');
   $name=reset($admin_profile_scope->new_avatars);ats_check(preg_match('/^[a-f0-9]{32}\.png$/D',$name)&&strpos($sql,$name)!==false&&file_get_contents($pa_root.'/avatars/'.$name)===$bytes,'Validated random filename and unchanged uploaded bytes');
   unlink($pa_root.'/avatars/'.$name);
  }else{ats_check(($kind==='invalid'?$error:$failed)&&!$admin_profile_scope->new_avatars&&!$admin_profile_scope->old_avatars,'Rejected/failed upload enlists no files');}
 }
 echo "Actual ACP avatar upload/delete enlisting and failure ordering passed (CLI transport stub).\n";
}finally{
 $admin_profile_scope=null;
 foreach(array($pa_input,$pa_root.'/avatars/before.png') as $file){if(is_file($file)){unlink($file);}}
 rmdir($pa_root.'/avatars');rmdir($pa_root);
}
