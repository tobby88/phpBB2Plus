<?php
// Actual module assignments, shared helper and EN/DE translations; no SQL.
define('IN_PHPBB', true); error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$app=dirname(dirname(__DIR__)).'/phpBB2/';
function mt_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['checks']++;}
$checks=0;$cases=0;
$source=file_get_contents($app.'includes/functions.php');
mt_check(preg_match('/^function phpbb_timezone_label\(.*?^\}/ms',$source,$match)===1,'Actual shared timezone helper');eval($match[0]);
$expressions=array();
foreach(array('printview.php','pafiledb/includes/functions.php')as$file){
 $source=file_get_contents($app.$file);
 mt_check(preg_match('/[\x27"]S_TIMEZONE[\x27"]\s*=>\s*(sprintf\()/',$source,$match,PREG_OFFSET_CAPTURE)===1,'Actual module timezone field');
 $expression='';$depth=0;$opened=false;
 foreach(token_get_all('<?php '.substr($source,$match[1][1]))as$token){
  if(is_array($token)&&$token[0]===T_OPEN_TAG){continue;}
  $expression.=is_array($token)?$token[1]:$token;
  if($token==='('){$depth++;$opened=true;}elseif($token===')'){$depth--;}
  if($opened&&$depth===0){break;}
 }
 mt_check($opened&&$depth===0,'Complete actual sprintf expression');$expressions[$file]=$expression;
}
foreach(array('english','german')as$locale){
 $lang=array();require$app.'language/lang_'.$locale.'/lang_main.php';$values=array();
 foreach($lang as$key=>$label){if(is_numeric($key)&&is_string($label)){$values[]=array((string)$key,$label);}}
 mt_check(count($values)>20,'Real translated legacy GMT labels');
 $values[]=array('5.50',$lang['5.5']);$values[]=array('-3.50',$lang['-3.5']);
 $values[]=array('13.25','GMT + 13.25 hours');$values[]=array('-13.25','GMT - 13.25 hours');
 $values[]=array('0.25','GMT + 0.25 hours');$values[]=array('unknown',$lang['0']);$values[]=array('',$lang['0']);
 foreach($expressions as$file=>$expression){foreach($values as$value){
  $board_config=array('board_timezone'=>$value[0]);$snapshot=serialize(array($board_config,$lang));
  $actual=eval('return '.$expression.';');$expected=sprintf($lang['All_times'],$value[1]);
  mt_check($actual===$expected,'Correct module label: '.$locale.' '.$file.' '.$value[0]);
  mt_check(serialize(array($board_config,$lang))===$snapshot,'Module label preserves stored configuration and translations');$cases++;
 }}
}
restore_error_handler();echo 'Module timezone labels: ',$cases,' actual EN/DE assignments, ',$checks," assertions PASS; integer, fractional, legacy and unknown offsets without rounding or warnings.\n";
