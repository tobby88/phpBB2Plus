<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';

function kb_config_boolean_fields()
{
	return array('allow_new','approve_new','allow_edit','approve_edit','show_pretext','comments','allow_anon','del_topic',
		'comments_show','bump_post','stats_list','header_banner','allow_rating','allow_anonymos_rating','votes_check_ip','votes_check_userid');
}

function kb_config_editable_fields()
{
	return array_merge(kb_config_boolean_fields(), array('notify','news_sort','news_sort_par','art_pagination','comments_pagination',
		'pt_header','pt_body','admin_id','forum_id','mod_group'));
}

// Only actual strings from form fields, not arrays, partial numeric IDs, booleans
// or invalid UTF-8. Character limits behave identically without mbstring.
function kb_config_input_value($value)
{
	if (!is_string($value) || strlen($value)>1048576 || strpos($value,"\0")!==false || preg_match('//u',$value)!==1) { kb_publication_fail('KB_config_invalid'); }
	return trim($value);
}

function kb_config_values($current, $input)
{
	if (!is_array($input)) { kb_publication_fail('KB_config_invalid'); }
	$expected=$current;
	foreach (kb_config_editable_fields() as $key) {
		if (!array_key_exists($key,$current)) { kb_publication_fail('KB_config_invalid'); }
		$value=kb_config_input_value(array_key_exists($key,$input)?$input[$key]:$current[$key]);
		if (in_array($key,kb_config_boolean_fields(),true)) { $valid=in_array($value,array('0','1'),true); }
		elseif ($key==='notify') { $valid=in_array($value,array('0','1','2'),true); }
		elseif ($key==='news_sort') { $valid=in_array($value,array('Latest','Creation','Id','Userrank','Alphabetic'),true); }
		elseif ($key==='news_sort_par') { $value=strtoupper($value);$valid=in_array($value,array('ASC','DESC'),true); }
		elseif ($key==='art_pagination' || $key==='comments_pagination') {
			$valid=preg_match('/^[0-9]{1,4}$/D',$value)===1 && (int)$value>=1 && (int)$value<=1000;
			if ($valid) { $value=(string)(int)$value; }
		} elseif ($key==='pt_header' || $key==='pt_body') {
			$limit=$key==='pt_header'?100:255;
			if (function_exists('mb_substr')) { $value=mb_substr($value,0,$limit,'UTF-8'); }
			else { preg_match('/^.{0,'.$limit.'}/us',$value,$match);$value=$match[0]; }
			$valid=true;
		} else {
			try { $value=(string)kb_counter_category_id($value,true);$valid=true; }
			catch (RuntimeException $error) { $valid=false; }
		}
		if (!$valid) { kb_publication_fail('KB_config_invalid'); }
		// A partial form/API call must not trim or truncate stored fields it did
		// not send. Validate them above, but retain their original bytes.
		$expected[$key]=array_key_exists($key,$input)?$value:$current[$key];
	}
	return $expected;
}

// Same forum writer owner as KB add/edit/approval/deletion: no policy update can
// be interleaved with one of those operations. The exact config ACP grant is
// checked at entry, every write and while locking current authority at COMMIT.
class PhpbbKbConfigDatabase extends PhpbbKbPublicationDatabase
{
	var $settings=array();
	function __construct($database) { parent::__construct($database,'config'); }
	function write_tables() { return array(KB_CONFIG_TABLE); }
	function read_settings()
	{
		$settings=array();
		foreach ($this->rows('SELECT config_name,config_value FROM '.KB_CONFIG_TABLE.' ORDER BY config_name LIMIT 10001 FOR UPDATE') as $row) {
			if (!is_string($row['config_name']) || !is_string($row['config_value']) || array_key_exists($row['config_name'],$settings)) { kb_publication_fail('KB_config_invalid'); }
			$settings[$row['config_name']]=$row['config_value'];
		}
		if (count($settings)>10000) { kb_publication_fail('KB_config_invalid'); }return $settings;
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION');$this->transactional=true;
		kb_publication_pin_tables($this,array(KB_CONFIG_TABLE,USERS_TABLE,FORUMS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE));
		$this->settings=$this->read_settings();$this->actor();
	}
	function references($settings)
	{
		foreach (array('admin_id'=>array(USERS_TABLE,'user_id'),'forum_id'=>array(FORUMS_TABLE,'forum_id'),'mod_group'=>array(GROUPS_TABLE,'group_id')) as $key=>$reference) {
			$id=(int)$settings[$key];
			if ($id && count($this->rows('SELECT '.$reference[1].' FROM '.$reference[0].' WHERE '.$reference[1].'='.$id.' LOCK IN SHARE MODE'))!==1) { kb_publication_fail('KB_config_invalid'); }
		}
	}
}

function kb_save_config($database,$input)
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST' || empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'],$_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	if (!is_array($input)) { kb_publication_fail('KB_config_invalid'); }
	$owner=new PhpbbKbConfigDatabase($database);$original=$GLOBALS['db'];
	try {
		$GLOBALS['db']=$owner;$owner->begin();$expected=kb_config_values($owner->settings,$input);$owner->references($expected);
		foreach (kb_config_editable_fields() as $key) {
			if ($expected[$key]!==$owner->settings[$key]) {
				$owner->sql_query('UPDATE '.KB_CONFIG_TABLE." SET config_value='".$owner->sql_escape($expected[$key])."' WHERE config_name='".$owner->sql_escape($key)."'");
				if ((int)$owner->sql_affectedrows()!==1) { kb_publication_fail('KB_config_failed'); }
			}
		}
		if ($owner->read_settings()!==$expected) { kb_publication_fail('KB_config_failed'); }
		$owner->references($expected);$owner->commit();return $expected;
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (PhpbbAclException $error) { kb_publication_fail('Not_Authorised'); }
	catch (Exception $error) { kb_publication_fail('KB_config_failed'); }
	catch (Error $error) { kb_publication_fail('KB_config_failed'); }
	finally { $GLOBALS['db']=$original;$owner->release(); }
}
