<?php
// Synthetic forums on an isolated installation. The original policy and board counts are restored on cleanup.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once './includes/functions_admin.php';
$user->session_begin();
$user->setup();
$complete = false;
$db->sql_return_on_error(true);
register_shutdown_function(static function () use (&$complete, $db) {
	if (!$complete || $db->get_sql_error_triggered())
	{
		fwrite(STDERR, "Age-pruning fixture failed: " . ($db->get_sql_error_returned()['message'] ?? 'incomplete') . "\n");
		exit(1);
	}
});

const AGE_AUTO = 650, AGE_QUEUE = 651, AGE_CHANGED = 652, AGE_MANUAL = 653, AGE_OUTER = 654, AGE_INNER = 655;
const AGE_TOPIC = 947650, AGE_POST = 948000;
$queue = $table_prefix . 'plus_prune_queue';

function age_expect($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function age_rows($sql)
{
	global $db;
	$r = $db->sql_query($sql);
	if ($r === false) { throw new RuntimeException($db->get_sql_error_returned()['message']); }
	$rows = $db->sql_fetchrowset($r);
	$db->sql_freeresult($r);
	return $rows;
}
function age_number($sql) { $rows = age_rows($sql); return (int) reset($rows[0]); }
function age_insert($table, $data)
{
	global $db;
	age_expect($db->sql_query("INSERT INTO $table " . $db->sql_build_array('INSERT', $data)) !== false,
		'Could not insert synthetic row in ' . $table);
}
function age_room($room, $changes)
{
	global $db;
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $changes) . ' WHERE forum_id=' . $room);
}
function age_state($erased, $kept)
{
	global $db;
	$cases = array_merge($erased, $kept);
	$ids = array_map(static function ($n) { return AGE_TOPIC + $n; }, $cases);
	$scope = $db->sql_in_set('topic_id', $ids);
	$topics = array_column(age_rows('SELECT topic_id,topic_status,topic_visibility,plus_legacy_removed FROM ' .
		TOPICS_TABLE . " WHERE $scope"), null, 'topic_id');
	$by_topic = array();
	foreach (age_rows('SELECT topic_id,post_id,post_visibility,plus_legacy_erased,plus_public_post,post_text,poster_id
		FROM ' . POSTS_TABLE . " WHERE $scope ORDER BY post_id") as $post) { $by_topic[$post['topic_id']][] = $post; }
	foreach ($cases as $n)
	{
		$id = AGE_TOPIC + $n;
		age_expect(isset($topics[$id], $by_topic[$id]), "Topic or posts physically missing for case $n");
		$t = $topics[$id];
		$posts = $by_topic[$id];
		if (in_array($n, $erased, true))
		{
			age_expect((int) $t['plus_legacy_removed'] === 1 && (int) $t['topic_status'] === ITEM_LOCKED &&
				(int) $t['topic_visibility'] === ITEM_DELETED, "Expected a neutral topic marker for case $n");
			foreach ($posts as $post)
			{
				age_expect((int) $post['plus_legacy_erased'] === 1 && (int) $post['plus_public_post'] === 1 &&
					(int) $post['post_visibility'] === ITEM_DELETED && (int) $post['poster_id'] === ANONYMOUS &&
					$post['post_text'] === '', "Expected a neutral marker for post " . $post['post_id']);
			}
		}
		else
		{
			$visibility = array(16 => ITEM_UNAPPROVED, 17 => ITEM_DELETED)[$n] ?? ITEM_APPROVED;
			age_expect(!(int) $t['plus_legacy_removed'] && (int) $t['topic_visibility'] === $visibility, "Protected topic changed in case $n");
			foreach ($posts as $index => $post)
			{
				if ($index === 1 && in_array($n, array(15, 36), true)) { continue; } // Previously erased reply.
				$state = $index === 1 ? (array(3 => ITEM_UNAPPROVED, 4 => ITEM_DELETED)[$n] ?? ITEM_APPROVED) : $visibility;
				age_expect(!(int) $post['plus_legacy_erased'] && (int) $post['post_visibility'] === $state &&
					$post['post_text'] === '<t>Age fixture ' . $post['post_id'] . '</t>', 'Protected contribution changed: ' . $post['post_id']);
			}
		}
	}
}
function age_marker_count($room)
{
	return age_number('SELECT COUNT(*) FROM ' . TOPICS_TABLE . ' WHERE plus_legacy_removed=1 AND forum_id=' . $room);
}

if ($argv[2] === 'seed')
{
	$old = time() - 120 * 86400;
	$recent = time() - 86400;
	$forum = age_rows('SELECT * FROM ' . FORUMS_TABLE . ' WHERE forum_type=' . FORUM_POST . ' ORDER BY forum_id LIMIT 1')[0];
	$topic = age_rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_moved_id=0 AND topic_visibility=' . ITEM_APPROVED . ' ORDER BY topic_id LIMIT 1')[0];
	$post = age_rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_visibility=' . ITEM_APPROVED . ' ORDER BY post_id LIMIT 1')[0];
	$edge = age_number('SELECT MAX(right_id) FROM ' . FORUMS_TABLE) + 1;
	$snapshot = array_intersect_key(iterator_to_array($config), array_flip(array('plus_post_delete_policy', 'num_posts', 'num_topics')));
	age_expect(count($snapshot) === 3, 'Missing source board policy or counters');
	age_expect(!age_number('SELECT COUNT(*) FROM ' . FORUMS_TABLE . ' WHERE forum_id BETWEEN 650 AND 655') &&
		!age_number('SELECT COUNT(*) FROM ' . TOPICS_TABLE . ' WHERE topic_id BETWEEN 947650 AND 947699') &&
		!age_number('SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE post_id BETWEEN 948000 AND 948499'), 'Test fixture ID collision');
	age_expect(!age_number("SELECT COUNT(*) FROM $queue"), 'Run the age-pruning fixture with an idle continuation queue');
	$db->sql_transaction('begin');
	foreach (range(AGE_AUTO, AGE_INNER) as $id)
	{
		age_insert(FORUMS_TABLE, array_replace($forum, array('forum_id' => $id, 'forum_name' => 'Age fixture ' . $id,
			'forum_desc' => $id === AGE_AUTO ? json_encode($snapshot) : '', 'parent_id' => 0,
			'left_id' => $edge++, 'right_id' => $edge++, 'forum_parents' => '', 'forum_password' => '',
			'enable_prune' => 1, 'prune_freq' => 3, 'prune_days' => 30, 'prune_viewed' => 20, 'prune_next' => 0,
			'enable_shadow_prune' => 0, 'forum_flags' => 0, 'forum_status' => ITEM_UNLOCKED)));
	}
	$option = age_number('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='f_list'");
	foreach (age_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean IN ('plusfounder','modernmember')") as $admin)
	{
		foreach (array(AGE_MANUAL, AGE_OUTER) as $access)
		{
			age_insert(ACL_USERS_TABLE, array('user_id' => $admin['user_id'], 'forum_id' => $access, 'auth_option_id' => $option,
				'auth_role_id' => 0, 'auth_setting' => ACL_YES));
		}
		$auth->acl_clear_prefetch($admin['user_id']);
	}
	$marker = array('post_visibility' => ITEM_DELETED, 'plus_legacy_erased' => 1, 'plus_public_post' => 1,
		'post_text' => '', 'post_subject' => '', 'post_delete_time' => $old);
	$make = static function ($room, $n, $values = array(), $parts = array(array())) use ($old, $recent, $topic, $post) {
		$id = AGE_TOPIC + $n;
		$first = AGE_POST + 10 * $n;
		$states = array_count_values(array_map(static function ($p) { return $p['post_visibility'] ?? ITEM_APPROVED; }, $parts));
		age_insert(TOPICS_TABLE, array_replace($topic, array('topic_id' => $id, 'forum_id' => $room, 'topic_title' => 'Age case ' . $n,
			'topic_type' => POST_NORMAL, 'topic_status' => ITEM_UNLOCKED, 'topic_moved_id' => 0, 'topic_visibility' => ITEM_APPROVED,
			'topic_time' => $old, 'topic_last_post_time' => $old, 'topic_last_view_time' => $recent, 'topic_first_post_id' => $first,
			'topic_last_post_id' => $first + count($parts) - 1, 'topic_poster' => ANONYMOUS, 'topic_last_poster_id' => ANONYMOUS,
			'topic_attachment' => 0, 'topic_posts_approved' => $states[ITEM_APPROVED] ?? 0, 'topic_posts_unapproved' => $states[ITEM_UNAPPROVED] ?? 0,
			'topic_posts_softdeleted' => $states[ITEM_DELETED] ?? 0, 'poll_title' => '', 'poll_start' => 0, 'poll_last_vote' => 0,
			'plus_legacy_removed' => 0, 'topic_delete_time' => 0), $values));
		foreach ($parts as $index => $changes)
		{
			$pid = $first + $index;
			age_insert(POSTS_TABLE, array_replace($post, array('post_id' => $pid, 'topic_id' => $id, 'forum_id' => $room,
				'poster_id' => ANONYMOUS, 'post_postcount' => 0, 'post_visibility' => ITEM_APPROVED, 'post_time' => $old,
				'post_attachment' => 0, 'post_subject' => 'Age fixture', 'post_text' => '<t>Age fixture ' . $pid . '</t>',
				'post_delete_time' => 0, 'post_delete_user' => 0, 'plus_legacy_erased' => 0, 'plus_public_post' => 0), $changes));
		}
	};
	$make(AGE_AUTO, 0);
	$make(AGE_AUTO, 1, array('topic_status' => ITEM_LOCKED));
	$make(AGE_AUTO, 2, array('topic_last_view_time' => $old), array(array(), array('post_time' => $recent)));
	$make(AGE_AUTO, 3, array(), array(array(), array('post_visibility' => ITEM_UNAPPROVED)));
	$make(AGE_AUTO, 4, array(), array(array(), array('post_visibility' => ITEM_DELETED)));
	$make(AGE_AUTO, 5, array('topic_visibility' => ITEM_DELETED, 'topic_status' => ITEM_LOCKED,
		'plus_legacy_removed' => 1, 'topic_delete_time' => $old), array($marker));
	$make(AGE_AUTO, 6); // Poll option without its topic header.
	$make(AGE_AUTO, 7); // Poll vote without its topic header or options.
	$make(AGE_AUTO, 8, array('topic_type' => POST_STICKY));
	$make(AGE_AUTO, 9, array('topic_type' => POST_ANNOUNCE));
	$make(AGE_AUTO, 10, array('topic_type' => POST_GLOBAL));
	$make(AGE_AUTO, 11, array('poll_start' => $old, 'poll_last_vote' => $old));
	$make(AGE_AUTO, 12, array('poll_start' => $old, 'poll_last_vote' => $recent));
	$make(AGE_AUTO, 13, array('topic_last_post_time' => $recent, 'topic_last_view_time' => $old));
	$make(AGE_AUTO, 14, array('topic_last_post_time' => $recent));
	$make(AGE_AUTO, 15, array(), array(array(), $marker));
	$make(AGE_AUTO, 16, array('topic_visibility' => ITEM_UNAPPROVED), array(array('post_visibility' => ITEM_UNAPPROVED)));
	$make(AGE_AUTO, 17, array('topic_visibility' => ITEM_DELETED), array(array('post_visibility' => ITEM_DELETED)));
	$make(AGE_AUTO, 18, array(), array(array(), array('forum_id' => AGE_INNER)));
	$make(AGE_AUTO, 19, array('topic_status' => ITEM_MOVED, 'topic_moved_id' => AGE_TOPIC));
	$make(AGE_INNER, 20, array('topic_last_post_time' => $recent, 'topic_last_view_time' => $old));
	$make(AGE_INNER, 21, array('topic_last_post_time' => $recent));
	foreach (array(6, 11, 12) as $n)
	{
		age_insert(POLL_OPTIONS_TABLE, array('poll_option_id' => 1, 'topic_id' => AGE_TOPIC + $n, 'poll_option_text' => 'Age option', 'poll_option_total' => 1));
	}
	foreach (array(7, 11, 12) as $n)
	{
		age_insert(POLL_VOTES_TABLE, array('topic_id' => AGE_TOPIC + $n, 'poll_option_id' => 1, 'vote_user_id' => ANONYMOUS, 'vote_user_ip' => '127.0.0.1'));
	}
	foreach (array(25, 26, 27) as $n) { $make(AGE_QUEUE, $n); }
	foreach (array(28, 29) as $n) { $make(AGE_CHANGED, $n); }
	$make(AGE_OUTER, 30);
	$make(AGE_INNER, 31);
	$make(AGE_OUTER, 32, array('topic_type' => POST_STICKY));
	$make(AGE_OUTER, 33, array('topic_type' => POST_STICKY));
	$make(AGE_MANUAL, 35, array(), array(array(), array()));
	$make(AGE_MANUAL, 36, array(), array(array(), $marker));
	$make(AGE_MANUAL, 37, array(), array(array(), array('post_time' => $recent)));
	$make(AGE_MANUAL, 38, array('topic_last_post_time' => $recent, 'topic_last_view_time' => $old));
	sync('forum', 'forum_id', range(AGE_AUTO, AGE_INNER), true, true);
	foreach (array('num_posts' => array(POSTS_TABLE, 'post_visibility'), 'num_topics' => array(TOPICS_TABLE, 'topic_visibility')) as $name => $source)
	{
		$added = age_number("SELECT COUNT(*) FROM {$source[0]} WHERE {$source[1]}=" . ITEM_APPROVED . ' AND forum_id BETWEEN 650 AND 655');
		$config->set($name, (int) $snapshot[$name] + $added, false);
	}
	$config->set('plus_post_delete_policy', 0, false);
	$db->sql_transaction('commit');
	$cache->destroy('sql', FORUMS_TABLE);
}
elseif ($argv[2] === 'rules')
{
	age_room(AGE_AUTO, array('enable_prune' => 0));
	auto_prune(AGE_AUTO, 'posted', 0, 30, 3, false);
	age_state(array(5), array(0, 1, 13, 15));
	age_room(AGE_AUTO, array('enable_prune' => 1, 'prune_next' => time() + 86400));
	auto_prune(AGE_AUTO, 'posted', 0, 30, 3, false);
	age_state(array(5), array(0, 1, 13, 15));
	age_room(AGE_AUTO, array('prune_days' => 0, 'prune_next' => 0));
	auto_prune(AGE_AUTO, 'posted', 0, 0, 3, false);
	age_state(array(5), array(0));
	age_room(AGE_AUTO, array('prune_days' => 30, 'prune_freq' => 0, 'prune_next' => 0));
	auto_prune(AGE_AUTO, 'posted', 0, 30, 0, false);
	age_state(array(5), array(0));
	age_room(AGE_AUTO, array('prune_freq' => 3, 'prune_next' => 0));
	auto_prune(AGE_AUTO, 'posted', 0, 30, 3, false);
	$protected = array(2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 14, 16, 17, 18, 19);
	age_state(array(0, 1, 5, 15), array_merge($protected, array(13)));
	auto_prune(AGE_AUTO, 'viewed', 0, 20, 3, false);
	age_state(array(13), $protected);
	foreach (array(FORUM_FLAG_PRUNE_STICKY => array(array(8), array(9, 10, 11)),
		FORUM_FLAG_PRUNE_ANNOUNCE => array(array(9, 10), array(11)), FORUM_FLAG_PRUNE_POLL => array(array(11), array(12))) as $flag => $expected)
	{
		age_room(AGE_AUTO, array('forum_flags' => $flag, 'prune_next' => 0));
		auto_prune(AGE_AUTO, 'posted', $flag, 30, 3, false);
		age_state($expected[0], $expected[1]);
	}
	age_state(array(8, 9, 10, 11), array(2, 3, 4, 6, 7, 12, 14, 16, 17, 18, 19));
	foreach (array(POLL_OPTIONS_TABLE => 6, POLL_VOTES_TABLE => 7) as $table => $malformed)
	{
		age_expect(age_number("SELECT COUNT(*) FROM $table WHERE topic_id=" . (AGE_TOPIC + $malformed)) === 1 &&
			age_number("SELECT COUNT(*) FROM $table WHERE topic_id=" . (AGE_TOPIC + 11)) === 0 &&
			age_number("SELECT COUNT(*) FROM $table WHERE topic_id=" . (AGE_TOPIC + 12)) === 1, 'Unexpected poll data after pruning');
	}
	$original = age_number('SELECT topic_delete_time FROM ' . TOPICS_TABLE . ' WHERE topic_id=' . (AGE_TOPIC + 5));
	foreach (array(AGE_POST + 50, AGE_POST + 151) as $previous)
	{
		age_expect($original < time() - 110 * 86400 &&
			age_number('SELECT post_delete_time FROM ' . POSTS_TABLE . ' WHERE post_id=' . $previous) === $original, 'An earlier marker was rewritten');
	}
}
elseif ($argv[2] === 'queue')
{
	// Exercise the core scheduler itself, reducing only its pass size from 300 to one.
	$phpbb_dispatcher->addListener('core.prune_sql', static function ($event) {
		if (in_array((int) $event['forum_id'][0], array(AGE_QUEUE, AGE_CHANGED), true)) { $event['prune_limit'] = 1; }
	}, 1);
	foreach (array(AGE_QUEUE, AGE_CHANGED) as $room)
	{
		auto_prune($room, 'posted', 0, 30, 3, false);
		age_expect(age_marker_count($room) === 1 && age_number('SELECT prune_next FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . $room) > time() &&
			age_number("SELECT COUNT(*) FROM $queue WHERE forum_id=$room AND prune_mode='posted' AND days=30 AND frequency=3") === 1,
			'The native limited scheduler did not persist a single unfinished pass');
	}
	age_room(AGE_CHANGED, array('prune_days' => 31));
}
elseif ($argv[2] === 'resume')
{
	// A separate PHP request has no access to the original in-memory selection.
	$cron = $phpbb_container->get('phpbb2plus.community.prune_continuation');
	age_expect($cron->should_run(), 'The saved work is unavailable to cron');
	for ($pass = 0; $pass < 3 && $cron->should_run(); $pass++) { $cron->run(); }
	age_expect(!$cron->should_run(), 'A completed or cancelled continuation was left pending');
	age_state(array(25, 26, 27), array());
	age_expect(age_marker_count(AGE_CHANGED) === 1 && age_number('SELECT COUNT(*) FROM ' . TOPICS_TABLE .
		' WHERE forum_id=' . AGE_CHANGED . ' AND topic_visibility=' . ITEM_APPROVED) === 1, 'Cron continued a replaced forum schedule');
	$cron->run();
	age_expect(age_marker_count(AGE_QUEUE) === 3 && age_marker_count(AGE_CHANGED) === 1, 'Repeating a finished continuation changed the forum');
}
elseif ($argv[2] === 'nested')
{
	$config->set('plus_post_delete_policy', 1, false);
	$called = false;
	$callback = static function ($event) use (&$called) {
		if (!$called && in_array(AGE_TOPIC + 30, $event['topic_list']))
		{
			$called = true;
			auto_prune(AGE_INNER, 'posted', 0, 30, 3, false);
		}
	};
	$phpbb_dispatcher->addListener('core.prune_delete_before', $callback, 1);
	try { auto_prune(AGE_OUTER, 'posted', 0, 30, 3, false); }
	finally { $phpbb_dispatcher->removeListener('core.prune_delete_before', $callback); }
	age_expect($called, 'The second native scheduler did not run inside the first');
	age_state(array(30, 31), array(32, 33));
	// The viewed rule belongs to one scheduled run, even if an administrator postpones the forum in between.
	age_room(AGE_INNER, array('prune_next' => time() + 8 * 86400));
	prune(AGE_INNER, 'viewed', time() - 20 * 86400, 0, true, 5);
	age_state(array(), array(20));
	age_room(AGE_INNER, array('prune_next' => 0));
	auto_prune(AGE_INNER, 'posted', 0, 30, 3, false);
	$db->sql_query('UPDATE ' . TOPICS_TABLE . ' SET topic_last_post_time=' . (time() - 120 * 86400) . ' WHERE topic_id=' . (AGE_TOPIC + 21));
	prune(AGE_INNER, 'posted', time() - 30 * 86400, 0, true, 5);
	age_state(array(), array(21));
	auto_prune(AGE_INNER, 'viewed', 0, 20, 3, false);
	age_state(array(20), array(21));
	// A rule or a contribution can change after phpBB builds its candidate list.
	$flags = FORUM_FLAG_PRUNE_STICKY;
	age_room(AGE_OUTER, array('forum_flags' => $flags, 'prune_next' => 0));
	foreach (array('rule', 'post') as $change)
	{
		$changed = false;
		$callback = static function ($event) use ($db, $change, &$changed) {
			if (!in_array(AGE_TOPIC + 32, $event['topic_list']) || !in_array(AGE_TOPIC + 33, $event['topic_list'])) { return; }
			$changed = true;
			if ($change === 'rule') { age_room(AGE_OUTER, array('prune_days' => 31)); }
			else { $db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_time=' . time() . ' WHERE post_id=' . (AGE_POST + 330)); }
		};
		$phpbb_dispatcher->addListener('core.prune_delete_before', $callback, 1);
		try { auto_prune(AGE_OUTER, 'posted', $flags, 30, 3, false); }
		finally { $phpbb_dispatcher->removeListener('core.prune_delete_before', $callback); }
		age_expect($changed, 'The change did not occur between selection and deletion');
		if ($change === 'rule')
		{
			age_state(array(), array(32, 33));
			age_room(AGE_OUTER, array('prune_days' => 30, 'prune_next' => 0));
		}
		else { age_state(array(32), array(33)); }
	}
	// Match phpBB2 Plus's strict boundary using a fixed cutoff in the native prune call.
	$cutoff = time() - 30 * 86400 - 60;
	age_room(AGE_OUTER, array('prune_next' => 0));
	$db->sql_query('UPDATE ' . POSTS_TABLE . ' SET post_time=' . $cutoff . ' WHERE post_id=' . (AGE_POST + 330));
	prune(AGE_OUTER, 'posted', $cutoff, $flags, true, 5);
	age_state(array(), array(33));
	prune(AGE_OUTER, 'posted', $cutoff + 1, $flags, true, 5);
	age_state(array(33), array());
}
elseif ($argv[2] === 'manual-before')
{
	age_state(array(), array(35, 36, 37, 38));
	age_room(AGE_MANUAL, array('forum_desc' => json_encode(array((int) $config['num_topics'], (int) $config['num_posts']))));
}
elseif ($argv[2] === 'manual-after')
{
	age_state(array(35, 36, 38), array(37));
	age_expect(age_marker_count(AGE_MANUAL) === 3, 'Manual repeat changed the number of marked topics');
	age_expect(age_number('SELECT COUNT(*) FROM ' . POSTS_TABLE . ' WHERE forum_id=' . AGE_MANUAL .
		' AND plus_legacy_erased=1') === 5, 'Unexpected number of old and newly marked posts');
	$room = age_rows('SELECT forum_desc,forum_topics_approved,forum_posts_approved FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . AGE_MANUAL)[0];
	$before = json_decode($room['forum_desc'], true);
	age_expect((int) $room['forum_topics_approved'] === 1 && (int) $room['forum_posts_approved'] === 2 &&
		(int) $config['num_topics'] === $before[0] - 3 && (int) $config['num_posts'] === $before[1] - 4,
		'Forum/board totals do not agree with the native ACP pruning result');
}
elseif ($argv[2] === 'cleanup')
{
	$room = age_rows('SELECT forum_desc FROM ' . FORUMS_TABLE . ' WHERE forum_id=' . AGE_AUTO);
	age_expect($room && ($snapshot = json_decode($room[0]['forum_desc'], true)) && count($snapshot) === 3, 'Missing initial synthetic board state');
	$db->sql_transaction('begin');
	foreach (array(POLL_OPTIONS_TABLE, POLL_VOTES_TABLE, POSTS_TABLE, TOPICS_TABLE) as $table)
	{
		$db->sql_query("DELETE FROM $table WHERE topic_id BETWEEN 947650 AND 947699");
	}
	foreach (age_rows('SELECT user_id FROM ' . ACL_USERS_TABLE . ' WHERE forum_id=' . AGE_MANUAL) as $row) { $auth->acl_clear_prefetch($row['user_id']); }
	foreach (array(ACL_USERS_TABLE, $queue, FORUMS_TABLE) as $table) { $db->sql_query("DELETE FROM $table WHERE forum_id BETWEEN 650 AND 655"); }
	$log_data = $db->sql_escape(serialize(array('Age fixture 653, Age fixture 654')));
	$db->sql_query('DELETE FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PRUNE' AND log_data='$log_data'");
	foreach ($snapshot as $key => $value) { $config->set($key, $value, false); }
	$db->sql_transaction('commit');
	$cache->destroy('sql', FORUMS_TABLE);
}
else { throw new RuntimeException('Unknown age-pruning fixture phase'); }
$complete = true;
