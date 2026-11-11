<?php
// Pure file/format fixtures replace only the owned authorization boundary.
// The real boundary and the unmodified controller run against native MariaDB
// in check-ctracker-log-authority-native.php. No production bypass is added.
class CtrackerLogFixtureScope {
 function check(){} function finish(){} function release(){}
}
function ctracker_log_fixture_date_runtime($root)
{
 // Load the actual common.php date dependency without duplicating the cache
 // helpers already exercised independently by these isolated ACP fixtures.
 if(!function_exists('create_date')){
  $source=file_get_contents($root.'includes/functions.php');$a=strpos($source,'function phpbb_date_number(');$b=strpos($source,'function create_date_day(',$a);
  if($a===false||$b<=$a){throw new RuntimeException('Actual forum date runtime unavailable');}eval(substr($source,$a,$b-$a));
 }
 global $board_config;$board_config=array_replace(array('default_lang'=>'english','board_timezone'=>0,'default_dateformat'=>'Y-m-d H:i:s'),isset($board_config)&&is_array($board_config)?$board_config:array());
}
function ctracker_log_fixture_source($root)
{
 ctracker_log_fixture_date_runtime($root);
 require_once $root.'ctracker/classes/class_log_authority.php';
 $source=file_get_contents($root.'ctracker/admin/acp_module_logmanager.php');
 $require="require_once dirname(__DIR__) . '/classes/class_log_authority.php';";
 $open="ctracker_log_scope_open(in_array(\$mode, array('delete', 'delete_all'), true))";
 if(substr_count($source,$require)!==1||substr_count($source,$open)!==1||substr($source,0,5)!=='<?php'){throw new RuntimeException('Actual log controller boundary changed');}
 return substr(str_replace(array($require,$open),array('','new CtrackerLogFixtureScope()'),$source),5);
}
