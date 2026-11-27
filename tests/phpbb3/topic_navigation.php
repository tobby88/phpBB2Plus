<?php
// A pair of published neighbours with an unapproved topic between each pair.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$db->sql_transaction('begin');
if ($argv[2] === 'seed')
{
	$result = $db->sql_query_limit('SELECT t.*, p.post_id AS seed_post FROM ' . TOPICS_TABLE . ' t
		JOIN ' . POSTS_TABLE . ' p ON p.post_id=t.topic_first_post_id WHERE t.topic_id=1', 1);
	$topic = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	$result = $db->sql_query('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=' . (int) $topic['seed_post']);
	$post = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	unset($topic['seed_post']);
	for ($i = 0; $i < 5; ++$i)
	{
		$id = 985700 + $i;
		$time = 1637003500 + $i;
		$status = $i % 2 ? ITEM_UNAPPROVED : ITEM_APPROVED;
		$label = 'Topic navigation ' . $i;
		$t = array_replace($topic, array('topic_id' => $id, 'topic_title' => $label, 'topic_first_post_id' => $id,
			'topic_last_post_id' => $id, 'topic_time' => $time, 'topic_last_post_time' => $time,
			'topic_visibility' => $status, 'topic_moved_id' => 0, 'topic_posts_approved' => $status === ITEM_APPROVED ? 1 : 0,
			'topic_posts_unapproved' => $status === ITEM_UNAPPROVED ? 1 : 0, 'topic_posts_softdeleted' => 0));
		$p = array_replace($post, array('post_id' => $id, 'topic_id' => $id, 'post_time' => $time,
			'post_visibility' => $status, 'post_subject' => $label, 'post_text' => $label));
		$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
		$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
	}
}
elseif ($argv[2] === 'clean')
{
	$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE topic_id BETWEEN 985700 AND 985704 AND topic_id=post_id');
	$db->sql_query('DELETE FROM ' . TOPICS_TABLE . " WHERE topic_id BETWEEN 985700 AND 985704 AND topic_title LIKE 'Topic navigation %'");
}
else { throw new RuntimeException('Unknown topic navigation fixture phase'); }
$db->sql_transaction('commit');
