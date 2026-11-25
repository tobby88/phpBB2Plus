<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[2])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[2]) { exit(2); }
require_once './includes/acp/auth.php';
$user->session_begin();
$user->setup();

function print_fixture_row($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	if (!$row) { throw new RuntimeException('Missing print view fixture record'); }
	return $row;
}

$member = print_fixture_row('SELECT * FROM ' . USERS_TABLE . " WHERE username='HistoricMember'");
$rights = new auth_admin();
switch ($argv[1])
{
	case 'setup':
		$guest = print_fixture_row('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id=' . ANONYMOUS);
		$auth->acl($guest);
		if (!$auth->acl_get('f_print', 15) || !$auth->acl_get('f_read', 15) || $auth->acl_get('f_post', 15))
		{ throw new RuntimeException('A guest cannot print a publicly readable phpBB2 topic without also obtaining posting rights'); }
		$auth->acl($member);
		if (!$auth->acl_get('f_read', 10) || !$auth->acl_get('f_print', 10) || !(int) $config['plus_print_read_acl'])
		{ throw new RuntimeException('Separate source viewing and reading groups lost their combined right to print'); }
		$topic = print_fixture_row('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1');
		$post = print_fixture_row('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=1');
		$now = time();
		$day = 86400;
		$dates = array(0 => $now-370*$day, 1 => $now-364*$day-3600, 2 => $now-13*$day, 3 => $now-3*$day, 4 => $now-36*3600);
		$db->sql_transaction('begin');
		foreach (array(981010 => ITEM_APPROVED, 981011 => ITEM_UNAPPROVED, 981012 => ITEM_DELETED) as $id => $visibility)
		{
			$first = $id === 981010 ? 982000 : $id;
			$t = array_replace($topic, array('topic_id' => $id, 'forum_id' => 15, 'topic_title' => 'Print &amp; Grüße ' . $id,
				'topic_visibility' => $visibility, 'topic_first_post_id' => $first, 'topic_last_post_id' => $id === 981010 ? 982071 : $first,
				'topic_posts_approved' => $id === 981010 ? 69 : 1, 'topic_posts_softdeleted' => $id === 981010 ? 2 : 0,
				'topic_posts_unapproved' => $id === 981010 ? 1 : 0, 'plus_news_cat' => 0, 'poll_start' => 0, 'topic_attachment' => 0));
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
			for ($index = 0; $index < ($id === 981010 ? 72 : 1); ++$index)
			{
				$state = $index === 68 || $index === 69 ? ITEM_DELETED : ($index === 70 ? ITEM_UNAPPROVED : ITEM_APPROVED);
				$p = array_replace($post, array('post_id' => $first+$index,'forum_id' => 15,'topic_id' => $id,'post_time' => $dates[$index] ?? $now-7200,
					'post_visibility' => $state,'plus_public_post' => $index < 69 || $index === 71 ? 1 : 0,'poster_id' => 2,
					'post_subject' => 'Print subject ' . ($first+$index), 'post_text' => '<r><B>Print body ' . ($first+$index) . '</B></r>',
					'post_attachment' => 0,'post_edit_count' => 0));
				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
			}
		}
		$db->sql_transaction('commit');
		break;
	case 'ignore':
		$db->sql_query('INSERT INTO ' . ZEBRA_TABLE . ' ' . $db->sql_build_array('INSERT', array('user_id' => (int) $member['user_id'], 'zebra_id' => 2, 'foe' => 1, 'friend' => 0)));
		break;
	case 'unignore':
		$db->sql_query('DELETE FROM ' . ZEBRA_TABLE . ' WHERE user_id=' . (int) $member['user_id'] . ' AND zebra_id=2 AND foe=1 AND friend=0');
		break;
	case 'deny':
	case 'restore':
		$setting = $argv[1] === 'deny' ? ACL_NEVER : ACL_NO;
		foreach (array(1, 10) as $forum) { $rights->acl_set('user', $forum, (int) $member['user_id'], array('f_print' => $setting)); }
		break;
	case 'before-print-import':
		// Replay the real upgrade on a board where the forum owner already disabled printing.
		$rights->acl_set('user', 1, (int) $member['user_id'], array('f_print' => ACL_NEVER));
		$class = \phpbb2plus\community\migrations\v131_print_topic::class;
		$table = $table_prefix . 'migrations';
		$row = print_fixture_row('SELECT migration_name FROM ' . $table . ' WHERE ' . $db->sql_in_set('migration_name', array($class, '\\' . $class)));
		$name = $row['migration_name'];
		$db->sql_query("DELETE FROM $table WHERE migration_name='" . $db->sql_escape($name) . "'");
		$config->delete('plus_print_read_acl');
		$migrator = $phpbb_container->get('migrator');
		$migrator->load_migration_state();
		$migrator->set_migrations(array($name));
		for ($step = 0; !$migrator->finished() && $step < 6; ++$step) { $migrator->update(); }
		if (!$migrator->finished() || !isset($config['plus_print_read_acl']) || (int) $config['plus_print_read_acl'] !== 0)
		{ throw new RuntimeException('The native upgrade changed an earlier installation to fresh-import permissions'); }
		break;
	case 'after-print-import':
		$rights->acl_set('user', 1, (int) $member['user_id'], array('f_print' => ACL_NO));
		$config->set('plus_print_read_acl', 1);
		break;
	case 'password':
		$forum = print_fixture_row('SELECT forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=15');
		if ($forum['forum_password'] !== '') { throw new RuntimeException('The synthetic print forum already has a password'); }
		$hash = $phpbb_container->get('passwords.manager')->hash('local-print-room');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $hash)) . ' WHERE forum_id=15');
		break;
	case 'cleanup':
		$db->sql_transaction('begin');
		$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE forum_id=15 AND topic_id BETWEEN 981010 AND 981012');
		$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE forum_id=15 AND topic_id BETWEEN 981010 AND 981012');
		$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=15');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='' WHERE forum_id=15");
		$db->sql_transaction('commit');
		break;
	default: exit(2);
}
