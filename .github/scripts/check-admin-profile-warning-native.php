<?php
// Complete owned ACP profile projection/selected warning parser on canonical
// native storage. Not an HTTP/bootstrap test and never a live user mutation.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ADMIN_PROFILE_NATIVE')!=='1'){echo "Native ACP warning checks require explicitly enabled disposable storage.\n";return;}
error_reporting(E_ALL);
$aw_file=__DIR__.'/check-admin-profile-native.php';$aw_source=file_get_contents($aw_file);
$aw_cut=strpos($aw_source," foreach(array('root','junior') as \$actor)");
if($aw_cut===false){throw new RuntimeException('Profile fixture setup boundary changed');}
$aw_head=str_replace('__DIR__',var_export(__DIR__,true),substr($aw_source,5,$aw_cut-5));
$aw_head=str_replace('codex_admin_profile_','codex_admin_warning_',$aw_head);
$aw_finally=strrpos($aw_source,"\n}finally{\n if(isset(\$admin_profile_scope)");
if($aw_finally===false){throw new RuntimeException('Profile fixture cleanup boundary changed');}
function aw_suite()
{
 global $db,$userdata,$board_config,$ap_hook,$ap_actor,$ap_target,$ap_write,$ap_queries,$peer;
 $cases=0;
 foreach(array(null,true,false,1.5,array(1),'','-1','1x','1e2','32768','999999999999999999999','1, user_level=1')as$value){
  ap_reset();$_POST['user_ycard']=$value;$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before&&$ap_write===0,'Invalid warning input fails before profile/quota/avatar writes');$cases++;
 }
 foreach(array(0,'0',1,'00010',32767,'32767')as$value){
  ap_reset();$_POST['user_ycard']=$value;ats_check(ap_run('edit')===true,'Bounded warning input commits');
  ats_check((int)ap_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===(int)$value,'Actual selected warning parser/projection retains integer value');
  $banned=(int)$value>=10;ats_check(count(ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===($banned?1:0),'Current warning limit applies at equality');
  if($banned){ats_check(!ap_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')&&!ap_rows('SELECT user_id FROM fixture_sessions_keys WHERE user_id=2'),'Banned warning selection retires sessions/keys');}$cases++;
 }
 foreach(array('manual-ban','unchanged-ban','missing-warnings')as$scenario){
  ap_reset('root',$scenario);ats_check(ap_run($scenario)===true&&ap_run($scenario)===true,'Repeated unchanged/missing selection saves');
  ats_check(count(ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===1&&!ap_rows('SELECT session_id FROM fixture_sessions WHERE session_user_id=2')&&!ap_rows('SELECT key_id FROM fixture_sessions_keys WHERE user_id=2'),'An unchanged user ban persists without duplicates or old credentials');$cases++;
 }
 ap_reset();ap_sql('UPDATE fixture_users SET user_warnings=7 WHERE user_id=2');unset($_POST['user_ycard']);ats_check(ap_run('edit')===true&&(int)ap_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===7&&!ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'),'Absent warning field preserves a below-threshold count');$cases++;
 foreach(array('0','1','32767')as$limit){
  ap_reset();$board_config['max_user_bancard']=$limit;ap_sql("UPDATE fixture_config SET config_value='$limit' WHERE config_name='max_user_bancard'");$_POST['user_ycard']=$limit==='32767'?'32767':'1';
  ats_check(ap_run('edit')===true&&count(ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===1,'Zero/minimum/maximum current policy has a bounded effective threshold');$cases++;
 }
 foreach(array('-1','32768','bad','1.5','')as$limit){
  ap_reset();$board_config['max_user_bancard']=$limit;ap_sql("UPDATE fixture_config SET config_value='".$peer->sql_escape($limit)."' WHERE config_name='max_user_bancard'");$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before&&$ap_write===0,'Invalid current warning policy cannot mutate a profile');$cases++;
 }
 foreach(array('0','10','32767')as$limit){
  ap_reset('root','self');$board_config['max_user_bancard']=$limit;ap_sql("UPDATE fixture_config SET config_value='$limit' WHERE config_name='max_user_bancard'");$_POST['user_ycard']=$limit==='0'?'1':$limit;$before=ap_snap();ats_check(ap_run('self')==='error'&&ap_snap()===$before&&$ap_write===0,'Cannot self-ban at an effective threshold, including zero/max policy');$cases++;
 }
 ap_reset('root','self');$board_config['max_user_bancard']='0';ap_sql("UPDATE fixture_config SET config_value='0' WHERE config_name='max_user_bancard'");ats_check(ap_run('self')===true,'Zero configured limit does not ban an ordinary zero-warning self edit');$cases++;
 foreach(array('0','9','10')as$value){
  ap_reset('root','mixed-edit');$_POST['user_ycard']=$value;ats_check(ap_run('mixed-edit')===true,'Selected warning reduction/equality saves');
  $rules=ap_rows("SELECT ban_userid,ban_ip,ban_ip_mask,ban_email FROM fixture_banlist WHERE ban_ip='7f0000ff'");ats_check(count($rules)===1&&(int)$rules[0]['ban_userid']===($value==='10'?2:0)&&$rules[0]['ban_ip_mask']==='ffffffff'&&$rules[0]['ban_email']==='keep@example.invalid','Only an actual reduction below limit detaches combined rules');$cases++;
 }
 ap_reset('root','mixed-edit');ap_sql('UPDATE fixture_users SET user_active=0,user_blocktime=2147483647 WHERE user_id=2');$_POST['user_status']='0';
 ats_check(ap_run('disable')===true,'Warning reduction on an inactive/temporarily blocked account saves');
 $other=ap_rows('SELECT user_active,user_blocktime,user_warnings FROM fixture_users WHERE user_id=2')[0];ats_check((int)$other['user_active']===0&&(int)$other['user_blocktime']===2147483647&&(int)$other['user_warnings']===0&&!ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'),'Warning reduction does not reactivate an account or remove its independent temporary block');$cases++;
 ap_reset();unset($_POST['user_ycard']);ats_check(ap_run('edit')===true&&(int)ap_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===0,'Missing field with a canonical nullable legacy counter safely saves zero');$cases++;
 foreach(array(array(0,0,6,true),array(5,5,6,true),array(0,1,6,false),array(5,0,6,false))as$state){
  ap_reset();$_POST['user_ycard_original']=(string)$state[0];$_POST['user_ycard']=(string)$state[1];ap_sql('UPDATE fixture_users SET user_warnings='.$state[2].' WHERE user_id=2');ap_insert('fixture_banlist',array('ban_userid'=>2));$before=ap_snap();
  $saved=ap_run('edit');if($state[3]){ats_check($saved===true&&(int)ap_rows('SELECT user_warnings FROM fixture_users WHERE user_id=2')[0]['user_warnings']===$state[2]&&count(ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===1,'Unchanged stale form preserves newer warnings and a concurrent ban');}else{ats_check($saved==='error'&&ap_snap()===$before&&$ap_write===0,'Edited stale warning field fails before any profile preparation');}$cases++;
 }
 foreach(array(null,array(0),'','-1','32768')as$origin){ap_reset();$_POST['user_ycard_original']=$origin;$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before&&$ap_write===0,'Malformed form origin cannot authorize a warning change');$cases++;}
 ap_reset();unset($_POST['user_ycard_original']);$_POST['user_ycard']='1';$before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before&&$ap_write===0,'Pre-origin form must reload before saving warning fields');$cases++;
 ap_reset('root','mixed-edit');ats_check(ap_run('mixed-edit')===true,'Initial warning reduction commits');ap_insert('fixture_banlist',array('ban_userid'=>2));ats_check(ap_run('mixed-edit')===true&&count(ap_rows('SELECT ban_id FROM fixture_banlist WHERE ban_userid=2'))===1,'Replay of an already-applied reduction cannot remove a later manual ban');$cases++;
 // Actual main-form and avatar-gallery expressions carry the same origin.
 ats_check(preg_match('/^\t\t\t\x27BANCARD_ORIGINAL\x27 => ([^\r\n]+),/m',$GLOBALS['ap_controller'],$origin_expression)===1,'Main form origin projection');
 $admin_profile_scope=null;$new_user=false;$_POST=array('user_ycard_original'=>'5');$this_userdata=array('user_warnings'=>6);eval('$rendered_origin='.$origin_expression[1].';');ats_check($rendered_origin===5,'Navigation preserves the original displayed count, not a newer value');$cases++;
 ats_check(preg_match('/^\t\t\t\$s_hidden_fields \.= [^\r\n]+name="user_ycard_original"[^\r\n]+;/m',$GLOBALS['ap_controller'],$gallery_origin)===1,'Actual gallery hidden-field propagation');$s_hidden_fields='';eval($gallery_origin[0]);ats_check(strpos($s_hidden_fields,'value="5"')!==false,'Avatar navigation carries the same validated baseline');$cases++;
 ap_reset();ap_sql('UPDATE fixture_users SET user_warnings=6 WHERE user_id=2');$admin_profile_scope=new PhpbbAdminProfileScope($db,2,false,$_POST);try{eval('$rendered_origin='.$origin_expression[1].';');ats_check($rendered_origin===6&&$admin_profile_scope->warnings===6,'Validation redisplay rebases an unchanged stale field to the current locked count');}finally{$admin_profile_scope->release();}$cases++;
 // This is the actual GET-form warning projection for nullable legacy rows.
 ats_check(preg_match('/^\t\t\$user_ycard = \$new_user \? 0 : max\(0, \(int\)\$this_userdata\[\x27user_warnings\x27\]\);/m',$GLOBALS['ap_controller'],$projection)===1,'Actual legacy warning form projection');
 foreach(array(null,0,-1,32767)as$value){$new_user=false;$this_userdata=array('user_warnings'=>$value);eval($projection[0]);ats_check(is_int($user_ycard)&&$user_ycard===max(0,(int)$value),'Form sends an explicit valid count for historical null/negative warnings');$cases++;}
 $new_user=true;$this_userdata=array('user_warnings'=>32767);eval($projection[0]);ats_check($user_ycard===0&&PhpbbAdminProfileScope::warning_origin(array(),32767,true)===0,'A new account cannot inherit warnings from the reference profile');$cases++;
 echo 'Native ACP warning selection: '.$cases." complete-profile boundary cases passed.\n";
}
eval($aw_head."\n aw_suite();".substr($aw_source,$aw_finally));
