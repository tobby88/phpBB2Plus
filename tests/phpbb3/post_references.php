<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php'; require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows = static function ($sql, $limit = 0) use ($db) {
	$r = $limit ? $db->sql_query_limit($sql, $limit) : $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$n = 630000; $missing = 639999; $end = $n + 214;
$records = array(TOPICS_TABLE=>'topic_id', POSTS_TABLE=>'post_id');
$key = 'test_plus_post_references';
$state = $phpbb_container->get('config_text');
$actor = $rows('SELECT user_id,user_lang,user_type FROM ' . USERS_TABLE . " WHERE username_clean='arcadecategoryjunior'")[0];
$backup = (int) $rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_backup'")[0]['auth_option_id'];
$assignment = 'user_id=' . (int) $actor['user_id'] . ' AND forum_id=0 AND auth_role_id=0 AND auth_option_id=' . $backup;
$range = static function ($column) use ($n, $end) { return "$column BETWEEN $n AND $end"; };
$fingerprint = static function () use ($records, $range, $rows) {
	$all = array();
	foreach ($records as $table=>$column) { $all[] = $rows("SELECT * FROM $table WHERE " . $range($column) . " ORDER BY $column"); }
	return hash('sha256', serialize($all));
};
if ($argv[2] === 'seed')
{
	if ($state->get($key) !== null || !$backup || (int) $actor['user_type'] !== USER_NORMAL || $rows('SELECT auth_setting FROM ' . ACL_USERS_TABLE . ' WHERE ' . $assignment))
	{ throw new RuntimeException('Unexpected native reviewer permission'); }
	foreach ($records as $table=>$id)
	{
		if ($rows("SELECT $id FROM $table WHERE " . $range($id) . " OR $id=$missing", 1)) { throw new RuntimeException('The test IDs are in use'); }
	}
	if ($rows('SELECT forum_id FROM ' . FORUMS_TABLE . " WHERE forum_id=$missing") || $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE user_id=$missing"))
	{ throw new RuntimeException('Expected a vacant reference'); }
	$forums = array_map('intval', array_column($rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_POST . ' ORDER BY forum_id', 2), 'forum_id'));
	$cat = (int) $rows('SELECT forum_id FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_CAT . ' ORDER BY forum_id', 1)[0]['forum_id'];
	list($a,$b) = $forums;
	$t = $rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1')[0];
	$p = $rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1')[0];
	$t = array_merge($t, array('forum_id'=>$a, 'topic_poster'=>ANONYMOUS, 'topic_type'=>POST_NORMAL, 'topic_status'=>ITEM_UNLOCKED,
		'topic_moved_id'=>0, 'topic_visibility'=>ITEM_APPROVED, 'topic_title'=>'Private reference fixture title', 'plus_legacy_removed'=>0));
	$p = array_merge($p, array('forum_id'=>$a, 'topic_id'=>$n, 'poster_id'=>ANONYMOUS, 'post_username'=>'Private retained guest byline',
		'post_text'=>'Private reference fixture text', 'post_visibility'=>ITEM_APPROVED, 'plus_legacy_erased'=>0));
	$topic_changes = array(array(), array('topic_type'=>POST_GLOBAL, 'forum_id'=>0), array('topic_type'=>POST_GLOBAL),
		array('forum_id'=>$b,'topic_status'=>ITEM_MOVED,'topic_moved_id'=>$n), array('forum_id'=>$missing), array('topic_poster'=>$missing), array(),
		array('topic_status'=>ITEM_MOVED,'topic_moved_id'=>$missing), array('topic_status'=>ITEM_MOVED,'topic_moved_id'=>$n+8),
		array('topic_status'=>ITEM_MOVED,'topic_moved_id'=>$n+3), array('topic_moved_id'=>$n), array('forum_id'=>$cat),
		array('topic_status'=>ITEM_LOCKED,'topic_visibility'=>ITEM_DELETED,'plus_legacy_removed'=>1,'topic_poster'=>0), array('topic_visibility'=>ITEM_DELETED));
	$post_changes = array(
		200=>array('forum_id'=>$b), 201=>array('topic_id'=>$missing), 202=>array('poster_id'=>$missing),
		203=>array('topic_id'=>$n+3,'forum_id'=>$b), 204=>array('topic_id'=>$n+1,'forum_id'=>0), 205=>array('topic_id'=>$n+2,'forum_id'=>0),
		206=>array('topic_id'=>$n+2), 207=>array('topic_id'=>$n+4,'forum_id'=>$missing), 208=>array('topic_id'=>$n+5),
		209=>array('topic_id'=>$n+11,'forum_id'=>$cat), 210=>array('topic_id'=>$n+12,'post_visibility'=>ITEM_DELETED,'plus_legacy_erased'=>1,'poster_id'=>0),
		211=>array('topic_id'=>$n+13,'post_visibility'=>ITEM_DELETED), 212=>array('forum_id'=>0),
		213=>array('topic_id'=>$n+1), 214=>array('topic_id'=>$n+2,'forum_id'=>$b));
	$db->sql_transaction('begin');
	$topics = $posts = array();
	foreach ($topic_changes as $i=>$change) { $topics[] = array_merge($t, $change, array('topic_id'=>$n+$i)); }
	for ($i=0; $i<=214; $i++) { $posts[] = array_merge($p, $post_changes[$i] ?? array(), array('post_id'=>$n+$i)); }
	$db->sql_multi_insert(TOPICS_TABLE, $topics);
	foreach (array_chunk($posts, 40) as $batch) { $db->sql_multi_insert(POSTS_TABLE, $batch); }
	$state->set($key, json_encode(array($actor['user_lang'], $fingerprint())));
	$db->sql_transaction('commit');
	return;
}
$previous = json_decode($state->get($key) ?? '', true);
if (!$previous) { throw new RuntimeException('Missing local reference fixture'); }
switch ($argv[2])
{
	case 'grant':
		$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', array('user_id'=>(int) $actor['user_id'],
			'forum_id'=>0,'auth_option_id'=>$backup,'auth_role_id'=>0,'auth_setting'=>ACL_YES)));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='en' WHERE user_id=" . (int) $actor['user_id']);
		$auth->acl_clear_prefetch((int) $actor['user_id']);
		break;
	case 'verify':
		if ($fingerprint() !== $previous[1]) { throw new RuntimeException('The reference report changed native topic or post data'); }
		break;
	case 'restore':
		$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . ' WHERE ' . $assignment);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='" . $db->sql_escape($previous[0]) . "' WHERE user_id=" . (int) $actor['user_id']);
		$auth->acl_clear_prefetch((int) $actor['user_id']);
		foreach (array_reverse($records, true) as $table=>$id) { $db->sql_query("DELETE FROM $table WHERE " . $range($id)); }
		$state->delete($key);
		break;
	default: exit(2);
}
