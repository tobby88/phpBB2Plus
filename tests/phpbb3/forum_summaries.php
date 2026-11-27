<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$read = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
};
$check = static function ($valid, $message) { if (!$valid) { throw new RuntimeException($message); } };
$phase = $argv[2];
$first = 31550;
$last = $first + 17;
$id = 982074;
if ($phase === 'native-board' || $phase === 'native-ignored')
{
	$sample = $read('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_POST . ' ORDER BY forum_id');
	$forum = (int) $sample['forum_id'];
	if ($phase === 'native-board') { $db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_posts_approved=987 WHERE forum_id=$forum"); }
	else
	{
		$value = $read('SELECT forum_posts_approved FROM ' . FORUMS_TABLE . " WHERE forum_id=$forum");
		$check((int) $value['forum_posts_approved'] === 987, 'Updating a native installation traversed its unrelated forums');
	}
	return;
}
if ($phase === 'older')
{
	$shadow = $id+1;
	$check(!$read('SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE forum_id BETWEEN $first AND $last") &&
		!$read('SELECT post_id FROM ' . POSTS_TABLE . " WHERE post_id=$id") && !$read('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE topic_id BETWEEN $id AND $shadow"),
		'Synthetic upgrade IDs are occupied');
	$room = $read('SELECT * FROM ' . FORUMS_TABLE . ' WHERE forum_id=1');
	$edge = $read('SELECT MAX(right_id) AS furthest FROM ' . FORUMS_TABLE);
	$forum = array_merge($room, array('parent_id'=>0, 'forum_parents'=>'', 'forum_name'=>'Later native room', 'plus_legacy_category'=>0,
		'forum_topics_approved'=>11, 'forum_topics_unapproved'=>9, 'forum_topics_softdeleted'=>4,
		'forum_posts_approved'=>99, 'forum_posts_unapproved'=>7, 'forum_posts_softdeleted'=>8, 'forum_last_post_id'=>695));
	for ($room_id=$first; $room_id <= $last; $room_id++)
	{
		$forum['forum_id'] = $room_id;
		$forum['left_id'] = (int) $edge['furthest'] + ($room_id-$first)*2 + 1;
		$forum['right_id'] = $forum['left_id']+1;
		$db->sql_query('INSERT INTO ' . FORUMS_TABLE . ' ' . $db->sql_build_array('INSERT', $forum));
	}
	$topic = $read('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=9');
	$post = $read('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=72');
	$topic = array_merge($topic, array('topic_id'=>$id, 'forum_id'=>$last, 'topic_title'=>'Native addition',
		'topic_first_post_id'=>$id, 'topic_last_post_id'=>$id, 'topic_visibility'=>ITEM_APPROVED, 'topic_status'=>ITEM_UNLOCKED,
		'topic_moved_id'=>0, 'topic_posts_approved'=>1, 'topic_posts_unapproved'=>0, 'topic_posts_softdeleted'=>0));
	$post = array_merge($post, array('post_id'=>$id, 'topic_id'=>$id, 'forum_id'=>$last, 'post_visibility'=>ITEM_APPROVED,
		'post_subject'=>'Native addition', 'post_text'=>'<t>Added after conversion</t>'));
	$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $topic));
	$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $post));
	$moved = array_merge($topic, array('topic_id'=>$shadow, 'forum_id'=>$first+1, 'topic_status'=>ITEM_MOVED, 'topic_moved_id'=>$id));
	$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $moved));
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET forum_last_post_id=695,forum_posts_approved=19,forum_topics_softdeleted=21 WHERE forum_id=1');
	$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_posts_approved=991,forum_topics_approved=913,forum_last_post_subject='Previous heading' WHERE forum_id=14");
	return;
}
$check(in_array($phase, array('converted','current','upgraded'), true), 'Unrecognized forum summary fixture');
foreach ($phase === 'converted' ? array(1,14) : array(1,14,$first,$first+1,$last) as $forum)
{
	$stored = $read('SELECT forum_last_post_id,forum_topics_approved,forum_topics_unapproved,forum_topics_softdeleted,
		forum_posts_approved,forum_posts_unapproved,forum_posts_softdeleted FROM ' . FORUMS_TABLE . " WHERE forum_id=$forum");
	$expected = $read('SELECT MAX(p.post_id) AS latest FROM ' . POSTS_TABLE . ' p JOIN ' . TOPICS_TABLE . " t ON t.topic_id=p.topic_id
		WHERE p.forum_id=$forum AND p.post_visibility=1 AND t.topic_visibility=1");
	$topics = $read('SELECT SUM(topic_visibility=1) AS approved, SUM(topic_visibility IN (0,3)) AS unapproved,
		SUM(topic_visibility=2) AS deleted FROM ' . TOPICS_TABLE . " WHERE forum_id=$forum");
	$posts = $read('SELECT SUM(topic_posts_approved) AS approved, SUM(topic_posts_unapproved) AS unapproved,
		SUM(topic_posts_softdeleted) AS deleted FROM ' . TOPICS_TABLE . " WHERE forum_id=$forum AND topic_status<>" . ITEM_MOVED);
	$expected = array_merge(array_values($expected), array_values($topics), array_values($posts));
	$check(array_map('intval', array_values($stored)) === array_map('intval', array_values($expected)), "Wrong current last post or totals in forum $forum");
	$details = $read('SELECT f.forum_last_post_subject=COALESCE(p.post_subject,\'\') AND f.forum_last_post_time=COALESCE(p.post_time,0) AS current_details
		FROM ' . FORUMS_TABLE . ' f LEFT JOIN ' . POSTS_TABLE . " p ON p.post_id=f.forum_last_post_id AND p.forum_id=f.forum_id WHERE f.forum_id=$forum");
	$check((int) $details['current_details'] === 1, "The post subject or date in forum $forum was not refreshed");
}
$latest = $read('SELECT forum_last_post_id FROM ' . FORUMS_TABLE . ' WHERE forum_id=1');
$check(($phase !== 'converted' || (int) $latest['forum_last_post_id'] === 72) && !isset($config[\phpbb2plus\community\service\forum_summaries::CURSOR]),
	'A converted forum retained a withdrawn article, a private moved destination or an unfinished checkpoint');
if ($phase === 'upgraded')
{
	$db->sql_query('DELETE FROM ' . POSTS_TABLE . " WHERE post_id=$id AND forum_id=$last");
	$db->sql_query('DELETE FROM ' . TOPICS_TABLE . " WHERE topic_id IN ($id," . ($id+1) . ") AND forum_id IN ($last," . ($first+1) . ')');
	$db->sql_query('DELETE FROM ' . FORUMS_TABLE . " WHERE forum_id BETWEEN $first AND $last");
}
