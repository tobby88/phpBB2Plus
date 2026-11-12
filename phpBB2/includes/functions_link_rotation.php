<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_listing.php';

function phpbb_link_rotation_empty()
{
	$config=phpbb_link_settings_defaults();return array('logos'=>array(),'width'=>(int)$config['width'],'height'=>(int)$config['height'],'interval'=>(int)$config['display_interval'],'visible'=>(int)$config['display_logo_num']);
}

function phpbb_link_rotation_item($row,$extension)
{
	if(!is_array($row)||!isset($row['link_id'],$row['link_logo_src'])||!is_string($extension)||preg_match('/^[a-z0-9]{1,8}$/iD',$extension)!==1){return false;}
	$id=phpbb_link_visit_id($row['link_id']);$image=phpbb_normalize_external_url($row['link_logo_src']);
	if($id===false||$image===false||preg_match('//u',$image)!==1){return false;}
	$title=isset($row['link_title'])&&is_string($row['link_title'])&&preg_match('//u',$row['link_title'])===1?html_entity_decode($row['link_title'],ENT_QUOTES,'UTF-8'):'';
	return array('url'=>append_sid('links.'.$extension.'?action=go&link_id='.$id,false),'image'=>$image,'title'=>$title);
}

function phpbb_link_rotation($database,$extension)
{
	$scope=new PhpbbLinkVisitScope($database,false,true);
	try{
		$config=phpbb_link_public_config($scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value)'));
		$out=array('logos'=>array(),'width'=>(int)$config['width'],'height'=>(int)$config['height'],'interval'=>(int)$config['display_interval'],'visible'=>(int)$config['display_logo_num']);
		$r=$scope->query('SELECT link_id,link_title,link_logo_src FROM '.LINKS_TABLE.' WHERE link_active=1 ORDER BY link_hits DESC,link_id DESC');
		try{while($row=$scope->connection->sql_fetchrow($r)){$item=phpbb_link_rotation_item($row,$extension);if($item!==false){$out['logos'][]=$item;}}}finally{$scope->connection->sql_freeresult($r);}
		return$out;
	}finally{$scope->release();}
}

function phpbb_link_index_logo($database,$enabled)
{
	$config=phpbb_link_settings_defaults();$scope=null;
	if($enabled){try{$scope=new PhpbbLinkVisitScope($database,false,true);$config=phpbb_link_public_config($scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value)'));}catch(Exception$error){}catch(Error$error){}finally{if($scope!==null){$scope->release();}}}
	$logo=$config['site_logo']===''?'images/links/web_logo88a.gif':$config['site_logo'];return array('logo'=>htmlspecialchars($logo,ENT_QUOTES,'UTF-8',false),'width'=>(int)$config['width'],'height'=>(int)$config['height']);
}
