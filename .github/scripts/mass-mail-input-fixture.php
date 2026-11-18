<?php
// Exercise the real input boundary without sessions, a database or transport.
// The previous per-character array exhausted 64 MiB for valid 1 MiB ASCII.
if(!defined('IN_PHPBB')){define('IN_PHPBB',true);}if(!defined('POST_GROUPS_URL')){define('POST_GROUPS_URL','g');}
require_once dirname(dirname(__DIR__)).'/phpBB2/includes/php_compat.php';
require_once dirname(dirname(__DIR__)).'/phpBB2/includes/functions_mass_mail.php';
$mass_input_old_memory=ini_get('memory_limit');ini_set('memory_limit','32M');
$mass_input_checks=0;
function mass_input_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['mass_input_checks']++;}
try{
 foreach(array(str_repeat('x',1048576),str_repeat('😀',262144))as$body){$payload=phpbb_mass_mail_payload(array('g'=>'-1','subject'=>'Synthetic only','message'=>$body));mass_input_check($payload['message']===$body&&strlen($payload['message'])===1048576,'Full historical byte capacity preserved as valid ASCII/UTF-8');unset($body,$payload);}
 foreach(array(array(str_repeat('x',1048577),'Mail_too_long'),array(str_repeat('😀',262143)."\xf0\x9f\x98",'Mail_invalid'),array("\0text",'Mail_invalid'),array('  ','Mail_invalid'))as$case){$error=null;try{phpbb_mass_mail_payload(array('g'=>'-1','subject'=>'Synthetic only','message'=>$case[0]));}catch(PhpbbMassMailFailure$failure){$error=$failure->getMessage();}mass_input_check($error===$case[1],'Oversized/truncated UTF-8/NUL/empty draft is rejected, never silently truncated');}unset($case);
 $payload=phpbb_mass_mail_payload(array('g'=>'-1','subject'=>'0','message'=>'0'));mass_input_check($payload['subject']==='0'&&$payload['message']==='0','Literal zero remains valid text');
 mass_input_check(memory_get_peak_usage(true)<33554432,'Maximum valid payload needs less than 32 MiB for the real input helper');
 echo 'Mass-mail input bounds: '.$mass_input_checks." checks PASS; full 1 MiB ASCII/UTF-8, explicit oversize/invalid rejection and less than32 MiB memory; no mail or SQL.\n";
}finally{ini_set('memory_limit',$mass_input_old_memory);}
