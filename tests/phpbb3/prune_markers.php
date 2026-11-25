<?php
// Use a synthetic forum so the real phpBB prune routine cannot select another topic.
if (!defined('IN_PHPBB') || PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' ||
	$dbhost !== '127.0.0.1' || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $dbname)) { exit(2); }
require_once './includes/functions_admin.php';
$room = 947620;
$old = print_fixture_row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=805100');
$first = print_fixture_row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=805200');
$db->sql_transaction('begin');
try
{
	$forum = print_fixture_row('SELECT * FROM ' . FORUMS_TABLE . ' WHERE forum_id=15');
	$forum['forum_id'] = $room;
	$db->sql_query('INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $forum));
	foreach (array(947621 => 1, 947622 => 0) as $id => $erased)
	{
		$pid = $id + 10;
		$topic = array_replace($old, array('topic_id' => $id, 'forum_id' => $room, 'topic_first_post_id' => $pid,
			'topic_last_post_id' => $pid, 'topic_last_post_time' => 1000, 'topic_posts_softdeleted' => 1,
			'plus_legacy_removed' => $erased));
		$post = array_replace($first, array('post_id' => $pid, 'topic_id' => $id, 'forum_id' => $room,
			'post_time' => 1000, 'plus_legacy_erased' => $erased));
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $topic));
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $post));
	}
	$config->set('plus_post_delete_policy', -1, false);
	$result = prune($room, 'posted', 1001, 0, false);
	$topic = print_fixture_row('SELECT COUNT(*) AS remaining, MIN(topic_id) AS survivor FROM ' . TOPICS_TABLE . ' WHERE forum_id=' . $room);
	$post = print_fixture_row('SELECT COUNT(*) AS remaining, MIN(post_id) AS survivor FROM ' . POSTS_TABLE . ' WHERE forum_id=' . $room);
	if ((int) $result['topics'] !== 1 || (int) $result['posts'] !== 1 || (int) $topic['remaining'] !== 1 || (int) $post['remaining'] !== 1 ||
		(int) $topic['survivor'] !== 947621 || (int) $post['survivor'] !== 947631)
	{
		throw new RuntimeException('Native age pruning removed an erased Plus bookmark or skipped the ordinary topic');
	}
}
finally { $db->sql_transaction('rollback'); }
