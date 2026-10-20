<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';

// Type administration has its own exact ACP grant, not category/article rights.
// Its shared writer owner serializes frontend reference checks and reassignment.
class PhpbbKbTypeDatabase extends PhpbbKbPublicationDatabase
{
	var $types = array();
	function __construct($database) { parent::__construct($database, 'type'); }
	function write_tables() { return array(KB_TYPES_TABLE,KB_ARTICLES_TABLE,KB_SEARCH_TABLE); }
	function read_types()
	{
		$types = array();
		foreach ($this->rows('SELECT id,type FROM ' . KB_TYPES_TABLE . ' ORDER BY id LIMIT 10001 FOR UPDATE') as $row) {
			$id = kb_counter_category_id($row['id']);
			if (array_key_exists($id,$types)) { kb_publication_fail('KB_type_invalid'); } $types[$id] = $row['type'];
		}
		if (count($types)>10000) { kb_publication_fail('KB_type_invalid'); } ksort($types,SORT_NUMERIC);return $types;
	}
	function references($types)
	{
		$counts = array();
		foreach ($this->rows('SELECT article_type,COUNT(*) AS total FROM ' . KB_ARTICLES_TABLE . ' GROUP BY article_type') as $row) {
			$id = kb_counter_category_id($row['article_type'],true);
			if ($id && !isset($types[$id])) { kb_publication_fail('KB_type_invalid'); }
			$counts[$id] = kb_counter_category_id($row['total'],true);
		}return $counts;
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION');$this->transactional=true;
		kb_publication_pin_tables($this,array_merge($this->write_tables(),array(USERS_TABLE,SESSIONS_TABLE,JR_ADMIN_TABLE,GROUPS_TABLE,USER_GROUP_TABLE)));
		$this->types=$this->read_types();$this->references($this->types);
		if ($this->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id IS NULL OR article_id<=0 OR article_id>16777215 LIMIT 1')
			|| $this->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' GROUP BY article_id HAVING COUNT(*)<>1 LIMIT 1')) { kb_publication_fail('KB_type_invalid'); }
		$this->actor();
	}
}

function kb_type_text($value)
{
	if (!is_string($value) || strlen($value)>1048576 || strpos($value,"\0")!==false || preg_match('//u',$value)!==1) { kb_publication_fail('KB_type_invalid'); }
	$value=trim($value);
	if (function_exists('mb_substr')) { return mb_substr($value,0,255,'UTF-8'); }
	preg_match('/^.{0,255}/us',$value,$match);return $match[0];
}

function kb_change_type($database,$action,$type_id,$input=array())
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD']!=='POST' || empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'],$_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	if (!is_string($action) || !in_array($action,array('create','edit','delete'),true) || !is_array($input)) { kb_publication_fail('KB_type_invalid'); }
	try {
		$type_id=kb_counter_category_id($type_id,$action==='create');
		if ($action==='create' && $type_id!==0) { kb_publication_fail('KB_type_invalid'); }
		if ($action==='delete') { $destination=kb_counter_category_id(isset($input['destination'])?$input['destination']:null,true); }
		else { $name=kb_type_text(isset($input['name'])?$input['name']:null);if($name===''){kb_publication_fail('Empty_type');} }
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (RuntimeException $error) { kb_publication_fail('KB_type_invalid'); }
	$owner=new PhpbbKbTypeDatabase($database);$original=$GLOBALS['db'];
	try {
		$GLOBALS['db']=$owner;$owner->begin();$expected=$owner->types;
		if ($action!=='create' && !isset($expected[$type_id])) { kb_publication_fail('Empty_type'); }
		if ($action==='create') {
			$owner->sql_query('INSERT INTO ' . KB_TYPES_TABLE . " (type) VALUES('" . $owner->sql_escape($name) . "')");
			$type_id=kb_counter_category_id((string)$owner->sql_nextid());
			if (isset($expected[$type_id])) { kb_publication_fail('KB_type_failed'); }$expected[$type_id]=$name;
		} elseif ($action==='edit') {
			$owner->sql_query('UPDATE ' . KB_TYPES_TABLE . " SET type='" . $owner->sql_escape($name) . "' WHERE id=" . $type_id);$expected[$type_id]=$name;
		} else {
			if ($destination===$type_id || ($destination && !isset($expected[$destination]))) { kb_publication_fail('KB_type_invalid'); }
			$counts=$owner->references($expected);$affected=isset($counts[$type_id])?$counts[$type_id]:0;
			$owner->sql_query('UPDATE ' . KB_ARTICLES_TABLE . ' SET article_type=' . $destination . ' WHERE article_type=' . $type_id);
			if ((int)$owner->sql_affectedrows()!==$affected) { kb_publication_fail('KB_type_failed'); }
			$owner->sql_query('DELETE FROM ' . KB_TYPES_TABLE . ' WHERE id=' . $type_id);
			if ((int)$owner->sql_affectedrows()!==1) { kb_publication_fail('KB_type_failed'); }unset($expected[$type_id]);
		}
		ksort($expected,SORT_NUMERIC);
		if ($owner->read_types()!==$expected) { kb_publication_fail('KB_type_failed'); }$owner->references($expected);
		// This ACP controller does not load the frontend functions_kb.php helpers.
		$owner->sql_query('DELETE FROM ' . KB_SEARCH_TABLE);
		$owner->commit();return array('type_id'=>$type_id);
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (PhpbbAclException $error) { kb_publication_fail('Not_Authorised'); }
	catch (Exception $error) { kb_publication_fail('KB_type_failed'); }
	catch (Error $error) { kb_publication_fail('KB_type_failed'); }
	finally { $GLOBALS['db']=$original;$owner->release(); }
}
