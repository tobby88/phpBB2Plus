<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_delete_storage.php';

// One category operation owns SQL, every selected article and the final counts.
// Reused topic workers accumulate file receipts; no nested owner or early unlink.
class PhpbbKbCategoryDatabase extends PhpbbKbDeleteDatabase
{
	var $categories = array();
	function __construct($database) { $this->initialize($database, 'category'); }
	function authorize()
	{
		if ($this->article === null) { $this->actor(); return true; }
		return parent::authorize();
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode=CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		kb_publication_pin_tables($this, $this->tables());
		foreach ($this->rows('SELECT config_name,config_value FROM ' . KB_CONFIG_TABLE . ' ORDER BY config_name FOR UPDATE') as $row) { $this->settings[$row['config_name']] = $row['config_value']; }
		if (!isset($this->settings['del_topic']) || !in_array($this->settings['del_topic'], array('0','1'), true)) { $this->fail(''); }
		$this->categories = $this->rows('SELECT category_id,category_name,category_details,number_articles,parent,cat_order FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id LIMIT 10001 FOR UPDATE');
		if (count($this->categories) > 10000) { $this->fail(''); }
		kb_counter_rebuild_plan($this->categories, $this->article_counts($this->categories));
		if ($this->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' GROUP BY article_id HAVING COUNT(*)<>1 LIMIT 1')) { $this->fail(''); }
		$this->authorize();
	}
	function article_counts($categories)
	{
		$valid = array(); foreach ($categories as $row) { $valid[kb_counter_category_id($row['category_id'])] = true; }
		$counts = array();
		foreach ($this->rows('SELECT article_category_id,approved,COUNT(*) AS total FROM ' . KB_ARTICLES_TABLE . ' GROUP BY article_category_id,approved') as $row) {
			$id = kb_counter_category_id($row['article_category_id']);
			if (!isset($valid[$id]) || !in_array((string)$row['approved'], array('0','1','2'), true)) { $this->fail(''); }
			if ((string)$row['approved'] === '1') { $counts[$id] = kb_counter_category_id($row['total'], true); }
		}
		return $counts;
	}
	function commit()
	{
		kb_publication_lock_actor($this); $this->authorize();
		$this->control('COMMIT'); $this->transactional = false; $this->confirmed = true;
	}
}

function kb_category_text($value)
{
	if (!is_string($value) || strlen($value) > 1048576 || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) { kb_publication_fail('KB_category_invalid'); }
	$value = trim($value);
	if (function_exists('mb_substr')) { return mb_substr($value, 0, 255, 'UTF-8'); }
	preg_match('/^.{0,255}/us', $value, $text); return $text[0];
}

function kb_category_projection($rows)
{
	$out = array();
	foreach ($rows as $row) {
		$id = kb_counter_category_id($row['category_id']);
		if (isset($out[$id])) { kb_publication_fail('KB_category_invalid'); }
		$out[$id] = array('category_name'=>$row['category_name'], 'category_details'=>$row['category_details'],
			'parent'=>$row['parent'] === null ? 0 : kb_counter_category_id($row['parent'], true), 'cat_order'=>kb_counter_category_id($row['cat_order'], true));
	}
	ksort($out, SORT_NUMERIC); return $out;
}

function kb_change_category($database, $action, $category_id, $input = array())
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	if (!is_string($action) || !in_array($action, array('create','edit','delete','up','down'), true) || !is_array($input)) { kb_publication_fail('KB_category_invalid'); }
	try {
		$category_id = kb_counter_category_id($category_id, $action === 'create');
		if ($action === 'create' && $category_id !== 0) { kb_publication_fail('KB_category_invalid'); }
		if ($action === 'create' || $action === 'edit') {
			if (!isset($input['name'], $input['description'], $input['parent'])) { kb_publication_fail('KB_category_invalid'); }
			$name = kb_category_text($input['name']); $description = kb_category_text($input['description']);
			$parent = kb_counter_category_id($input['parent'], true);
			if ($name === '') { kb_publication_fail('Empty_category'); }
		} elseif ($action === 'delete') { $destination = kb_counter_category_id(isset($input['destination']) ? $input['destination'] : null, true); }
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (RuntimeException $error) { kb_publication_fail('KB_category_invalid'); }
	$storage = new PhpbbKbCategoryDatabase($database); $original = $GLOBALS['db'];
	try {
		$GLOBALS['db'] = $storage; $storage->begin(); $rows = $storage->categories;
		$expected = kb_category_projection($rows); $hierarchy = kb_counter_rebuild_plan($rows, array());
		if ($action !== 'create' && !isset($expected[$category_id])) { kb_publication_fail('Empty_category'); }
		if ($action === 'create' || $action === 'edit') {
			if ($parent && !isset($expected[$parent])) { kb_publication_fail('KB_category_invalid'); }
			if ($action === 'create') {
				$order = 0; foreach ($expected as $row) { if ($row['parent'] === $parent) { $order = max($order, $row['cat_order']); } }
				if ($order > 16777205) { kb_publication_fail('KB_category_invalid'); } $order += 10;
				$storage->sql_query('INSERT INTO ' . KB_CATEGORIES_TABLE . " (category_name,category_details,number_articles,parent,cat_order) VALUES('" . $storage->sql_escape($name) . "','" . $storage->sql_escape($description) . "',0," . $parent . ',' . $order . ')');
				$category_id = kb_counter_category_id((string)$storage->sql_nextid());
				if (isset($expected[$category_id])) { kb_publication_fail('KB_category_invalid'); }
				$expected[$category_id] = array('category_name'=>$name,'category_details'=>$description,'parent'=>$parent,'cat_order'=>$order);
			} else {
				$expected[$category_id]['category_name'] = $name; $expected[$category_id]['category_details'] = $description; $expected[$category_id]['parent'] = $parent;
				// Validate the proposed complete hierarchy BEFORE its first write.
				$proposed = array(); foreach ($expected as $id=>$row) { $proposed[] = array('category_id'=>$id,'parent'=>$row['parent'],'number_articles'=>0); } kb_counter_rebuild_plan($proposed, array());
				$storage->sql_query('UPDATE ' . KB_CATEGORIES_TABLE . " SET category_name='" . $storage->sql_escape($name) . "',category_details='" . $storage->sql_escape($description) . "',parent=" . $parent . ' WHERE category_id=' . $category_id);
			}
		} elseif ($action === 'delete') {
			if ($destination === $category_id || ($destination && !isset($expected[$destination]))) { kb_publication_fail('KB_category_invalid'); }
			if ($destination) {
				$storage->sql_query('UPDATE ' . KB_ARTICLES_TABLE . ' SET article_category_id=' . $destination . ' WHERE article_category_id=' . $category_id);
			} else {
				$articles = $storage->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' WHERE article_category_id=' . $category_id . ' ORDER BY article_id FOR UPDATE');
				foreach ($articles as $article) {
					$storage->load_article($article['article_id'], $hierarchy);
					if ((int)$storage->article['article_category_id'] !== $category_id) { kb_publication_fail('KB_deletion_relation'); }
					kb_delete_article_owned($storage);
				}
			}
			$parent = $expected[$category_id]['parent'];
			foreach ($expected as $id=>$row) { if ($row['parent'] === $category_id) { $expected[$id]['parent'] = $parent; } }
			unset($expected[$category_id]);
			$storage->sql_query('UPDATE ' . KB_CATEGORIES_TABLE . ' SET parent=' . $parent . ' WHERE parent=' . $category_id);
			$storage->sql_query('DELETE FROM ' . KB_CATEGORIES_TABLE . ' WHERE category_id=' . $category_id);
			if ((int)$storage->sql_affectedrows() !== 1) { kb_publication_fail('KB_category_failed'); }
		} else {
			$parent = $expected[$category_id]['parent']; $siblings = array();
			foreach ($rows as $row) { $id = (int)$row['category_id']; if ($expected[$id]['parent'] === $parent) { $siblings[] = $id; } }
			usort($siblings, function($a, $b) use ($expected) { $x=$expected[$a]['cat_order']; $y=$expected[$b]['cat_order']; return $x===$y ? ($a<$b?-1:($a>$b?1:0)) : ($x<$y?-1:1); });
			$index = array_search($category_id, $siblings, true); $other = $action === 'up' ? $index-1 : $index+1;
			if (isset($siblings[$other])) { $siblings[$index]=$siblings[$other]; $siblings[$other]=$category_id; }
			foreach ($siblings as $index=>$id) {
				$order = ($index+1)*10; $expected[$id]['cat_order'] = $order;
				$storage->sql_query('UPDATE ' . KB_CATEGORIES_TABLE . ' SET cat_order=' . $order . ' WHERE category_id=' . $id);
			}
		}
		$actual = $storage->rows('SELECT category_id,category_name,category_details,number_articles,parent,cat_order FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id FOR UPDATE');
		ksort($expected, SORT_NUMERIC);
		if (kb_category_projection($actual) !== $expected) { kb_publication_fail('KB_category_failed'); }
		$plan = kb_counter_rebuild_plan($actual, $storage->article_counts($actual));
		foreach ($plan['changes'] as $id=>$change) { $storage->sql_query('UPDATE ' . KB_CATEGORIES_TABLE . ' SET number_articles=' . $change['new'] . ' WHERE category_id=' . $id); }
		$actual = $storage->rows('SELECT category_id,parent,number_articles FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id');
		if (kb_counter_rebuild_plan($actual, $storage->article_counts($actual))['changes']) { kb_publication_fail('KB_category_failed'); }
		kb_clear_search_cache(); $storage->commit();
		return array('category_id'=>$category_id,'cleanup_pending'=>!$storage->cleanup());
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (PhpbbAclException $error) { kb_publication_fail('Not_Authorised'); }
	catch (Exception $error) { kb_publication_fail('KB_category_failed'); }
	catch (Error $error) { kb_publication_fail('KB_category_failed'); }
	finally { $GLOBALS['db'] = $original; $storage->release(); }
}
