<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }

const BOOKMARK_FORUM = 673, BOOKMARK_TOPIC = 947820, BOOKMARK_POST = 947830;
function bookmark_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function bookmark_insert($table, $row)
{
	global $db;
	$db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $row));
}
function bookmark_check($condition, $message)
{
	if (!$condition) { throw new RuntimeException($message); }
}

$member = (int) bookmark_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$forum = BOOKMARK_FORUM;
$topic = BOOKMARK_TOPIC;
$post = BOOKMARK_POST;
$scope = 'forum_id=' . $forum;
$step = $argv[2];
$rights = array('f_list', 'f_list_topics', 'f_read', 'm_split', 'm_merge', 'm_info', 'm_approve');
if ($step === 'seed')
{
	bookmark_check(!bookmark_rows('SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE $scope") &&
		!bookmark_rows('SELECT topic_id FROM ' . TOPICS_TABLE . " WHERE topic_id BETWEEN $topic AND " . ($topic + 1)) &&
		!bookmark_rows('SELECT post_id FROM ' . POSTS_TABLE . " WHERE post_id BETWEEN $post AND " . ($post + 4)), 'Occupied moderation fixture IDs');
	$edge = (int) bookmark_rows('SELECT MAX(right_id) AS edge FROM ' . FORUMS_TABLE)[0]['edge'] + 1;
	$f = bookmark_rows('SELECT * FROM ' . FORUMS_TABLE . ' WHERE forum_id=1')[0];
	$t = bookmark_rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1')[0];
	$p = bookmark_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1')[0];
	$db->sql_transaction('begin');
	bookmark_insert(FORUMS_TABLE, array_replace($f, array('forum_id' => $forum, 'left_id' => $edge, 'right_id' => $edge + 1,
		'parent_id' => 0, 'forum_name' => 'Moderator bookmarks', 'forum_type' => FORUM_POST, 'forum_parents' => '', 'forum_password' => '',
		'enable_prune' => 0, 'enable_shadow_prune' => 0, 'forum_status' => ITEM_UNLOCKED)));
	foreach (array(ITEM_APPROVED, ITEM_DELETED) as $i => $visibility)
	{
		$first = $i ? $post + 4 : $post;
		bookmark_insert(TOPICS_TABLE, array_replace($t, array('topic_id' => $topic + $i, 'forum_id' => $forum,
			'topic_title' => 'Bookmark selection ' . $i, 'topic_poster' => ANONYMOUS, 'topic_first_post_id' => $first,
			'topic_last_post_id' => $i ? $first : $post + 1, 'topic_moved_id' => 0, 'topic_visibility' => $visibility,
			'topic_posts_approved' => $i ? 0 : 2, 'topic_posts_unapproved' => $i ? 0 : 1, 'topic_posts_softdeleted' => 1,
			'topic_type' => POST_NORMAL, 'topic_status' => $i ? ITEM_LOCKED : ITEM_UNLOCKED, 'plus_legacy_removed' => $i,
			'topic_attachment' => 0, 'poll_start' => 0, 'poll_title' => '')));
	}
	foreach (array(ITEM_APPROVED, ITEM_APPROVED, ITEM_UNAPPROVED, ITEM_DELETED, ITEM_DELETED) as $i => $visibility)
	{
		$removed = $visibility === ITEM_DELETED;
		bookmark_insert(POSTS_TABLE, array_replace($p, array('post_id' => $post + $i, 'forum_id' => $forum,
			'topic_id' => $topic + (int) ($i === 4), 'poster_id' => ANONYMOUS, 'post_username' => '',
			'poster_ip' => $removed ? '' : '192.0.2.101', 'post_visibility' => $visibility, 'post_attachment' => 0,
			'post_subject' => $removed ? '' : 'Bookmark record ' . $i, 'post_text' => $removed ? '' : '<t>Selection fixture</t>',
			'plus_public_post' => (int) $removed, 'plus_legacy_erased' => (int) $removed)));
	}
	$db->sql_transaction('commit');
}
if ($step === 'seed' || strpos($step, 'rights:') === 0)
{
	$except = $step === 'seed' ? '' : substr($step, 7);
	bookmark_check($except === '' || in_array($except, $rights, true) || $except === 'approve', 'Unknown fixture permission');
	$db->sql_transaction('begin');
	$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE $scope AND user_id=$member");
	foreach (bookmark_rows('SELECT auth_option_id,auth_option FROM ' . ACL_OPTIONS_TABLE . ' WHERE ' . $db->sql_in_set('auth_option', $rights)) as $option)
	{
		$deny = $option['auth_option'] === $except || ($option['auth_option'] === 'm_approve' && $except !== 'approve');
		bookmark_insert(ACL_USERS_TABLE, array('user_id' => $member, 'forum_id' => $forum, 'auth_option_id' => $option['auth_option_id'],
			'auth_role_id' => 0, 'auth_setting' => $deny ? ACL_NEVER : ACL_YES));
	}
	$db->sql_transaction('commit');
	$auth->acl_clear_prefetch($member);
}
elseif ($step === 'password')
{
	$hash = $phpbb_container->get('passwords.manager')->hash('local-bookmark-room');
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $hash)) . " WHERE $scope");
}
elseif ($step === 'intact')
{
	$rows = bookmark_rows('SELECT topic_id,topic_type,topic_status,topic_visibility FROM ' . TOPICS_TABLE . " WHERE $scope ORDER BY topic_id");
	bookmark_check(count($rows) === 2 && (int) $rows[0]['topic_id'] === $topic && (int) $rows[0]['topic_status'] === ITEM_UNLOCKED &&
		(int) $rows[0]['topic_type'] === POST_NORMAL && (int) $rows[0]['topic_visibility'] === ITEM_APPROVED &&
		(int) $rows[1]['topic_status'] === ITEM_LOCKED && (int) $rows[1]['topic_visibility'] === ITEM_DELETED, 'A bookmark changed topic state');
	$posts = bookmark_rows('SELECT post_id,topic_id,post_visibility FROM ' . POSTS_TABLE . " WHERE $scope ORDER BY post_id");
	bookmark_check(count($posts) === 5, 'A bookmark deleted posts');
	foreach ($posts as $i => $row)
	{
		bookmark_check((int) $row['topic_id'] === $topic + (int) ($i === 4) && (int) $row['post_id'] === $post + $i &&
			(int) $row['post_visibility'] === array(ITEM_APPROVED, ITEM_APPROVED, ITEM_UNAPPROVED, ITEM_DELETED, ITEM_DELETED)[$i],
			'A bookmark changed a post or moved it to another topic');
	}
}
elseif ($step === 'cleanup')
{
	$db->sql_transaction('begin');
	foreach (array(ACL_USERS_TABLE, FORUMS_ACCESS_TABLE, FORUMS_TRACK_TABLE, TOPICS_TRACK_TABLE, POSTS_TABLE, TOPICS_TABLE, FORUMS_TABLE) as $table)
	{
		$db->sql_query("DELETE FROM $table WHERE $scope");
	}
	$db->sql_transaction('commit');
	$auth->acl_clear_prefetch($member);
}
else { exit(2); }
