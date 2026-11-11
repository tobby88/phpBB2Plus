<?php
if(PHP_SAPI!=='cli'){exit(2);}define('IN_PHPBB',true);error_reporting(E_ALL);
set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
$as_app=dirname(dirname(__DIR__)).'/phpBB2/';$phpbb_root_path=$as_app;$phpEx='php';$table_prefix='fixture_';
require $as_app.'includes/php_compat.php';require $as_app.'includes/constants.php';
$as_cases=$as_checks=0;
function as_check($ok,$message){if(!$ok){throw new RuntimeException($message);}$GLOBALS['as_checks']++;}
function as_function($file,$name){if($name==='create_date'){foreach(array('phpbb_date_number','phpbb_date_integer_sum','phpbb_date_timestamp','phpbb_date_text')as$dependency){if(!function_exists($dependency)){as_function($file,$dependency);}}}$source=file_get_contents($file);as_check(preg_match('/^function '.preg_quote($name,'/').'\(.*?^\}/ms',$source,$part)===1,'Actual complete function '.$name);eval($part[0]);}
foreach(array('phpbb_profile_text','phpbb_stored_text','phpbb_rtrim','phpbb_ltrim','phpbb_serialized_data_read','phpbb_serialized_data_write','phpbb_data_cache_read','phpbb_data_cache_write')as$name){as_function($as_app.'includes/functions.php',$name);}
as_function($as_app.'includes/sessions.php','append_sid');
class ArcadeSummaryExit extends RuntimeException {}
function message_die($level,$message){throw new ArcadeSummaryExit($message);}
$board_config=array('default_lang'=>'english','sitename'=>'Synthetic board');$SID='';$lang=array();require $as_app.'language/lang_english/lang_main.php';require $as_app.'includes/functions_arcade.php';
function as_language($locale){global $lang,$board_config,$phpEx;$lang=array();require $GLOBALS['as_app'].'language/lang_'.$locale.'/lang_main.php';require $GLOBALS['as_app'].'language/lang_'.$locale.'/lang_extend_arcade.php';}
$as_root=null;$as_original=null;$as_created=false;
function as_open()
{
	global $as_root,$as_original,$as_created,$phpbb_root_path,$arcade;
	$as_original=getcwd();$as_root=sys_get_temp_dir().'/codex_arcade_summary_'.bin2hex(phpbb_random_bytes(8));
	as_check(!file_exists($as_root)&&!is_link($as_root)&&mkdir($as_root,0700),'Exclusive own cache root');$as_created=true;as_check(mkdir($as_root.'/cache',0700)&&chdir($as_root),'Owned cache directory and working directory');$phpbb_root_path=$as_root.'/';
	$arcade=new arcade();$arcade->arcade_config=array('use_cache'=>1,'highscore_cache'=>300,'at_highscore_cache'=>300);
	as_check(file_put_contents($as_root.'/cache/unrelated.keep','Owned cache canary')===18,'Own unrelated-cache canary');
}
function as_clear(){global $arcade;$arcade->clear_cache('best_player','./');$arcade->clear_cache('best_at_player','./');}
function as_payload($key){global $as_root;return phpbb_data_cache_read($as_root.'/cache/arcade_'.$key.'.cache');}
function as_close()
{
	global $as_root,$as_original,$as_created;
	if(!$as_created){return;}
	as_check(!is_link($as_root)&&realpath(dirname($as_root))===realpath(sys_get_temp_dir())&&preg_match('/^codex_arcade_summary_[a-f0-9]{16}$/D',basename($as_root)),'Exact owned cache cleanup root');as_check(chdir($as_original),'Restore original working directory');
	foreach(array('arcade_best_player.cache','arcade_best_at_player.cache','unrelated.keep')as$name){$file=$as_root.'/cache/'.$name;if(file_exists($file)){as_check(!is_link($file)&&is_file($file)&&unlink($file),'Remove only own cache file');}}
	as_check(rmdir($as_root.'/cache')&&rmdir($as_root),'Remove only own empty directories');
}
function as_links($html,$id,$sid,$mode){
	preg_match_all('/href="([^"]+)"/',$html,$links);as_check(count($links[1])===2,'Actual two summary links');$values=array();foreach($links[1]as$url){parse_str(parse_url(html_entity_decode($url,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);as_check(isset($query['sid'])&&$query['sid']===$sid,'Current request SID, never cached visitor SID');$values[]=$query;}
	as_check($values[0]['u']===(string)$id&&$values[0]['mode']==='viewprofile'&&$values[1]['user_id']===(string)$id&&$values[1]['id']===(string)$mode,'Correct profile and leaderboard identities');
}
