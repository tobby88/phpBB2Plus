<?php
define('IN_PHPBB', true);
define('GENERAL_ERROR', 202); define('GENERAL_MESSAGE', 200); define('END_TRANSACTION', 2);
define('FORUMS_TABLE', 'fixture_forums'); define('POSTS_TABLE', 'fixture_posts');
define('TOPICS_TABLE', 'fixture_topics'); define('USERS_TABLE', 'fixture_users');
define('BOOKMARK_TABLE', 'fixture_bookmarks'); define('TOPICS_WATCH_TABLE', 'fixture_watches');
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
require $forum_root . 'includes/functions_post.php';
require $forum_root . 'attach_mod/includes/functions_delete.php';
function board_stats() {}
function cache_tree($force = false) {}
class PostStatsFailure extends RuntimeException {}
function message_die($type, $message, $title = '', $line = 0, $file = '', $sql = '') { throw new PostStatsFailure($message); }
function stats_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function stats_expect_failure($callback)
{
	$caught = false;
	try { call_user_func($callback); } catch (PostStatsFailure $error) { $caught = true; }
	stats_check($caught, 'Expected explicit statistics/cleanup failure');
}
class PostStatsDatabase
{
	var $pdo; var $queries = array(); var $failure = ''; var $hook = null;
	function __construct($count_posts)
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY, count_posts INTEGER, forum_posts INTEGER, forum_topics INTEGER, forum_last_post_id INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_topics (topic_id INTEGER PRIMARY KEY, topic_moved_id INTEGER, topic_replies INTEGER, topic_first_post_id INTEGER, topic_last_post_id INTEGER, topic_vote INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_posts (post_id INTEGER PRIMARY KEY, topic_id INTEGER, forum_id INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, user_posts INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_bookmarks (topic_id INTEGER, user_id INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_watches (topic_id INTEGER, user_id INTEGER)');
		$this->pdo->exec('INSERT INTO fixture_forums VALUES (3,' . (int) $count_posts . ',5,2,15)');
		$this->pdo->exec('INSERT INTO fixture_topics VALUES (100,0,2,10,15,1),(101,0,1,20,21,0)');
		$this->pdo->exec('INSERT INTO fixture_posts VALUES (10,100,3),(12,100,3)');
		$this->pdo->exec('INSERT INTO fixture_users VALUES (8,9)');
		$this->pdo->exec('INSERT INTO fixture_bookmarks VALUES (100,8),(100,9),(101,8)');
		$this->pdo->exec('INSERT INTO fixture_watches VALUES (100,8),(100,9),(101,8)');
	}
	function sql_query($sql, $transaction = false)
	{
		$this->queries[] = $sql;
		if (is_callable($this->hook)) { call_user_func($this->hook, $sql); }
		if ($this->failure !== '' && strpos($sql, $this->failure) === 0) { return false; }
		return $this->pdo->query($sql);
	}
	function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($result) { $result->closeCursor(); }
	function value($sql) { return (int) $this->pdo->query($sql)->fetchColumn(); }
}
function stats_run($mode, $flags)
{
	$forum_id = 3; $topic_id = 100; $post_id = 15; $user_id = 8;
	update_post_stats($mode, $flags, $forum_id, $topic_id, $post_id, $user_id);
}
$lang = array('Topic_post_not_exist' => 'missing post');
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	$db = new PostStatsDatabase(1);
	stats_run('delete', array('first_post' => false, 'last_post' => true, 'last_topic' => true));
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3, 'Deleting last reply must preserve all topic bookmarks');
	stats_check($db->value('SELECT COUNT(*) FROM fixture_watches') === 3, 'Deleting last reply must preserve watches');
	foreach (array(0, 1) as $count_posts)
	{
		foreach (array('newtopic', 'reply', 'poll_delete') as $mode)
		{
			$db = new PostStatsDatabase($count_posts);
			stats_run($mode, array());
			stats_check($db->value('SELECT forum_posts FROM fixture_forums') === ($mode === 'poll_delete' ? 5 : 6), 'Forum post totals ignore user-count setting');
			stats_check($db->value('SELECT forum_topics FROM fixture_forums') === ($mode === 'newtopic' ? 3 : 2), 'Forum topic totals ignore user-count setting');
			stats_check($db->value('SELECT topic_replies FROM fixture_topics WHERE topic_id=100') === ($mode === 'reply' ? 3 : 2), 'Reply totals ignore user-count setting');
			stats_check($db->value('SELECT user_posts FROM fixture_users') === ($mode !== 'poll_delete' && $count_posts ? 10 : 9), 'Only opted-in forums increment personal post count');
			stats_check($db->value('SELECT topic_vote FROM fixture_topics WHERE topic_id=100') === ($mode === 'poll_delete' ? 0 : 1), 'Poll removal leaves post counts alone');
		}
		foreach (array(array(false, false), array(true, false), array(false, true), array(true, true)) as $flags)
		{
			$db = new PostStatsDatabase($count_posts);
			stats_run('delete', array('first_post' => $flags[0], 'last_post' => $flags[1], 'last_topic' => true));
			stats_check($db->value('SELECT forum_posts FROM fixture_forums') === 4, 'All deleted posts decrement forum total');
			stats_check($db->value('SELECT forum_topics FROM fixture_forums') === ($flags[0] && $flags[1] ? 1 : 2), 'Only whole-topic deletion decrements topic total');
			stats_check($db->value('SELECT topic_replies FROM fixture_topics WHERE topic_id=100') === ($flags[0] && $flags[1] ? 2 : 1), 'All deleted replies decrement reply total');
			stats_check($db->value('SELECT user_posts FROM fixture_users') === ($count_posts ? 8 : 9), 'Only counted posts decrement personal count');
			stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3, 'Statistics never delete topic preferences');
		}
	}
	$db = new PostStatsDatabase(1);
	$db->pdo->exec('UPDATE fixture_forums SET forum_posts=0,forum_topics=0');
	$db->pdo->exec('UPDATE fixture_users SET user_posts=0');
	$db->pdo->exec('UPDATE fixture_topics SET topic_replies=0');
	stats_run('delete', array('first_post'=>false,'last_post'=>false,'last_topic'=>false));
	stats_check($db->value('SELECT forum_posts FROM fixture_forums') === 0 && $db->value('SELECT user_posts FROM fixture_users') === 0 && $db->value('SELECT topic_replies FROM fixture_topics WHERE topic_id=100') === 0, 'Stale low counters cannot become negative');
	foreach (array(false, true) as $missing)
	{
		$db = new PostStatsDatabase(1);
		if ($missing) { $db->pdo->exec('DELETE FROM fixture_forums'); } else { $db->failure = 'SELECT count_posts'; }
		stats_expect_failure(function () { stats_run('reply', array()); });
		stats_check(count($db->queries) === 1, 'Unavailable forum stops all counter writes');
	}

	$db = new PostStatsDatabase(1);
	phpbb_cleanup_removed_topic_preferences($db, 100);
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3 && $db->value('SELECT COUNT(*) FROM fixture_watches') === 3, 'Existing topic retains all preferences');
	$db->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=100');
	phpbb_cleanup_removed_topic_preferences($db, '100');
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 1 && $db->value('SELECT COUNT(*) FROM fixture_watches') === 1, 'Removed topic cleanup retains other topics');
	phpbb_cleanup_removed_topic_preferences($db, 100);
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 1, 'Cleanup is idempotent');
	foreach (array(0, -1, null, true, '100,100', array(100), '999999999999999999999999') as $invalid)
	{
		$db = new PostStatsDatabase(1);
		stats_expect_failure(function () use ($db, $invalid) { phpbb_cleanup_removed_topic_preferences($db, $invalid); });
		stats_check(!$db->queries, 'Reject malformed preference scope before SQL');
	}
	$db = new PostStatsDatabase(1); $db->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=100');
	$db->hook = function ($sql) use ($db)
	{
		if (strpos($sql, 'DELETE FROM fixture_watches') === 0) { $db->pdo->exec('INSERT INTO fixture_topics VALUES(100,0,0,10,10,0)'); }
	};
	phpbb_cleanup_removed_topic_preferences($db, 100);
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3 && $db->value('SELECT COUNT(*) FROM fixture_watches') === 3, 'Each cleanup write checks current topic absence');
	$db = new PostStatsDatabase(1); $db->pdo->exec('DELETE FROM fixture_topics WHERE topic_id=100'); $db->failure = 'DELETE FROM fixture_watches';
	stats_expect_failure(function () use ($db) { phpbb_cleanup_removed_topic_preferences($db, 100); });
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3, 'Failed preference cleanup is explicit and stops subsequent deletion');

	// Execute the actual whole-topic removal branch without deleting user posts.
	$source = file_get_contents($forum_root . 'includes/functions_post.php');
	$controller = strpos($source, 'function delete_post(');
	$start = strpos($source, "\t\t\t\t\$sql = \"DELETE FROM \" . TOPICS_TABLE", $controller);
	$end = strpos($source, "\n\t\t\t}\n\t\t}", $start);
	stats_check($controller !== false && $start !== false && $end > $start, 'Locate actual whole-topic cleanup branch');
	$branch = substr($source, $start, $end-$start); $topic_id=100;
	$db = new PostStatsDatabase(1); $db->failure = 'DELETE FROM fixture_topics';
	stats_expect_failure(function () use ($branch, $topic_id, $db) { eval($branch); });
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 3, 'Failed topic removal must retain bookmarks');
	$db = new PostStatsDatabase(1); eval($branch);
	stats_check($db->value('SELECT COUNT(*) FROM fixture_bookmarks') === 1 && $db->value('SELECT COUNT(*) FROM fixture_watches') === 1, 'Successful whole-topic controller invokes scoped cleanup');
	echo "Post statistics and topic preference cleanup checks passed.\n";
}
finally { restore_error_handler(); }
