<?php
// Run the real return-field/validation/link branch, without starting the ACP,
// opening an archive, connecting FTP or touching any forum database/files.
$app=dirname(dirname(__DIR__)).'/phpBB2/';
$source=file_get_contents($app.'admin/xs_import.php');
$a=strpos($source,"\$lang['xs_import_back'] = str_replace");$b=strpos($source,'// Check required functions',$a);
if($a===false||$b===false){throw new RuntimeException('Actual XS return branch unavailable');}$branch=substr($source,$a,$b-$a);
function append_sid($url){return$url.'?sid=fixture';}
function xi_legacy($values){foreach($values as$key=>$value){if(is_string($value)){$values[$key]=addslashes($value);}}return$values;}
function xi_run($branch,$app,$locale,$post,$get){
 $lang=array();require$app.'language/lang_'.$locale.'/lang_xs.php';$phpEx='php';
 $HTTP_POST_VARS=xi_legacy($post);$HTTP_GET_VARS=xi_legacy($get);eval($branch);
 return array('url'=>$return_url,'query'=>$return,'link'=>$lang['xs_import_back'],'post'=>$HTTP_POST_VARS,'get'=>$HTTP_GET_VARS);
}
$cases=array();
foreach(array('xs_install.php','xs_export.php?style=7&mode=export','xs_download.php#done','xs_install.php?style=7#step2',
 'index.html?empty=&literal=%23','xs_install.php?label=Grüße 😀 & <em> & "quote" & O\'Reilly')as$url){
 $cases[]=array(array('return'=>$url),array(),$url);$cases[]=array(array(),array('return'=>$url),$url);
}
foreach(array('https://example.invalid/','//example.invalid/','../xs_install.php','admin/xs_install.php','/xs_install.php','javascript:alert(1)',
 'xs_install.php?next=//example.invalid/','xs_install.php?path=C:\\temp',"xs_install.php?x=1\r\nInjected:yes","xs_install.php#x\0tail",
 "xs_install.php?x=\ttab", "xs_install.php#x\x7ftail",array('invalid'),null,0,false,'')as$invalid){$cases[]=array(array('return'=>$invalid),array(),'');}
$cases[]=array(array('return'=>'xs_install.php'),array('return'=>'xs_export.php'),'xs_install.php');
$cases[]=array(array('return'=>''),array('return'=>'xs_export.php'),'');
$cases[]=array(array('return'=>'../invalid.php'),array('return'=>'xs_export.php'),'');
$cases[]=array(array('return'=>array('invalid')),array('return'=>'xs_export.php'),'xs_export.php');
$cases[]=array(array('return'=>null),array('return'=>'xs_export.php'),'xs_export.php');
$cases[]=array(array(),array(),'');$cases[]=array(array(),array('return'=>array('invalid')),'');
$count=0;$checks=0;
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
foreach(array('english','german')as$locale){foreach($cases as$case){
 $result=xi_run($branch,$app,$locale,$case[0],$case[1]);$expected=$case[2];
 if($result['url']!==$expected||$result['query']!==($expected!==''?'&return='.urlencode($expected):'')){throw new RuntimeException('XS return field changed its validation or URL round trip');}$checks++;
 $expected_href=$expected!==''?$expected:'xs_import.php?sid=fixture';
 if(preg_match('~href="([^"]*)"~',$result['link'],$match)!==1||html_entity_decode($match[1],ENT_QUOTES,'UTF-8')!==$expected_href||strpos($result['link'],'<em>')!==false||strpos($result['link'],'{URL}')!==false){throw new RuntimeException('Actual EN/DE return link must be escaped once and point to the intended page');}$checks++;
 if($expected!==''&&($result['post']['return']!==$expected||$result['get']['return']!==$expected)){throw new RuntimeException('Validated return was not propagated to both legacy aliases');}$checks++;$count++;
}}
restore_error_handler();echo 'XS import returns: ',$count,' actual-controller cases, ',$checks," assertions PASS; EN/DE links, request precedence, escaping and invalid targets.\n";
