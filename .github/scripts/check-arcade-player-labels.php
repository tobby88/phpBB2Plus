<?php
// Actual controller bindings; synthetic rows, no board bootstrap or real SQL.
if(PHP_SAPI!=='cli'){exit(2);}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
define('IN_PHPBB',true);$table_prefix='fixture_';$phpEx='php';
$al_app=isset($al_app)?$al_app:dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$al_app;
require $al_app.'includes/php_compat.php';require $al_app.'includes/constants.php';
$helpers=file_get_contents($al_app.'includes/functions.php');foreach(array('phpbb_profile_text','phpbb_stored_text')as$name){if(preg_match('/^function '.$name.'\(.*?^\}/ms',$helpers,$part)!==1){throw new RuntimeException('Actual text helper required');}eval($part[0]);}
$activity=file_get_contents($al_app.'activity.php');$comment=file_get_contents($al_app.'arcade_comment.php');
if(preg_match('/^function arcade_output_html\(.*?^\}/ms',$activity,$part)!==1){throw new RuntimeException('Actual raw metadata renderer required');}eval($part[0]);
function append_sid($url){return $url;}
function al_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['al_checks']++;}
class ArcadeLabelPlayerFixture{var $name;function get_username($id){if((int)$id!==7){throw new RuntimeException('Unexpected synthetic account');}return $this->name;}}
$bindings=array();
foreach(preg_split('/\r?\n/',$activity)as$line){
 if(preg_match('/^\s*\$profile =.*(?:username|get_username|arcade_output_html\(\$best_player\)|phpbb_stored_text\(\$best_player\)).*;$/',$line)){$bindings[]=array('link',trim($line));}
 elseif(preg_match('/^\s*[\'"](?:BEST_PLAYER|BEST_AT_PLAYER|NAME|ARCADE_USERNAME)[\'"]\s*=>\s*(?:arcade_output_html|phpbb_stored_text)\(.*\),?\s*$/',$line)&&strpos($line,'catrows')===false){$bindings[]=array('array',trim($line));}
 elseif(strpos($line,'$best_at_player = htmlspecialchars(')!==false||strpos($line,'$best_at_player = phpbb_stored_text(')!==false){$bindings[]=array('alltime',trim($line));}
 elseif(strpos($line,'$display_last_tour = \'<a ')!==false){$bindings[]=array('tournament',trim($line));}
}
foreach(preg_split('/\r?\n/',$comment)as$line){if(preg_match('/^\s*\$poster =.*(?:first_commenter|commentrow).*;$/',$line)){$bindings[]=array('comment',trim($line));}}
if(count($bindings)!==15){throw new RuntimeException('All fifteen actual account display bindings required, got '.count($bindings));}
$arcade=new ArcadeLabelPlayerFixture();$cases=0;$al_checks=0;$board_config=array('sitename'=>'Synthetic board');
foreach(array('A&B',"O'Reilly",'"Name"','&#39;','&amp;','<b>ü</b>','Grüße 😀','C:\\notes')as$raw){foreach(array(ENT_QUOTES,ENT_COMPAT)as$flags){
 $stored=phpbb_username_key($raw,$flags);if($stored===''){throw new RuntimeException('Valid canonical/historical account key required');}
 foreach($bindings as$index=>$binding){
  $arcade->name=$stored;$i=0;$temp_url='profile.php?mode=viewprofile&amp;u=7';$best_player=$best_at_player=$user_name=$stored;$userdata=array('username'=>$stored);$score_at_info=array('username'=>$stored);$catrows=array(array('username'=>$stored,'mod_id'=>7));$first_commenter=array('user_id'=>7,'username'=>$stored);$commentrow=array(array('user_id'=>7,'username'=>$stored));$last_tour=array('tour_id'=>11,'tour_name'=>'Raw &amp; <caption>');$last_tour_user_id=7;$last_tour_date='DATE';
  if($binding[0]==='array'){$values=eval('return array('.$binding[1].');');$markup=reset($values);}
  else{eval($binding[1]);$markup=$binding[0]==='comment'?$poster:($binding[0]==='alltime'?$best_at_player:($binding[0]==='tournament'?$display_last_tour:$profile));}
  $visible=html_entity_decode(strip_tags($markup),ENT_QUOTES,'UTF-8');$expected=$binding[0]==='tournament'?'Raw &amp; <caption>DATE'.$raw:$raw;
  al_check(trim($visible)===$expected,'Actual controller account label must decode once, including literal entity spellings');
  al_check(strpos($markup,htmlspecialchars($raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))!==false,'Account data must stay HTML-escaped');
  if(in_array($binding[0],array('link','comment','tournament'),true)){al_check(strpos($markup,'href="profile.php?mode=viewprofile&amp;u=7"')!==false,'Account names retain the correct numeric profile target');}
  if($binding[0]==='tournament'){al_check(strpos($markup,'Raw &amp;amp; &lt;caption&gt;')!==false,'Tournament captions remain raw metadata');}
  $cases++;
 }
}}

// Exercise the actual score-table visibility decision, not just its encoder.
$a=strpos($activity,"if(\$user_name == 'Anonymous')");$b=$a===false?false:strpos($activity,'// Requested thousands',$a);
al_check($a!==false&&$b>$a,'Complete score-table visibility branch');$visibility=substr($activity,$a,$b-$a);
$a=strpos($activity,"'NAME' =>",$b);$b=$a===false?false:strpos($activity,"\n",$a);
al_check($a!==false&&$b>$a,'Actual score-table template binding');$score_binding=trim(substr($activity,$a,$b-$a));
foreach(array('english','german')as$locale){$lang=array();require $al_app.'language/lang_'.$locale.'/lang_main.php';require $al_app.'language/lang_'.$locale.'/lang_extend_arcade.php';
 foreach(array(0,ADMIN,MOD)as$level){foreach(array(0,1)as$visible_online){foreach(array('Anonymous','A&B','&#39;')as$raw){
  $stored=phpbb_username_key($raw);$user_name=$stored;$row=array('user_allow_viewonline'=>$visible_online);$userdata=array('user_level'=>$level);
  eval($visibility);$values=eval('return array('.$score_binding.');');$expected=$raw==='Anonymous'?$lang['Guest']:(!$visible_online&&$level!==ADMIN&&$level!==MOD?$lang['game_hidden']:$raw);
  al_check(html_entity_decode($values['NAME'],ENT_QUOTES,'UTF-8')===$expected,'Guest and hidden score owners preserve their existing visibility rules');$cases++;
 }}}
}

// The comment fallback still belongs to the shared guest/former-author policy.
class ArcadeLabelAliasFixture{
 function sql_escape($v){return str_replace("'","''",$v);}
 function sql_query($sql){if(strpos($sql,'SELECT n.author_user_id FROM fixture_removed_author_names n WHERE ')!==0){throw new RuntimeException('Synthetic alias reads only');}return(object)array('rows'=>strpos($sql,"n.author_name='Legacy former'")!==false?array(array('author_user_id'=>0)):array());}
 function sql_fetchrowset($r){return $r->rows;}function sql_freeresult($r){}
}
require_once $al_app.'includes/functions_author_visibility.php';$keep=array();
foreach($bindings as$binding){if($binding[0]==='comment'&&strpos($binding[1],'commentrow')!==false){$comment_binding=$binding[1];}}
al_check(isset($comment_binding),'Actual comment row and fallback');
$viewers=array(array('id'=>-1,'logged'=>false,'mask'=>true),array('id'=>3,'logged'=>true,'mask'=>false));if(isset($al_extra_viewers)){$viewers=array_merge($viewers,$al_extra_viewers);}
foreach(array('english','german')as$locale){$lang=array();require $al_app.'language/lang_'.$locale.'/lang_main.php';
 foreach($viewers as$viewer){$board_config=array('hide_removed_author_names'=>1);if(isset($al_extra_config)){$board_config=array_merge($board_config,$al_extra_config);}$userdata=array('user_id'=>$viewer['id'],'session_logged_in'=>$viewer['logged']);$db=new ArcadeLabelAliasFixture();$keep[]=$db;
  foreach(array('Guest & "Name"','Legacy former','&#39;','')as$raw){$i=0;$commentrow=array(array('user_id'=>null,'comment_user_id'=>ANONYMOUS,'comment_username'=>$raw===''?'':phpbb_post_author_key($raw)));$snapshot=serialize(array($commentrow,$userdata,$board_config));eval($comment_binding);
   $expected=$raw===''?$lang['Guest']:($viewer['mask']&&$raw==='Legacy former'?$lang['Former_member']:$raw);
   al_check(html_entity_decode($poster,ENT_QUOTES,'UTF-8')===$expected,'Genuine guests and former authors retain current anonymization');
   al_check(strpos($poster,'<')===false&&strpos($poster,'>')===false,'Guest/former labels cannot inject markup or account links');
   al_check(serialize(array($commentrow,$userdata,$board_config))===$snapshot,'Rendering cannot rewrite historical bylines or policy');$cases++;
  }
 }
}
restore_error_handler();echo 'Arcade player labels: ',$cases,' cases, ',$al_checks," assertions PASS; fifteen actual controller bindings, canonical/historical keys, literal entities, safe profile targets, raw captions, hidden players and guest/former-author policy; synthetic reads only.\n";
