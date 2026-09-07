<?php
define('IN_PHPBB', true); define('ANONYMOUS', -1);
define('USERS_TABLE', 'fixture_users'); define('POSTS_TABLE', 'fixture_posts'); define('FORUMS_TABLE', 'fixture_forums');
function recount_check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class RecountFailure extends RuntimeException {}
function throw_error($message, $line = 0, $file = '', $sql = '') { throw new RecountFailure($message); }
function check_mysql_version() { return $GLOBALS['recount_modern_mysql']; }
function lock_db($unlock = false) { $GLOBALS['recount_lock_actions'][] = (bool) $unlock; }
class RecountDatabase
{
	var $pdo; var $queries = array(); var $failure = '';
	function __construct()
	{
		$this->pdo = new PDO('sqlite::memory:');
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('CREATE TABLE fixture_users (user_id INTEGER PRIMARY KEY, username TEXT, user_posts INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_posts (post_id INTEGER PRIMARY KEY, poster_id INTEGER, forum_id INTEGER)');
		$this->pdo->exec('CREATE TABLE fixture_forums (forum_id INTEGER PRIMARY KEY, count_posts INTEGER)');
		$this->pdo->exec("INSERT INTO fixture_users VALUES (-1,'Guest',77),(0,'Reserved',66),(8,'Grüße <img src=x>',99),(9,'Disabled forum only',7),(10,'No posts',5),(11,'Correct',1)");
		$this->pdo->exec('INSERT INTO fixture_forums VALUES (3,1),(4,0),(5,1)');
		$this->pdo->exec('INSERT INTO fixture_posts VALUES (10,8,3),(11,8,4),(12,8,5),(13,8,999),(14,9,4),(15,11,3),(16,-1,3),(17,0,3)');
	}
	function sql_query($sql)
	{
		$this->queries[] = $sql;
		if ($this->failure !== '' && strpos($sql, $this->failure) === 0) { return false; }
		return $this->pdo->query($sql);
	}
	function sql_fetchrow($result) { return $result->fetch(PDO::FETCH_ASSOC); }
	function sql_freeresult($result) { $result->closeCursor(); }
	function posts($user_id) { return (int) $this->pdo->query('SELECT user_posts FROM fixture_users WHERE user_id=' . (int) $user_id)->fetchColumn(); }
}
$forum_root = dirname(dirname(__DIR__)) . '/phpBB2/';
$source = file_get_contents($forum_root . 'admin/admin_db_maintenance.php');
$start = strpos($source, "\t\t\tcase 'synchronize_user':");
$end = strpos($source, "\t\t\tcase 'synchronize_mod_state':", $start);
recount_check($start !== false && $end > $start, 'Locate actual user recount controller');
$recount_branch = "switch ('synchronize_user') {\n" . substr($source, $start, $end-$start) . "\n}";
$lang = array('Synchronize_post_counters'=>'User counters','Synchronize_user_post_counter'=>'Recount','Synchronizing_users'=>'Changed users','Synchronizing_user_counter'=>'%s (%s): %s to %s','Nothing_to_do'=>'Nothing to do');
function run_recount()
{
	global $db, $lang, $recount_branch, $recount_lock_actions, $recount_last_output;
	$list_open = false; $recount_lock_actions = array();
	ob_start();
	try { eval($recount_branch); return ob_get_contents(); }
	finally { $recount_last_output = ob_get_contents(); ob_end_clean(); }
}
set_error_handler(function ($severity, $message) { if (error_reporting() & $severity) { throw new RuntimeException($message); } });
try
{
	foreach (array(true, false) as $recount_modern_mysql)
	{
		$db = new RecountDatabase(); $output = run_recount();
		recount_check($db->posts(8) === 2, 'Count only posts in existing count-enabled forums');
		recount_check($db->posts(9) === 0 && $db->posts(10) === 0, 'Recount users with only excluded posts or no posts to zero');
		recount_check($db->posts(11) === 1, 'Already correct user stays unchanged');
		recount_check($db->posts(-1) === 77 && $db->posts(0) === 66, 'Guest and reserved identities are never recounted');
		recount_check(strpos($output, '<img src=x>') === false && strpos($output, 'Grüße &lt;img src=x&gt;') !== false, 'Preserve UTF-8 and escape user names in report');
		recount_check($recount_lock_actions === array(false, true), 'Preserve existing maintenance lock/unlock workflow');
		$db->queries = array(); $output = run_recount();
		recount_check(strpos($output, 'Nothing to do') !== false && count($db->queries) === 1, 'Idempotent recount needs one read and no writes');
	}
	$db = new RecountDatabase(); $db->pdo->exec('UPDATE fixture_forums SET count_posts=0'); run_recount();
	recount_check($db->posts(8) === 0 && $db->posts(9) === 0 && $db->posts(10) === 0 && $db->posts(11) === 0, 'An empty counted-post set resets all positive users correctly');
	recount_check($db->posts(-1) === 77 && $db->posts(0) === 66, 'An empty counted set is not a blanket sentinel reset');
	$db = new RecountDatabase(); $db->pdo->exec('DELETE FROM fixture_users WHERE user_id>0'); $output=run_recount();
	recount_check(count($db->queries) === 1 && strpos($output, 'Nothing to do') !== false && $db->posts(-1) === 77 && $db->posts(0) === 66, 'No real users means no counter writes');
	foreach (array('SELECT ', 'UPDATE ') as $failure)
	{
		$db = new RecountDatabase(); $db->failure=$failure; $caught=false;
		try { run_recount(); } catch (RecountFailure $error) { $caught=true; }
		recount_check($caught && $db->posts(8) === 99 && $db->posts(9) === 7 && $db->posts(-1) === 77, 'Read/write failure must stop without resetting other counters');
		recount_check(strpos($recount_last_output, 'Grüße') === false, 'Do not report a failed counter write as completed');
	}
	echo "User post-count maintenance checks passed.\n";
}
finally { restore_error_handler(); }
