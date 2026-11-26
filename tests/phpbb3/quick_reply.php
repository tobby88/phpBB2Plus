<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
if ($argv[2] === 'source-off')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->query("UPDATE phpbb_plus SET config_value='0' WHERE config_name='show_quickreply' AND config_value='1'");
	if ($source->affected_rows !== 1) { throw new RuntimeException('The original Plus switch is missing from the synthetic source'); }
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$store = $phpbb_container->get('config_text');
$key = 'test_plus_quick_reply';
$read = static function ($sql, $name) use ($db) {
	$result = $db->sql_query($sql); $value = $db->sql_fetchfield($name); $db->sql_freeresult($result); return (int) $value;
};
$fixture_posts = static function () use ($db) {
	$result = $db->sql_query('SELECT post_id,post_visibility,post_text FROM ' . POSTS_TABLE .
		" WHERE forum_id=7 AND topic_id=7 AND post_subject='Plus quick reply submission fixture'");
	$rows = $db->sql_fetchrowset($result); $db->sql_freeresult($result); return $rows;
};
switch ($argv[2])
{
	case 'setup':
	case 'setup-off':
		$forum = $read('SELECT forum_flags FROM ' . FORUMS_TABLE . ' WHERE forum_id=1', 'forum_flags');
		$unrelated = $read('SELECT COUNT(*) AS total FROM ' . FORUMS_TABLE . ' WHERE (forum_type<>' . FORUM_POST .
			' OR plus_recovery_role<>0) AND (forum_flags & ' . FORUM_FLAG_QUICK_REPLY . ')<>0', 'total');
		if ((int) $config['allow_quick_reply'] !== (int) ($argv[2] === 'setup') || !($forum & FORUM_FLAG_QUICK_REPLY) || $unrelated)
		{ throw new RuntimeException('The converter lost the Plus quick-reply setting or enabled a synthetic/non-posting forum'); }
		if ($store->get($key) !== null || $fixture_posts()) { throw new RuntimeException('A quick-reply fixture already exists'); }
		$settings = array('posts_per_page' => 1, 'allow_sig' => 1, 'email_enable' => 1, 'allow_topic_notify' => 1,
			'allow_bbcode' => 1, 'allow_smilies' => 1, 'allow_quick_reply' => 1);
		$original = array();
		foreach ($settings as $name => $value) { $original[$name] = $config[$name]; }
		$status = $read('SELECT topic_status FROM ' . TOPICS_TABLE . ' WHERE topic_id=1', 'topic_status');
		$store->set($key, json_encode(array($original, $forum, $status)));
		foreach ($settings as $name => $value) { $config->set($name, $value); }
		break;
	case 'disabled-tools':
		foreach (array('allow_sig', 'email_enable', 'allow_bbcode', 'allow_smilies') as $name) { $config->set($name, 0); }
		break;
	case 'posted':
		$rows = $fixture_posts();
		if (count($rows) !== 1 || (int) $rows[0]['post_visibility'] !== ITEM_APPROVED ||
			\s9e\TextFormatter\Unparser::unparse($rows[0]['post_text']) !== 'Eine gesendete Kurzantwort 😀')
		{ throw new RuntimeException('The quick reply did not publish exactly one original message'); }
		break;
	case 'locked':
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status=' . ITEM_LOCKED . ' WHERE topic_id=1');
		break;
	case 'forum-off':
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status=' . ITEM_UNLOCKED . ' WHERE topic_id=1');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=forum_flags & ~' . FORUM_FLAG_QUICK_REPLY . ' WHERE forum_id=1');
		break;
	case 'board-off':
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=forum_flags | ' . FORUM_FLAG_QUICK_REPLY . ' WHERE forum_id=1');
		$config->set('allow_quick_reply', 0);
		break;
	case 'restore':
		$snapshot = json_decode((string) $store->get($key), true);
		if (!is_array($snapshot)) { throw new RuntimeException('Missing quick reply fixture'); }
		list($settings, $forum, $status) = $snapshot;
		foreach ($settings as $name => $value) { $config->set($name, $value); }
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_flags=' . (int) $forum . ' WHERE forum_id=1');
		$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_status=' . (int) $status . ' WHERE topic_id=1');
		$posts = array_column($fixture_posts(), 'post_id');
		if ($posts)
		{
			require_once './includes/functions_admin.php';
			delete_posts('post_id', $posts);
		}
		$store->delete($key);
		break;
	default: exit(2);
}
