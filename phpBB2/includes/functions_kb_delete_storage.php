<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_kb_publication.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_attach.php';
require_once dirname(__DIR__) . '/attach_mod/includes/functions_delete.php';
require_once dirname(__FILE__) . '/functions_moderation.php';

// Reuse the core topic SQL/attachment cleanup worker with KB-specific authority.
// The article's stored relation is the capability, never a posted topic/forum ID.
class PhpbbKbDeleteDatabase extends PhpbbTopicRemovalDatabase
{
	var $owner;
	var $context;
	var $article_id;
	var $article = null;
	var $article_deleted = false;
	var $settings = array();
	function __construct($database, $article_id, $context)
	{
		$this->owner = new PhpbbKbPublicationDatabase($database, $context);
		$this->connection = $this->owner->connection; $this->context = $context;
		$this->article_id = kb_counter_category_id($article_id);
		// A KB article need not have a discussion or forum. Initialize the inherited
		// SQL/cleanup state directly, without inventing a forum or reusing its ACL.
		$this->policy = 'moderator'; $this->mode = 'newtopic';
		$this->forum_id = 0; $this->topic_id = 0; $this->required = array();
	}
	function fail($key) { kb_publication_fail('KB_deletion_failed'); }
	function control($sql) { return $this->owner->control($sql); }
	function actor() { return kb_publication_actor($this, $this->context); }
	function tables()
	{
		return array_unique(array_merge(parent::tables(), array(KB_ARTICLES_TABLE, KB_CATEGORIES_TABLE,
			KB_CONFIG_TABLE, KB_TYPES_TABLE, KB_WORD_TABLE, KB_MATCH_TABLE, KB_SEARCH_TABLE, KB_VOTES_TABLE,
			JR_ADMIN_TABLE, GROUPS_TABLE)));
	}
	function authorize()
	{
		$this->actor();
		if ($this->article === null) { $this->fail(''); }
		$rows = $this->rows('SELECT article_id,article_category_id,approved,topic_id FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $this->article_id);
		if ($this->article_deleted) { if ($rows) { $this->fail(''); } }
		else {
			if (count($rows) !== 1) { $this->fail(''); }
			foreach (array('article_category_id','approved','topic_id') as $key) {
				if ((string)$rows[0][$key] !== (string)$this->article[$key]) { $this->fail(''); }
			}
		}
		return true;
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		kb_publication_pin_tables($this, $this->tables());
		foreach ($this->rows('SELECT config_name,config_value FROM ' . KB_CONFIG_TABLE . ' ORDER BY config_name FOR UPDATE') as $row) { $this->settings[$row['config_name']] = $row['config_value']; }
		if (!isset($this->settings['del_topic']) || !in_array($this->settings['del_topic'], array('0','1'), true)) { $this->fail(''); }
		$categories = $this->rows('SELECT category_id,parent,number_articles FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id FOR UPDATE');
		try { $hierarchy = kb_counter_rebuild_plan($categories, array()); } catch (RuntimeException $error) { $this->fail(''); }
		$rows = $this->rows('SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $this->article_id . ' FOR UPDATE');
		if (count($rows) !== 1) { kb_publication_fail('Article_not_exsist'); } $this->article = $rows[0];
		$category = kb_counter_category_id($this->article['article_category_id']); $topic = kb_counter_category_id($this->article['topic_id'], true);
		if (!isset($hierarchy['expected'][$category]) || !in_array((string)$this->article['approved'], array('0','1','2'), true)) { $this->fail(''); }
		if ($this->settings['del_topic'] === '1' && $topic) {
			$references = $this->rows('SELECT article_id FROM ' . KB_ARTICLES_TABLE . ' WHERE topic_id=' . $topic . ' ORDER BY article_id FOR UPDATE');
			if (count($references) !== 1 || (int)$references[0]['article_id'] !== $this->article_id) { kb_publication_fail('KB_deletion_relation'); }
			$topics = $this->rows('SELECT topic_id,forum_id,topic_moved_id FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . $topic . ' FOR UPDATE');
			if (!$topics) {
				if ($this->rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE topic_id=' . $topic . ' LIMIT 1 FOR UPDATE')) { kb_publication_fail('KB_deletion_relation'); }
			} else {
				if (count($topics) !== 1 || (int)$topics[0]['topic_moved_id'] !== 0) { kb_publication_fail('KB_deletion_relation'); }
				$this->forum_id = kb_counter_category_id($topics[0]['forum_id']); $this->topic_id = $topic;
				$forums = $this->rows('SELECT forum_id,forum_link,count_posts FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $this->forum_id . ' FOR UPDATE');
				if (count($forums) !== 1 || (string)$forums[0]['forum_link'] !== '' || !in_array((string)$forums[0]['count_posts'], array('0','1'), true)) { kb_publication_fail('KB_deletion_relation'); }
			}
		}
		$this->authorize();
	}
	function commit()
	{
		if (!$this->article_deleted) { $this->fail(''); }
		kb_publication_lock_actor($this); $this->authorize();
		$this->control('COMMIT'); $this->transactional = false; $this->confirmed = true;
	}
	function release()
	{
		$this->rollback(); $this->owner->release(); $this->connection = null;
	}
}

function kb_delete_article($database, $article_id, $context)
{
	global $userdata;
	if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($userdata['session_id']) || !is_string($userdata['session_id'])
		|| !isset($_POST['sid']) || !is_string($_POST['sid']) || !hash_equals($userdata['session_id'], $_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	try { $article_id = kb_counter_category_id($article_id); } catch (RuntimeException $error) { kb_publication_fail('Article_not_exsist'); }
	$storage = new PhpbbKbDeleteDatabase($database, $article_id, $context); $original = $GLOBALS['db'];
	try {
		$GLOBALS['db'] = $storage; $storage->begin(); $article = $storage->article; $removed = array('topic_ids'=>array(),'post_ids'=>array());
		if ($storage->topic_id) {
			$removed = phpbb_delete_moderated_topics_owned($storage, $storage->forum_id, array($storage->topic_id));
			if ($removed['topic_ids'] !== array($storage->topic_id)) { kb_publication_fail('KB_deletion_relation'); }
			$storage->audit($removed['topic_ids']);
		}
		if ((int)$article['approved'] === 1) { update_kb_number((int)$article['article_category_id'], -1); }
		kb_remove_article_words($article_id);
		$storage->sql_query('DELETE FROM ' . KB_VOTES_TABLE . ' WHERE votes_file=' . $article_id);
		$storage->sql_query('DELETE FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $article_id);
		if ((int)$storage->sql_affectedrows() !== 1) { kb_publication_fail('KB_deletion_failed'); }
		$storage->article_deleted = true; $storage->authorize(); $storage->commit();
		return array('article'=>$article,'topic_ids'=>$removed['topic_ids'],'post_ids'=>$removed['post_ids'],'cleanup_pending'=>!$storage->cleanup());
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (PhpbbAclException $error) { kb_publication_fail('Not_Authorised'); }
	catch (Exception $error) { kb_publication_fail('KB_deletion_failed'); }
	catch (Error $error) { kb_publication_fail('KB_deletion_failed'); }
	finally { $GLOBALS['db'] = $original; $storage->release(); }
}
