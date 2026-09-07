<?php

// Execute the real result-query block against a disposable in-memory database.
// This exercises SQL joins, authorization, counts and LIMIT together, without
// changing forum permissions or content on an installed board.
require dirname(dirname(__DIR__)) . '/phpBB2/includes/functions_search.php';
define('AUTH_ALL', 0);
define('AUTH_LIST_ALL', 0);
define('GENERAL_MESSAGE', 0);
define('GENERAL_ERROR', 1);
foreach (array('FORUMS', 'TOPICS', 'USERS', 'POSTS', 'POSTS_TEXT') as $table)
{
	define($table . '_TABLE', strtolower($table));
}

class SearchRuntimeDatabase
{
	public $connection;
	public function __construct()
	{
		$this->connection = new PDO('sqlite::memory:');
		$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	public function sql_query($sql) { return $this->connection->query($sql); }
	public function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	public function sql_freeresult($result) { $result->closeCursor(); }
}
function auth($type, $forum_id, $userdata)
{
	return $GLOBALS['search_runtime_permissions'];
}
function message_die($type, $message)
{
	throw new RuntimeException($message);
}
function AJAX_message_die($data)
{
	throw new RuntimeException('ajax:' . json_encode($data));
}
function search_runtime_assert($condition, $message)
{
	if (!$condition)
	{
		fwrite(STDERR, "Search result runtime test failed: $message\n");
		exit(1);
	}
}

$db = new SearchRuntimeDatabase();
$db->sql_query('CREATE TABLE forums (forum_id INTEGER PRIMARY KEY, forum_name TEXT)');
$db->sql_query('CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT, user_sig TEXT, user_sig_bbcode_uid TEXT)');
$db->sql_query('CREATE TABLE topics (topic_id INTEGER PRIMARY KEY, forum_id INTEGER, topic_poster INTEGER, topic_first_post_id INTEGER, topic_last_post_id INTEGER, topic_title TEXT)');
$db->sql_query('CREATE TABLE posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, forum_id INTEGER, poster_id INTEGER, post_username TEXT, post_time INTEGER)');
$db->sql_query('CREATE TABLE posts_text (post_id INTEGER PRIMARY KEY, post_text TEXT, bbcode_uid TEXT, post_subject TEXT)');
$db->sql_query("INSERT INTO users VALUES (1, 'Author', '', '')");
$db->sql_query("INSERT INTO forums VALUES (1, 'Public'), (2, 'Private'), (3, 'Hidden')");
// First four are public; the rest are unreadable or hidden. No post text for 7.
foreach (array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 1) as $id => $forum)
{
	$db->sql_query("INSERT INTO topics VALUES ($id, $forum, 1, $id, $id, 'Topic $id')");
	$db->sql_query("INSERT INTO posts VALUES ($id, $id, $forum, 1, '', $id)");
	if ($id !== 7)
	{
		$db->sql_query("INSERT INTO posts_text VALUES ($id, 'Text $id', '', 'Subject $id')");
	}
}
$search_runtime_permissions = array(
	1 => array('auth_view' => true, 'auth_read' => true),
	2 => array('auth_view' => true, 'auth_read' => false),
	3 => array('auth_view' => false, 'auth_read' => true),
);
$lang = array('No_search_match' => 'none', 'No_Bookmarks' => 'no bookmarks');
$source = file_get_contents(dirname(dirname(__DIR__)) . '/phpBB2/search.php');
$begin = strpos($source, "\tif ( \$search_results != '' )", strpos($source, '// Look up data'));
$end = strpos($source, '// Define censored word matches', $begin);
search_runtime_assert($begin !== false && $end !== false, 'actual result query block must be located');
$query_block = substr($source, $begin, $end - $begin) . "\n}";

function search_runtime_results($mode, $ids, $offset = 0, $ajax = 0, $sort = 0)
{
	global $db, $lang, $query_block;
	$search_results = $ids;
	$show_results = $mode;
	$start = $offset;
	$is_ajax = $ajax;
	$userdata = array();
	$board_config = array('posts_per_page' => 2, 'topics_per_page' => 2);
	$sort_by = $sort;
	$sort_dir = 'ASC';
	eval($query_block);
	return array('rows' => $searchset, 'total' => $total_match_count, 'start' => $start);
}

foreach (array('posts', 'topics', 'bookmarks') as $mode)
{
	$result = search_runtime_results($mode, '1,2,3,4,5,6,999');
	search_runtime_assert($result['total'] === 4 && count($result['rows']) === 2, "$mode must count only current authorized rows before LIMIT");
	search_runtime_assert((int) $result['rows'][0]['topic_id'] === 1, "$mode must sort the authorized result set");
	$result = search_runtime_results($mode, '1,2,3,4,5,6,999', 200);
	search_runtime_assert($result['start'] === 2 && (int) $result['rows'][0]['topic_id'] === 3, "$mode must clamp stale page offsets to the last nonempty page");
	for ($sort = 1; $sort <= 4; $sort++)
	{
		$result = search_runtime_results($mode, '1,2,3,4,5,6', 0, 0, $sort);
		search_runtime_assert($result['total'] === 4, "$mode alternative sort $sort must retain authorization");
	}
}
$result = search_runtime_results('posts', '1,7,999');
search_runtime_assert($result['total'] === 1, 'missing post text and deleted IDs must not inflate the count');
$db->sql_query('UPDATE topics SET forum_id = 2 WHERE topic_id = 4');
$result = search_runtime_results('posts', '1,2,3,4');
search_runtime_assert($result['total'] === 3, 'a moved topic with stale post forum IDs must not leak into results');
$search_runtime_permissions[1]['auth_read'] = false;
try
{
	search_runtime_results('posts', '1,2,3,4');
	search_runtime_assert(false, 'revoked permission must not return cached results');
}
catch (RuntimeException $error)
{
	search_runtime_assert($error->getMessage() === 'none', 'revoked permissions must give the standard empty result');
}
try
{
	search_runtime_results('posts', '1,2,3,4', 0, 1);
	search_runtime_assert(false, 'empty AJAX searches must terminate with a response');
}
catch (RuntimeException $error)
{
	search_runtime_assert($error->getMessage() === 'ajax:{"search_id":0,"results":0,"keywords":""}', 'empty AJAX searches must retain their JSON contract');
}
echo "Search result runtime tests passed.\n";
