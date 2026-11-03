<?php
if (PHP_SAPI !== 'cli') { exit(2); }
define('IN_PHPBB', true); $phpEx='php'; $table_prefix='fixture_';
$ip_app=dirname(dirname(__DIR__)).'/phpBB2/';
$phpbb_root_path=$ip_app;
require $ip_app.'includes/php_compat.php'; require $ip_app.'includes/constants.php';
require isset($ip_auth_path)?$ip_auth_path:$ip_app.'includes/auth.php'; require $ip_app.'includes/functions_ip_lookup.php';
require $ip_app.'attach_mod/includes/functions_includes.php';
$ip_cases=$ip_checks=0;
class IpLookupDenied extends RuntimeException {}
function message_die($level,$message) { throw new IpLookupDenied($message); }
function append_sid($value) { return $value; }
function ip_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } $GLOBALS['ip_checks']++; }
function ip_fragment($source,$start,$end) { $a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);ip_check($a!==false&&$b!==false,'Actual IP controller boundary');return substr($source,$a,$b-$a); }
function ip_language($locale) { global $lang; $lang=array(); require $GLOBALS['ip_app'].'language/lang_'.$locale.'/lang_main.php'; }
class IpLookupTemplate
{
	var $row;
	function assign_block_vars($name,$values) { ip_check($name==='userrow','Actual IP user row');$this->row=$values; }
}
function ip_controller_row($route,$raw,$id=7)
{
	global $lang,$phpEx;
	$source=file_get_contents($GLOBALS['ip_app'].$route);$begin=strpos($source,'// Get other users who');ip_check($begin!==false,'Exact IP correlation branch');
	$code=ip_fragment(substr($source,$begin),'$id = (int)$row[','$i++;');$row=array('user_id'=>$id,'username'=>$raw,'postings'=>2);$i=0;$theme=array('td_color1'=>'ffffff','td_color2'=>'eeeeee','td_class1'=>'row1','td_class2'=>'row2');$template=new IpLookupTemplate();
	$userdata=array('session_id'=>'synthetic');$post_id=4;$topic_id=5;eval($code);return $template->row;
}
function ip_controller_queries($route)
{
	$source=file_get_contents($GLOBALS['ip_app'].$route);$user_at=strpos($source,'// Get other users who');ip_check($user_at!==false,'Actual user correlation query');
	$rows=array('users'=>ip_fragment(substr($source,$user_at),'$ip_forum_filter =','if ( !($result'));
	if($route==='modcp.php') { $at=strpos($source,"case 'ip':");$tail=substr($source,$at);$at=strpos($tail,'// Get other IP');$rows['ips']=ip_fragment(substr($tail,$at),'$ip_forum_filter =','if ( !($result'); }
	return $rows;
}
error_reporting(E_ALL);set_error_handler(function($severity,$message){if(error_reporting()&$severity){throw new RuntimeException($message);}});
