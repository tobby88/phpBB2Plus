<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_submission.php';
require_once dirname(__FILE__).'/functions_jr_admin.php';

function phpbb_link_admin_id($value)
{
	if(!(is_int($value)||is_string($value))||preg_match('/^[1-9][0-9]{0,7}$/D',(string)$value)!==1||(int)$value>16777215){phpbb_link_fail('Link_target_changed');}
	return(int)$value;
}

function phpbb_link_admin_actor($scope,$viewer)
{
	$id=phpbb_link_admin_id(isset($viewer['user_id'])?$viewer['user_id']:null);
	if($id>8388607||empty($viewer['session_logged_in'])||empty($viewer['session_admin'])||!isset($viewer['session_id'])||!is_string($viewer['session_id'])||$viewer['session_id']===''||strlen($viewer['session_id'])>32){phpbb_link_fail('Not_Authorised');}
	$sid=$scope->escape($viewer['session_id']);
	$users=$scope->rows('SELECT u.user_id,u.user_level FROM '.USERS_TABLE.' u JOIN '.SESSIONS_TABLE.' s ON s.session_user_id=u.user_id AND s.session_logged_in=1 AND s.session_admin=1'
		.' WHERE u.user_id='.$id." AND u.user_active<>0 AND s.session_id='".$sid."' AND HEX(s.session_id)=HEX('".$sid."') LOCK IN SHARE MODE");
	if(count($users)!==1){phpbb_link_fail('Not_Authorised');}
	if((int)$users[0]['user_level']===ADMIN){return$id;}
	$grants=$scope->rows('SELECT user_jr_admin FROM '.JR_ADMIN_TABLE.' WHERE user_id='.$id.' LOCK IN SHARE MODE');
	$routes=jr_admin_authorization_routes();global$phpEx;
	if(count($grants)!==1||$routes===false){phpbb_link_fail('Not_Authorised');}
	// Preserve the established ACP file-level delegation: either registered
	// Link menu grant authorizes this file; unrelated modules never do.
	foreach(explode(EXPLODE_SEPERATOR_CHAR,$grants[0]['user_jr_admin'])as$hash){if(isset($routes[$hash])&&jr_admin_route_matches_file($routes[$hash],'admin_links.'.$phpEx)){return$id;}}
	phpbb_link_fail('Not_Authorised');
}

function phpbb_link_admin_mutate($database,$post,$viewer)
{
	$method=isset($_SERVER['REQUEST_METHOD'])&&is_string($_SERVER['REQUEST_METHOD'])?strtoupper($_SERVER['REQUEST_METHOD']):'';
	if($method!=='POST'||!is_array($post)||!is_array($viewer)||!isset($post['sid'],$viewer['session_id'])||!is_string($post['sid'])||!is_string($viewer['session_id'])
		||!hash_equals($viewer['session_id'],phpbb_request_raw_value($post['sid']))||!isset($post['mode'],$post['action'])||$post['mode']!=='update'||!in_array($post['action'],array('add','modify','delete'),true)){phpbb_link_fail('Not_Authorised');}
	$action=$post['action'];$id=$action==='add'?null:phpbb_link_admin_id(isset($post['link_id'])?$post['link_id']:null);
	$input=array();
	if($action!=='delete'){
		$input=phpbb_link_submission_input($post);$active=isset($post['link_active'])?$post['link_active']:'0';
		if(!(is_int($active)||is_string($active))||!in_array((string)$active,array('0','1'),true)){phpbb_link_fail('Link_incomplete');}
		$input['link_active']=(int)$active;
	}
	$scope=new PhpbbLinkScope($database,array(LINKS_TABLE,LINK_CATEGORIES_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE));
	try{
		$actor=phpbb_link_admin_actor($scope,$viewer);
		if($action!=='add'){
			$rows=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE link_id='.$id.' FOR UPDATE');
			if(count($rows)!==1){phpbb_link_fail('Link_target_changed');}$expected=$rows[0];
		}
		if($action==='delete'){
			$scope->query('DELETE FROM '.LINKS_TABLE.' WHERE link_id='.$id);
			if($scope->rows('SELECT link_id FROM '.LINKS_TABLE.' WHERE link_id='.$id.' FOR UPDATE')){phpbb_link_fail();}
		}else{
			$categories=$scope->rows('SELECT cat_id FROM '.LINK_CATEGORIES_TABLE.' WHERE cat_id='.$input['link_category'].' LOCK IN SHARE MODE');
			if(count($categories)!==1){phpbb_link_fail('Link_category_not_exist');}
			$values=array();foreach(array('link_title','link_desc','link_url','link_logo_src')as$key){$values[$key]="'".$scope->escape($input[$key])."'";}
			if($action==='add'){
				$now=time();global$user_ip;$ip=is_string($user_ip)?$user_ip:'';
				if(!is_int($now)||$now<1||$now>2147483647||preg_match('/^[a-f0-9]{8}$/iD',$ip)!==1){phpbb_link_fail();}
				$scope->query('INSERT INTO '.LINKS_TABLE.' (link_title,link_desc,link_category,link_url,link_logo_src,link_active,link_joined,user_id,user_ip) VALUES ('
					.$values['link_title'].','.$values['link_desc'].','.$input['link_category'].','.$values['link_url'].','.$values['link_logo_src'].','.$input['link_active'].','.$now.','.$actor.",'".$scope->escape($ip)."')");
				$id=(int)$scope->connection->sql_nextid();if($id<1){phpbb_link_fail();}
				$expected=array('link_id'=>$id,'link_joined'=>$now,'user_id'=>$actor,'user_ip'=>$ip,'link_hits'=>0,'last_user_ip'=>'');
			}else{
				$scope->query('UPDATE '.LINKS_TABLE.' SET link_title='.$values['link_title'].',link_desc='.$values['link_desc'].',link_url='.$values['link_url'].',link_logo_src='.$values['link_logo_src'].',link_category='.$input['link_category'].',link_active='.$input['link_active'].' WHERE link_id='.$id);
			}
			foreach($input as$key=>$value){$expected[$key]=$value;}
			$stored=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE link_id='.$id.' FOR UPDATE');
			if(count($stored)!==1){phpbb_link_fail();}
			foreach($expected as$key=>$value){if(!is_string($key)){continue;}if(!array_key_exists($key,$stored[0])||$value===null&&$stored[0][$key]!==null||$value!==null&&(string)$stored[0][$key]!==(string)$value){phpbb_link_fail();}}
		}
		$scope->commit();return array('action'=>$action,'link_id'=>$id);
	}finally{$scope->release();}
}
