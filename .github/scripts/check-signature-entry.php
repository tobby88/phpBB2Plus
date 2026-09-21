<?php
define('IN_PHPBB',true);define('GENERAL_ERROR',1);
$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require $phpbb_root_path.'includes/php_compat.php';
require $phpbb_root_path.'includes/functions.php';
require $phpbb_root_path.'includes/template.php';
$lang=array();require $phpbb_root_path.'language/lang_english/lang_main.php';
// Actual session URL builder, including the no-cookie SID boundary.
$sessions=file_get_contents($phpbb_root_path.'includes/sessions.php');
$sid_function=substr($sessions,strpos($sessions,'function append_sid('));eval(substr($sid_function,0,strrpos($sid_function,'?>')));
function signature_entry_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
class SignatureNoWriter {}
$db=new SignatureNoWriter();
$source=file_get_contents($phpbb_root_path.'includes/usercp_signature.php');
$start=strpos($source,'// Profile editing stays');signature_entry_check($start!==false,'Actual controller bootstrap');
eval(substr($source,5,$start-5));$body=substr($source,$start);
// Omit only the global page chrome; all entry/action/template code is real.
foreach(array('header','tail') as $part){$line="include(\$phpbb_root_path . 'includes/page_".$part.".'.\$phpEx);";signature_entry_check(substr_count($body,$line)===1,'Known chrome boundary');$body=str_replace($line,'',$body);}
$board_config=array('allow_html'=>0,'allow_bbcode'=>1,'allow_smilies'=>0,'max_sig_chars'=>10000,'allow_html_tags'=>'b','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
$userdata=array('user_id'=>2,'session_id'=>'fixture-session','session_logged_in'=>true,'user_lang'=>'english','template_name'=>'fisubsilversh','user_allowhtml'=>0,'user_allowbbcode'=>1,'user_allowsmile'=>0,'user_sig'=>'Stored &amp; text','user_sig_bbcode_uid'=>'');
$theme=array('template_name'=>'fisubsilversh');$images=array('icon_profile'=>'profile.png','icon_pm'=>'pm.png','icon_email'=>'email.png','icon_www'=>'www.png');
function signature_entry_template(){
 $t=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$t->vars=&$t->_tpldata['.'][0];$t->load_config($GLOBALS['phpbb_root_path'].'templates/fisubsilversh',false);return $t;
}
$payload="Grüße \\ & \" </textarea><script>alert('test')</script>";$cases=0;
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 foreach(array('','sid=fixture-session') as $SID){foreach(array('direct','preview','current','too-long','busy','legacy-fields','nested') as $case){
  $template=signature_entry_template();$_GET=array('mode'=>'signature');$_POST=array();$_SERVER['REQUEST_METHOD']='GET';$board_config['max_sig_chars']=10000;
  if($case==='preview'){$_POST=array('preview'=>'1','signature_text'=>$payload);}
  elseif($case==='current'){$_POST=array('current'=>'1','preview'=>'1','save'=>'1','signature_text'=>$payload);}
  elseif($case==='too-long'||$case==='busy'){$_POST=array('save'=>'1','signature_text'=>$payload,'sid'=>'fixture-session');if($case==='too-long'){$board_config['max_sig_chars']=2;}}
  elseif($case==='legacy-fields'){$_POST=array('username'=>'private-user','email'=>'private@example.invalid','new_password'=>'private-password','avatarurl'=>'private-upload','editprofile'=>'1');}
  elseif($case==='nested'){$_POST=array('save'=>array('1'),'preview'=>array('1'),'current'=>array('1'),'signature_text'=>array($payload));}
  if($_POST){$_SERVER['REQUEST_METHOD']='POST';}
  ob_start();try{eval($body);$html=ob_get_contents();}finally{ob_end_clean();}
  signature_entry_check(strpos($html,'<script>')===false&&strpos($html,'private-')===false,'No payload injection or unrelated profile values '.$case);
  preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s',$html,$forms);signature_entry_check(count($forms[1])===1,'Exactly one active form '.$case);
  signature_entry_check(substr_count($forms[1][0],'name="sid"')===1&&strpos($forms[1][0],'value="fixture-session"')!==false,'SID inside active form '.$case);
  signature_entry_check(strpos($html,'name="editprofile"')===false&&strpos($html,'document.preview.submit')===false,'No profile auto-submit');
  signature_entry_check($template->vars['SIG_LINK']===phpbb_profile_text(append_sid('profile.php?mode=signature',true))&&strpos($template->vars['SIG_LINK'],'&amp;amp;')===false,'Cookie-less action SID escaped once');
  if(in_array($case,array('preview','too-long','busy'),true)){
   preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s',$html,$m);signature_entry_check(isset($m[1])&&html_entity_decode($m[1],ENT_QUOTES,'UTF-8')===$payload,'Rendered textarea retains rejected/preview text '.$case);
  }else{signature_entry_check($template->vars['SIGNATURE']==='Stored &amp; text','Current signature remains source '.$case);}
  $cases++;
 }}
 $register=file_get_contents($phpbb_root_path.'includes/usercp_register.php');
 $profile=file_get_contents($phpbb_root_path.'profile.php');
 signature_entry_check(strpos($register,"\$_GET['second']")===false,'Remove unused alternate profile state parser');
 signature_entry_check(strpos($profile,"!empty(\$_POST['signature'])")===false,'Profile fields cannot override routing, including avatar return');
 $route_start=strpos($profile,'$get_mode =');$route_end=strpos($profile,"if ( \$mode == 'viewprofile' )",$route_start);
 signature_entry_check($route_start!==false&&$route_end>$route_start,'Actual profile mode selection');
 foreach(array('editprofile','register','signature') as $expected_mode){foreach(array('stored signature',array('nested')) as $signature_field){
  $_GET=array('mode'=>$expected_mode);$_POST=array('signature'=>$signature_field);eval(substr($profile,$route_start,$route_end-$route_start));
  signature_entry_check($mode===$expected_mode,'Unrelated signature field cannot hijack '.$expected_mode);$cases++;
 }}
 foreach(array(false,true) as $member){$template=signature_entry_template();$template->set_filenames(array('body'=>'profile_add_body.tpl'));
  foreach(array('U_SIGNATURE_EDITOR','L_SIGNATURE_NEW_TAB','SIG_BUTTON_DESC') as $key){preg_match("/'".$key."' => ([^\r\n]+),/",$register,$m);signature_entry_check(isset($m[1]),'Actual profile link assignment');$template->assign_vars(array($key=>eval('return '.$m[1].';')));}
  if($member){$template->assign_block_vars('switch_signature_editor',array());}
  ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
  signature_entry_check((strpos($html,'target="_blank" rel="noopener noreferrer"')!==false)===$member,'Separate tab link only for existing member');
  signature_entry_check(strpos($html,'name="signature"')===false,'Signature link never submits or reloads original profile form');$cases++;
 }
 echo 'Signature entry: '.$cases." full-controller/Extreme Styles cases passed without warnings; private fields and profile form remain separate.\n";
}finally{restore_error_handler();}
