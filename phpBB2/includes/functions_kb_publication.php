<?php
if (!defined('IN_PHPBB')) { die('Hacking attempt'); }
require_once dirname(__FILE__) . '/functions_acl_storage.php';
require_once dirname(__FILE__) . '/functions_kb_counter_policy.php';

class PhpbbKbPublicationException extends RuntimeException {}
function kb_publication_fail($key = 'KB_publication_failed')
{
	global $lang;
	throw new PhpbbKbPublicationException(isset($lang[$key]) ? $lang[$key] : $key);
}

// Approval owns its SQL effects, not the caller's connection/transaction.
// The shared forum writer lock also coordinates optional discussion creation.
// Article deletion and its physical attachment cleanup are NOT handled here.
class PhpbbKbPublicationDatabase extends PhpbbAclDatabase
{
	var $identity = '';
	var $context;
	var $transactional = false;
	var $finished = false;
	function __construct($database, $context)
	{
		if (!in_array($context, array('acp', 'moderator'), true) || !method_exists($database, 'sql_dedicated_connection')) { kb_publication_fail(); }
		$this->context = $context; $candidate = null;
		try {
			$source = phpbb_acl_rows($database, 'SELECT CONNECTION_ID() AS kb_connection_id');
			if (count($source) !== 1) { kb_publication_fail(); }
			$candidate = $database->sql_dedicated_connection();
			if (!$candidate || !$candidate->db_connect_id) { kb_publication_fail(); }
			$rows = phpbb_acl_rows($candidate, 'SELECT CONNECTION_ID() AS kb_connection_id');
			if (count($rows) !== 1 || (string)$rows[0]['kb_connection_id'] === (string)$source[0]['kb_connection_id']) {
				// A broken factory must never close/commit/roll back its caller.
				$candidate = null; kb_publication_fail();
			}
			$this->connection = $candidate; $this->identity = (string)$rows[0]['kb_connection_id'];
			register_shutdown_function(array($this, 'release'));
			$name = 'attachment:' . md5($database->dbname . "\0" . ATTACHMENTS_TABLE);
			$rows = $this->rows("SELECT GET_LOCK('" . $name . "', 10) AS acquired");
			if (count($rows) !== 1 || (int)$rows[0]['acquired'] !== 1) { kb_publication_fail(); }
		} catch (Exception $error) {
			if ($this->connection) { $this->release(); } elseif ($candidate) { $candidate->sql_close(); }
			kb_publication_fail();
		} catch (Error $error) {
			if ($this->connection) { $this->release(); } elseif ($candidate) { $candidate->sql_close(); }
			kb_publication_fail();
		}
	}
	function control($sql)
	{
		if (!$this->connection || $this->finished) { kb_publication_fail(); }
		try {
			$r = $this->connection->sql_query('SELECT CONNECTION_ID() AS kb_connection_id');
			$row = $r ? $this->connection->sql_fetchrow($r) : false;
			if ($r) { $this->connection->sql_freeresult($r); }
			if (!$row || (string)$row['kb_connection_id'] !== $this->identity) { kb_publication_fail(); }
			$result = $this->connection->sql_query($sql);
		} catch (Exception $error) { kb_publication_fail(); }
		catch (Error $error) { kb_publication_fail(); }
		if (!$result) { kb_publication_fail(); } return $result;
	}
	function rows($sql)
	{
		$r = $this->sql_query($sql); $rows = $this->sql_fetchrowset($r); $this->sql_freeresult($r); return $rows;
	}
	function sql_query($sql, $transaction = false)
	{
		if (!is_string($sql) || !preg_match('/^\s*(SELECT|UPDATE|INSERT|DELETE)\b/i', $sql, $command)) { kb_publication_fail(); }
		if (strtoupper($command[1]) !== 'SELECT') {
			if (!$this->transactional || !preg_match('/^\s*(?:UPDATE\s+|INSERT\s+(?:IGNORE\s+)?INTO\s+|DELETE\s+FROM\s+)([A-Za-z0-9_]+)\b/i', $sql, $table)
				|| !in_array($table[1], $this->write_tables(), true)) { kb_publication_fail(); }
			$this->actor();
		}
		return $this->control($sql);
	}
	function write_tables()
	{
		return array(KB_ARTICLES_TABLE, KB_CATEGORIES_TABLE, KB_WORD_TABLE, KB_MATCH_TABLE, KB_SEARCH_TABLE,
			TOPICS_TABLE, POSTS_TABLE, POSTS_TEXT_TABLE, FORUMS_TABLE, USERS_TABLE, SEARCH_WORD_TABLE, SEARCH_MATCH_TABLE);
	}
	function actor()
	{
		global $userdata, $phpEx;
		if ($this->context === 'acp') { return phpbb_acp_actor($this, 'admin_kb_art.' . $phpEx); }
		$user = phpbb_current_moderator_user($this);
		if (!$user || empty($userdata['session_id']) || !is_string($userdata['session_id'])) { kb_publication_fail('Not_Authorised'); }
		$id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		if (count($this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') AND session_user_id=" . $id . ' AND session_logged_in=1')) !== 1) { kb_publication_fail('Not_Authorised'); }
		if ((int)$user['user_level'] !== ADMIN) {
			$config = $this->rows("SELECT config_value FROM " . KB_CONFIG_TABLE . " WHERE config_name='mod_group'");
			if (count($config) !== 1) { kb_publication_fail('Not_Authorised'); }
			try { $group = kb_counter_category_id($config[0]['config_value']); } catch (RuntimeException $error) { kb_publication_fail('Not_Authorised'); }
			if (count($this->rows('SELECT g.group_id FROM ' . GROUPS_TABLE . ' g INNER JOIN ' . USER_GROUP_TABLE . ' m ON m.group_id=g.group_id WHERE g.group_id=' . $group . ' AND m.user_id=' . $id . ' AND m.user_pending=0')) !== 1) { kb_publication_fail('Not_Authorised'); }
		}
		return $user;
	}
	function begin()
	{
		$this->actor();
		$this->control("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");
		$this->control('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
		$this->control('START TRANSACTION'); $this->transactional = true;
		$tables = array_unique(array_merge($this->write_tables(), array(KB_CONFIG_TABLE, KB_TYPES_TABLE, SESSIONS_TABLE, JR_ADMIN_TABLE, GROUPS_TABLE, USER_GROUP_TABLE)));
		foreach ($tables as $table) {
			if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) { kb_publication_fail(); }
			$r = $this->sql_query('SELECT * FROM ' . $table . ' LIMIT 0'); $this->sql_freeresult($r); $name = $this->sql_escape($table);
			$metadata = $this->rows("SELECT ENGINE,ROW_FORMAT,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $name . "') AND TABLE_TYPE='BASE TABLE'");
			// Bundled KB text and the core word dictionary intentionally use bin.
			$binary = in_array($table, array(KB_ARTICLES_TABLE, KB_CATEGORIES_TABLE, KB_TYPES_TABLE, KB_WORD_TABLE), true)
				? "COLLATION_NAME NOT IN ('utf8mb4_unicode_ci','utf8mb4_bin')"
				: ($table === SEARCH_WORD_TABLE ? "(COLLATION_NAME<>'utf8mb4_unicode_ci' AND NOT (COLUMN_NAME='word_text' AND COLLATION_NAME='utf8mb4_bin'))" : "COLLATION_NAME<>'utf8mb4_unicode_ci'");
			$legacy = $this->rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND HEX(TABLE_NAME)=HEX('" . $name . "') AND CHARACTER_SET_NAME IS NOT NULL AND (CHARACTER_SET_NAME<>'utf8mb4' OR " . $binary . ')');
			if (count($metadata) !== 1 || $metadata[0]['ENGINE'] !== 'InnoDB' || strtolower($metadata[0]['ROW_FORMAT']) !== 'dynamic' || $metadata[0]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $legacy) { kb_publication_fail('KB_publication_upgrade'); }
		}
		$this->actor();
	}
	function commit()
	{
		global $userdata;
		$user = $this->actor(); $id = (int)$user['user_id']; $sid = $this->sql_escape($userdata['session_id']);
		$this->rows('SELECT session_id FROM ' . SESSIONS_TABLE . " WHERE session_id='" . $sid . "' AND HEX(session_id)=HEX('" . $sid . "') LOCK IN SHARE MODE");
		$this->rows('SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id=' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT user_id FROM ' . JR_ADMIN_TABLE . ' WHERE user_id=' . $id . ' LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . USER_GROUP_TABLE . ' WHERE user_id=' . $id . ' ORDER BY group_id LOCK IN SHARE MODE');
		$this->rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_id IN (SELECT group_id FROM " . USER_GROUP_TABLE . ' WHERE user_id=' . $id . ') ORDER BY group_id LOCK IN SHARE MODE');
		$this->actor(); $this->control('COMMIT'); $this->transactional = false; $this->finished = true;
	}
	function release()
	{
		$connection = $this->connection; $this->connection = null; $this->finished = true;
		if ($connection) {
			if ($this->transactional) { try { $connection->sql_query('ROLLBACK'); } catch (Exception $ignored) {} catch (Error $ignored) {} }
			$this->transactional = false;
			try { $connection->sql_close(); } catch (Exception $ignored) {} catch (Error $ignored) {}
		}
	}
}

function kb_publish_approval($database, $article_id, $approved, $context)
{
	global $userdata;
	if (!in_array($approved, array(0, 1), true) || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST'
		|| empty($userdata['session_id']) || !is_string($userdata['session_id']) || !isset($_POST['sid']) || !is_string($_POST['sid'])
		|| !hash_equals($userdata['session_id'], $_POST['sid'])) { kb_publication_fail('Session_invalid'); }
	try { $article_id = kb_counter_category_id($article_id); } catch (RuntimeException $error) { kb_publication_fail('Article_not_exsist'); }
	$owner = new PhpbbKbPublicationDatabase($database, $context);
	$original_db = $GLOBALS['db']; $original_config = isset($GLOBALS['kb_config']) ? $GLOBALS['kb_config'] : array();
	try {
		$GLOBALS['db'] = $owner; $owner->begin();
		$config = array(); foreach ($owner->rows('SELECT config_name,config_value FROM ' . KB_CONFIG_TABLE . ' ORDER BY config_name FOR UPDATE') as $row) { $config[$row['config_name']] = $row['config_value']; }
		if (!isset($config['comments']) || !in_array($config['comments'], array('0','1'), true)) { kb_publication_fail(); }
		$GLOBALS['kb_config'] = $config;
		$categories = $owner->rows('SELECT category_id,parent,number_articles FROM ' . KB_CATEGORIES_TABLE . ' ORDER BY category_id FOR UPDATE');
		try { $hierarchy = kb_counter_rebuild_plan($categories, array()); } catch (RuntimeException $error) { kb_publication_fail(); }
		$rows = $owner->rows('SELECT * FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $article_id . ' FOR UPDATE');
		if (count($rows) !== 1) { kb_publication_fail('Article_not_exsist'); } $article = $rows[0];
		$category_id = kb_counter_category_id($article['article_category_id']); $topic_id = kb_counter_category_id($article['topic_id'], true);
		if (!in_array((string)$article['approved'], array('0','1','2'), true) || !isset($hierarchy['expected'][$category_id])) { kb_publication_fail(); }
		if ($approved && !empty($config['comments']) && !$topic_id) { $topic_id = kb_approval_discussion($owner, $article, $config); }
		$owner->sql_query('UPDATE ' . KB_ARTICLES_TABLE . ' SET approved=' . $approved . ',topic_id=' . $topic_id . ' WHERE article_id=' . $article_id);
		if (((int)$article['approved'] === 1) !== ($approved === 1)) { update_kb_number((int)$article['article_category_id'], $approved ? 1 : -1); }
		// Rebuild even on a repeated approval, so an old missing index can recover.
		if ($approved) { add_kb_words($article_id, $article['article_body'], $article['article_title']); }
		else { kb_remove_article_words($article_id); }
		$stored = $owner->rows('SELECT approved,topic_id FROM ' . KB_ARTICLES_TABLE . ' WHERE article_id=' . $article_id);
		if (count($stored) !== 1 || (int)$stored[0]['approved'] !== $approved || (int)$stored[0]['topic_id'] !== $topic_id) { kb_publication_fail(); }
		$owner->commit(); $article['approved'] = $approved; $article['topic_id'] = $topic_id; return $article;
	} catch (PhpbbKbPublicationException $error) { throw $error; }
	catch (PhpbbAclException $error) { kb_publication_fail('Not_Authorised'); }
	catch (Exception $error) { kb_publication_fail(); }
	catch (Error $error) { kb_publication_fail(); }
	finally { $GLOBALS['db'] = $original_db; $GLOBALS['kb_config'] = $original_config; $owner->release(); }
}

function kb_approval_discussion($owner, $article, $config)
{
	global $lang, $phpEx;
	try { $forum_id = kb_counter_category_id(isset($config['forum_id']) ? $config['forum_id'] : null); $poster_id = kb_counter_category_id(isset($config['admin_id']) ? $config['admin_id'] : null); }
	catch (RuntimeException $error) { kb_publication_fail(); }
	$forums = $owner->rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $forum_id . ' FOR UPDATE');
	$users = $owner->rows('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . $poster_id . ' FOR UPDATE');
	if (count($forums) !== 1 || count($users) !== 1 || empty($users[0]['user_active'])) { kb_publication_fail(); } $user = $users[0];
	$category = get_kb_cat($article['article_category_id']); $type = get_kb_type($article['article_type']); $author = get_kb_author($article['article_author_id']);
	$decode = function($text) { return preg_replace(array("'&(quot|#34);'i", "'&(amp|#38);'i", "'&(lt|#60);'i", "'&(gt|#62);'i"), array('"', '&', '<', '>'), $text); };
	$message = '[b]' . $lang['Category'] . ':[/b] ' . $category['category_name'] . "\n";
	$message .= '[b]' . $lang['Article_type'] . ':[/b] ' . $type . "\n\n";
	$message .= '[b]' . $lang['Article_title'] . ':[/b] ' . $decode($article['article_title']) . "\n";
	$message .= '[b]' . $lang['Author'] . ':[/b] ' . $author . "\n";
	$message .= '[b]' . $lang['Article_description'] . ':[/b] ' . $decode($article['article_description']) . "\n\n";
	$message .= '[b][url=' . phpbb_board_url('kb.' . $phpEx . '?mode=article&k=' . (int)$article['article_id']) . ']' . $lang['Read_full_article'] . '[/url][/b]';
	// No preference switch, notification or filesystem side effect before COMMIT.
	$created = insert_post($message, '[ KB ] ' . $article['article_title'], $forum_id, $poster_id, $user['username'], $user['user_attachsig']);
	if (!is_array($created) || empty($created['topic_id']) || empty($created['post_id'])) { kb_publication_fail(); }
	return (int)$created['topic_id'];
}
