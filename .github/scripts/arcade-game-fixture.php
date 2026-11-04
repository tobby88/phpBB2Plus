<?php
require __DIR__.'/arcade-import-fixture.php';
require_once $as_app.'includes/functions_arcade_game_storage.php';
as_function($as_app.'admin/admin_arcade_games.php','arcade_admin_post_int');
// Instrument only the delivery endpoint; execute the real notification body
// and real controller boundary without sending mail or private messages.
as_check(preg_match('/^function arcade_admin_notify_new_game\(.*?^\}/ms',file_get_contents($as_app.'admin/admin_arcade_games.php'),$ag_notify)===1,'Actual notification function');
as_check(substr_count($ag_notify[0],'ina_send_user_pm(')===1,'One delivery endpoint');
eval(str_replace('ina_send_user_pm(','ag_capture_pm(',$ag_notify[0]));
$ag_notifications=array();
function ag_capture_pm($to,$subject,$message,$from,$enabled)
{ $GLOBALS['ag_notifications'][]=array($to,$subject,$message,$from,$enabled); }
function ag_values($name='old_game')
{
 $out=array_fill_keys(phpbb_arcade_game_fields(),0);
 foreach(array('game_name'=>$name,'game_path'=>'games/','image_path'=>'images/Grüße😀.png','game_desc'=>"Raw Grüße 😀 &amp; '",
  'instructions'=>"Instructions 😀 \\ &amp; '",'win_width'=>550,'win_height'=>400,'cat_id'=>7,'game_show_score'=>1,'game_avail'=>1)as$key=>$value){$out[$key]=$value;}
 return $out;
}
function ag_controller($id,$revision,$values,$reset=array('current'=>false,'all_time'=>false))
{
 global $as_app,$db,$lang,$phpbb_root_path,$arcade,$userdata,$HTTP_POST_VARS,$phpEx;
 $post=array('sid'=>'fixture-import','save_game'=>'1','id'=>(string)$id,'game_revision'=>$revision);
 $names=array('reverse_list'=>'game_reverse_list','at_game_bonus'=>'game_at_bonus','cat_id'=>'game_cat_id','instructions'=>'game_instructions');
 foreach($values as$key=>$value){$post[isset($names[$key])?$names[$key]:$key]=(string)$value;}
 $post['reset_scores']=$reset['current']?'1':'0';$post['reset_at_scores']=$reset['all_time']?'1':'0';profile_fixture_request($post);
 $file='admin_arcade_games.php';$cat_id=7;$sort_order='ASC';$sort_mode='game_id';$start=0;
 $source=file_get_contents($as_app.'admin/admin_arcade_games.php');
 $begin=strpos($source,"if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$HTTP_POST_VARS['save_game']))");
 $end=strpos($source,'//  Generate the page',$begin);as_check($begin!==false&&$end>$begin,'Actual complete save controller boundary');
 try{eval(substr($source,$begin,$end-$begin));throw new RuntimeException('Actual save controller must produce a result');}
 catch(ArcadeSummaryExit$e){return $e->getMessage();}
}
function ag_language($locale='english')
{
 global $lang,$as_app,$phpEx;as_language($locale);require $as_app.'language/lang_'.$locale.'/lang_admin.php';require $as_app.'language/lang_'.$locale.'/lang_admin_arcade.php';
}
