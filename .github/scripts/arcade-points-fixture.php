<?php
if(PHP_SAPI!=='cli'){exit(2);}define('IN_PHPBB',true);date_default_timezone_set('UTC');error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$ap_app=isset($ap_app)?$ap_app:dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$ap_app;$phpEx='php';$table_prefix='fixture_';
require $ap_app.'includes/php_compat.php';require $ap_app.'includes/constants.php';require_once $ap_app.'includes/constants_arcade.php';require_once $ap_app.'includes/functions_author_visibility.php';
$ap_checks=$ap_cases=0;
function ap_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['ap_checks']++;}
class ArcadePointsReadFailure extends RuntimeException{}
function message_die($level,$message){throw new ArcadePointsReadFailure($message);}
class ArcadePointsTemplate{var $rows=array();function assign_block_vars($block,$values){ap_check($block==='total','Actual points template rows only');$this->rows[]=$values;}}
$ap_source=file_get_contents($ap_app.'arcade_point_scores.php');
$a=strpos($ap_source,'$monthly_points =');$b=$a===false?false:strpos($ap_source,'$year =',$a);ap_check($a!==false&&$b>$a,'Actual weights and aggregation initialization');$ap_config=substr($ap_source,$a,$b-$a);
$a=strpos($ap_source,'$monthly_ranges =');$b=$a===false?false:strpos($ap_source,'$sql = "SELECT h.highscore_user_id,',$a);ap_check($a!==false&&$b>$a,'Actual current/previous-month range construction');$ap_window=substr($ap_source,$a,$b-$a);
$a=$b;$b=$a===false?false:strpos($ap_source,'$template->assign_vars(array(',$a);ap_check($a!==false&&$b>$a,'Complete actual monthly/all-time queries, aggregation, sorting and template loop');$ap_pipeline=substr($ap_source,$a,$b-$a);
function ap_language($locale){global $lang,$board_config,$phpEx;$lang=array();$board_config['sitename']='Fixture';require $GLOBALS['ap_app'].'language/lang_'.$locale.'/lang_main.php';require $GLOBALS['ap_app'].'language/lang_'.$locale.'/lang_extend_arcade.php';}
function ap_run($year=2026,$month=1,$previous=true){
 global $db,$template,$lang,$userdata,$ap_config,$ap_window,$ap_pipeline;
 $template=new ArcadePointsTemplate();eval($ap_config);$include_previous_month=$previous;eval($ap_window);eval($ap_pipeline);return array('totals'=>$totals,'rows'=>$template->rows,'ranges'=>$monthly_ranges);
}
function ap_labels($out){$labels=array();$keys=array_keys($out['totals']);foreach($out['rows']as$index=>$row){$labels[$keys[$index]]=html_entity_decode($row['NAME'],ENT_QUOTES,'UTF-8');}return $labels;}
