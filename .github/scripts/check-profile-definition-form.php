<?php
putenv('PHPBB_PROFILE_DEFINITION_NATIVE=0');
require __DIR__.'/check-profile-definition-storage.php';
require_once __DIR__.'/profile-request-fixture.php';
require_once $ats_source.'includes/functions_profile_definition_form.php';
require_once $ats_source.'includes/template.php';
$constants=file_get_contents($ats_source.'includes/constants.php');
foreach(array('NOT_REQUIRED','ALLOW_VIEW','DISALLOW_VIEW','REQUIRED','VIEW_IN_PROFILE','NO_VIEW_IN_PROFILE','CONTACTS','ABOUT','VIEW_IN_MEMBERLIST','NO_VIEW_IN_MEMBERLIST','VIEW_IN_TOPIC','NO_VIEW_IN_TOPIC','AUTHOR','ABOVE_SIGNATURE','BELOW_SIGNATURE','TEXT_FIELD_MINLENGTH') as $name){
 ats_check(preg_match('/define\(\x27'.$name.'\x27,([0-9]+)\);/',$constants,$m)===1,'Canonical form constant '.$name);if(!defined($name)){define($name,(int)$m[1]);}
}
function pdf_request(){
 $out=array();$defaults=phpbb_profile_definition_form_defaults();
 foreach(phpbb_profile_definition_form_map() as $key=>$input){$out[$input]=$defaults[$key];}
 $out['field_name']='Notizen 😀';return $out;
}
function pdf_section($source,$from,$to){$a=strpos($source,$from);$b=$a===false?false:strpos($source,$to,$a);ats_check($a!==false&&$b>$a,'Actual definition controller section '.$from);return substr($source,$a,$b-$a);}
$controller=str_replace("\r\n","\n",file_get_contents($ats_source.'admin/admin_profile_fields.php'));
$add=pdf_section($controller,"if(\$mode == 'add')","elseif(\$mode == 'update')");
$edit='if(false){} '.pdf_section($controller,"elseif(\$mode == 'edit')","elseif(\$mode == 'delete')");
$bindings=pdf_section($controller,"\$template->assign_vars(array(\n  'L_NEW_FIELD_NAME'","\$template->pparse('body');");
class DefinitionFormRows {
 var $row; function sql_query($sql){return true;} function sql_fetchrow($r){return $this->row;}
}
$db=new DefinitionFormRows();$filename='admin_profile_fields.php';$phpbb_root_path=$ats_source;
$theme=array('template_name'=>'fisubsilversh');$userdata=array('user_lang'=>'english','template_name'=>'fisubsilversh','session_id'=>'fixture');
$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
$session_field=phpbb_admin_session_field();$cases=0;
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 foreach(array('english','german') as $language){
  require $ats_source.'language/lang_'.$language.'/lang_main.php';require $ats_source.'language/lang_'.$language.'/lang_admin.php';
  foreach(array('add','edit') as $action){foreach(array('0',"Grüße \\ &amp; ' 😀",'</textarea><script>x</script>') as $raw){
   $request=pdf_request();foreach(array('field_name','field_descrition','text_field_default','text_area_default') as $key){$request[$key]=$raw;}
   $request['text_area_maxlen']='1024';$request['radio_values']="first\n0";$request['radio_default_value']='0';$request['checkbox_values']="first\n0";$request['check_default_values']='0';
   $operation=str_repeat('a',64);$revision=str_repeat('b',64);
   $request['definition_operation']=$operation;$request['definition_revision']=$revision;
   $row=phpbb_profile_definition_form_defaults();$row['field_id']=1;$row['field_column']='user_notes';$row['field_name']='Stored';$db->row=$row;
   for($hop=0;$hop<3;$hop++){
    profile_fixture_request($request);$mode=$action;$pfid=$action==='add'?'x':1;$definition_draft=$_POST;
    $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($ats_source.'templates/fisubsilversh',false);
    eval($action==='add'?$add:$edit);eval($bindings);
    ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
    ats_check(strpos($html,'<script>x</script>')===false&&strpos($html,'selectAll()')===false,'Safe real form/no missing JS');
    foreach(phpbb_profile_definition_form_map() as $key=>$input){
     if(in_array($key,array('field_type','is_required','users_can_view','view_in_profile','profile_location','view_in_memberlist','view_in_topic','topic_location'),true)){
      $pattern='/<input\b[^>]*name="'.$input.'"[^>]*value="([^"]*)"[^>]*checked="checked"[^>]*>/i';
     }elseif(in_array($key,array('text_area_default','radio_button_values','checkbox_values','checkbox_default'),true)){$pattern='/<textarea\b[^>]*name="'.$input.'"[^>]*>(.*?)<\/textarea>/is';}
     else{$pattern='/<input\b[^>]*name="'.$input.'"[^>]*value="([^"]*)"/i';}
     ats_check(preg_match($pattern,$html,$m)===1,'Rendered control '.$input);$read=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');ats_check($read===$request[$input],'Exact draft roundtrip '.$input);$request[$input]=$read;
    }
    $token=$action==='add'?'definition_operation':'definition_revision';ats_check(preg_match('/name="'.$token.'" value="([^"]*)"/',$html,$m)===1&&$m[1]===($action==='add'?$operation:$revision),'Original operation/revision retained on rejection');
   }$cases++;
  }}
 }
 foreach(array("\n","\r\n","\r") as $newline){
  $request=pdf_request();$request['field_type']='3';$request['checkbox_values']='first'.$newline.'0'.$newline;$request['check_default_values']='0';
  profile_fixture_request($request);$values=phpbb_profile_definition_form_values($_POST);ats_check($values['checkbox_values']==='first,0'&&$values['checkbox_default']==='0','Line-ending independent zero selection');$cases++;
 }
 foreach(array('missing','array','null','utf8','nul','comma','duplicate','duplicate-default','empty-option','unknown-default','enum','partial-int','overflow','encoded-limit') as $case){
  $request=pdf_request();
  if($case==='missing'){unset($request['field_type']);}elseif($case==='array'){$request['field_name']=array('bad');}elseif($case==='null'){$request['field_name']=null;}
  elseif($case==='utf8'){$request['field_name']="\xc3";}elseif($case==='nul'){$request['field_name']="bad\0name";}elseif($case==='comma'){$request['radio_values']='first,second';}
  elseif($case==='duplicate'){$request['checkbox_values']="one\none";}elseif($case==='duplicate-default'){$request['checkbox_values']='one';$request['check_default_values']="one\none";}elseif($case==='empty-option'){$request['radio_values']="one\n\ntwo";}elseif($case==='unknown-default'){$request['radio_default_value']='missing';}
  elseif($case==='enum'){$request['signature_wrap']='0';}elseif($case==='partial-int'){$request['text_field_maxlen']='10junk';}elseif($case==='overflow'){$request['text_field_maxlen']='999999999999999999';}
  else{$request['text_field_default']=str_repeat('&',60);}
  profile_fixture_request($request);$denied=false;try{phpbb_profile_definition_form_values($_POST);}catch(PhpbbAclException $e){$denied=true;}ats_check($denied,'Reject malformed form '.$case);$cases++;
 }
 $request=pdf_request();$request['field_column']='user_password';$request['sql']='DROP';profile_fixture_request($request);$v=phpbb_profile_definition_form_values($_POST);ats_check(array_keys($v)===phpbb_profile_definition_fields(),'Request cannot add identifiers/SQL assignments');$cases++;
 $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($ats_source.'templates/fisubsilversh',false);
 $mode='add';$pfid='x';$definition_draft=null;eval($add);$one=$definition_operation;eval($add);ats_check(strlen($one)===64&&ctype_xdigit($one)&&$one!==$definition_operation,'Fresh forms have independent random operation keys');$cases++;
 $request=pdf_request();$request['field_name']="Stored &amp; \\ 😀";$request['field_type']='2';$request['radio_values']="first\n0";$request['radio_default_value']='0';
 profile_fixture_request($request);$row=phpbb_profile_definition_form_values($_POST);$row['field_id']=1;$row['field_column']='user_notes';$db->row=$row;
 $mode='edit';$pfid=1;$definition_draft=null;eval($edit);$vars=$template->_tpldata['.'][0];
 ats_check(html_entity_decode($vars['FIELD_NAME'],ENT_QUOTES,'UTF-8')===$request['field_name']&&html_entity_decode($vars['RADIO_VALUES'],ENT_QUOTES,'UTF-8')===$request['radio_values']&&$vars['RADIO_DEFAULT']==='0','Stored metadata reopens without double escaping');
 ats_check($definition_revision===phpbb_profile_definition_revision($row),'Initial edit token fingerprints the displayed definition');$cases++;
 $db->row=false;$request['definition_revision']=str_repeat('c',64);profile_fixture_request($request);$definition_draft=$_POST;eval($edit);$vars=$template->_tpldata['.'][0];
 ats_check(html_entity_decode($vars['FIELD_NAME'],ENT_QUOTES,'UTF-8')===$request['field_name']&&$definition_revision===$request['definition_revision'],'Deleted field rejection retains draft and stale token, never silently becomes creation');$cases++;
 echo 'Profile definition forms: '.$cases." actual-controller/template, parser and token cases passed.\n";
}finally{restore_error_handler();}
