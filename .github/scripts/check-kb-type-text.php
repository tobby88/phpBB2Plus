<?php
// Intentionally load only the new ACP worker, not frontend functions_kb.php.
define('IN_PHPBB',true);$lang=array('KB_type_invalid'=>'invalid-type','Not_Authorised'=>'denied');
require dirname(dirname(__DIR__)).'/phpBB2/includes/functions_kb_type_storage.php';
function kt_text_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
foreach(array(array(" \n0\t ",'0'),array(" Typ Größe 😀 O'Reilly \\ exact ","Typ Größe 😀 O'Reilly \\ exact"),array(str_repeat('😀',300),str_repeat('😀',255)),array(str_repeat('ü',255).'x',str_repeat('ü',255)))as$case){$actual=kb_type_text($case[0]);kt_text_check($actual===$case[1]&&preg_match('//u',$actual)===1,'Actual type text bounds retain complete Unicode characters and literal punctuation');}
foreach(array(null,array('type'),false,1,"bad\xff","bad\0",str_repeat('x',1048577))as$invalid){$denied=false;try{kb_type_text($invalid);}catch(PhpbbKbPublicationException$error){$denied=true;}kt_text_check($denied,'Malformed/oversized type names refuse cleanly without any DB dependency');}
$denied=false;try{kb_publication_actor(null,array('type'));}catch(PhpbbKbPublicationException$error){$denied=true;}kt_text_check($denied,'Nonscalar actor context cannot trigger a PHP illegal-offset/type failure');
kt_text_check(!function_exists('kb_clear_search_cache'),'ACP worker is independently loadable without the frontend cache helper');
echo 'KB type standalone text/invalid-scope/bootstrap checks passed (mbstring '.(function_exists('mb_substr')?'enabled':'absent, Unicode fallback exercised').").\n";
