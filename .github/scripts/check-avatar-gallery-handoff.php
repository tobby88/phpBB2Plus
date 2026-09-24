<?php
define('IN_PHPBB',true);define('ALLOW_VIEW',1);define('CHECKBOX',3);define('USER_AVATAR_GALLERY',3);define('PROFILE_FIELDS_TABLE','fixture_fields');
$source=dirname(dirname(__DIR__)).'/phpBB2/';$phpEx='php';
require $source.'includes/php_compat.php';require $source.'includes/functions.php';
require $source.'includes/functions_profile_fields.php';require $source.'includes/usercp_avatar.php';require $source.'includes/template.php';
function gallery_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function gallery_function($file,$name){$t=token_get_all(file_get_contents($file));$out='';$on=false;$opened=false;$depth=0;for($i=0;$i<count($t);$i++){if(is_array($t[$i])&&$t[$i][0]===T_FUNCTION){$j=$i+1;while(is_array($t[$j])&&$t[$j][0]===T_WHITESPACE){$j++;}if(is_array($t[$j])&&$t[$j][1]===$name){$on=true;}}if(!$on){continue;}$out.=is_array($t[$i])?$t[$i][1]:$t[$i];if($t[$i]==='{'){$opened=true;$depth++;}elseif($t[$i]==='}'&&--$depth===0&&$opened){break;}}gallery_check($out!==''&&$depth===0,'Actual helper '.$name);eval($out);}
gallery_function($source.'includes/sessions.php','append_sid');gallery_function($source.'includes/usercp_register.php','usercp_post_scalar');
class GalleryReadFixture {
 var $rows=array();var $fields=array();
 function sql_query($sql){gallery_check(strpos($sql,'SELECT * FROM fixture_fields')===0,'Gallery performs only expected read');$this->rows=$this->fields;return true;}
 function sql_fetchrow($r){return array_shift($this->rows);}
}
$db=new GalleryReadFixture();$db->fields=array(array('field_name'=>'user_notes','field_type'=>0),array('field_name'=>'user_choices','field_type'=>3),array('field_name'=>'submit','field_type'=>0),array('field_name'=>'sid','field_type'=>0),array('field_name'=>'new_password','field_type'=>0));
$fixture=sys_get_temp_dir().'/phpbb-gallery-'.bin2hex(phpbb_random_bytes(8));
gallery_check(mkdir($fixture)&&mkdir($fixture.'/gallery')&&mkdir($fixture.'/gallery/first')&&mkdir($fixture.'/gallery/rock & roll'),'Owned gallery fixture');
$files=array('/gallery/first/one.png',"/gallery/rock & roll/member's_face.png");foreach($files as $file){file_put_contents($fixture.$file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));}
$phpbb_root_path=$fixture.'/';$theme=array('template_name'=>'fisubsilversh');$userdata=array('user_lang'=>'english','template_name'=>'fisubsilversh');$SID='sid=fixture-session';
$board_config=array('avatar_gallery_path'=>'gallery','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0,'default_lang'=>'english');
$controller=file_get_contents($source.'includes/usercp_register.php');
$start=strpos($controller,"\t\t// Raw form/display values");$end=strpos($controller,"\n\t\tif ( !isset(\$_POST['cancelavatar']))",$start);gallery_check($start!==false&&$end>$start,'Actual gallery return presentation');$return_code=substr($controller,$start,$end-$start);
$start=strpos($controller,"\t\$strip_var_list = array('email'");$end=strpos($controller,"\tforeach (array('fb'",$start);gallery_check($start!==false&&$end>$start,'Actual profile text parser');$parse_code=substr($controller,$start,$end-$start);
function gallery_posted($html){$post=array();preg_match_all('/<input type="hidden" name="([^"]*)" value="([^"]*)"\s*\/>/',$html,$m,PREG_SET_ORDER);foreach($m as $v){$name=html_entity_decode($v[1],ENT_QUOTES,'UTF-8');$value=html_entity_decode($v[2],ENT_QUOTES,'UTF-8');if(substr($name,-2)==='[]'){$post[substr($name,0,-2)][]=$value;}else{gallery_check(!array_key_exists($name,$post),'No duplicate control '.$name);$post[$name]=$value;}}return $post;}
$cases=0;set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 foreach(array('english','german') as $language){$lang=array();require $source.'language/lang_'.$language.'/lang_main.php';
 foreach(array('editprofile','register') as $mode){foreach(array('simple',"Grüße 😀 \\ path & \" ' <tag>",'&amp; literal') as $raw){
  $_POST=array('username'=>'Member','email'=>'member@example.invalid','signal'=>'user.42','threema'=>'A1B2C3D4','location'=>$raw,'occupation'=>$raw,'interests'=>$raw,'user_absence_text'=>$raw,'dateformat'=>'D \\ Y','user_notes'=>$raw,'user_choices'=>array($raw,'second',array('nested')),'new_password'=>'SECRET-do-not-echo','cur_password'=>'SECRET-current','password_confirm'=>'SECRET-confirm','submit'=>'malicious-command');
  $category='rock & roll';$values=array();foreach((new ReflectionFunction('display_avatar_gallery'))->getParameters() as $p){$key=$p->getName();$values[$key]=isset($_POST[$key])&&is_scalar($_POST[$key])?htmlspecialchars((string)$_POST[$key],ENT_QUOTES,'UTF-8'):'';}
  $values['mode']=$mode;$values['category']=$category;$values['user_id']=2;$values['birthday']=999999;$values['session_id']='fixture-session';
  $_POST['user_id']='999';$_POST['birthday']='123';
  for($hop=0;$hop<3;$hop++){
   $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($source.'templates/fisubsilversh',false);$template->set_filenames(array('body'=>'profile_avatar_gallery.tpl'));
   $args=array();foreach((new ReflectionFunction('display_avatar_gallery'))->getParameters() as $p){$args[]=&$values[$p->getName()];}call_user_func_array('display_avatar_gallery',$args);
   ob_start();try{$template->pparse('body');$html=ob_get_contents();}finally{ob_end_clean();}
   gallery_check(strpos($html,'SECRET-')===false&&strpos($html,'<tag>')===false,'No password reflection or raw markup');
   gallery_check(strpos($html,'Member&#039;s face')!==false,'Filename caption escaped');
   gallery_check(strpos($html,htmlspecialchars($lang['Avatar_gallery_form_notice'],ENT_NOQUOTES,'UTF-8'))!==false,'Localized re-entry notice');
   $post=gallery_posted($html);foreach(array('location','occupation','interests','user_absence_text','user_notes') as $key){gallery_check($post[$key]===$raw,'Exact raw round trip '.$key.'/'.$hop);}
   gallery_check($post['signal']==='user.42'&&$post['threema']==='A1B2C3D4','Modern contacts retained');
   gallery_check($post['dateformat']==='D \\ Y','Date-format escape retained');
   gallery_check($post['user_id']==='2'&&$post['birthday']==='999999'&&$post['coppa']==='','Owner-derived values, not field names or raw overrides');
   gallery_check($post['user_choices']===array($raw,'second')&&!isset($post['submit'])&&!isset($post['new_password'])&&!isset($post['cur_password'])&&!isset($post['password_confirm'])&&$post['sid']==='fixture-session','Custom choices filtered; reserved names cannot override controls');
   gallery_check($post['avatarcatname']==='rock & roll','Category with ampersand retained');
   $_POST=$post;
  }
  foreach(array('submitavatar','cancelavatar') as $action){$_POST[$action]='1';$signature='';$cur_password=$new_password=$password_confirm='';eval($parse_code);eval($return_code);
   foreach(array('location','occupation','interests','signal','threema') as $key){gallery_check(html_entity_decode(phpbb_profile_display_text($$key),ENT_QUOTES,'UTF-8')===$_POST[$key],'Actual returned profile field '.$key);}
   unset($_POST[$action]);$cases++;
  }
 }}}
 echo 'Avatar gallery: '.$cases." full-gallery/template and return cases; three-hop text/contact preservation and secret/control isolation passed.\n";
}finally{restore_error_handler();foreach($files as $file){unlink($fixture.$file);}rmdir($fixture.'/gallery/first');rmdir($fixture.'/gallery/rock & roll');rmdir($fixture.'/gallery');rmdir($fixture);}
