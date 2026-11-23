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

function check_topic_post_pages($db)
{
	global $phpbb_root_path, $rn_now, $lang;
	require_once $phpbb_root_path . 'includes/functions_topic_views.php';
	require_once $phpbb_root_path . 'includes/functions_profile_fields.php';
	$before = $rn_now - 86400;
	$topic = array('topic_id' => 1, 'forum_id' => 3);
	// The higher ID 503 is older than 501 and 502, as can happen after an import.
	foreach (array(501 => $rn_now, 502 => $rn_now, 503 => $before + 5, 504 => $rn_now + 1) as $id => $time)
	{
		seed('fixture_posts', array('post_id' => $id, 'topic_id' => 1, 'forum_id' => 3,
			'poster_id' => 7, 'post_time' => $time, 'post_deleted' => $id === 502 ? 1 : 0));
	}
	// 504 has no post text. It must not consume a slot or count towards the page number.
	foreach (array(101, 201, 501, 502, 503) as $id)
	{
		seed('fixture_posts_text', array('post_id' => $id, 'post_subject' => 'Post ' . $id, 'post_text' => 'Body'));
	}
	$ids = function ($page) {
		return array_map('intval', array_column($page['rows'], 'post_id'));
	};
	$page = \phpbb_topic_posts_page($db, $topic, $before, 'asc', 100, 2, array());
	check($page['total'] === 4 && $page['start'] === 2 && $page['pages'] === 2
		&& $ids($page) === array(501, 502) && (int) $page['rows'][1]['post_deleted'] === 1,
		'Filtered counts, timestamps, ties and deleted-post placeholders agree on the last page');
	$topic['requested_post_id'] = 501;
	$topic['requested_post_time'] = $rn_now;
	foreach (array('asc' => array(501, 502), 'desc' => array(502, 501)) as $order => $expected)
	{
		$printed = \phpbb_topic_print_posts($db, $topic, $rn_now, $order);
		$print_rows = $db->sql_fetchrowset($printed);
		$db->sql_freeresult($printed);
		check(array_map('intval', array_column($print_rows, 'post_id')) === $expected
			&& !array_key_exists('user_email', $print_rows[0]), 'Print view respects the period and stable order without loading contact fields');
		$page = \phpbb_topic_posts_page($db, $topic, $before, $order, 100, 2, array());
		check($ids($page) === $expected && $page['page'] === ($order === 'asc' ? 2 : 1),
			'Permalink resolves to the same post in either order, including equal timestamps');
	}
	$page = \phpbb_topic_posts_page($db, $topic, 0, 'desc', 20, 0, array());
	check($ids($page) === array(502, 501, 503, 201, 101) && $page['per_page'] === 15 && $page['total'] === 5,
		'Unfiltered list tolerates an empty legacy page-size setting');
	$page = \phpbb_topic_posts_page($db, $topic, $rn_now + 1, 'asc', 999, 2, array());
	check($page['total'] === 0 && $page['rows'] === array() && $page['start'] === 0
		&& $page['page'] === 1 && $page['pages'] === 1, 'An empty filtered page has a valid position');
	foreach (array('german', 'english') as $locale)
	{
		require $phpbb_root_path . 'language/lang_' . $locale . '/lang_main.php';
		$template = (new \ReflectionClass('Template'))->newInstanceWithoutConstructor();
		$template->vars =& $template->_tpldata['.'][0];
		$template->load_config($phpbb_root_path . 'templates/fisubsilversh', false);
		$template->set_filenames(array('body' => 'viewtopic_body.tpl'));
		$template->assign_vars(array('S_POST_DAYS_ACTION' => 'viewtopic.php?t=1'));
		$template->assign_block_vars('switch_no_matching_posts', array('MESSAGE' => $lang['No_posts_in_period']));
		ob_start();
		try
		{
			$template->pparse('body');
			$html = ob_get_contents();
		}
		finally
		{
			ob_end_clean();
		}
		check(substr_count($html, $lang['No_posts_in_period']) === 1
			&& strpos($html, '<form method="post" action="viewtopic.php?t=1">') !== false,
			'Empty results explain the active filter and retain its form in ' . $locale);
	}
	echo "Topic post lists: filters, post links in both orders, tied timestamps and placeholders passed.\n";
}
