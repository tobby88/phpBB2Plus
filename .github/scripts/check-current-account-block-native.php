<?php
// Use the actual native editor/posting fixtures and canonical installer tables.
if(PHP_SAPI!=='cli'||getenv('PHPBB_ACCOUNT_BLOCK_NATIVE')!=='1'){echo "Current account blocks require explicit disposable native storage.\n";return;}
$kind=isset($argv[1])?$argv[1]:'';$scripts=__DIR__;
if(!in_array($kind,array('ajax','post','full'),true)){throw new RuntimeException('Choose ajax, post or full');}
foreach(array('AJAX_EDIT','POST_SUBMIT','FULL_EDITOR')as$name){putenv('PHPBB_'.$name.'_NATIVE=1');putenv('PHPBB_'.$name.'_PORT='.(getenv('PHPBB_ACCOUNT_BLOCK_PORT')?:'3306'));putenv('PHPBB_'.$name.'_PASSWORD='.(getenv('PHPBB_ACCOUNT_BLOCK_PASSWORD')?:''));}
function account_block_fixture_setup($source,$scripts)
{
 $source=str_replace(array("file_get_contents(__DIR__ . '/check-ajax-edit-native.php')","file_get_contents(__DIR__.'/check-post-submit-native.php')"),array('file_get_contents('.var_export($scripts.'/check-ajax-edit-native.php',true).')','file_get_contents('.var_export($scripts.'/check-post-submit-native.php',true).')'),$source);
 return str_replace(array('var_export(__DIR__,true)','var_export(__DIR__, true)'),var_export(var_export($scripts,true),true),$source);
}
if($kind==='ajax'){$source=file_get_contents($scripts.'/check-ajax-edit-native.php');$cut=strpos($source," foreach(array('member','moderator','root') as \$actor){");if($cut===false){throw new RuntimeException('Actual AJAX fixture boundary missing');}$head=str_replace('__DIR__',var_export($scripts,true),substr($source,5,$cut-5));}
else{$source=file_get_contents($scripts.($kind==='post'?'/check-post-submit-native.php':'/check-full-editor-native.php'));$cut=strpos($source,$kind==='post'?"\$body = <<<'PHP'":"\$body= <<<'PHP'");if($cut===false){throw new RuntimeException('Actual posting fixture boundary missing');}eval(account_block_fixture_setup(substr($source,5,$cut-5),$scripts));}
$head=str_replace(array('codex_ajax_atomic_','codex_post_submit_','codex_full_editor_'),'codex_account_block_',$head);
function account_block_reset($role){$kind=$GLOBALS['kind'];if($kind==='ajax'){ae_fixture($role);}elseif($kind==='post'){ps_fixture($role);}else{fe_fixture($role);} }
function account_block_submit($variant){$kind=$GLOBALS['kind'];return$kind==='ajax'?ae_edit($variant):($kind==='post'?ps_submit($variant,array('poll'=>$variant==='newtopic')):fe_submit($variant==='reply'?array('post'=>20):array()));}
function account_block_snapshot(){$kind=$GLOBALS['kind'];return$kind==='ajax'?ae_snapshot():($kind==='post'?ps_snapshot():fe_snapshot());}
$body= <<<'PHP'
 if(getenv('PHPBB_ACCOUNT_BLOCK_MYSQL')==='8.4'){$v=ae_rows('SELECT VERSION() version');ae_check(strpos($v[0]['version'],'8.4.')===0&&stripos($v[0]['version'],'MariaDB')===false,'Actual MySQL8.4 account block tests');}
 $variants=$kind==='ajax'?array('subject','text'):($kind==='post'?array('reply','newtopic'):array('first','reply'));$cases=$serialized=0;
 foreach(array('member','moderator','root')as$role){foreach($variants as$variant){
  $expected=null;
  foreach(array('blocked'=>time()+3600,'expired'=>time()-60,'clear'=>0,'legacy-past'=>-1)as$policy=>$until){
   account_block_reset($role);ae_sql('UPDATE fixture_users SET user_blocktime='.$until.' WHERE user_id=8');$before=account_block_snapshot();$GLOBALS['ae_write']=0;
   $identity=phpbb_current_moderator_user($peer);ae_check(($identity===false)===($policy==='blocked'),'Shared current identity honors block and preserves expired/clear accounts');
   $out=account_block_submit($variant);$after=account_block_snapshot();
   if($policy==='blocked'){ae_check($out==='error'&&$after===$before&&$ae_write===0,'Current block refuses before content/index/poll/audit writes '.$role.'/'.$variant);}
   else{ae_check(is_array($out),'Unblocked/past account retains actual operation '.$role.'/'.$variant);if($policy==='clear'){$expected=$after;}}
   $cases++;
  }
  foreach(array('before','write','commit')as$boundary){
   account_block_reset($role);$reached=$blocked=false;$independent=null;
   $ae_hook=function($sql)use($boundary,&$reached,&$blocked,&$independent){
    $match=$boundary==='before'?strpos($sql,'SELECT user_id, user_level, user_active')===0:($boundary==='write'?preg_match('/^(INSERT|UPDATE|DELETE)\b/',$sql):$sql==='COMMIT');if($reached||!$match){return;}$reached=true;$GLOBALS['ae_hook']=null;
    list($r,$error)=native_fixture_conflict_query($GLOBALS['peer'],'UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=8');
    if(!$r){ae_check((int)$error['code']===1205,'Only real row conflicts serialize a later block');$blocked=true;}else{$independent=account_block_snapshot();}
   };
   $out=account_block_submit($variant);$ae_hook=null;ae_check($reached,'Exact account block boundary reached '.$kind.'/'.$boundary);
   if($blocked){ae_check(is_array($out)&&account_block_snapshot()===$expected,'Valid operation commits before a blocked later account change');ae_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=8');$serialized++;}
   else{ae_check($out==='error'&&account_block_snapshot()===$independent,'Effective block preserves only the independent account change');}
   $cases++;
  }
  account_block_reset($role);$before=account_block_snapshot();$ae_fail='SELECT user_id, user_level, user_active, user_blocktime FROM ';$out=account_block_submit($variant);$ae_fail='';ae_check($out==='error'&&account_block_snapshot()===$before,'Failed current account query preserves every participant');$cases++;
 }}
 if($kind==='post'){foreach($variants as$variant){account_block_reset('guest');ae_sql('UPDATE fixture_users SET user_blocktime='.(time()+3600).' WHERE user_id=-1');ae_check(is_array(account_block_submit($variant)),'Anonymous posting is not a registered-account block');$cases++;}}
 ae_check(ae_rows('SELECT @@SESSION.innodb_lock_wait_timeout fixture_wait')[0]['fixture_wait']==='1','Conflict probes restore ordinary fixture timeout');
 echo 'Native current account blocks '.$kind.': '.$cases.' cases, '.$serialized." serialized changes PASS; actual editors/publication, canonical tables, current/past blocks, every actor, SQL failure and unchanged denied data.\n";
}finally{$ae_hook=null;$ae_fail='';$ae_ack=false;$ae_failwrite=0;$db->sql_close();$peer->sql_close();$control->sql_query('DROP DATABASE '.$schema);$control->sql_close();restore_error_handler();}
PHP;
eval($head.$body);
