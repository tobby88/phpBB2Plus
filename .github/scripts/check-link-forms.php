<?php
if(PHP_SAPI!=='cli'){exit(2);}define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'includes/functions.php';require$phpbb_root_path.'includes/template.php';
$cases=0;error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
function lf_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function append_sid($url){return$url;}
try{
 foreach(array('english','german')as$locale){foreach(array('links_index','links_body','links_search','links_popnew')as$file){foreach(array('guest','member','locked')as$state){foreach(array(false,true)as$optional){
  $lang=array();require$phpbb_root_path.'language/lang_'.$locale.'/lang_main.php';require$phpbb_root_path.'language/lang_'.$locale.'/lang_main_link.php';
  $board_config=array('default_lang'=>$locale,'xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0);$theme=array('template_name'=>'fisubsilversh');$userdata=array('session_logged_in'=>$state==='member','user_id'=>$state==='member'?4:ANONYMOUS);
  $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);$template->set_filenames(array('body'=>$file.'.tpl'));
  $template->assign_vars(array('U_LINK_REG'=>'link_register.php','S_LINK_TOKEN'=>'<input type="hidden" name="sid" value="synthetic-token" />','L_LINK_REGISTER'=>$lang['Link_register'],'LINK_FORM_ERROR_ATTR'=>htmlspecialchars($lang['Link_form_invalid'],ENT_QUOTES,'UTF-8'),'LINK_PREVIEW_ERROR_ATTR'=>htmlspecialchars($lang['Link_preview_invalid'],ENT_QUOTES,'UTF-8'),'LINK_PREVIEW_ALT_ATTR'=>'Preview','LINK_LOGO_REQUIRED'=>$optional?'':'required="required"','LINK_CAT_OPTION'=>'<option value="1">Synthetic</option>'));
  if($state!=='locked'){$template->assign_block_vars('lock',array());$template->assign_block_vars('lock.'.($state==='member'?'submit':'logout'),array());}
  ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
  $forms=preg_match_all('/<form name="linkdata"[^>]*>(.*?)<\/form>/s',$html,$m);lf_check($forms===($state==='member'?1:0),'Actual XS include renders exactly the intended form');
  if($state==='member'){$form=$m[0][0];lf_check(strpos($form,'onsubmit="return phpbbLinkForm.validate(this);"')!==false&&substr_count($form,'name="sid"')===1&&strpos($form,'synthetic-token')!==false,'Actual form action/token/handler');lf_check(preg_match('/name="link_title"[^>]*maxlength="200"[^>]*data-link-maxchars="100"/',$form)===1&&preg_match('/name="link_desc"[^>]*maxlength="510"[^>]*data-link-maxchars="255"/',$form)===1,'Native code-unit ceiling permits full logical Unicode limits');lf_check(preg_match('/<input[^>]*name="link_logo_src"[^>]*>/',$form,$logo)===1&&strpos($logo[0],'value=""')!==false&&(strpos($logo[0],'required="required"')===false)===$optional,'Blank optional logo and current required flag');lf_check(strpos($form,'<form',1)===false&&strpos($form,'<table')<strpos($form,'</table>')&&strrpos($form,'</table>')<strrpos($form,'</form>'),'Form encloses complete tables');lf_check(strpos($form,'referrerpolicy="no-referrer"')!==false&&strpos($form,'window.open')===false&&strpos($html,'function checkForm')===false,'Inline image preview replaces dead/popup code');}
  $cases++;
 }}}}
 echo 'Link forms XS: '.$cases." actual views PASS; four pages, EN/DE, guest/member/locked states, shared form/token/handler, optional logo and full Unicode limits; no database or network.\n";
}finally{restore_error_handler();}
