<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_click.php';
require_once dirname(__FILE__).'/functions_link_settings.php';

function phpbb_link_list_scalar($post,$get,$key,$default)
{
	foreach(array($post,$get)as$input){if(is_array($input)&&array_key_exists($key,$input)){return is_string($input[$key])||is_int($input[$key])?(string)$input[$key]:null;}}return$default;
}

function phpbb_link_list_request($post,$get)
{
	$t=phpbb_link_list_scalar($post,$get,'t','index');if(!in_array($t,array('index','new','pop','sub_pages','search'),true)){$t='index';}
	$cat=phpbb_link_visit_id(phpbb_link_list_scalar($post,$get,'cat','1'));$mode=phpbb_link_list_scalar($post,$get,'mode','link_joined');
	if(!in_array($mode,array('link_hits','link_joined','link_title','link_desc'),true)){$mode='link_joined';}
	$order=phpbb_link_list_scalar($post,$get,'order','DESC')==='ASC'?'ASC':'DESC';$start=phpbb_link_list_scalar(array(),$get,'start','0');
	$start=is_string($start)&&preg_match('/^[0-9]{1,10}$/D',$start)===1&&(strlen($start)<10||strcmp($start,'2147483647')<=0)?(int)$start:0;
	try{$query=phpbb_link_text_input(phpbb_link_list_scalar($post,$get,'search_keywords',''),100);}catch(PhpbbLinkException$error){$query='';}
	return array('t'=>$t,'cat'=>$cat===false?0:$cat,'mode'=>$mode,'order'=>$order,'start'=>$start,'query'=>$query);
}

function phpbb_link_list_like($scope,$value)
{
	// An explicit non-backslash escape works in all supported SQL modes.
	$value=str_replace(array('!','%','_'),array('!!','!%','!_'),$value);return"'".$scope->escape('%'.$value.'%')."' ESCAPE '!'";
}

function phpbb_link_public_config($rows)
{
	$decoded=phpbb_link_settings_decode($rows);$config=$decoded['values'];$defaults=phpbb_link_settings_defaults();
	foreach($config as$key=>$value){try{$config[$key]=phpbb_link_setting_value($key,$value);}catch(PhpbbLinkException$error){$config[$key]=$key==='lock_submit_site'?'1':$defaults[$key];}}
	return$config;
}

function phpbb_link_listing($database,$request)
{
	if(!is_array($request)||!isset($request['t'],$request['cat'],$request['mode'],$request['order'],$request['start'],$request['query'])
		||!in_array($request['t'],array('index','new','pop','sub_pages','search'),true)||!is_int($request['cat'])||$request['cat']<0||$request['cat']>16777215
		||!in_array($request['mode'],array('link_hits','link_joined','link_title','link_desc'),true)||!in_array($request['order'],array('ASC','DESC'),true)
		||!is_int($request['start'])||$request['start']<0||$request['start']>2147483647||!is_string($request['query'])||preg_match('/^.{0,100}$/usD',$request['query'])!==1){phpbb_link_visit_fail();}
	$scope=new PhpbbLinkVisitScope($database,false,true);
	try{
		$config=phpbb_link_public_config($scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE.' ORDER BY HEX(config_name),HEX(config_value)'));
		$categories=$scope->rows('SELECT c.cat_id,c.cat_title,c.cat_order,COUNT(l.link_id) link_count FROM '.LINK_CATEGORIES_TABLE.' c LEFT JOIN '.LINKS_TABLE.' l ON l.link_category=c.cat_id AND l.link_active=1 GROUP BY c.cat_id,c.cat_title,c.cat_order ORDER BY c.cat_order,c.cat_id');
		$out=array('config'=>$config,'categories'=>$categories,'category'=>null,'rows'=>array(),'total'=>0,'start'=>0,'size'=>(int)$config['linkspp']);
		foreach($categories as$row){if((int)$row['cat_id']===$request['cat']){$out['category']=$row;}}
		if($request['t']==='sub_pages'&&$out['category']===null){phpbb_link_fail('Link_category_not_exist');}
		if($request['t']==='index'||$request['t']==='search'&&$request['query']===''){return$out;}
		$where='l.link_active=1';$order='l.link_joined DESC,l.link_id DESC';
		if($request['t']==='sub_pages'){$where.=' AND l.link_category='.$request['cat'];$order='l.'.$request['mode'].' '.$request['order'].',l.link_id DESC';}
		elseif($request['t']==='pop'){$order='l.link_hits DESC,l.link_id DESC';}
		elseif($request['t']==='search'){$like=phpbb_link_list_like($scope,$request['query']);$where.=' AND (l.link_title LIKE '.$like.' OR l.link_desc LIKE '.$like.')';$order='l.link_id DESC';}
		$count=$scope->rows('SELECT COUNT(*) total FROM '.LINKS_TABLE.' l WHERE '.$where);if(count($count)!==1||!isset($count[0]['total'])||preg_match('/^[0-9]{1,10}$/D',(string)$count[0]['total'])!==1||strlen((string)$count[0]['total'])===10&&strcmp((string)$count[0]['total'],'2147483647')>0){phpbb_link_visit_fail();}
		$out['total']=(int)$count[0]['total'];$last=$out['total']?(int)floor(($out['total']-1)/$out['size'])*$out['size']:0;$out['start']=(int)floor(min($request['start'],$last)/$out['size'])*$out['size'];
		if($out['total']){$out['rows']=$scope->rows('SELECT l.*,u.username FROM '.LINKS_TABLE.' l LEFT JOIN '.USERS_TABLE.' u ON u.user_id=l.user_id WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$out['start'].','.$out['size']);}
		return$out;
	}finally{$scope->release();}
}
