<?php
// Execute the actual view-mode parser and query helper without forum startup.
define('IN_PHPBB',true); $table_prefix='fixture_';
$root=dirname(dirname(__DIR__)).'/phpBB2/';
require $root.'album_mod/album_constants.php';
function arq_check($ok,$message){if(!$ok){throw new RuntimeException($message);}}
$body=file_get_contents($root.'album.php');
$start=strpos($body,"\$album_view_mode = '';");
$end=strpos($body,"\n\tif (isset (\$_POST['cat_id']))",$start);
arq_check($start!==false&&$end!==false,'Actual complete mode-parser boundaries');
$parser=substr($body,$start,$end-$start)."\n}";
$body=file_get_contents($root.'album_mod/album_hierarchy_functions.php');
arq_check(preg_match('/^function album_build_url_parameters\(.*?^\}/ms',$body,$match)===1,'Actual query-helper boundary');eval($match[0]);
$cases=0;$reporting=error_reporting(E_ALL);
set_error_handler(function($s,$m){if(error_reporting()&$s){throw new RuntimeException($m);}});
try{
 $requests=array(
  array(array(),array(),''),
  array(array(),array('mode'=>'ALL'),'all'),
  array(array(),array('mode'=>'LIST'),'list'),
  array(array('mode'=>'LIST'),array('mode'=>'all'),'list'),
  array(array('mode'=>'ALL'),array('mode'=>'list'),'all'),
  array(array('mode'=>array()),array('mode'=>'all'),''),
  array(array('mode'=>array('list')),array('mode'=>'all'),''),
  array(array('mode'=>null),array('mode'=>'all'),'all'),
  array(array('mode'=>''),array('mode'=>'all'),''),
  array(array('mode'=>'invalid'),array('mode'=>'all'),'')
 );
 foreach(array(array(),array('all'),array(array('list')),new stdClass(),true,false,0,1,1.5,null,'invalid',' all ')as$invalid){$requests[]=array(array(),array('mode'=>$invalid),'');}
 foreach(array(0,7)as$owner){foreach($requests as$request){
  $_POST=$request[0];$_GET=$request[1];$album_user_id=$owner;eval($parser);
  arq_check($album_view_mode===($owner===0?'':$request[2]),'Safe scalar mode/default and POST precedence');$cases++;
 }}
 arq_check(album_build_url_parameters(array('cat_id'=>10,'user_id'=>7,'mode'=>'all'))==='?cat_id=10&user_id=7&mode=all','Existing gallery parameter order/values');$cases++;
 foreach(array('Grüße 😀','A & B','x=1&mode=list','#fragment',"line\r\nbreak",'space + percent%','0','',"'\"<>/",true,false,0,1,1.5)as$value){
  $query=album_build_url_parameters(array('cat_id'=>10,'caption'=>$value));$parsed=array();parse_str(substr($query,1),$parsed);
  arq_check($parsed===array('cat_id'=>'10','caption'=>(string)$value),'One complete scalar value survives query encoding');
  arq_check(strpos($query,"\r")===false&&strpos($query,"\n")===false&&strpos($query,'#')===false,'No literal control/fragment delimiters in query');$cases++;
 }
 arq_check(album_build_url_parameters(array('odd&key'=>'a=b'))==='?odd%26key=a%3Db','Parameter names and values are independently encoded');$cases++;
 foreach(array(array(),array('mode'=>'all'),new stdClass(),null)as$value){arq_check(album_build_url_parameters(array('cat_id'=>10,'unexpected'=>$value))==='?cat_id=10','Nested/non-scalar values are omitted without warnings');$cases++;}
 foreach(array(array(),array('nested'=>array()),null,false,0,'query',new stdClass())as$container){arq_check(album_build_url_parameters($container)==='','Empty/invalid parameter collections yield no query delimiter');$cases++;}
 arq_check(strpos(file_get_contents($root.'album.php'),'album_build_url_parameters($_GET)')!==false,'Category redirect still uses actual tested builder');$cases++;
 echo 'Album request input: '.$cases." actual parser/query/Unicode/array/precedence/round-trip cases PASS; no HTTP or database.\n";
}finally{restore_error_handler();error_reporting($reporting);}
