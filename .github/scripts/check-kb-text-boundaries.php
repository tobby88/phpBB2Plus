<?php
// Exercise the actual KB truncator with and without mbstring, without bootstrap.
$source=file_get_contents(dirname(dirname(__DIR__)).'/phpBB2/includes/functions_kb.php');
if(preg_match('/^function kb_limit_text\(.*?^\}/ms',$source,$match)!==1){throw new RuntimeException('Actual KB text helper');}eval($match[0]);$cases=0;
foreach(array(
 array(' Grüße ',255,'Grüße'),array(str_repeat('ü',255).'x',255,str_repeat('ü',255)),
 array(str_repeat('😀',300),255,str_repeat('😀',255)),array('😀üx',1,'😀'),array('😀üx',2,'😀ü'),
 array("A\n😀üZ",4,"A\n😀ü"),array('0',255,'0'),array('',25,''),
 array(str_repeat('A',1048576),25,str_repeat('A',25)),array(str_repeat('😀',1000),60,str_repeat('😀',60))
)as$case){$out=kb_limit_text($case[0],$case[1]);if($out!==$case[2]||preg_match('//u',$out)!==1){throw new RuntimeException('KB Unicode truncation exactness');}$cases++;}
echo 'KB text boundaries: '.$cases.' actual Unicode/newline/large-input cases passed; mbstring '.(function_exists('mb_substr')?'enabled':'absent').". No database/network.\n";
