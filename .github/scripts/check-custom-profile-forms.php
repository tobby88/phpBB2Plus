<?php
define('IN_PHPBB', true);
foreach (array('TEXT_FIELD'=>0,'TEXTAREA'=>1,'RADIO'=>2,'CHECKBOX'=>3,'TEXT_FIELD_MAXLENGTH'=>255,'TEXTAREA_MAXLENGTH'=>60000,'REQUIRED'=>1,'DISALLOW_VIEW'=>0,'AUTHOR'=>0,'ABOVE_SIGNATURE'=>1,'BELOW_SIGNATURE'=>2) as $k=>$v) { define($k,$v); }
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/'; $phpEx='php';
require $phpbb_root_path.'includes/php_compat.php'; require $phpbb_root_path.'includes/functions.php';
require __DIR__.'/profile-request-fixture.php';
require $phpbb_root_path.'includes/functions_profile_fields.php'; require $phpbb_root_path.'includes/template.php';
$sessions=file_get_contents($phpbb_root_path.'includes/sessions.php');$sid_function=substr($sessions,strpos($sessions,'function append_sid('));eval(substr($sid_function,0,strrpos($sid_function,'?>')));$SID='sid=fixture';
function cpf_check($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
function cpf_loop($source,$start) {
 $a=strpos($source,$start);$b=strpos($source,'// END Custom Profile Fields MOD',$a);
 cpf_check($a!==false&&$b>$a,'Actual custom controller section');return substr($source,$a,$b-$a);
}
function cpf_encode($value) { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function cpf_decode($value) { return html_entity_decode($value,ENT_QUOTES,'UTF-8'); }
function cpf_read($html,$name,$type) {
 if($type===TEXTAREA) { cpf_check(preg_match('/<textarea\b[^>]*name="'.$name.'"[^>]*>(.*?)<\/textarea>/s',$html,$m)===1,'Textarea exists');return cpf_decode($m[1]); }
 preg_match_all('/<input\b[^>]*name="'.$name.'(?:\[\])?"[^>]*>/',$html,$matches);cpf_check(count($matches[0])>0,'Input exists '.$name);
 $values=array();foreach($matches[0] as $tag) { cpf_check(preg_match('/value="([^"]*)"/',$tag,$m)===1,'Input value');if($type===TEXT_FIELD) { return cpf_decode($m[1]); }if(strpos($tag,'checked="checked"')!==false) { $values[]=cpf_decode($m[1]); } }
 return $values;
}
$public=file_get_contents($phpbb_root_path.'includes/usercp_register.php');$admin=file_get_contents($phpbb_root_path.'admin/admin_users.php');
$render=array('public'=>cpf_loop($public,'foreach($profile_data as $field)'), 'admin'=>cpf_loop($admin,'foreach($profile_data as $field)'));
$validate=array('public'=>cpf_loop($public,'foreach($profile_data as $fields)'), 'admin'=>cpf_loop($admin,'foreach($profile_data as $fields)'));
$theme=array('template_name'=>'fisubsilversh');$board_config=array('xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
$cases=0;$raw="Grüße 😀 \\ &amp; ' \" </textarea><script>bad</script>";
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try {
 foreach(array('english','german') as $language) { $lang=array();require $phpbb_root_path.'language/lang_'.$language.'/lang_main.php';
 foreach(array('public','admin') as $surface) {
 foreach(array('existing','new','rejected','clear','nested','gallery-return') as $scenario) {
  $userdata=array('user_id'=>2,'session_logged_in'=>true,'user_lang'=>$language,'template_name'=>'fisubsilversh');$this_userdata=array();$profile_data=array();
  foreach(array(TEXT_FIELD,TEXTAREA,RADIO,CHECKBOX) as $type) {
   $name='user_fixture_'.$type;
   $profile_data[]=array('field_name'=>$name,'field_description'=>cpf_encode('Info & <safe>'),'field_type'=>$type,'is_required'=>1,'users_can_view'=>1,'text_field_maxlen'=>255,'text_area_maxlen'=>60000,'text_field_default'=>'0','text_area_default'=>'0','radio_button_default'=>'0','checkbox_default'=>'0','radio_button_values'=>'0,'.cpf_encode($raw),'checkbox_values'=>'0,'.cpf_encode($raw));
   $userdata[$name]=$this_userdata[$name]=cpf_encode($raw);
  }
  $new_user=$scenario==='new';$mode=$new_user?'register':'editprofile';$_POST=array();
  if(in_array($scenario,array('rejected','clear','nested','gallery-return'),true)) {
   $_POST[$scenario==='gallery-return'?'submitavatar':'submit']='1';
   foreach($profile_data as $field) {
    $name=phpbb_profile_field_column($field);$type=$field['field_type'];
    if($scenario==='clear') { if($type!==CHECKBOX) { $_POST[$name]=''; } }
    elseif($scenario==='nested') { $_POST[$name]=array(array('bad')); }
    else { $_POST[$name]=$type===CHECKBOX?array('0',$raw):$raw; }
   }
  }
  for($hop=0;$hop<3;$hop++) {
   profile_fixture_request($_POST);
   $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);
   $template->set_filenames(array('body'=>$surface==='public'?'profile_add_body.tpl':'admin/user_edit_body.tpl'));$template->assign_block_vars('switch_custom_fields',array());
   eval($render[$surface]);ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
   cpf_check(strpos($html,'<script>bad</script>')===false,'Safe real template '.$surface);
   cpf_check(strpos($html,'Info &amp; &lt;safe&gt;')!==false&&strpos($html,'Info &amp;amp;')===false,'Definition description encoded once');
   $next=array('submit'=>'1');
   foreach($profile_data as $field) {
    $type=$field['field_type'];$name=phpbb_profile_field_column($field);$value=cpf_read($html,$name,$type);
    $expected=$scenario==='new'?'0':(in_array($scenario,array('clear','nested'),true)?'':$raw);
    if($type===CHECKBOX||$type===RADIO) { $expected=$expected===''?array():array($expected);if($type===CHECKBOX&&in_array($scenario,array('rejected','gallery-return'),true)){$expected=array('0',$raw);} }
    cpf_check($value===$expected,'Exact rendered '.$surface.'/'.$scenario.'/'.$type.'/'.$hop);
    if($type===RADIO) { if($value){$next[$name]=$value[0];} } elseif($type===CHECKBOX) { if($value){$next[$name]=$value;} } else {$next[$name]=$value;}
   }
   $_POST=$next;$new_user=false;$mode=$surface==='admin'?'save':'editprofile';
  }
  $cases++;
 }
 // Run both controllers' actual required-field loops, not just the input helper.
 foreach(array('0','') as $raw_required) {
  $_POST=$HTTP_POST_VARS=array();$profile_names=array();$error=false;$error_msg='';
  foreach($profile_data as $field) {$name=phpbb_profile_field_column($field);$_POST[$name]=$field['field_type']===CHECKBOX?array($raw_required):$raw_required;}
  profile_fixture_request($_POST);foreach($profile_data as $field){$profile_names[phpbb_profile_field_column($field)]=phpbb_profile_field_input($field,$_POST);}
  eval($validate[$surface]);cpf_check($error===($raw_required===''),'Required-field zero semantics '.$surface);$cases++;
 }
 }}
 cpf_check(displayable_field_data('0',CHECKBOX)==='0'&&displayable_field_data(',0,,other,',CHECKBOX)==='0'.$lang['and'].'other','Zero choice display and separators');
 $userdata=array('session_logged_in'=>true);$field=array('field_name'=>'fixture_zero','field_type'=>TEXT_FIELD,'topic_location'=>AUTHOR);
 $out=get_topic_udata(array('user_id'=>999,'fixture_zero'=>'0'),array($field));cpf_check($out['author']===array('fixture_zero: 0'),'Zero visible beside topic author');
 $definition=file_get_contents($phpbb_root_path.'admin/admin_profile_fields.php');$a=strpos($definition,'function profile_field_post_value(');$b=strpos($definition,'function profile_field_column_identifier(',$a);cpf_check($a!==false&&$b>$a,'Actual definition input helper');eval(substr($definition,$a,$b-$a));
 profile_fixture_request(array('draft'=>$raw,'nested'=>array('bad')));cpf_check(profile_field_post_value('draft')===$raw&&profile_field_post_value('nested')==='','Definition inputs retain slashes and reject arrays');
 require_once $phpbb_root_path.'includes/functions_profile_definition_form.php';
 if(!defined('TEXTAREA_MINLENGTH')){define('TEXTAREA_MINLENGTH',0);}
 $request=array();foreach(phpbb_profile_definition_form_map() as $key=>$input){$defaults=phpbb_profile_definition_form_defaults();$request[$input]=$defaults[$key];}
 $request['field_name']='Fixture';$request['radio_values']="first\n0";$request['radio_default_value']='0';$request['checkbox_values']="first\n0";$request['check_default_values']='0';
 profile_fixture_request($request);$prepared=phpbb_profile_definition_form_values($_POST);
 cpf_check($prepared['radio_button_default']==='0'&&$prepared['checkbox_default']==='0','Explicit zero defaults are not replaced by first option');
 cpf_check(phpbb_profile_field_form_control(array('field_name'=>'bad-name!','field_type'=>99),'payload')==='', 'Unknown field type renders no control');
 cpf_check(phpbb_profile_field_form_value(array('field_name'=>'bad-name!','field_type'=>99),array(),array(),false,true)==='', 'Unknown field type has no default');
 echo 'Custom profiles: '.$cases." real public/ACP controller-template cases, three round trips, required zero, defaults, selections and safe output passed.\n";
} finally {restore_error_handler();}
