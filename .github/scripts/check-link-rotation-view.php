<?php
if(PHP_SAPI!=='cli'){exit(2);}define('IN_PHPBB',true);$phpEx='php';$table_prefix='fixture_';$phpbb_root_path=dirname(dirname(__DIR__)).'/phpBB2/';
require$phpbb_root_path.'includes/php_compat.php';require$phpbb_root_path.'includes/constants.php';require$phpbb_root_path.'includes/functions.php';require$phpbb_root_path.'includes/sessions.php';require$phpbb_root_path.'includes/functions_link_rotation.php';require$phpbb_root_path.'includes/template.php';$SID='';
function rv_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
function rv_html($rotation,$color='eaedf4'){
 global$phpbb_root_path,$phpEx,$board_config,$theme,$lang;$board_config=array('default_lang'=>'english','xs_use_cache'=>0,'xs_auto_compile'=>0,'xs_auto_recompile'=>0);$theme=array('template_name'=>'fisubsilversh','td_color1'=>$color);$lang=array('ENCODING'=>'old-encoding');
 $template=(new ReflectionClass('Template'))->newInstanceWithoutConstructor();$template->vars=&$template->_tpldata['.'][0];$template->load_config($phpbb_root_path.'templates/fisubsilversh',false);$template->set_filenames(array('body'=>'links_js_body.tpl'));
 $source=file_get_contents($phpbb_root_path.'links.js.php');$a=strpos($source,'$payload = json_encode(');$b=strpos($source,'$db->sql_close();',$a);rv_check($a!==false&&$b>$a,'Actual endpoint encoder/template binding');ob_start();try{eval(substr($source,$a,$b-$a));return ob_get_contents();}finally{ob_end_clean();}
}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
try{
 $row=array('link_id'=>'28','link_title'=>'One &amp; "Grüße 😀" </script>','link_logo_src'=>'https://example.invalid/image.php?a=1&b=2');$first=phpbb_link_rotation_item($row,'php');$second=$row;$second['link_id']='29';$second['link_title']='Two';$second=phpbb_link_rotation_item($second,'php');
 $rotation=phpbb_link_rotation_empty();$rotation['logos']=array($first,$second);$rotation['visible']=1;
 if(isset($argv[1])&&$argv[1]==='--sample'){echo rv_html($rotation);return;}
 $cases=0;
 foreach(array(null,array(),array('link_id'=>'28e0','link_logo_src'=>'https://example.invalid/a.png'),array('link_id'=>'28','link_logo_src'=>'javascript:invalid'),array('link_id'=>'28','link_logo_src'=>"https://example.invalid/\xc3"))as$bad){rv_check(phpbb_link_rotation_item($bad,'php')===false,'Malformed item refused independently');$cases++;}
 rv_check($first['url']==='links.php?action=go&link_id=28'&&$first['title']==='One & "Grüße 😀" </script>'&&$first['image']===$row['link_logo_src'],'Current structured payload preserves redirect and decoded title/dynamic image');$cases++;
 $SID='sid='.str_repeat('a',32);rv_check(phpbb_link_rotation_item($row,'php')['url']==='links.php?action=go&link_id=28&'.$SID,'Actual session helper appends raw separators for DOM URLs without cookies');$SID='';$cases++;
 $bad=$row;$bad['link_title']="bad\xc3";rv_check(phpbb_link_rotation_item($bad,'php')['title']==='','One invalid legacy title cannot break the whole payload');$cases++;
 foreach(array('php;',array('php'),'')as$extension){rv_check(phpbb_link_rotation_item($row,$extension)===false,'Extension cannot alter redirect path');$cases++;}
 foreach(array(array(),'#bad',null,42,'fff','EAEDF4','red;bad')as$color){foreach(array(0,1,2)as$count){$data=$rotation;$data['logos']=array_slice($rotation['logos'],0,$count);$html=rv_html($data,$color);rv_check(substr_count($html,'</script>')===1&&strpos($html,'<meta charset="UTF-8"')!==false,'Actual safe script boundary and UTF-8 frame');rv_check(strpos($html,'new Array(')===false&&strpos($html,'document.all')===false&&strpos($html,'document.write')===false,'Actual frame has one flat payload and standard DOM');$expected=is_string($color)&&preg_match('/^(?:[a-f0-9]{3}|[a-f0-9]{6})$/iD',$color)?$color:'eaedf4';rv_check(strpos($html,'background-color:#'.$expected)!==false,'Background restricted to colors');$cases++;}}
 $source=file_get_contents($phpbb_root_path.'links.js.php');rv_check(strpos($source,"define('SESSION_BACKGROUND', true);")<strpos($source,'include($phpbb_root_path . "common.$phpEx");')&&strpos($source,"define('SESSION_BACKGROUND', true);")!==false,'Actual iframe declares background session before bootstrap');$cases++;
 echo 'Link rotation views: '.$cases." cases PASS; actual controller encoder/XS frame, UTF-8/script boundaries, structured dynamic images, title handling, safe colors, standard DOM and background bootstrap; no database or network.\n";
}finally{restore_error_handler();}
