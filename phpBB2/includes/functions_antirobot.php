<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_confirmation.php';
require_once dirname(__FILE__).'/functions_forum_read_snapshot.php';

// Separate namespace in the existing confirmation table. The ID contains a
// 16-second creation bucket and 96 random bits; the answer stays server-side.
function phpbb_antirobot_id($id,$now=null)
{
	if(!is_string($id)||!preg_match('/^r[0-9a-f]{31}$/D',$id)){return false;}
	$created=hexdec(substr($id,1,7))*16;$now=$now===null?time():$now;
	return $created<=$now && $created+3615>=$now;
}
function phpbb_antirobot_actor($actor)
{
	return is_array($actor)&&isset($actor['user_id'],$actor['session_id'],$actor['session_logged_in'])
		&&(is_int($actor['user_id'])||is_string($actor['user_id']))&&(string)$actor['user_id']==='-1'
		&&in_array($actor['session_logged_in'],array(false,0,'0'),true)&&is_string($actor['session_id'])&&preg_match('/^[a-f0-9]{32}$/iD',$actor['session_id']);
}
function phpbb_antirobot_context($store,$actor,$locked)
{
	$share=$locked?' LOCK IN SHARE MODE':'';$update=$locked?' FOR UPDATE':'';$sid=$store->sql_escape($actor['session_id']);
	$settings=array();foreach($store->rows('SELECT config_name,config_value FROM '.CONFIG_TABLE." WHERE config_name IN ('session_length','registration_status')".$share)as$row){$settings[$row['config_name']]=$row['config_value'];}
	if(!isset($settings['session_length'],$settings['registration_status'])||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$settings['session_length'])||(float)$settings['session_length']>2147483647||(string)$settings['registration_status']!=='0'){throw new PhpbbOwnedStorageFailure('Registration_closed');}
	$plus=$store->rows('SELECT config_value FROM '.PLUS_TABLE." WHERE config_name='enable_antirobot' AND HEX(config_name)=HEX('enable_antirobot')".$share);
	if(count($plus)!==1||(string)$plus[0]['config_value']!=='1'){throw new PhpbbOwnedStorageFailure('Wrong_reg_key');}
	$session=$store->rows('SELECT session_user_id,session_logged_in,session_time FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')".$update);
	if(count($session)!==1||(string)$session[0]['session_user_id']!=='-1'||(string)$session[0]['session_logged_in']!=='0'
		||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$session[0]['session_time'])||(float)$session[0]['session_time']>2147483647
		||(float)$session[0]['session_time']>time()+60||(float)$session[0]['session_time']<time()-(int)$settings['session_length']){throw new PhpbbOwnedStorageFailure('Session_invalid');}
	return (int)$settings['session_length'];
}
class PhpbbAntirobotStore extends PhpbbConfirmationStore
{
	protected function storage_tables(){return array(SESSIONS_TABLE,CONFIG_TABLE,CONFIRM_TABLE,PLUS_TABLE);}
	function sql_escape($value){return$this->escape($value);}
	protected function new_id(){return 'r'.sprintf('%07x',(int)floor(time()/16)).bin2hex(phpbb_random_bytes(12));}
	protected function new_code(){return phpbb_random_string(5,'abcdefghijklmnopqrstuvwxyz');}
	function robot_challenge()
	{
		$ttl=phpbb_antirobot_context($this,$this->actor,true);$sid=$this->escape($this->actor['session_id']);$cutoff=time()-$ttl;
		$this->query('DELETE c FROM '.CONFIRM_TABLE.' c LEFT JOIN '.SESSIONS_TABLE.' s ON s.session_id=c.session_id WHERE s.session_id IS NULL OR s.session_time<'.$cutoff);
		$this->query('DELETE FROM '.CONFIRM_TABLE." WHERE confirm_id REGEXP '^r[0-9a-f]{31}$' AND CHAR_LENGTH(RTRIM(code))=5 AND CONV(SUBSTRING(confirm_id,2,7),16,10)*16<".(time()-3615));
		$predicate=" WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid')";
		$rows=$this->rows('SELECT confirm_id,RTRIM(code) code FROM '.CONFIRM_TABLE.$predicate." AND confirm_id LIKE 'r%' ORDER BY confirm_id LIMIT 4 FOR UPDATE");
		foreach($rows as$row){if(!phpbb_antirobot_id($row['confirm_id'])||!preg_match('/^[a-z]{5}$/D',$row['code'])){$this->storage_fail('Owned_storage_upgrade');}}
		if(count($rows)===4){$id=$rows[0]['confirm_id'];}
		else
		{
			$id=$this->new_id();$code=$this->new_code();if(!phpbb_antirobot_id($id)||!is_string($code)||!preg_match('/^[a-z]{5}$/D',$code)){$this->storage_fail();}
			$key=$this->escape($id);$value=$this->escape($code);
			$this->query('INSERT INTO '.CONFIRM_TABLE." (confirm_id,session_id,code) VALUES ('$key','$sid','$value')");
			$read=$this->rows('SELECT RTRIM(code) code FROM '.CONFIRM_TABLE.$predicate." AND confirm_id='$key' AND HEX(confirm_id)=HEX('$key')");
			if(count($read)!==1||!hash_equals($code,$read[0]['code'])){$this->storage_fail();}
		}
		phpbb_antirobot_context($this,$this->actor,true);if(!phpbb_antirobot_id($id)){$this->storage_fail('Wrong_reg_key');}
		$this->commit();return$id;
	}
}
function phpbb_antirobot_challenge($database,$actor)
{
	$store=null;try{$store=new PhpbbAntirobotStore($database,$actor);return$store->robot_challenge();}
	catch(PhpbbOwnedStorageFailure$error){throw$error;}catch(Exception$error){throw new PhpbbOwnedStorageFailure('Owned_storage_failed');}catch(Error$error){throw new PhpbbOwnedStorageFailure('Owned_storage_failed');}
	finally{if($store){$store->release();}}
}
function phpbb_antirobot_code($database,$actor,$id)
{
	if(!phpbb_antirobot_actor($actor)||!phpbb_antirobot_id($id)){return false;}$reader=null;
	try
	{
		$reader=new PhpbbForumReadSnapshot($database,array(SESSIONS_TABLE,CONFIG_TABLE,CONFIRM_TABLE,PLUS_TABLE));phpbb_antirobot_context($reader,$actor,false);
		$sid=$reader->sql_escape($actor['session_id']);$key=$reader->sql_escape($id);
		$rows=$reader->rows('SELECT RTRIM(code) code FROM '.CONFIRM_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND confirm_id='$key' AND HEX(confirm_id)=HEX('$key')");
		return count($rows)===1&&is_string($rows[0]['code'])&&preg_match('/^[a-z]{5}$/D',$rows[0]['code'])&&phpbb_antirobot_id($id)?$rows[0]['code']:false;
	}
	catch(Exception$error){throw new PhpbbOwnedStorageFailure('Owned_storage_failed');}catch(Error$error){throw new PhpbbOwnedStorageFailure('Owned_storage_failed');}
	finally{if($reader){$reader->release();}}
}
function phpbb_antirobot_check($database,$actor,$id,$answer)
{
	if(!is_string($answer)||!preg_match('/^[a-z]{5}$/iD',$answer)){return false;}$code=phpbb_antirobot_code($database,$actor,$id);return $code!==false&&hash_equals($code,strtolower($answer));
}
// Called by the existing registration owner; never opens or commits another
// transaction. The answer is consumed only with successful account creation.
function phpbb_antirobot_consume($store,$sid,$id,$answer)
{
	if(!phpbb_antirobot_id($id)||!is_string($answer)||!preg_match('/^[a-z]{5}$/iD',$answer)){return false;}
	$sid=$store->sql_escape($sid);$id_sql=$store->sql_escape($id);
	$where=" WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND confirm_id='$id_sql' AND HEX(confirm_id)=HEX('$id_sql')";
	$rows=$store->rows('SELECT RTRIM(code) code FROM '.CONFIRM_TABLE.$where.' FOR UPDATE');
	if(count($rows)!==1||!is_string($rows[0]['code'])||!preg_match('/^[a-z]{5}$/D',$rows[0]['code'])||!hash_equals($rows[0]['code'],strtolower($answer))||!phpbb_antirobot_id($id)){return false;}
	$store->sql_query('DELETE FROM '.CONFIRM_TABLE.$where);
	if($store->rows('SELECT confirm_id FROM '.CONFIRM_TABLE.$where)){throw new PhpbbOwnedStorageFailure('Owned_storage_failed');}return true;
}
