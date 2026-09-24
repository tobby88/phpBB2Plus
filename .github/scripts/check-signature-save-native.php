<?php
// Actual signature save branch and native driver; owned loopback fixture only.
$signature_source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/usercp_signature.php');
$signature_worker=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/functions_signature_storage.php');
if(strpos($signature_source,'phpbb_signature_save($db, $signature_text, $submitted_sid)')===false||strpos($signature_worker,'$writer->escape($text)')===false||strpos($signature_worker,'$writer->escape($uid)')===false){throw new RuntimeException('Signature SQL values must use the guarded native writer');}
if(PHP_SAPI!=='cli'||getenv('PHPBB_SIGNATURE_NATIVE')!=='1'){echo "Signature SQL boundary checked; native round trips require an explicitly enabled disposable fixture.\n";return;}
putenv('PHPBB_PUBLIC_PROFILE_NATIVE=1');putenv('PHPBB_PUBLIC_PROFILE_PORT='.getenv('PHPBB_SIGNATURE_PORT'));putenv('PHPBB_PUBLIC_PROFILE_PASSWORD='.getenv('PHPBB_SIGNATURE_PASSWORD'));
$file=__DIR__.'/check-public-profile-native.php';$source=file_get_contents($file);
$cut=strpos($source," foreach(array('edit','password','rename','avatar','reactivate','reactivate-password') as \$scenario)");
if($cut===false){throw new RuntimeException('Owned profile fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_public_profile_','codex_signature_save_',$head);
$tail= <<<'PHP'
 foreach(array('usercp_signature_post_scalar'=>'includes/usercp_signature.php','phpbb_post_escape_html'=>'includes/functions_post.php','prepare_message'=>'includes/functions_post.php','clean_html'=>'includes/functions_post.php') as $name=>$path){ats_load_function($ats_source.$path,$name);}
 require_once $ats_source.'includes/bbcode.php';
 require_once $ats_source.'includes/functions_signature_storage.php';
 function sig_reset($html=0,$bbcode=0){
  pp_reset();$GLOBALS['phpbb_root_path']=$GLOBALS['ats_source'];
  foreach(array('max_sig_chars'=>10000,'allow_html'=>$html,'allow_html_tags'=>'b','allow_bbcode'=>$bbcode,'allow_smilies'=>0) as $key=>$value){$GLOBALS['board_config'][$key]=$value;pp_sql("REPLACE INTO fixture_config (config_name,config_value) VALUES ('$key','".$GLOBALS['peer']->sql_escape((string)$value)."')");}
  pp_sql('UPDATE fixture_users SET user_allowhtml='.$html.',user_allowbbcode='.$bbcode.',user_allowsmile=0 WHERE user_id=2');
  $GLOBALS['userdata']=array_merge($GLOBALS['userdata'],pp_rows('SELECT * FROM fixture_users WHERE user_id=2')[0]);
  $_POST=array('sid'=>'exact-session');
 }
 function sig_run($text='new signature'){
  global $db,$userdata,$board_config,$phpbb_root_path,$phpEx,$lang,$template,$body;
  profile_fixture_request(array('sid'=>isset($_POST['sid'])?$_POST['sid']:'','signature_text'=>$text));
  $submit='Save';$signature_text=trim(usercp_signature_post_scalar('signature_text'));$save_message='';eval($body);return $save_message===$lang['sig_save_message'];
 }
 $src=file_get_contents($ats_source.'includes/usercp_signature.php');$a=strpos($src,'// save new signature');$b=strpos($src,'// catch the submitted message',$a);ats_check($a!==false&&$b>$a,'Actual complete signature save branch');$body=substr($src,$a,$b-$a);
 class SignatureSaveTemplate {function assign_block_vars($name,$values){}}
 $template=new SignatureSaveTemplate();$html_entities_match=array('#&(?!(\#[0-9]+;))#','#<#','#>#','#"#');$html_entities_replace=array('&amp;','&lt;','&gt;','&quot;');$cases=0;
 foreach(array(0,1) as $html_on){foreach(array('',"Grüße ' 😀",'C:\new\test \\ tail',"', user_level=1, user_sig='","a\0b\nc",'"quoted" & text','<b>bold</b>') as $raw){
  sig_reset($html_on);profile_fixture_request(array('sid'=>'exact-session','signature_text'=>$raw));
  $submit='Save';$bbcode_on=$smilies_on=0;$signature_text=trim(usercp_signature_post_scalar('signature_text'));$save_message='';
  $before=pp_snap();eval($body);$row=pp_rows('SELECT user_sig,user_sig_bbcode_uid,user_level FROM fixture_users WHERE user_id=2')[0];
  $expected=$raw==='<b>bold</b>'?($html_on?$raw:'&lt;b&gt;bold&lt;/b&gt;'):str_replace(array('&','"'),array('&amp;','&quot;'),$raw);
  ats_check($row['user_sig']===$expected,'Exact native signature bytes with HTML='.$html_on.' case='.$cases);
  ats_check($row['user_sig_bbcode_uid']===''&&(int)$row['user_level']===0,'Signature cannot assign privilege/token columns');
  $after=pp_snap();foreach($after['users'] as &$user){if((int)$user['user_id']===2){foreach($before['users'] as $old){if((int)$old['user_id']===2){$user['user_sig']=$old['user_sig'];$user['user_sig_bbcode_uid']=$old['user_sig_bbcode_uid'];}}}}unset($user);
  ats_check($after===$before,'Only the two intended signature columns change');$cases++;
 }}
 sig_reset();$_POST=array('sid'=>'wrong-session');$submit='Save';$signature_text='denied';$before=pp_snap();$denied=false;try{eval($body);}catch(AttachSettingsExit $e){$denied=true;}ats_check($denied&&pp_snap()===$before,'Incorrect submitted session remains rejected');$cases++;
 sig_reset();$board_config['max_sig_chars']=2;$signature_text='too long';$before=pp_snap();eval($body);ats_check(pp_snap()===$before,'Length rejection remains non-mutating');$cases++;
 $changes=array('session'=>"DELETE FROM fixture_sessions WHERE session_id='exact-session'",'case'=>"UPDATE fixture_sessions SET session_id='EXACT-SESSION' WHERE session_id='exact-session'",'foreign'=>"UPDATE fixture_sessions SET session_user_id=7 WHERE session_id='exact-session'",'logout'=>"UPDATE fixture_sessions SET session_logged_in=0 WHERE session_id='exact-session'",'inactive'=>'UPDATE fixture_users SET user_active=0 WHERE user_id=2','password'=>"UPDATE fixture_users SET user_password='reset' WHERE user_id=2",'signature'=>"UPDATE fixture_users SET user_sig='other edit' WHERE user_id=2",'uid'=>"UPDATE fixture_users SET user_sig_bbcode_uid='changed' WHERE user_id=2",'html'=>'UPDATE fixture_users SET user_allowhtml=1 WHERE user_id=2','bbcode'=>'UPDATE fixture_users SET user_allowbbcode=1 WHERE user_id=2','smilies'=>'UPDATE fixture_users SET user_allowsmile=1 WHERE user_id=2','ban'=>"INSERT INTO fixture_banlist (ban_userid,ban_ip) VALUES (2,'')");
 foreach($changes as $name=>$sql){sig_reset();pp_sql($sql);$before=pp_snap();ats_check(!sig_run()&&pp_snap()===$before,'Reject revoked/stale signature request '.$name);$cases++;}
 foreach(phpbb_signature_policy_keys() as $key){foreach(array('change','remove') as $mode){sig_reset();pp_sql($mode==='remove'?"DELETE FROM fixture_config WHERE config_name='$key'":"UPDATE fixture_config SET config_value='changed' WHERE config_name='$key'");$before=pp_snap();ats_check(!sig_run()&&pp_snap()===$before,'Reject stale/missing signature policy '.$key.'/'.$mode);$cases++;}}
 $blocked_count=0;$probes=$changes;
 foreach(phpbb_signature_policy_keys() as $key){$probes['policy-'.$key]="UPDATE fixture_config SET config_value='changed' WHERE config_name='$key'";}
 foreach($probes as $name=>$probe){sig_reset();$blocked=false;$pp_hook=function($sql)use($probe,&$blocked){if(strpos($sql,'UPDATE fixture_users SET user_sig=')===0){$GLOBALS['pp_hook']=null;pp_sql('SET SESSION innodb_lock_wait_timeout=0');try{$r=$GLOBALS['peer']->sql_query($probe);$blocked=!$r&&(int)$GLOBALS['peer']->sql_error()['code']===1205;}finally{pp_sql('SET SESSION innodb_lock_wait_timeout=1');}}};ats_check(sig_run()&&$blocked,'Hold signature authority/policy through write '.$name);$blocked_count++;$cases++;}
 foreach(array('write','commit','ack') as $failure){sig_reset();$before=pp_snap();if($failure==='write'){$pp_fail=1;}else{$pp_commit=$failure==='commit'?'fail':'ack';}ats_check(!sig_run(),'No success for failed/uncertain signature save '.$failure);ats_check($failure==='ack'?pp_rows('SELECT user_sig FROM fixture_users WHERE user_id=2')[0]['user_sig']==='new signature':pp_snap()===$before,'Signature transaction result '.$failure);$lock=new attach_mutation_lock($db);ats_check($lock->acquired,'Failed save releases writer');$lock->release();$cases++;}
 foreach(array('users','sessions','config','banlist') as $table){foreach(array('ENGINE=MyISAM','ROW_FORMAT=COMPACT','CONVERT TO CHARACTER SET utf8 COLLATE utf8_general_ci') as $ddl){
  // The canonical wide users row cannot fit COMPACT at all; test that legacy
  // format on every other participant without weakening its native schema.
  if($table==='users'&&$ddl==='ROW_FORMAT=COMPACT'){continue;}
  sig_reset();pp_sql('ALTER TABLE fixture_'.$table.' '.$ddl);$before=pp_snap();ats_check(!sig_run()&&pp_snap()===$before,'Reject legacy signature participant '.$table.'/'.$ddl);pp_sql('ALTER TABLE fixture_'.$table.' ENGINE=InnoDB, ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$cases++;
 }}
 sig_reset(0,1);ats_check(sig_run('[b]Grüße 😀[/b]'),'Native BBCode signature save');$row=pp_rows('SELECT user_sig,user_sig_bbcode_uid FROM fixture_users WHERE user_id=2')[0];ats_check($row['user_sig_bbcode_uid']!==''&&$row['user_sig']==='[b:'.$row['user_sig_bbcode_uid'].']Grüße 😀[/b:'.$row['user_sig_bbcode_uid'].']','BBCode UID and content round trip');$cases++;
 sig_reset();$_SERVER['REQUEST_METHOD']='GET';$before=pp_snap();ats_check(!sig_run()&&pp_snap()===$before,'Signature requires POST');$cases++;
 sig_reset();pp_sql('ALTER TABLE fixture_album ENGINE=MyISAM');ats_check(sig_run(),'Unrelated album engine does not block signature');pp_sql('ALTER TABLE fixture_album ENGINE=InnoDB ROW_FORMAT=DYNAMIC');$cases++;
 echo 'Native signature save: '.$cases.' cases / '.$blocked_count." serialized revocations; text, BBCode, authority, policy and failure boundaries passed.\n";
}finally{
 if($public_avatar_scope){$public_avatar_scope->release();}$pp_hook=$pp_after=null;$pp_main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned signature schema');$control->sql_close();
 foreach(array('avatars/before.png','avatars/after.png','cache/cg_users.cache','cache/arcade_best_player.cache','cache/arcade_best_at_player.cache') as $f){if(is_file($pp_files.'/'.$f)){unlink($pp_files.'/'.$f);}}
 rmdir($pp_files.'/avatars');rmdir($pp_files.'/cache');rmdir($pp_files);restore_error_handler();
}
PHP;
eval($head.$tail);
