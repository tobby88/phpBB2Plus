<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/php_compat.php';

class PhpbbLinkException extends RuntimeException{}
function phpbb_link_fail($key='Link_storage_failed'){throw new PhpbbLinkException($key);}

function phpbb_link_text_input($value,$length)
{
	if(!is_string($value)){phpbb_link_fail('Link_incomplete');}
	$value=trim(phpbb_request_raw_value($value));
	if(preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){phpbb_link_fail('Link_incomplete');}
	if(preg_match('/^.{0,'.(int)$length.'}/us',$value,$match)!==1){phpbb_link_fail('Link_incomplete');}
	return $match[0];
}

function phpbb_link_submission_input($post)
{
	if(!is_array($post)){phpbb_link_fail('Link_incomplete');}
	$out=array();
	foreach(array('link_title'=>100,'link_desc'=>255)as$key=>$limit){
		$out[$key]=phpbb_link_text_input(isset($post[$key])?$post[$key]:null,$limit);
		if($out[$key]===''){phpbb_link_fail('Link_incomplete');}
	}
	$category=isset($post['link_category'])?$post['link_category']:null;
	if(!(is_int($category)||is_string($category))||preg_match('/^[1-9][0-9]{0,7}$/D',(string)$category)!==1||(int)$category>16777215){phpbb_link_fail('Link_incomplete');}
	$out['link_category']=(int)$category;
	foreach(array('link_url'=>100,'link_logo_src'=>120)as$key=>$limit){
		$value=isset($post[$key])?$post[$key]:'';
		if(!is_string($value)){phpbb_link_fail('Link_incomplete');}
		$value=phpbb_request_raw_value($value);
		$url=$value===''&&$key==='link_logo_src'?'':phpbb_normalize_external_url($value);
		if($url===false||strlen($url)>$limit||preg_match('//u',$url)!==1){phpbb_link_fail('Link_incomplete');}
		$out[$key]=$url;
	}
	return $out;
}

// Own all persistence on a fresh connection. The shared writer mutex also
// coordinates optional PM publication with ordinary mailbox/quota writers.
class PhpbbLinkScope
{
	var $connection=null;var $transactional=false;
	function __construct($database,$tables)
	{
		if(!is_object($database)||!method_exists($database,'sql_dedicated_connection')||!isset($database->dbname)){phpbb_link_fail();}
		try{$connection=$database->sql_dedicated_connection();}catch(Exception$error){phpbb_link_fail();}catch(Error$error){phpbb_link_fail();}
		if(!is_object($connection)||empty($connection->db_connect_id)||$connection===$database
			||(isset($database->db_connect_id)&&$connection->db_connect_id===$database->db_connect_id)){phpbb_link_fail();}
		$this->connection=$connection;
		try{
			$state=$this->rows('SELECT @@SESSION.autocommit AS link_autocommit');
			if(count($state)!==1||(int)$state[0]['link_autocommit']!==1){$this->connection=null;phpbb_link_fail();}
			try{$ready=$connection->sql_query('SET TRANSACTION READ WRITE');}catch(Exception$error){if((int)$error->getCode()===1568){$this->connection=null;}throw$error;}
			if(!$ready){$error=$connection->sql_error();if(isset($error['code'])&&(int)$error['code']===1568){$this->connection=null;}phpbb_link_fail();}
			$name='attachment:'.md5($database->dbname."\0".ATTACHMENTS_TABLE);
			$lock=$this->rows("SELECT GET_LOCK('".$name."',10) AS acquired");
			if(count($lock)!==1||(string)$lock[0]['acquired']!=='1'){phpbb_link_fail('Attachment_storage_busy');}
			$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
			$this->control('START TRANSACTION');$this->transactional=true;
			foreach(array_unique($tables)as$table){
				if(!is_string($table)||preg_match('/^[A-Za-z0-9_]{1,64}$/D',$table)!==1){phpbb_link_fail();}
				$r=$this->query('SELECT * FROM '.$table.' LIMIT 0');$this->connection->sql_freeresult($r);$name=$this->escape($table);
				$rows=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$name."' AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$name."' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if(count($rows)!==1||$rows[0]['ENGINE']!=='InnoDB'||strtolower($rows[0]['ROW_FORMAT'])!=='dynamic'||$rows[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){phpbb_link_fail();}
			}
		}catch(Exception$error){$this->release();if($error instanceof PhpbbLinkException){throw$error;}phpbb_link_fail();}
		catch(Error$error){$this->release();phpbb_link_fail();}
		register_shutdown_function(array($this,'release'));
	}
	function escape($value){return $this->connection->sql_escape((string)$value);}
	private function control($sql){$r=$this->connection->sql_query($sql);if(!$r){phpbb_link_fail();}return $r;}
	function query($sql)
	{
		if(!is_string($sql)||preg_match('/^SELECT /',$sql)!==1&&(!$this->transactional||preg_match('/^(INSERT INTO|UPDATE|DELETE FROM) /',$sql)!==1)){phpbb_link_fail();}
		return $this->control($sql);
	}
	function rows($sql){$r=$this->query($sql);try{return $this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}}
	function commit()
	{
		if(!$this->transactional){phpbb_link_fail();}
		try{$this->control('COMMIT');}catch(Exception$error){phpbb_link_fail('Link_save_unconfirmed');}catch(Error$error){phpbb_link_fail('Link_save_unconfirmed');}
		$this->transactional=false;
	}
	function release()
	{
		$connection=$this->connection;$this->connection=null;
		if($connection!==null){try{if($this->transactional){$connection->sql_query('ROLLBACK');}}catch(Exception$ignored){}catch(Error$ignored){}$this->transactional=false;try{$connection->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}
	}
}

function phpbb_link_configuration($scope)
{
	$config=array('lock_submit_site'=>0,'allow_no_logo'=>1,'email_notify'=>0,'pm_notify'=>0);$seen=array();
	foreach($scope->rows('SELECT config_name,config_value FROM '.LINK_CONFIG_TABLE." WHERE config_name IN ('lock_submit_site','allow_no_logo','email_notify','pm_notify') LOCK IN SHARE MODE")as$row){
		$key=$row['config_name'];if(!array_key_exists($key,$config)||isset($seen[$key])||!in_array((string)$row['config_value'],array('0','1'),true)){phpbb_link_fail();}
		$config[$key]=(int)$row['config_value'];$seen[$key]=true;
	}
	return $config;
}

function phpbb_submit_link($database,$post,$viewer)
{
	$method=isset($_SERVER['REQUEST_METHOD'])&&is_string($_SERVER['REQUEST_METHOD'])?strtoupper($_SERVER['REQUEST_METHOD']):'';
	$id=is_array($viewer)&&isset($viewer['user_id'])?$viewer['user_id']:null;
	$sid=is_array($viewer)&&isset($viewer['session_id'])?$viewer['session_id']:null;
	if($method!=='POST'||!(is_int($id)||is_string($id))||preg_match('/^[1-9][0-9]{0,7}$/D',(string)$id)!==1||(int)$id>8388607
		||empty($viewer['session_logged_in'])||!is_string($sid)||$sid===''||strlen($sid)>32||!is_array($post)||!isset($post['sid'])
		||!is_string($post['sid'])||!hash_equals($sid,phpbb_request_raw_value($post['sid']))){phpbb_link_fail('Not_Authorised');}
	$input=phpbb_link_submission_input($post);$scope=new PhpbbLinkScope($database,array(LINKS_TABLE,LINK_CATEGORIES_TABLE,LINK_CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE));
	try{
		$sid_sql=$scope->escape($sid);
		$users=$scope->rows('SELECT u.user_id,u.user_level FROM '.USERS_TABLE.' u JOIN '.SESSIONS_TABLE.' s ON s.session_user_id=u.user_id AND s.session_logged_in=1'
			. " WHERE u.user_id=".(int)$id." AND u.user_active<>0 AND s.session_id='".$sid_sql."' AND HEX(s.session_id)=HEX('".$sid_sql."') LOCK IN SHARE MODE");
		if(count($users)!==1){phpbb_link_fail('Not_Authorised');}$admin=(int)$users[0]['user_level']===ADMIN;
		$config=phpbb_link_configuration($scope);
		if($config['lock_submit_site']&&!$admin){phpbb_link_fail('Link_lock_submit_site');}
		if(!$config['allow_no_logo']&&$input['link_logo_src']===''){phpbb_link_fail('Link_incomplete');}
		$category=$scope->rows('SELECT cat_id FROM '.LINK_CATEGORIES_TABLE.' WHERE cat_id='.$input['link_category'].' LOCK IN SHARE MODE');if(count($category)!==1){phpbb_link_fail('Link_incomplete');}
		$now=time();if(!is_int($now)||$now<1||$now>2147483647){phpbb_link_fail();}
		$latest=$scope->rows('SELECT MAX(link_joined) AS stamp FROM '.LINKS_TABLE.' WHERE user_id='.(int)$id.' FOR UPDATE');
		if(count($latest)!==1){phpbb_link_fail();}if($latest[0]['stamp']!==null&&$now-(int)$latest[0]['stamp']<=60){phpbb_link_fail('Link_intval_warning');}
		global$user_ip;$ip=is_string($user_ip)?$user_ip:'';if(preg_match('/^[a-f0-9]{8}$/iD',$ip)!==1){phpbb_link_fail();}
		$values=array();foreach(array('link_title','link_desc','link_url','link_logo_src')as$key){$values[$key]=$scope->escape($input[$key]);}
		$scope->query('INSERT INTO '.LINKS_TABLE.' (link_title,link_desc,link_category,link_url,link_logo_src,link_joined,link_active,user_id,user_ip) VALUES ('
			."'".$values['link_title']."','".$values['link_desc']."',".$input['link_category'].",'".$values['link_url']."','".$values['link_logo_src']."',".$now.','.($admin?1:0).','.(int)$id.",'".$scope->escape($ip)."')");
		$link=(int)$scope->connection->sql_nextid();if($link<1){phpbb_link_fail();}
		$stored=$scope->rows('SELECT link_title,link_desc,link_category,link_url,link_logo_src,link_joined,link_active,user_id,user_ip FROM '.LINKS_TABLE.' WHERE link_id='.$link.' LOCK IN SHARE MODE');
		if(count($stored)!==1){phpbb_link_fail();}
		foreach($input as$key=>$value){if((string)$stored[0][$key]!== (string)$value){phpbb_link_fail();}}
		if((int)$stored[0]['user_id']!==(int)$id||(int)$stored[0]['link_active']!==($admin?1:0)||(int)$stored[0]['link_joined']!==$now||$stored[0]['user_ip']!==$ip){phpbb_link_fail();}
		$scope->commit();return array('link_id'=>$link,'user_id'=>(int)$id,'active'=>$admin,'email_notify'=>(bool)$config['email_notify'],'pm_notify'=>(bool)$config['pm_notify']);
	}finally{$scope->release();}
}
