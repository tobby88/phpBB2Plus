<?php
// Actual signature save branch and native driver; owned loopback fixture only.
$signature_source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/usercp_signature.php');
if(strpos($signature_source,'$db->sql_escape($signature_text)')===false||strpos($signature_source,'$db->sql_escape($bbcode_uid)')===false){throw new RuntimeException('Signature SQL values must use the active driver');}
if(PHP_SAPI!=='cli'||getenv('PHPBB_SIGNATURE_NATIVE')!=='1'){echo "Signature SQL boundary checked; native round trips require an explicitly enabled disposable fixture.\n";return;}
putenv('PHPBB_PUBLIC_PROFILE_NATIVE=1');putenv('PHPBB_PUBLIC_PROFILE_PORT='.getenv('PHPBB_SIGNATURE_PORT'));putenv('PHPBB_PUBLIC_PROFILE_PASSWORD='.getenv('PHPBB_SIGNATURE_PASSWORD'));
$file=__DIR__.'/check-public-profile-native.php';$source=file_get_contents($file);
$cut=strpos($source," foreach(array('edit','password','rename','avatar','reactivate','reactivate-password') as \$scenario)");
if($cut===false){throw new RuntimeException('Owned profile fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_public_profile_','codex_signature_save_',$head);
$tail= <<<'PHP'
 foreach(array('usercp_signature_post_scalar'=>'includes/usercp_signature.php','phpbb_post_escape_html'=>'includes/functions_post.php','prepare_message'=>'includes/functions_post.php','clean_html'=>'includes/functions_post.php') as $name=>$path){ats_load_function($ats_source.$path,$name);}
 $src=file_get_contents($ats_source.'includes/usercp_signature.php');$a=strpos($src,'// save new signature');$b=strpos($src,'// catch the submitted message',$a);ats_check($a!==false&&$b>$a,'Actual complete signature save branch');$body=substr($src,$a,$b-$a);
 class SignatureSaveTemplate {function assign_block_vars($name,$values){}}
 $template=new SignatureSaveTemplate();$html_entities_match=array('#&(?!(\#[0-9]+;))#','#<#','#>#','#"#');$html_entities_replace=array('&amp;','&lt;','&gt;','&quot;');$cases=0;
 foreach(array(0,1) as $html_on){foreach(array('',"Grüße ' 😀",'C:\new\test \\ tail',"', user_level=1, user_sig='","a\0b\nc",'"quoted" & text','<b>bold</b>') as $raw){
  pp_reset();$board_config['max_sig_chars']=10000;$board_config['allow_html_tags']='b';$_POST=array('sid'=>'exact-session');$submit='Save';$bbcode_on=$smilies_on=0;$signature_text=$raw;$save_message='';
  $before=pp_snap();eval($body);$row=pp_rows('SELECT user_sig,user_sig_bbcode_uid,user_level FROM fixture_users WHERE user_id=2')[0];
  $expected=$raw==='<b>bold</b>'?($html_on?$raw:'&lt;b&gt;bold&lt;/b&gt;'):str_replace(array('&','"'),array('&amp;','&quot;'),$raw);
  ats_check($row['user_sig']===$expected,'Exact native signature bytes with HTML='.$html_on.' case='.$cases);
  ats_check($row['user_sig_bbcode_uid']===''&&(int)$row['user_level']===0,'Signature cannot assign privilege/token columns');
  $after=pp_snap();foreach($after['users'] as &$user){if((int)$user['user_id']===2){foreach($before['users'] as $old){if((int)$old['user_id']===2){$user['user_sig']=$old['user_sig'];$user['user_sig_bbcode_uid']=$old['user_sig_bbcode_uid'];}}}}unset($user);
  ats_check($after===$before,'Only the two intended signature columns change');$cases++;
 }}
 pp_reset();$_POST=array('sid'=>'wrong-session');$submit='Save';$signature_text='denied';$before=pp_snap();$denied=false;try{eval($body);}catch(AttachSettingsExit $e){$denied=true;}ats_check($denied&&pp_snap()===$before,'Incorrect submitted session remains rejected');$cases++;
 pp_reset();$_POST=array('sid'=>'exact-session');$board_config['max_sig_chars']=2;$signature_text='too long';$before=pp_snap();eval($body);ats_check(pp_snap()===$before,'Length rejection remains non-mutating');$cases++;
 echo 'Native signature save: '.$cases." cases; literal SQL-like text, quotes, slashes, Unicode, HTML and unchanged account authority passed.\n";
}finally{
 if($public_avatar_scope){$public_avatar_scope->release();}$pp_hook=$pp_after=null;$pp_main->sql_close();$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Remove owned signature schema');$control->sql_close();
 foreach(array('avatars/before.png','avatars/after.png','cache/cg_users.cache','cache/arcade_best_player.cache','cache/arcade_best_at_player.cache') as $f){if(is_file($pp_files.'/'.$f)){unlink($pp_files.'/'.$f);}}
 rmdir($pp_files.'/avatars');rmdir($pp_files.'/cache');rmdir($pp_files);restore_error_handler();
}
PHP;
eval($head.$tail);
