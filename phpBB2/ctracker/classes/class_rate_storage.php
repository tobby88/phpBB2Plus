<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(dirname(__DIR__)).'/includes/php_compat.php';

class CtrackerRateStorageException extends RuntimeException{}
function ctracker_rate_storage_fail(){throw new CtrackerRateStorageException('Rate storage unavailable');}

// A rate decision owns only its hash row, never the forum-wide writer mutex.
// Pending work lent by a broken factory must remain owned by its caller.
class CtrackerRateScope
{
	var$connection=null;var$transactional=false;var$write=false;var$confirmed=false;
	function __construct($database,$write=false)
	{
		if(!is_object($database)||empty($database->db_connect_id)||!isset($database->dbname)||!is_string($database->dbname)||$database->dbname===''||!method_exists($database,'sql_dedicated_connection')||!defined('CTRACKER_RATE_LIMITS')||preg_match('/^[A-Za-z0-9_]{1,64}$/D',CTRACKER_RATE_LIMITS)!==1){ctracker_rate_storage_fail();}
		try{$candidate=$database->sql_dedicated_connection();}catch(Exception$error){ctracker_rate_storage_fail();}catch(Error$error){ctracker_rate_storage_fail();}
		if(!is_object($candidate)||empty($candidate->db_connect_id)||$candidate===$database||$candidate->db_connect_id===$database->db_connect_id){ctracker_rate_storage_fail();}
		try{
			$r=$candidate->sql_query('SELECT DATABASE() rate_schema,@@SESSION.autocommit rate_autocommit');if(!$r){ctracker_rate_storage_fail();}
			try{$state=$candidate->sql_fetchrowset($r);}finally{$candidate->sql_freeresult($r);}
			if(count($state)!==1||$state[0]['rate_schema']!==$database->dbname||(string)$state[0]['rate_autocommit']!=='1'||!$candidate->sql_query($write?'SET TRANSACTION READ WRITE':'SET TRANSACTION READ ONLY')){ctracker_rate_storage_fail();}
		}catch(Exception$error){ctracker_rate_storage_fail();}catch(Error$error){ctracker_rate_storage_fail();}
		$this->connection=$candidate;$this->write=(bool)$write;
		try{
			$this->control('SET SESSION lock_wait_timeout=1');$this->control('SET SESSION innodb_lock_wait_timeout=1');$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
			if($write){$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");}
			$this->control($write?'START TRANSACTION':'START TRANSACTION READ ONLY');$this->transactional=true;if($write){$this->pin();}
		}catch(Exception$error){$this->release();ctracker_rate_storage_fail();}catch(Error$error){$this->release();ctracker_rate_storage_fail();}
		register_shutdown_function(array($this,'release'));
	}
	private function control($sql){$r=$this->connection->sql_query($sql);if(!$r){ctracker_rate_storage_fail();}return$r;}
	private function pin()
	{
		$r=$this->query('SELECT * FROM '.CTRACKER_RATE_LIMITS.' LIMIT 0');$this->connection->sql_freeresult($r);$name=$this->escape(CTRACKER_RATE_LIMITS);
		$rows=$this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$name."' AND NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$name."' AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR COLLATION_NAME<>'utf8mb4_unicode_ci'))");
		if(count($rows)!==1||$rows[0]['ENGINE']!=='InnoDB'||strtolower($rows[0]['ROW_FORMAT'])!=='dynamic'||$rows[0]['TABLE_COLLATION']!=='utf8mb4_unicode_ci'){ctracker_rate_storage_fail();}
	}
	function escape($value){return$this->connection->sql_escape((string)$value);}
	function query($sql){if(!$this->transactional||!is_string($sql)||strpos($sql,'SELECT ')!==0&&(!$this->write||strpos($sql,'INSERT INTO '.CTRACKER_RATE_LIMITS.' ')!==0&&strpos($sql,'DELETE FROM '.CTRACKER_RATE_LIMITS.' ')!==0)){ctracker_rate_storage_fail();}return$this->control($sql);}
	function rows($sql){$r=$this->query($sql);try{return$this->connection->sql_fetchrowset($r);}finally{$this->connection->sql_freeresult($r);}}
	function commit(){if(!$this->write||!$this->transactional){ctracker_rate_storage_fail();}$this->control('COMMIT');$this->transactional=false;$this->confirmed=true;}
	function collect($now)
	{
		if(!$this->write||!$this->confirmed||$this->transactional){ctracker_rate_storage_fail();}
		// Optional cleanup has its own transaction. A failure cannot undo or
		// invalidate an already confirmed count, and each pass is bounded.
		$this->control('START TRANSACTION');$this->transactional=true;$this->pin();$cutoff=(int)$now-172800;
		$this->query('DELETE FROM '.CTRACKER_RATE_LIMITS.' WHERE updated_at < '.$cutoff.' AND window_start < '.$cutoff.' LIMIT 500');$this->commit();
	}
	function release(){
		$c=$this->connection;$this->connection=null;if($c!==null){try{if($this->transactional){$c->sql_query('ROLLBACK');}}catch(Exception$ignored){}catch(Error$ignored){}$this->transactional=false;try{$c->sql_close();}catch(Exception$ignored){}catch(Error$ignored){}}
	}
}

function ctracker_rate_arguments($bucket,$identity)
{
	$bucket=is_scalar($bucket)?(string)$bucket:'';$identity=is_scalar($identity)?(string)$identity:'';
	return$bucket!==''&&$identity!==''?hash('sha256',$bucket."\0".$identity):false;
}

function ctracker_rate_success_sql($database,$hash,$now)
{
	return'INSERT INTO '.CTRACKER_RATE_LIMITS." (bucket_hash,window_start,request_count,updated_at) VALUES ('".$database->sql_escape($hash)."', $now, 1, $now) ON DUPLICATE KEY UPDATE window_start=GREATEST(window_start,VALUES(window_start)),request_count=LEAST(request_count,4294967294)+1,updated_at=GREATEST(updated_at,VALUES(updated_at))";
}

function ctracker_rate_success_on_registration($scope,$bucket,$identity)
{
	// This participant is already canonical, pinned and owned by registration.
	// Its success marker must commit/roll back with the new account, not apart.
	if(!($scope instanceof PhpbbRegistrationScope)||!$scope->transactional||$scope->connection===null){return false;}$hash=ctracker_rate_arguments($bucket,$identity);if($hash===false){return false;}$now=time();
	if(!$scope->sql_query(ctracker_rate_success_sql($scope,$hash,$now))){return false;}$r=$scope->sql_query('SELECT window_start,request_count,updated_at FROM '.CTRACKER_RATE_LIMITS." WHERE bucket_hash='".$scope->sql_escape($hash)."'");
	if(!$r){return false;}try{$rows=$scope->sql_fetchrowset($r);}finally{$scope->sql_freeresult($r);}
	return count($rows)===1&&(float)$rows[0]['window_start']>=$now&&(float)$rows[0]['updated_at']>=$now&&(float)$rows[0]['request_count']>=1;
}
