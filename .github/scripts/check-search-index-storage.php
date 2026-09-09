<?php
// Real index SQL on disposable tables; never bootstrap an installed forum.
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'includes/functions_search.php';
define('GENERAL_ERROR', 202);
define('SQL_LAYER', 'mysqli');
define('POSTS_TABLE', 'fixture_posts');
define('SEARCH_WORD_TABLE', 'fixture_words');
define('SEARCH_MATCH_TABLE', 'fixture_matches');
define('CONFIG_TABLE', 'fixture_config');
function message_die($type, $message) { throw new RuntimeException($message); }
function index_check($condition, $message)
{
	if (!$condition) { throw new RuntimeException($message); }
}
function index_failure($callback, $message)
{
	$caught = false;
	try { call_user_func($callback); } catch (RuntimeException $error) { $caught = $error->getMessage() === $message; }
	index_check($caught, 'Expected controlled failure: ' . $message);
}
class IndexUnusedDatabase
{
	function sql_query($sql) { throw new RuntimeException('Unexpected global connection'); }
}
class IndexDatabase
{
	var $pdo; var $queries = array(); var $affected = 0; var $failure = ''; var $hook;
	function __construct()
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_posts (post_id INTEGER PRIMARY KEY)');
		$this->pdo->exec('CREATE TABLE fixture_config (config_name VARCHAR(64) PRIMARY KEY, config_value VARCHAR(255))');
		$this->pdo->exec('CREATE TABLE fixture_words (word_id INTEGER PRIMARY KEY AUTOINCREMENT, word_text VARCHAR(255) UNIQUE, word_common INTEGER NOT NULL DEFAULT 0)');
		$this->pdo->exec('CREATE TABLE fixture_matches (post_id INTEGER, word_id INTEGER, title_match INTEGER, UNIQUE(post_id, word_id, title_match))');
	}
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if (is_callable($this->hook)) { call_user_func($this->hook, $sql, $this); }
		if ($this->failure !== '' && strpos($sql, $this->failure) === 0) { return false; }
		if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') { $sql = str_replace('INSERT IGNORE INTO ', 'INSERT OR IGNORE INTO ', $sql); }
		$result = $this->pdo->query($sql);
		$this->affected = $result->rowCount();
		return $result;
	}
	function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($result) { $result->closeCursor(); }
	function sql_affectedrows() { return $this->affected; }
	function sql_escape($value) { return substr($this->pdo->quote($value), 1, -1); }
	function scalar($sql) { return (int) $this->pdo->query($sql)->fetchColumn(); }
}
function index_fixture()
{
	$storage = new IndexDatabase();
	$storage->pdo->exec("INSERT INTO fixture_words VALUES (1,'sharedword',0),(2,'titleonly',0),(3,'foreignword',0),(4,'batchword',0),(5,'commonword',1)");
	$storage->pdo->exec('INSERT INTO fixture_matches VALUES (10,1,1),(10,1,0),(10,2,1),(11,3,0),(10,4,0),(12,4,0),(10,5,0)');
	return $storage;
}
$db = new IndexUnusedDatabase();
$phpbb_root_path = $forum_root;
$board_config = array('default_lang' => 'english');
$lang = array();
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } return false; });
try
{
	index_check(phpbb_search_ascii_lower('Grüße ÄÖÜ AND 😀') === 'grüße ÄÖÜ and 😀', 'ASCII folding preserves UTF-8 bytes regardless of runtime locale');
	$stopwords = array(); $synonyms = array();
	index_check(trim(clean_words('post', 'Grüße', $stopwords, $synonyms)) === 'grüße', 'Real tokenizer preserves non-ASCII bytes');
	$storage = index_fixture();
	index_check(remove_search_post(10, true, false, $storage) === 1, 'Remove only the now-unused title word');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words WHERE word_id = 1') === 1, 'Title deletion preserves a word still used in the body');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE post_id = 10 AND title_match = 1') === 0, 'Only selected title references disappear');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE post_id = 10 AND title_match = 0') === 3, 'Body references survive title edits');
	index_check(remove_search_post('010, 12,10', false, true, $storage) === 2, 'Batch deletion collects words used more than once inside the selection');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words') === 2, 'Retain foreign vocabulary and common-word markers');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches') === 1, 'Retain the unrelated post index');

	$storage = index_fixture();
	remove_search_post(10, false, true, $storage);
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words WHERE word_id = 1') === 1, 'Body deletion preserves a surviving title');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words WHERE word_id = 4') === 1, 'Body deletion preserves another post reference');
	index_check(remove_search_post(999, true, true, $storage) === false, 'Unindexed post does not emit an empty IN clause');
	$storage->queries = array();
	index_check(remove_search_post('', true, true, $storage) === false && remove_search_post(array(), true, true, $storage) === false, 'Empty selections are harmless');
	index_check(remove_search_post(10, false, false, $storage) === false && !$storage->queries, 'Both filters disabled performs no SQL');
	foreach (array(0, -1, null, true, 1.2, array(10,array(11)), '10,', '10 OR 1=1', '99999999999999999999999999999999999') as $invalid)
	{
		index_failure(function () use ($invalid, $storage) { remove_search_post($invalid, true, true, $storage); }, 'Invalid search index post selection');
	}
	index_check(!$storage->queries, 'Malformed IDs never reach SQL');
	index_failure(function () use ($storage) { add_search_words('single', '10,11', 'quasarword', '', $storage); }, 'Invalid search index post selection');

	foreach (array('SELECT DISTINCT' => 'Could not obtain search word matches', 'DELETE FROM fixture_matches' => 'Could not delete word match entry', 'DELETE FROM fixture_words' => 'Could not delete word list entry') as $prefix => $message)
	{
		$storage = index_fixture(); $storage->failure = $prefix;
		index_failure(function () use ($storage) { remove_search_post(10, true, true, $storage); }, $message);
		index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words') === 5, 'Failed cleanup never deletes vocabulary ahead of references');
		index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches') === ($prefix === 'DELETE FROM fixture_words' ? 2 : 7), 'Only a successfully executed reference delete changes matches');
	}
	$storage = index_fixture();
	$storage->hook = function ($sql, $database)
	{
		if (strpos($sql, 'DELETE FROM fixture_words') === 0) { $database->pdo->exec('INSERT INTO fixture_matches VALUES (99,2,0)'); }
	};
	remove_search_post(10, true, false, $storage);
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words WHERE word_id = 2') === 1, 'Recheck all current references at vocabulary deletion, not a stale count');

	foreach (array('english', 'german') as $language)
	{
		$storage = new IndexDatabase(); $board_config['default_lang'] = $language;
		$storage->pdo->exec("INSERT INTO fixture_words (word_text,word_common) VALUES ('commonword',1)");
		add_search_words('single', 10, 'quasarwort commonword Grüße', 'quasarwort titelwort', $storage);
		index_check($storage->scalar("SELECT COUNT(*) FROM fixture_matches m JOIN fixture_words w ON m.word_id=w.word_id WHERE w.word_text='grüße'") === 1, 'Unicode word bytes survive storage and match publication');
		index_check($storage->scalar("SELECT COUNT(*) FROM fixture_matches m JOIN fixture_words w ON m.word_id=w.word_id WHERE w.word_text='quasarwort'") === 2, 'Index title and body separately');
		index_check($storage->scalar("SELECT COUNT(*) FROM fixture_matches m JOIN fixture_words w ON m.word_id=w.word_id WHERE w.word_common=1") === 0, 'Do not republish matches for known common words');
		remove_search_post(10, true, true, $storage);
		index_check($storage->scalar('SELECT COUNT(*) FROM fixture_words') === 1, 'Round trip keeps only common-word marker');
	}
	$storage = new IndexDatabase();
	for ($i = 1; $i <= 100; $i++) { $storage->pdo->exec('INSERT INTO fixture_posts VALUES (' . $i . ')'); }
	$storage->pdo->exec("INSERT INTO fixture_words VALUES (1,'thirtyword',0),(2,'fortyoneword',0),(3,'atom''probe',0)");
	for ($i = 1; $i <= 41; $i++)
	{
		$storage->pdo->exec('INSERT INTO fixture_matches VALUES (' . $i . ',2,0),(' . $i . ',2,1),(' . $i . ',3,0)');
		if ($i <= 30) { $storage->pdo->exec('INSERT INTO fixture_matches VALUES (' . $i . ',1,0),(' . $i . ',1,1)'); }
	}
	remove_common('single', .4, array('thirtyword'), $storage);
	index_check($storage->scalar('SELECT word_common FROM fixture_words WHERE word_id = 1') === 0, 'Title plus body must count as one post, not two');
	remove_common('single', .4, array("atom'probe"), $storage);
	index_check($storage->scalar('SELECT word_common FROM fixture_words WHERE word_id = 3') === 1, 'Escape dictionary words through the selected driver');
	$storage->queries = array();
	remove_common('single', .4, array(), $storage);
	index_check(!$storage->queries, 'Empty single-post vocabulary is not a global reclassification');
	$storage->pdo->exec("INSERT INTO fixture_config VALUES ('dbmtnc_rebuild_job','{\"s\":\"run\"}')");
	remove_common('global', .4, array(), $storage);
	index_check($storage->scalar('SELECT word_common FROM fixture_words WHERE word_id=2')===0&&$storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE word_id=2')===82,'Normal posting does not prune an active rebuild');
	$storage->pdo->exec("UPDATE fixture_config SET config_value=''");
	$storage->hook=function($sql,$database){if(strpos($sql,'UPDATE fixture_words')===0){$database->hook=null;$database->pdo->exec("UPDATE fixture_config SET config_value='{\"s\":\"run\"}'");}};
	remove_common('global', .4, array(), $storage);
	index_check($storage->scalar('SELECT word_common FROM fixture_words WHERE word_id=2')===0&&$storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE word_id=2')===82,'A rebuild started after candidate selection blocks both pruning writes');
	$storage->pdo->exec("UPDATE fixture_config SET config_value='{\"s\":\"done\"}'");
	remove_common('global', .4, array(), $storage);
	index_check($storage->scalar('SELECT word_common FROM fixture_words WHERE word_id = 2') === 1 && $storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE word_id = 2') === 0, 'Global threshold removes genuinely common matches');
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE word_id = 1') === 60, 'Uncommon search matches remain');
	$db = $storage;
	remove_search_post(1);
	index_check($storage->scalar('SELECT COUNT(*) FROM fixture_matches WHERE post_id = 1') === 0, 'Existing callers keep the default connection');
	echo "Search index storage, partial edits, common words and failure checks passed.\n";
}
finally { restore_error_handler(); }
