<?php
// Actual complete monthly renderer and author policy; no board bootstrap.
if(PHP_SAPI!=='cli'){exit(2);}define('IN_PHPBB',true);error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$am_app=isset($am_app)?$am_app:dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$am_app;$phpEx='php';$table_prefix='fixture_';
require $am_app.'includes/php_compat.php';require $am_app.'includes/constants.php';require_once $am_app.'includes/functions_author_visibility.php';
$am_checks=$am_cases=0;
function am_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['am_checks']++;}
$source=file_get_contents($am_app.'includes/functions.php');foreach(array('phpbb_profile_text','phpbb_rtrim','phpbb_ltrim')as$name){am_check(preg_match('/^function '.$name.'\(.*?^\}/ms',$source,$m)===1,'Actual common renderer helper');eval($m[0]);}
$source=file_get_contents($am_app.'includes/sessions.php');am_check(preg_match('/^function append_sid\(.*?^\}/ms',$source,$m)===1,'Actual session URL helper');eval($m[0]);
$board_config=array('default_lang'=>'english','sitename'=>'Fixture');$lang=array();require $am_app.'language/lang_english/lang_main.php';require $am_app.'includes/functions_arcade.php';$arcade=new arcade();$arcade->arcade_config=array('games_path'=>'','games_default_img'=>'','games_image_width'=>50,'games_image_height'=>50);$SID='sid=monthly_fixture';
$source=file_get_contents($am_app.'arcade_highscore.php');$a=strpos($source,'$sql = "SELECT h.highscore_game,');$b=$a===false?false:strpos($source,'if( !$result =',$a);am_check($a!==false&&$b>$a,'Actual complete monthly JOIN and period query');$am_query=substr($source,$a,$b-$a);
$a=strpos($source,'while($row = $db->sql_fetchrow($result))');$b=$a===false?false:strpos($source,"if(!empty(\$HTTP_POST_VARS['cat_id'])",$a);am_check($a!==false&&$b>$a,'Complete real monthly permission/render/padding/empty-result branches');$am_render=substr($source,$a,$b-$a);
function am_language($locale){global $lang,$board_config,$phpEx;$lang=array();$board_config['sitename']='Fixture';require $GLOBALS['am_app'].'language/lang_'.$locale.'/lang_main.php';require $GLOBALS['am_app'].'language/lang_'.$locale.'/lang_extend_arcade.php';}
function am_row($id,$name,$score='0.0000'){
 return array('highscore_game'=>'fixture_game','highscore_user_id'=>$id,'highscore_player'=>$name,'highscore_score'=>$score,'game_path'=>'','image_path'=>'','game_desc'=>'Raw &amp; <game>','game_id'=>9,'win_width'=>640,'win_height'=>480,'allow_guest'=>1,'level_required'=>0,'rank_required'=>0,'group_required'=>0);
}
function am_render($result){
 global $db,$am_render,$arcade,$userdata,$lang,$phpEx;
 $group_list=array(0);$level_required=isset($userdata['user_level'])?$userdata['user_level']:0;$rank_required=0;$i='0';$bgcounter=0;$highscore_temp='';eval($am_render);return $highscore_temp;
}
function am_name($html){am_check(preg_match_all('#<b><span class="gen">(.*?) : ([^<]*)</span></b>#s',$html,$m)!==false,'Actual player/score cells');return array($m[1],$m[2]);}
