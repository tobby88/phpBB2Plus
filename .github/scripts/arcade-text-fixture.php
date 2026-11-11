<?php
define('ARCADE_ADMIN',1);
require __DIR__.'/arcade-summary-fixture.php';require __DIR__.'/profile-request-fixture.php';
require_once $as_app.'includes/functions_arcade_category_storage.php';
as_function($as_app.'admin/admin_arcade_games.php','arcade_admin_post_string');
$ax_sources=array();foreach(array('cats','games','tournaments')as$surface){$ax_sources[$surface]=file_get_contents($as_app.'admin/admin_arcade_'.$surface.'.php');}
function ax_assignment($surface,$variable)
{
 global $ax_sources;
 as_check(preg_match_all('/^\h*\$'.preg_quote($variable,'/').' = [^\r\n]*;/m',$ax_sources[$surface],$matches)>0,'Actual assignment '.$surface.'/'.$variable);
 $found=array();foreach($matches[0]as$line){if(strpos($line,'HTTP_POST_VARS')!==false||strpos($line,'arcade_admin_post_string')!==false){$found[]=$line;}}
 as_check(count($found)===1,'Unique actual input assignment');return $found[0];
}
function ax_input($surface,$variable,$key,$raw)
{
 global $HTTP_POST_VARS;
 if($surface==='cats'){
  $post=array('save_cat'=>'1','tree_revision'=>str_repeat('a',64),'cat_id'=>'0','cat_revision'=>'','cat_order'=>'0','name'=>'Fixture','desc'=>'','icon'=>'','cat_type'=>'p','cat_parent'=>'0','group_required'=>'0','moderator'=>'','special'=>'0');$post[$key]=$raw;profile_fixture_request($post);
  try{$input=phpbb_arcade_category_input($HTTP_POST_VARS);return $input['values'][$variable==='moderator'?'moderator':'cat_'.$variable];}catch(PhpbbOwnedStorageFailure$e){return null;}
 }
 profile_fixture_request(array($key=>$raw));eval(ax_assignment($surface,$variable));return ${$variable};
}
function ax_public($name,$description)
{
 global $as_app,$HTTP_POST_VARS,$lang;profile_fixture_request(array('tour_name'=>$name,'tour_desc'=>$description));
 $source=file_get_contents($as_app.'arcade_tournament.php');$a=strpos($source,"\t\$tour_name = (isset(");$b=strpos($source,'  // Save the event and its initial membership',$a);
 as_check($a!==false&&$b>$a,'Actual public tournament validation pipeline');eval(substr($source,$a,$b-$a));return array($tour_name,$tour_desc);
}
function ax_moderator($raw)
{
 global $db;return phpbb_arcade_category_moderator($db,ax_input('cats','moderator','moderator',$raw));
}
function ax_caption_cases()
{
 $vectors=array();foreach(array(array('cats','name','name',100,0),array('cats','desc','desc',65535,65535),array('games','game_desc','game_desc',255,0),array('games','game_instructions','game_instructions',65535,65535),array('games','search_word','search_word',100,0),array('tournaments','tour_name','tour_name',25,0),array('tournaments','tour_desc','tour_desc',65535,65535))as$surface){
  list($file,$var,$key,$limit,$bytes)=$surface;
  foreach(array('0',"A\\B &amp; '&#39;' <caption>",'ä😀Ö')as$text){$expected=implode('',array_slice(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY),0,$limit));$vectors[]=array($file,$var,$key,$text,$expected);}
  foreach(array('ä','😀','&')as$char){$count=$bytes?(int)floor($bytes/strlen($char)):$limit;$raw=str_repeat($char,$count);$expected=$bytes?($count*strlen($char)<$bytes?$raw.'Z':$raw):$raw;if($file==='cats'){$vectors[]=array($file,$var,$key,$raw,$raw);if($expected!==$raw.'Z'){$expected=null;}}$vectors[]=array($file,$var,$key,$raw.'Z',$expected);if($bytes){$vectors[]=array($file,$var,$key,$raw.$char.'Z',$file==='cats'?null:$raw);}}
 }
 return $vectors;
}
