<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/php_compat.php';

class PhpbbLinkVisitException extends RuntimeException{}
function phpbb_link_visit_fail(){throw new PhpbbLinkVisitException('Link visit unavailable');}

function phpbb_link_visit_id($value)
{
	if(!(is_int($value)||is_string($value))||preg_match('/^[1-9][0-9]{0,7}$/D',(string)$value)!==1||(int)$value>16777215){return false;}return(int)$value;
}

// Keep unsigned INT counts exact on 32-bit PHP too; never overflow or wrap.
function phpbb_link_next_hit($value)
{
	$value=(string)$value;if(preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$value)!==1||strlen($value)===10&&strcmp($value,'4294967295')>0){phpbb_link_visit_fail();}
	if($value==='4294967295'){return$value;}
	for($i=strlen($value)-1;$i>=0;$i--){if($value[$i]!=='9'){$value[$i]=chr(ord($value[$i])+1);return$value;}$value[$i]='0';}return'1'.$value;
}

// This optional statistic takes only its Link row lock, not the forum-wide
// attachment/PM writer mutex. A failed count is followed by a new read owner.
class PhpbbLinkVisitScope
{
	var$connection=null;var$transactional=false;var$writable=false;
	function __construct($database,$write=false)
	{
		if(!is_object($database)||empty($database->db_connect_id)||!isset($database->dbname)||!is_string($database->dbname)||$database->dbname===''||!method_exists($database,'sql_dedicated_connection')){phpbb_link_visit_fail();}
		try{$candidate=$database->sql_dedicated_connection();}catch(Exception$error){phpbb_link_visit_fail();}catch(Error$error){phpbb_link_visit_fail();}
		if(!is_object($candidate)||empty($candidate->db_connect_id)||$candidate===$database||$candidate->db_connect_id===$database->db_connect_id){phpbb_link_visit_fail();}
		// Until the idle/same-schema guard succeeds, a factory may have lent us
		// somebody else's pending work. Never close, commit or roll it back.
		try{
			$r=$candidate->sql_query('SELECT DATABASE() link_schema,@@SESSION.autocommit link_autocommit');if(!$r){phpbb_link_visit_fail();}
			try{$state=$candidate->sql_fetchrowset($r);}finally{$candidate->sql_freeresult($r);}
			if(count($state)!==1||$state[0]['link_schema']!==$database->dbname||(string)$state[0]['link_autocommit']!=='1'){phpbb_link_visit_fail();}
			if(!$candidate->sql_query($write?'SET TRANSACTION READ WRITE':'SET TRANSACTION READ ONLY')){phpbb_link_visit_fail();}
		}catch(Exception$error){phpbb_link_visit_fail();}catch(Error$error){phpbb_link_visit_fail();}
		$this->connection=$candidate;$this->writable=(bool)$write;
		try{
			$this->control('SET SESSION lock_wait_timeout=1');
			$this->control('SET SESSION innodb_lock_wait_timeout=1');
			$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
			if($write){$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");}
			$this->control($write?'START TRANSACTION':'START TRANSACTION READ ONLY');$this->transactional=true;
			if(!is_string(LINKS_TABLE)||preg_match('/^[A-Za-z0-9_]{1,64}$/D',LINKS_TABLE)!==1){phpbb_link_visit_fail();}
			if($write){
				$r=$this->query('SELECT * FROM '.LINKS_TABLE.' LIMIT 0');$this->connection->sql_freeresult($r);$table=$this->escape(LINKS_TABLE);
				$meta=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
				if(count($meta)!==1||$meta[0]['ENGINE']!=='InnoDB'||strtolower($meta[0]['ROW_FORMAT'])!=='dynamic'||$meta[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){phpbb_link_visit_fail();}
			}
		}catch(Exception$error){$this->release();phpbb_link_visit_fail();}catch(Error$error){$this->release();phpbb_link_visit_fail();}
		register_shutdown_function(array($this,'release'));
	}
	function escape($value){return$this->connection->sql_escape((string)$value);}
	private function control($sql){$r=$this->connection->sql_query($sql);if(!$r){phpbb_link_visit_fail();}return$r;}
	function query($sql)
	{
		if(!is_string($sql)||!$this->transactional||strpos($sql,'SELECT ')!==0&&(!$this->writable||strpos($sql,'UPDATE '.LINKS_TABLE.' SET ')!==0)){phpbb_link_visit_fail();}return$this->control($sql);
	}
	function rows($sql){$r=$this->query($sql);try{return$this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}}
	function commit(){if(!$this->transactional||!$this->writable){phpbb_link_visit_fail();}$this->control('COMMIT');$this->transactional=false;}
	function release()
	{
		$c=$this->connection;$this->connection=null;if($c!==null){try{if($this->transactional){$c->sql_query('ROLLBACK');}}catch(Exception$ignored){}catch(Error$ignored){}$this->transactional=false;try{$c->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}
	}
}

function phpbb_link_visit_url($rows)
{
	if(!is_array($rows)||count($rows)!==1||!is_array($rows[0])||!isset($rows[0]['link_active'],$rows[0]['link_url'])||(string)$rows[0]['link_active']!=='1'){return false;}
	$url=phpbb_normalize_external_url($rows[0]['link_url']);return$url!==false&&preg_match('//u',$url)===1?$url:false;
}

function phpbb_link_click_read($database,$id)
{
	$id=phpbb_link_visit_id($id);if($id===false){return false;}
	$scope=null;
	try{$scope=new PhpbbLinkVisitScope($database);return phpbb_link_visit_url($scope->rows('SELECT link_id,link_url,link_active FROM '.LINKS_TABLE.' WHERE link_id='.$id));}
	catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
}

function phpbb_link_click($database,$value,$ip)
{
	$id=phpbb_link_visit_id($value);$method=isset($_SERVER['REQUEST_METHOD'])&&is_string($_SERVER['REQUEST_METHOD'])?strtoupper($_SERVER['REQUEST_METHOD']):'';
	if($id===false||!in_array($method,array('GET','HEAD'),true)){return false;}
	if($method==='HEAD'||!is_string($ip)||preg_match('/^[a-f0-9]{8}$/iD',$ip)!==1){return phpbb_link_click_read($database,$id);}
	$ip=strtolower($ip);$scope=null;
	try{
		$scope=new PhpbbLinkVisitScope($database,true);$rows=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE link_id='.$id.' FOR UPDATE');$url=phpbb_link_visit_url($rows);if($url===false){return false;}$row=$rows[0];
		if(strtolower((string)$row['last_user_ip'])===$ip){return$url;}
		$hits=phpbb_link_next_hit($row['link_hits']);
		$scope->query('UPDATE '.LINKS_TABLE." SET link_hits=CASE WHEN link_hits<4294967295 THEN link_hits+1 ELSE link_hits END,last_user_ip='".$scope->escape($ip)."' WHERE link_id=".$id." AND link_active=1 AND LOWER(last_user_ip)<>'".$scope->escape($ip)."'");
		$after=$scope->rows('SELECT * FROM '.LINKS_TABLE.' WHERE link_id='.$id.' FOR UPDATE');if(count($after)!==1){phpbb_link_visit_fail();}
		foreach($row as$key=>$value){if(!is_string($key)){continue;}$expected=$key==='link_hits'?$hits:($key==='last_user_ip'?$ip:$value);if(!array_key_exists($key,$after[0])||($expected===null?$after[0][$key]!==null:(string)$after[0][$key]!==(string)$expected)){phpbb_link_visit_fail();}}
		$scope->commit();return$url;
	}catch(Exception$error){}catch(Error$error){}finally{if($scope!==null){$scope->release();}}
	// No second count attempt: a failed acknowledgement may already have
	// committed. Only an independent current destination read is retried.
	return phpbb_link_click_read($database,$id);
}
