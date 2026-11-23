<?php
namespace RecentTopicsFixture;

function check_forum_topic_pages($db)
{
	global $phpbb_root_path, $phpEx, $lang, $board_config, $rn_now;
	require_once $phpbb_root_path . 'includes/functions_announces.php';

	// The parent fixture owns this database and created these four tables from the installer.
	$before = $rn_now - 86400;
	sql('START TRANSACTION');
	try
	{
		foreach (array('topics', 'posts', 'forums', 'users') as $name)
		{
			sql('DELETE FROM fixture_' . $name);
		}
		seed('fixture_users', array('user_id' => 7, 'username' => 'Member'));
		foreach (array(3, 4, 5, 6) as $id)
		{
			seed('fixture_forums', array('forum_id' => $id, 'forum_name' => 'Forum ' . $id));
		}
		$topics = array(
			// id, forum, type, time of the first post, time of the last post
			array(1, 3, POST_NORMAL, $before - 1000000, $before),
			array(2, 3, POST_NORMAL, $before - 10, $before - 1),
			array(3, 3, POST_NORMAL, $before + 1, $rn_now),
			array(4, 3, POST_STICKY, $before + 1, $rn_now),
			array(5, 3, POST_ANNOUNCE, $before - 20, $before - 10),
			array(6, 3, POST_NEWS, $before - 20, $before - 10),
			array(7, 4, POST_GLOBAL_ANNOUNCE, $before - 20, $before - 10),
			array(8, 4, POST_NORMAL, $before, $rn_now),
			array(9, 5, POST_GLOBAL_ANNOUNCE, $before, $rn_now)
		);
		foreach ($topics as $topic)
		{
			list($id, $forum, $type, $first, $last) = $topic;
			seed('fixture_topics', array('topic_id' => $id, 'forum_id' => $forum, 'topic_type' => $type,
				'topic_poster' => 7, 'topic_first_post_id' => 100 + $id, 'topic_last_post_id' => 200 + $id));
			foreach (array(100 + $id => $first, 200 + $id => $last) as $post_id => $post_time)
			{
				seed('fixture_posts', array('post_id' => $post_id, 'topic_id' => $id, 'forum_id' => $forum,
					'poster_id' => 7, 'post_time' => $post_time));
			}
		}
		sql('COMMIT');
	}
	catch (\Exception $exception)
	{
		sql('ROLLBACK');
		throw $exception;
	}

	$tree = array('type' => array('c', 'f', 'f', 'f', 'f'), 'id' => array(1, 3, 4, 5, 6), 'auth' => array(
		'f3' => array('auth_view' => 1, 'auth_read' => 1), 'f4' => array('auth_view' => 1, 'auth_read' => 1),
		'f5' => array('auth_view' => 1, 'auth_read' => 0), 'f6' => array('auth_view' => 0, 'auth_read' => 1)));
	$other = \phpbb_announcement_forums($tree, 'f3');
	check(array_values($other) === array(4), 'Announcement source forums match the navigation permissions');
	$ids = function ($page) {
		return array_map('intval', array_column($page['rows'], 'topic_id'));
	};
	$page = \phpbb_forum_topics_page($db, 3, $other, $before, 0, 2);
	check($page['total'] === 3 && $page['pages'] === 2 && $page['page'] === 1
		&& $ids($page) === array(7, 5, 6, 4, 3), 'Recent sticky and normal topics follow announcements');
	$page = \phpbb_forum_topics_page($db, 3, $other, $before, 999, 2);
	check($page['start'] === 2 && $page['page'] === 2 && $ids($page) === array(7, 5, 6, 1),
		'Old topic with a recent reply appears at the inclusive boundary on the last real page');
	$page = \phpbb_forum_topics_page($db, 3, array(), 0, 1, 0);
	check($page['per_page'] === 50 && $page['start'] === 0 && $page['total'] === 4
		&& $ids($page) === array(5, 6, 4, 3, 2, 1), 'All dates and local announcements with the installer page size');
	$page = \phpbb_forum_topics_page($db, 3, $other, $rn_now + 1, 20, 2);
	check($page['total'] === 0 && $page['page'] === 1 && $page['pages'] === 1
		&& $ids($page) === array(7, 5, 6), 'Announcements remain when no ordinary topic matches the filter');
	$page = \phpbb_forum_topics_page($db, 6, array(), 0, 99, 2);
	check($page['rows'] === array() && $page['start'] === 0 && $page['pages'] === 1
		&& \generate_pagination('viewforum.php?f=6', 0, 2, 0) === '', 'Empty forum has one page and no navigation links');

	$board_config['announcement_date_display'] = 0;
	check(\get_announces_title($rn_now, 7) === '', 'Disabled announcement dates produce empty text');
	$board_config['announcement_date_display'] = 1;
	check(\get_announces_title($rn_now, -1) === '', 'Permanent announcements have no end date');
	$lang['Announces_from_to'] = '%s to %s';
	$lang['DATE_FORMAT'] = 'Y-m-d';
	check(\get_announces_title($rn_now, 7) === '2026-03-01 to 2026-03-08', 'Dated announcement includes its interval');
	echo "Forum topic lists: activity cutoff, pagination, announcements and dates passed.\n";
}
