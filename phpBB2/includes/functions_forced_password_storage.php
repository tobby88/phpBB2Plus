<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__).'/functions_owned_storage.php';
require_once dirname(__FILE__).'/functions_forum_read_snapshot.php';
require_once dirname(__FILE__).'/functions_validate.php';
require_once dirname(__DIR__).'/ctracker/classes/class_ct_database.php';
require_once dirname(__DIR__).'/ctracker/classes/class_ct_userfunctions.php';

class PhpbbForcedPasswordFailure extends RuntimeException {}
function phpbb_forced_password_fail($key='Forced_password_failed') { throw new PhpbbForcedPasswordFailure($key); }
function phpbb_forced_password_named($row) { $out=array();foreach($row as$key=>$value){if(is_string($key)){$out[$key]=$value;}}return$out; }
class PhpbbForcedPasswordStore extends PhpbbOwnedStore
{
	public $commit_attempted=false;
	protected function storage_tables() { return array(CONFIG_TABLE,USERS_TABLE,SESSIONS_TABLE,SESSIONS_KEYS_TABLE,BANLIST_TABLE,CTRACKER_CONFIG); }
	protected function storage_fail($key='Forced_password_failed') { phpbb_forced_password_fail($key==='Owned_storage_upgrade'?'Forced_password_unavailable':'Forced_password_failed'); }
	function state($expected_hash,$request_ip)
	{
		global $board_config,$ctracker_config;
		$id=(int)$this->actor['user_id'];$sid=$this->escape($this->actor['session_id']);$now=time();$policy=array();
		foreach($this->rows('SELECT config_name,config_value FROM '.CONFIG_TABLE.' ORDER BY config_name LOCK IN SHARE MODE')as$row){$policy[$row['config_name']]=$row['config_value'];}
		foreach(array('session_length','board_disable','password_hashing','min_password_len','password_not_login','force_complex_password','max_password_age')as$key)
		{
			if(!isset($policy[$key],$board_config[$key])||!is_scalar($board_config[$key])||(string)$policy[$key]!== (string)$board_config[$key]
				|| !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$policy[$key])||(float)$policy[$key]>2147483647){phpbb_forced_password_fail('Forced_password_unavailable');}
		}
		foreach(array('board_disable','password_hashing','password_not_login','force_complex_password')as$key){if(!in_array((string)$policy[$key],array('0','1'),true)){phpbb_forced_password_fail('Forced_password_unavailable');}}
		if((int)$policy['session_length']<1){phpbb_forced_password_fail('Forced_password_unavailable');}
		$sessions=$this->rows('SELECT * FROM '.SESSIONS_TABLE." WHERE session_id='$sid' AND HEX(session_id)=HEX('$sid') AND session_user_id=$id AND session_logged_in=1 FOR UPDATE");
		if(count($sessions)!==1){phpbb_forced_password_fail('Session_invalid');}$session=phpbb_forced_password_named($sessions[0]);
		if(!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$session['session_time'])||(float)$session['session_time']>2147483647||(float)$session['session_time']>$now+60
			||(float)$session['session_time']<$now-(int)$policy['session_length']||!preg_match('/^[a-f0-9]{8}$/iD',(string)$session['session_ip'])
			||strcasecmp(substr($session['session_ip'],0,6),substr($request_ip,0,6))!==0){phpbb_forced_password_fail('Session_invalid');}
		$users=$this->rows('SELECT * FROM '.USERS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
		if(count($users)!==1){phpbb_forced_password_fail('Not_Authorised');}$current=phpbb_forced_password_named($users[0]);
		if((string)$current['user_active']!=='1'||!in_array((string)$current['user_level'],array((string)USER,(string)MOD,(string)ADMIN),true)
			||!preg_match('/^-?[0-9]+$/D',(string)$current['user_blocktime'])||(float)$current['user_blocktime']>=$now
			||((string)$policy['board_disable']==='1'&&(int)$current['user_level']!==ADMIN)
			||$this->rows('SELECT ban_id FROM '.BANLIST_TABLE.' WHERE ban_userid='.$id.' LOCK IN SHARE MODE')){phpbb_forced_password_fail('Not_Authorised');}
		if(!is_string($current['user_password'])||!hash_equals($expected_hash,$current['user_password'])){phpbb_forced_password_fail('Current_password_mismatch');}
		if(!($ctracker_config instanceof ct_database)||!is_array($ctracker_config->settings)){phpbb_forced_password_fail('Forced_password_unavailable');}
		$stored=array();foreach($this->rows('SELECT ct_config_name,ct_config_value FROM '.CTRACKER_CONFIG.' ORDER BY ct_config_name LOCK IN SHARE MODE')as$row){$stored[$row['ct_config_name']]=$row['ct_config_value'];}
		$defaults=$ctracker_config->default_settings();
		foreach(array('pw_complex','pw_complex_min','pw_complex_mode')as$key)
		{
			if(!isset($stored[$key])){foreach($stored as$name=>$value){if(strcasecmp($name,$key)===0){phpbb_forced_password_fail('Forced_password_unavailable');}}}
			$value=isset($stored[$key])&&$ctracker_config->valid_numeric_setting($key,$stored[$key])?$stored[$key]:$defaults[$key];
			if(!isset($ctracker_config->settings[$key])||!is_scalar($ctracker_config->settings[$key])||(string)$ctracker_config->settings[$key]!== (string)$value){phpbb_forced_password_fail('Forced_password_unavailable');}
		}
		return array('user'=>$current,'session'=>$session);
	}
	function change($ip,$old,$new_password,$replacement)
	{
		$context=$this->state($this->actor['user_password'],$ip);$current=$context['user'];
		if(!phpbb_password_verify($old,$current['user_password'])){phpbb_forced_password_fail('Current_password_mismatch');}
		$validation = validate_complex_password($current['username'], $new_password);
		if($validation['error']){throw new PhpbbForcedPasswordFailure($validation['error_msg']);}
		$tracker_error=(new ct_userfunctions())->password_validation($new_password);
		if($tracker_error!==''){throw new PhpbbForcedPasswordFailure($tracker_error);}
		$id=(int)$current['user_id'];$sid=$this->escape($this->actor['session_id']);$now=time();
		$this->rows('SELECT key_id FROM '.SESSIONS_KEYS_TABLE.' WHERE user_id='.$id.' FOR UPDATE');
		$this->rows('SELECT session_id FROM '.SESSIONS_TABLE.' WHERE session_user_id='.$id.' FOR UPDATE');
		$hash=$this->escape($replacement);
		$this->query('UPDATE '.USERS_TABLE." SET user_password='$hash',user_passwd_change=$now,ct_last_pw_change=$now,user_newpasswd='',user_actkey='',ct_last_pw_reset=0 WHERE user_id=$id");
		$this->query('DELETE FROM '.SESSIONS_KEYS_TABLE.' WHERE user_id='.$id);
		$this->query('DELETE FROM '.SESSIONS_TABLE." WHERE session_user_id=$id AND HEX(session_id)<>HEX('$sid')");
		$expected=$current;$expected['user_password']=$replacement;$expected['user_passwd_change']=(string)$now;$expected['ct_last_pw_change']=(string)$now;
		$expected['user_newpasswd']='';$expected['user_actkey']='';$expected['ct_last_pw_reset']='0';
		$after=$this->state($replacement,$ip);
		if($after['user']!==$expected||$after['session']!==$context['session']||$this->rows('SELECT key_id FROM '.SESSIONS_KEYS_TABLE.' WHERE user_id='.$id)
			||$this->rows('SELECT session_id FROM '.SESSIONS_TABLE." WHERE session_user_id=$id AND HEX(session_id)<>HEX('$sid')")){phpbb_forced_password_fail();}
		$this->commit_attempted=true;$this->commit();return$expected;
	}
}
function phpbb_forced_password_change($database,$actor,$ip,$old,$new,$replacement)
{
	global $board_config;
	if(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!PhpbbForumReadSnapshot::valid_actor($actor)
		||!isset($_POST['sid'])||!is_string($_POST['sid'])||!hash_equals($actor['session_id'],$_POST['sid'])||!isset($actor['user_password'])||!is_string($actor['user_password'])
		||!is_string($ip)||!preg_match('/^[a-f0-9]{8}$/iD',$ip)){phpbb_forced_password_fail('Session_invalid');}
	if(!is_string($old)||$old===''||strlen($old)>128||strpos($old,"\0")!==false){phpbb_forced_password_fail('Current_password_mismatch');}
	if(!is_string($new)){phpbb_forced_password_fail('Password_invalid');}$input=phpbb_password_input_error($new);if($input!==''){phpbb_forced_password_fail($input);}
	if(!is_string($replacement)||!phpbb_password_verify($new,$replacement)
		||(!empty($board_config['password_hashing'])?!preg_match('/^\$2[aby]\$/D',$replacement):!hash_equals(md5($new),$replacement))){phpbb_forced_password_fail('Password_hash_failed');}
	$owner=null;
	try{$owner=new PhpbbForcedPasswordStore($database,$actor);return$owner->change($ip,$old,$new,$replacement);}
	catch(Exception$error){if($owner&&$owner->commit_attempted){phpbb_forced_password_fail('Forced_password_unconfirmed');}if($error instanceof PhpbbForcedPasswordFailure){throw$error;}phpbb_forced_password_fail();}
	catch(Error$error){phpbb_forced_password_fail($owner&&$owner->commit_attempted?'Forced_password_unconfirmed':'Forced_password_failed');}
	finally{if($owner){$owner->release();}}
}
function phpbb_forced_password_message($error)
{
	global $lang;
	if($error instanceof PhpbbForcedPasswordFailure){$message=$error->getMessage();return isset($lang[$message])?$lang[$message]:$message;}
	return isset($lang['Forced_password_failed'])?$lang['Forced_password_failed']:'The password could not be changed.';
}
