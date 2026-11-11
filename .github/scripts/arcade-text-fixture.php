<?php
define('ARCADE_ADMIN',1);
require __DIR__.'/arcade-summary-fixture.php';require __DIR__.'/profile-request-fixture.php';
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
 global $HTTP_POST_VARS;profile_fixture_request(array($key=>$raw));eval(ax_assignment($surface,$variable));return ${$variable};
}
function ax_public($name,$description)
{
 global $as_app,$HTTP_POST_VARS,$lang;profile_fixture_request(array('tour_name'=>$name,'tour_desc'=>$description));
 $source=file_get_contents($as_app.'arcade_tournament.php');$a=strpos($source,"\t\$tour_name = (isset(");$b=strpos($source,'  // Save the event and its initial membership',$a);
 as_check($a!==false&&$b>$a,'Actual public tournament validation pipeline');eval(substr($source,$a,$b-$a));return array($tour_name,$tour_desc);
}
function ax_moderator_source()
{
 global $ax_sources;$a=strpos($ax_sources['cats'],"\tif (\$moderator !== '')");$b=strpos($ax_sources['cats'],"\t\$name_sql =",$a);as_check($a!==false&&$b>$a,'Actual category moderator resolver');return substr($ax_sources['cats'],$a,$b-$a);
}
function ax_moderator($raw)
{
 global $db,$lang;$moderator=ax_input('cats','moderator','moderator',$raw);$mod_id=0;eval(ax_moderator_source());return $mod_id;
}
function ax_caption_cases()
{
 $vectors=array();foreach(array(array('cats','name','name',100,0),array('cats','desc','desc',65535,65535),array('games','game_desc','game_desc',255,0),array('games','game_instructions','game_instructions',65535,65535),array('games','search_word','search_word',100,0),array('tournaments','tour_name','tour_name',25,0),array('tournaments','tour_desc','tour_desc',65535,65535))as$surface){
  list($file,$var,$key,$limit,$bytes)=$surface;
  foreach(array('0',"A\\B &amp; '&#39;' <caption>",'ä😀Ö')as$text){$expected=implode('',array_slice(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY),0,$limit));$vectors[]=array($file,$var,$key,$text,$expected);}
  foreach(array('ä','😀','&')as$char){$count=$bytes?(int)floor($bytes/strlen($char)):$limit;$raw=str_repeat($char,$count);$vectors[]=array($file,$var,$key,$raw.'Z',$bytes?($count*strlen($char)<$bytes?$raw.'Z':$raw):$raw);if($bytes){$vectors[]=array($file,$var,$key,$raw.$char.'Z',$raw);}}
 }
 return $vectors;
}
