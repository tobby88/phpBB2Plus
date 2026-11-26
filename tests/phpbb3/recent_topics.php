<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$done = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$done, $db) {
	if (!$done || $db->get_sql_error_triggered()) {
		fwrite(STDERR, "Recent topics fixture failed: " . ($db->get_sql_error_returned()['message'] ?? 'incomplete') . "\n");
		exit(1);
	}
});
$row = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
};
$insert = static function ($table, $row) use ($db) {
	if ($db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $row)) === false)
	{ throw new RuntimeException($db->get_sql_error_returned()['message']); }
};
$state = $phpbb_container->get('config_text');
$bylines = $table_prefix . 'plus_bylines';
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	$berlin = new DateTimeZone('Europe/Berlin');
	foreach (array('2025-03-31'=>23, '2025-10-27'=>25, '2026-01-01'=>24) as $date=>$hours)
	{
		$test = new DateTimeImmutable($date . ' 04:00', $berlin);
		$range = \phpbb2plus\community\service\topic_activity::period('yesterday', 3, $test->getTimestamp(), $berlin);
		if ($range[1] !== $test->setTime(0, 0)->getTimestamp() || $range[1] - $range[0] !== $hours * 3600)
		{ throw new RuntimeException('The previous calendar day has incorrect boundaries'); }
		$rolling = \phpbb2plus\community\service\topic_activity::period('last24', 3, $test->getTimestamp(), $berlin);
		if ($rolling[0] !== $test->getTimestamp() - 86400) { throw new RuntimeException('Rolling day length changed'); }
	}
	if ($row('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id BETWEEN 947821 AND 947841') ||
		$row('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE post_id BETWEEN 947921 AND 947942'))
	{ throw new RuntimeException('Synthetic topic IDs are occupied'); }
	$topic = $row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1');
	$post = $row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1');
	$state->set('plus_test_recent_password', $row('SELECT forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=15')['forum_password']);
	$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='temporary-recent-fixture' WHERE forum_id=15");
	$now = time();
	$zone = new DateTimeZone($config['board_timezone']);
	$today = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime(0, 0);
	for ($n = 1; $n <= 21; $n++)
	{
		$id = 947820 + $n;
		$pid = 947920 + $n;
		$forum = $n === 14 ? 9 : ($n === 15 ? 15 : 1);
		$when = $n === 19 ? $today->getTimestamp() - 1 : ($n === 20 ? $now-5*86400 : ($n === 21 ? $now-8*86400 : $now));
		$insert(TOPICS_TABLE, array('topic_id'=>$id, 'forum_id'=>$forum, 'topic_title'=>'Recent fixture ' . $n,
			'topic_first_post_id'=>$n === 13 ? 947942 : $pid, 'topic_last_post_id'=>$pid, 'topic_time'=>$when, 'topic_last_post_time'=>$when,
			'topic_status'=>$n === 18 ? ITEM_MOVED : ITEM_UNLOCKED, 'topic_moved_id'=>$n === 18 ? 1 : 0,
			'topic_visibility'=>$n === 17 ? ITEM_UNAPPROVED : ITEM_APPROVED, 'plus_legacy_removed'=>0, 'topic_posts_approved'=>1) + $topic);
		$last = array('post_id'=>$pid, 'topic_id'=>$id, 'forum_id'=>$forum, 'post_time'=>$when, 'poster_id'=>ANONYMOUS,
			'post_username'=>'Recent guest', 'post_visibility'=>$n === 16 ? ITEM_DELETED : ITEM_APPROVED, 'plus_public_post'=>1);
		$insert(POSTS_TABLE, $last + $post);
		if ($n === 12) { $insert($bylines, array('post_id'=>$pid, 'author_name'=>'RecentFormerMember', 'editor_name'=>'',
			'author_clean'=>'recentformermember','author_key'=>hash('sha256','recentformermember'))); }
		if ($n === 13)
		{
			$insert(POSTS_TABLE, array('post_id'=>947942, 'post_visibility'=>ITEM_DELETED, 'post_username'=>'HiddenFirstAlias') + $last + $post);
		}
	}
}
elseif ($argv[2] === 'cleanup')
{
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password'=>$state->get('plus_test_recent_password'))) . ' WHERE forum_id=15');
	$state->delete('plus_test_recent_password');
	$db->sql_query("DELETE FROM $bylines WHERE post_id=947932");
	$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id BETWEEN 947921 AND 947942');
	$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id BETWEEN 947821 AND 947841');
}
else { throw new RuntimeException('Unknown recent topic fixture stage'); }
if (!$db->sql_transaction('commit')) { throw new RuntimeException('Recent topics fixture did not commit'); }
$done = true;
