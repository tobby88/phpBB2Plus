<?php
if(PHP_SAPI!=='cli'||getenv('PHPBB_ADMIN_PROFILE_NATIVE')!=='1'){echo "Profile text owner checks require an explicitly enabled owned native database.\n";return;}
$source=file_get_contents(__DIR__.'/check-admin-profile-native.php');$cut=strpos($source," foreach(array('root','junior') as \$actor)");
if($cut===false){throw new RuntimeException('Actual ACP native fixture boundary');}
$head=str_replace('__DIR__',var_export(__DIR__,true),substr($source,5,$cut-5));$head=str_replace('codex_admin_profile_','codex_profile_text_owner_',$head);
$tail= <<<'PHP'
 foreach(array('TEXT_FIELD'=>0,'TEXTAREA'=>1,'RADIO'=>2,'CHECKBOX'=>3,'TEXT_FIELD_MAXLENGTH'=>255,'TEXTAREA_MAXLENGTH'=>1024)as$key=>$value){if(!defined($key)){define($key,$value);}}
 function pto_definition($type){return array('field_name'=>'Synthetic text field','field_column'=>'user_custom_fixture','field_type'=>$type,'text_field_maxlen'=>2,'text_area_maxlen'=>60000,'text_field_default'=>'','text_area_default'=>'','radio_button_default'=>'','radio_button_values'=>'','checkbox_default'=>'','checkbox_values'=>'','users_can_view'=>1);}
 $count=0;
 foreach(array('root','junior')as$actor){
  foreach(array('ampersand','literal','emoji','textarea','radio','checkbox')as$kind){
   ap_reset($actor,'edit');$type=$kind==='textarea'?TEXTAREA:($kind==='radio'?RADIO:($kind==='checkbox'?CHECKBOX:TEXT_FIELD));$definition=pto_definition($type);
   if($kind==='ampersand'){$input='&Z';$expected='&Z';}elseif($kind==='literal'){$input='&amp;';$expected='&a';}elseif($kind==='emoji'){$input='ä😀Z';$expected='ä😀';}
   elseif($kind==='textarea'){$input=str_repeat('"',60001);$expected=str_repeat('"',60000);}
   elseif($kind==='radio'){$input=str_repeat('"',255);$expected=$input;$definition['radio_button_values']=htmlspecialchars($input,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
   else{$input=array();for($i=0;$i<100;$i++){$input[]=str_repeat('"',250).sprintf('%05d',$i);}$expected=implode(',',$input);$encoded=array();foreach($input as$item){$encoded[]=htmlspecialchars($item,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}$definition['checkbox_values']=implode(',',$encoded);}
   ap_insert('fixture_profile_fields',$definition);$GLOBALS['ap_custom_input']=$input;ats_check(ap_run('edit')===true,'Actual ACP custom-field save '.$actor.'/'.$kind);
   $stored=ap_rows('SELECT user_custom_fixture FROM fixture_users WHERE user_id=2')[0]['user_custom_fixture'];ats_check($stored===htmlspecialchars($expected,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),'Actual ACP exact encoded bytes '.$actor.'/'.$kind);$count++;
  }
  foreach(array('user-width','definition-width')as$old){
   ap_reset($actor,'edit');ap_insert('fixture_profile_fields',pto_definition(TEXT_FIELD));$GLOBALS['ap_custom_input']='&';
   if($old==='user-width'){ap_sql('ALTER TABLE fixture_users MODIFY user_custom_fixture VARCHAR(255) NULL DEFAULT NULL');}
   else{ap_sql('ALTER TABLE fixture_profile_fields MODIFY text_field_default VARCHAR(255) NULL DEFAULT NULL');}
   $before=ap_snap();ats_check(ap_run('edit')==='error'&&ap_snap()===$before,'Obsolete custom storage refuses save before mutation '.$actor.'/'.$old);
   ap_sql('ALTER TABLE fixture_users MODIFY user_custom_fixture MEDIUMTEXT NULL DEFAULT NULL');ap_sql('ALTER TABLE fixture_profile_fields MODIFY text_field_default MEDIUMTEXT NULL DEFAULT NULL');$count++;
  }
 }
 unset($GLOBALS['ap_custom_input']);echo 'Native custom-profile owners: '.$count." actual ACP raw-input/encoded-storage, large-value and old-storage refusal cases PASS.\n";
}finally{$ap_hook=null;$main_connection=isset($ap_main)?$ap_main:null;if($main_connection){$main_connection->sql_close();}$peer->sql_close();ats_check($control->sql_query('DROP DATABASE '.$fixture),'Owned profile owner schema cleanup');$control->sql_close();restore_error_handler();
 if(isset($ap_files)&&is_dir($ap_files)&&!is_link($ap_files)&&preg_match('~/phpbb-profile-[a-f0-9]{16}$~D',$ap_files)){foreach(array('before.png','after.png','cache/cg_users.cache')as$file){if(is_file($ap_files.'/'.$file)){unlink($ap_files.'/'.$file);}}if(is_dir($ap_files.'/cache')){rmdir($ap_files.'/cache');}rmdir($ap_files);}
}
PHP;
$tail=str_replace('__DIR__',var_export(__DIR__,true),$tail);if(eval($head.$tail)===false){throw new RuntimeException('Actual profile owner fixture did not execute');}
