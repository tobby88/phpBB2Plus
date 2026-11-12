<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/functions_link_settings.php';

function phpbb_link_category_title($value)
{
	if(!is_string($value)){phpbb_link_fail('Link_category_title_required');}$value=trim(phpbb_request_raw_value($value));
	if(preg_match('/^.{1,100}$/usD',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)){phpbb_link_fail('Link_category_title_required');}return$value;
}

function phpbb_link_categories_save($database,$post,$viewer)
{
	phpbb_link_admin_request($post,$viewer);$id=null;$target=null;$title=null;
	if(array_key_exists('cat_action',$post)){
		if(!is_string($post['cat_action'])||preg_match('/^move_(up|down):([1-9][0-9]{0,7})$/D',$post['cat_action'],$move)!==1){phpbb_link_fail('Link_target_changed');}
		$action='move';$id=phpbb_link_admin_id($move[2]);$direction=$move[1]==='up'?-1:1;
	}else{
		$action=isset($post['mode'])?$post['mode']:null;if(!in_array($action,array('new','edit','delete'),true)){phpbb_link_fail('Not_Authorised');}
		if($action!=='new'){$id=phpbb_link_admin_id(isset($post['cat_id'])?$post['cat_id']:null);}
		if($action==='new'||$action==='edit'){$title=phpbb_link_category_title(isset($post['cat_title'])?$post['cat_title']:null);}
		if($action==='delete'){
			$value=isset($post['target'])?$post['target']:null;$target=$value==='0'||$value===0?0:phpbb_link_admin_id($value);if($target===$id){phpbb_link_fail('Link_category_target_invalid');}
		}
	}
	$scope=new PhpbbLinkScope($database,array(LINK_CATEGORIES_TABLE,LINKS_TABLE,USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE));
	try{
		global$phpEx;phpbb_link_admin_actor($scope,$viewer,'admin_links_cat.'.$phpEx);
		$rows=$scope->rows('SELECT cat_id,cat_title,cat_order FROM '.LINK_CATEGORIES_TABLE.' ORDER BY cat_order,cat_id FOR UPDATE');$categories=array();$order=array();
		foreach($rows as$row){$key=(int)$row['cat_id'];if($key<1||isset($categories[$key])){phpbb_link_fail();}$categories[$key]=array('cat_id'=>$key,'cat_title'=>$row['cat_title'],'cat_order'=>(string)$row['cat_order']);$order[]=$key;}
		if($id!==null&&!isset($categories[$id])){phpbb_link_fail('Link_category_not_exist');}
		if($action==='delete'&&$target!==0&&!isset($categories[$target])){phpbb_link_fail('Link_category_target_invalid');}
		if($action==='new'){
			$scope->query('INSERT INTO '.LINK_CATEGORIES_TABLE." (cat_title,cat_order) VALUES ('".$scope->escape($title)."',0)");$id=(int)$scope->connection->sql_nextid();if($id<1||isset($categories[$id])){phpbb_link_fail();}$categories[$id]=array('cat_id'=>$id,'cat_title'=>$title,'cat_order'=>'0');$order[]=$id;
		}elseif($action==='edit'){$categories[$id]['cat_title']=$title;$scope->query('UPDATE '.LINK_CATEGORIES_TABLE." SET cat_title='".$scope->escape($title)."' WHERE cat_id=".$id);}
		elseif($action==='move'){
			$index=array_search($id,$order,true);$other=$index+$direction;if($other>=0&&$other<count($order)){$order[$index]=$order[$other];$order[$other]=$id;}
		}else{
			$selection=$target===0?'link_category='.$id:'link_category IN ('.$id.','.$target.')';$links=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE '.$selection.' ORDER BY link_id FOR UPDATE');
			if($target===0){$scope->query('DELETE FROM '.LINKS_TABLE.' WHERE link_category='.$id);}
			else{$scope->query('UPDATE '.LINKS_TABLE.' SET link_category='.$target.' WHERE link_category='.$id);}
			$stored=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE '.$selection.' ORDER BY link_id FOR UPDATE');
			if($target===0){if($stored){phpbb_link_fail();}}
			else{if(count($stored)!==count($links)){phpbb_link_fail();}foreach($links as$index=>$link){foreach($link as$key=>$value){if(!is_string($key)){continue;}$expected=$key==='link_category'?$target:$value;if($expected===null?$stored[$index][$key]!==null:(string)$stored[$index][$key]!==(string)$expected){phpbb_link_fail();}}}}
			$scope->query('DELETE FROM '.LINK_CATEGORIES_TABLE.' WHERE cat_id='.$id);unset($categories[$id]);$order=array_values(array_filter($order,function($value)use($id){return$value!==$id;}));
		}
		// Stable position swaps avoid unsigned subtraction and assumptions about
		// legacy spacing. Normalize the entire order in the same transaction.
		if($action!=='edit'){if(count($order)>1677721){phpbb_link_fail();}$position=10;foreach($order as$key){$categories[$key]['cat_order']=(string)$position;$scope->query('UPDATE '.LINK_CATEGORIES_TABLE.' SET cat_order='.$position.' WHERE cat_id='.$key);$position+=10;}}
		$actual=$scope->rows('SELECT cat_id,cat_title,cat_order FROM '.LINK_CATEGORIES_TABLE.' ORDER BY cat_order,cat_id FOR UPDATE');if(count($actual)!==count($order)){phpbb_link_fail();}
		foreach($order as$index=>$key){foreach($categories[$key]as$field=>$value){if((string)$actual[$index][$field]!==(string)$value){phpbb_link_fail();}}}
		$scope->commit();return array('action'=>$action,'cat_id'=>$id,'target'=>$target);
	}finally{$scope->release();}
}
