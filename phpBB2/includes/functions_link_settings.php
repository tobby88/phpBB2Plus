<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_admin.php';

function phpbb_link_admin_request($post,$viewer)
{
	if(!is_array($post)||!is_array($viewer)||!isset($_SERVER['REQUEST_METHOD'])||!is_string($_SERVER['REQUEST_METHOD'])||strtoupper($_SERVER['REQUEST_METHOD'])!=='POST'
		||!isset($post['sid'],$viewer['session_id'])||!is_string($post['sid'])||!is_string($viewer['session_id'])||$viewer['session_id']===''
		||!hash_equals($viewer['session_id'],phpbb_request_raw_value($post['sid']))){phpbb_link_fail('Not_Authorised');}
}

function phpbb_link_settings_defaults()
{
	return array('width'=>'88','height'=>'31','linkspp'=>'10','display_interval'=>'6000','display_logo_num'=>'10','display_links_logo'=>'1',
		'email_notify'=>'0','pm_notify'=>'0','lock_submit_site'=>'0','allow_no_logo'=>'1','site_logo'=>'','site_url'=>'');
}

function phpbb_link_settings_decode($rows)
{
	$values=phpbb_link_settings_defaults();$names=array();$unknown=array();
	foreach($rows as$row){
		if(!isset($row['config_name'],$row['config_value'])||!is_string($row['config_name'])||!is_string($row['config_value'])){phpbb_link_fail('Link_settings_invalid');}
		$key=strtolower(rtrim($row['config_name'],' '));
		if(!array_key_exists($key,$values)){$unknown[]=array('config_name'=>$row['config_name'],'config_value'=>$row['config_value']);continue;}
		if(isset($names[$key])){phpbb_link_fail('Link_settings_ambiguous');}
		$values[$key]=$row['config_value'];$names[$key]=$row['config_name'];
	}
	return array('values'=>$values,'names'=>$names,'unknown'=>$unknown);
}

function phpbb_link_settings_load($database)
{
	$r=$database->sql_query('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value)');if(!$r){phpbb_link_fail();}
	try{$rows=$database->sql_fetchrowset($r);}finally{$database->sql_freeresult($r);}
	$decoded=phpbb_link_settings_decode($rows);return$decoded['values'];
}

function phpbb_link_setting_value($key,$value)
{
	if(!is_string($value)){phpbb_link_fail('Link_settings_invalid');}
	if(in_array($key,array('display_links_logo','email_notify','pm_notify','lock_submit_site','allow_no_logo'),true)){
		if(!in_array($value,array('0','1'),true)){phpbb_link_fail('Link_settings_invalid');}return$value;
	}
	$ranges=array('width'=>array(1,1000),'height'=>array(1,1000),'linkspp'=>array(1,100),'display_interval'=>array(250,2147483647),'display_logo_num'=>array(1,1000));
	if(isset($ranges[$key])){
		$value=trim($value);if(preg_match('/^[0-9]{1,10}$/D',$value)!==1||strlen($value)===10&&strcmp($value,'2147483647')>0||(int)$value<$ranges[$key][0]||(int)$value>$ranges[$key][1]){phpbb_link_fail('Link_settings_invalid');}return(string)(int)$value;
	}
	if($key!=='site_logo'&&$key!=='site_url'){phpbb_link_fail('Link_settings_invalid');}
	$value=trim($value);if($value===''){return'';}
	$url=phpbb_normalize_external_url($value);
	if($url===false&&$key==='site_logo'){
		if(!function_exists('phpbb_profile_asset_path')){phpbb_link_fail();}
		$url=phpbb_profile_asset_path(html_entity_decode($value,ENT_QUOTES,'UTF-8'));
		if($url===''||preg_match('/\.(?:gif|jpe?g|png|webp)$/iD',$url)!==1){phpbb_link_fail('Link_settings_invalid');}
	}
	if($url===false||strlen($url)>255||preg_match('//u',$url)!==1){phpbb_link_fail('Link_settings_invalid');}return$url;
}

function phpbb_link_settings_save($database,$post,$viewer)
{
	phpbb_link_admin_request($post,$viewer);
	$scope=new PhpbbLinkScope($database,array(LINK_CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE));
	try{
		global$phpEx;phpbb_link_admin_actor($scope,$viewer,'admin_links_config.'.$phpEx);
		$before=$scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value) FOR UPDATE');$state=phpbb_link_settings_decode($before);$expected=array();
		// Unicode SQL collations also fold accents. Such unknown names must not
		// silently collide with a newly inserted ordinary setting.
		$keys=array();foreach(array_keys($state['values'])as$key){$keys[]="'".$key."'";}
		foreach($scope->rows('SELECT config_name FROM '.LINK_CONFIG_TABLE.' WHERE config_name IN ('.implode(',',$keys).') LOCK IN SHARE MODE')as$row){if(!array_key_exists(strtolower(rtrim($row['config_name'],' ')),$state['values'])){phpbb_link_fail('Link_settings_ambiguous');}}
		foreach($state['values']as$key=>$current){$value=array_key_exists($key,$post)?phpbb_request_raw_value($post[$key]):$current;$expected[$key]=phpbb_link_setting_value($key,$value);}
		foreach($expected as$key=>$value){
			if(isset($state['names'][$key])){$scope->query('UPDATE '.LINK_CONFIG_TABLE." SET config_name='".$key."',config_value='".$scope->escape($value)."' WHERE HEX(config_name)='".strtoupper(bin2hex($state['names'][$key]))."'");}
			else{$scope->query('INSERT INTO '.LINK_CONFIG_TABLE." (config_name,config_value) VALUES ('".$key."','".$scope->escape($value)."')");}
		}
		$after=phpbb_link_settings_decode($scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value) FOR UPDATE'));
		if($after['values']!==$expected||$after['unknown']!==$state['unknown']){phpbb_link_fail();}
		foreach($expected as$key=>$value){if(!isset($after['names'][$key])||$after['names'][$key]!==$key){phpbb_link_fail();}}
		$scope->commit();return$expected;
	}finally{$scope->release();}
}
