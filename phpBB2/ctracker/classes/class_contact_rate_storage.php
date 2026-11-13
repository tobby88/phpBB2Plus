<?php
if(!defined('IN_PHPBB')){die('Hacking attempt');}
require_once dirname(__FILE__).'/class_rate_storage.php';

// Read the old cooldown, never create/truncate/unlink a cache entry. Legacy
// files stay intact; subsequent acknowledged claims live in the database.
function ctracker_contact_legacy_stamp($directory,$identity)
{
	$file=rtrim($directory,'/\\').'/contact-'.hash('sha256',$identity).'.rate';
	if(is_link($file)){ctracker_rate_storage_fail();}
	if(!file_exists($file)){return 0;}
	if(!is_file($file)){ctracker_rate_storage_fail();}
	$handle=@fopen($file,'rb');if(!$handle){ctracker_rate_storage_fail();}
	try{
		if(!@flock($handle,LOCK_SH|LOCK_NB)){ctracker_rate_storage_fail();}
		$bytes=@stream_get_contents($handle,33);if(!is_string($bytes)||strlen($bytes)>32){ctracker_rate_storage_fail();}
		$value=trim($bytes);if(!ctype_digit($value)){return 0;}
		if(strlen($value)>10||(float)$value>2147483647){ctracker_rate_storage_fail();}
		return(int)$value;
	}finally{@flock($handle,LOCK_UN);fclose($handle);}
}
function ctracker_contact_rate_metadata($scope)
{
	$name=$scope->escape(CTRACKER_RATE_LIMITS);
	$key=$scope->rows("SELECT COLUMN_NAME,SUB_PART,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name') AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
	if(count($key)!==1||$key[0]['COLUMN_NAME']!=='bucket_hash'||$key[0]['SUB_PART']!==null||(string)$key[0]['NON_UNIQUE']!=='0'){ctracker_rate_storage_fail();}
	$columns=$scope->rows("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('$name') AND COLUMN_NAME IN ('bucket_hash','window_start','request_count','updated_at')");$found=array();
	foreach($columns as$row){$field=$row['COLUMN_NAME'];if(isset($found[$field])||$row['IS_NULLABLE']!=='NO'){ctracker_rate_storage_fail();}$found[$field]=true;
		if($field==='bucket_hash'){if($row['DATA_TYPE']!=='char'||(string)$row['CHARACTER_MAXIMUM_LENGTH']!=='64'){ctracker_rate_storage_fail();}}
		elseif(!in_array($field,array('window_start','request_count','updated_at'),true)||$row['DATA_TYPE']!=='int'||stripos($row['COLUMN_TYPE'],'unsigned')===false){ctracker_rate_storage_fail();}
	}
	if(count($found)!==4){ctracker_rate_storage_fail();}
}
function ctracker_contact_rate_row($rows)
{
	if(count($rows)!==1){ctracker_rate_storage_fail();}$row=$rows[0];
	foreach(array('window_start','request_count','updated_at')as$field){if(!isset($row[$field])||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$row[$field])||(float)$row[$field]>($field==='updated_at'?2147483647:4294967295)){ctracker_rate_storage_fail();}}
	if((float)$row['window_start']<(float)$row['updated_at']||((float)$row['updated_at']>0&&(float)$row['request_count']<1)){ctracker_rate_storage_fail();}return$row;
}
function ctracker_rate_limit_contact($directory,$identity,$interval)
{
	global$db;$scope=null;
	if(!is_string($directory)||$directory===''||strpos($directory,"\0")!==false||strpos($directory,'://')!==false||!is_string($identity)||$identity===''||strpos($identity,"\0")!==false||strlen($identity)>128||!(is_int($interval)||is_string($interval))||!preg_match('/^[0-9]{1,10}$/D',(string)$interval)||(float)$interval>2147483647){return false;}
	$interval=max(60,(int)$interval);$retention=max($interval,2419200);$hash=ctracker_rate_arguments('contact-cooldown',$identity);
	try{
		$legacy=ctracker_contact_legacy_stamp($directory,$identity);$scope=new CtrackerRateScope($db,true);ctracker_contact_rate_metadata($scope);$key=$scope->escape($hash);
		// The shared collector adds two days to this boundary. Preserve the old
		// thirty-day history if an administrator later increases the interval.
		$expiry=$legacy>0?sprintf('%.0f',(float)$legacy+$retention):'0';$count=$legacy>0?1:0;
		// This upsert obtains the unique row lock even for a previously unseen
		// identity. Seed the maximum legacy timestamp without renewing a denial.
		$scope->query('INSERT INTO '.CTRACKER_RATE_LIMITS." (bucket_hash,window_start,request_count,updated_at) VALUES ('$key',$expiry,$count,$legacy) ON DUPLICATE KEY UPDATE window_start=GREATEST(window_start,VALUES(window_start)),request_count=IF(request_count=0 AND VALUES(updated_at)>0,1,request_count),updated_at=GREATEST(updated_at,VALUES(updated_at))");
		$where=" WHERE bucket_hash='$key' AND HEX(bucket_hash)=HEX('$key')";$row=ctracker_contact_rate_row($scope->rows('SELECT window_start,request_count,updated_at FROM '.CTRACKER_RATE_LIMITS.$where.' FOR UPDATE'));
		$now=time();if($now<1||$now>2147483647){ctracker_rate_storage_fail();}
		if((float)$row['updated_at']>0&&$now-(float)$row['updated_at']<$interval){$scope->commit();return false;}
		$expiry=sprintf('%.0f',(float)$now+$retention);$count=sprintf('%.0f',min(4294967294,(float)$row['request_count'])+1);
		$scope->query('INSERT INTO '.CTRACKER_RATE_LIMITS." (bucket_hash,window_start,request_count,updated_at) VALUES ('$key',$expiry,$count,$now) ON DUPLICATE KEY UPDATE window_start=VALUES(window_start),request_count=VALUES(request_count),updated_at=VALUES(updated_at)");
		$read=ctracker_contact_rate_row($scope->rows('SELECT window_start,request_count,updated_at FROM '.CTRACKER_RATE_LIMITS.$where.' FOR UPDATE'));
		if((string)$read['window_start']!==$expiry||(string)$read['request_count']!==$count||(string)$read['updated_at']!==(string)$now){ctracker_rate_storage_fail();}
		$scope->commit();return true;
	}catch(Exception$error){return false;}catch(Error$error){return false;}finally{if($scope!==null){$scope->release();}}
}
