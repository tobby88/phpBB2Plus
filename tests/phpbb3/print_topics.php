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
	case 'markers-import':
	case 'markers-upgrade':
		require __DIR__ . '/removed_topics.php';
		break;
	case 'markers-prune':
		require __DIR__ . '/prune_markers.php';
		break;
	case 'markers-deny':
	case 'markers-allow':
		$rights->acl_set('user', 15, (int) $member['user_id'], array('f_print' => $argv[1] === 'markers-deny' ? ACL_NEVER : ACL_NO));
		break;
	case 'markers-cleanup':
		$db->sql_query('DELETE FROM ' . POLL_OPTIONS_TABLE . ' WHERE topic_id=805105');
		$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE forum_id=15 AND topic_id IN (805102,805103,805105) AND post_id IN (805262,805263,805265)');
		$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE forum_id=15 AND topic_id IN (805102,805103,805105)');
		break;
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
		$sample = "Print body 982067\n[url=https://example.invalid/archive?a=1&b=2]Weg[/url]\n" .
			"[list=a][*]Alpha[*]Beta[/list]\n[code]x<&\n  Keep &amp; 😀[/code]\n[quote=\"EditorFixture\"]Zitatprobe[/quote]\n:pf:";
		// Match phpBB's request normalization before the post parser decodes form entities.
		$sample = htmlspecialchars($sample, ENT_QUOTES, 'UTF-8');
		$uid = $bits = '';
		$flags = 0;
		generate_text_for_storage($sample, $uid, $bits, $flags, true, true, true);
		$db->sql_transaction('begin');
		foreach (array(981010 => ITEM_APPROVED, 981011 => ITEM_UNAPPROVED, 981012 => ITEM_DELETED, 981013 => ITEM_APPROVED,
			981014 => ITEM_APPROVED) as $id => $visibility)
		{
			$first = $id === 981010 ? 982000 : $id;
			$t = array_replace($topic, array('topic_id' => $id, 'forum_id' => 15, 'topic_title' => 'Print &amp; Grüße ' . $id,
				'topic_visibility' => $visibility, 'topic_first_post_id' => $first, 'topic_last_post_id' => $id === 981010 ? 982071 : $first,
				'topic_posts_approved' => $id === 981010 ? 69 : 1, 'topic_posts_softdeleted' => $id === 981010 ? 2 : 0,
				'topic_posts_unapproved' => $id === 981010 ? 1 : 0, 'plus_news_cat' => 0, 'poll_start' => 0, 'topic_attachment' => 0));
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT', $t));
			$count = $id === 981010 ? 72 : ($id === 981014 ? 0 : 1);
			for ($index = 0; $index < $count; ++$index)
			{
				$state = $index === 68 || $index === 69 ? ITEM_DELETED : ($index === 70 ? ITEM_UNAPPROVED : ITEM_APPROVED);
				$p = array_replace($post, array('post_id' => $first+$index,'forum_id' => 15,'topic_id' => $id,'post_time' => $dates[$index] ?? $now-7200,
					'post_visibility' => $state,'plus_public_post' => $index < 69 || $index === 71 ? 1 : 0,'poster_id' => 2,
					'post_subject' => 'Print subject ' . ($first+$index), 'post_text' => '<r><B>Print body ' . ($first+$index) . '</B></r>',
					'post_attachment' => 0,'post_edit_count' => 0));
				if ($index === 67)
				{
					$p['post_text'] = $sample;
					$p['bbcode_uid'] = $uid;
					$p['bbcode_bitfield'] = $bits;
				}
				if ($id === 981013)
				{
					$p['poster_id'] = 99001801;
					$p['post_username'] = 'UnknownRetiredWriter';
				}
				$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT', $p));
			}
		}
		$db->sql_transaction('commit');
		break;
	case 'text-limits':
		$language = $phpbb_container->get('language');
		$topic = array('topic_title' => 'A &amp; B', 'forum_name' => 'Text');
		$post = array('BODY' => str_repeat('A', 1024*1024), 'AUTHOR' => 'Fixture');
		list($file, $length) = \phpbb2plus\community\service\topic_archive::build($topic, array($post,$post,$post), $language);
		if ($length < 3*1024*1024 || fstat($file)['size'] !== $length || ftell($file) !== 0) { throw new RuntimeException('Incomplete text spool'); }
		fclose($file);
		$too_large = false;
		try { \phpbb2plus\community\service\topic_archive::build($topic, array($post,$post,$post), $language, $length-1); }
		catch (\LengthException $ok) { $too_large = true; }
		if (!$too_large || \phpbb2plus\community\service\topic_archive::MAX_BYTES !== 64*1024*1024) { throw new RuntimeException('Missing archive byte limit'); }
		foreach (array('AUX' => 'topic-AUX', '&#x2f;' => 'topic-51', str_repeat('Ä', 91) => str_repeat('Ä', 90)) as $title => $name)
		{
			$header = \phpbb2plus\community\service\topic_text::disposition($title, 51);
			if (substr($header, -strlen(rawurlencode($name . '.txt'))) !== rawurlencode($name . '.txt')) { throw new RuntimeException('Invalid download filename or UTF-8 boundary'); }
		}
		break;
	case 'text-snapshot':
		require __DIR__ . '/topic_exports.php';
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
		$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE forum_id=15 AND topic_id BETWEEN 981010 AND 981014');
		$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE forum_id=15 AND topic_id BETWEEN 981010 AND 981014');
		$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=15');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='' WHERE forum_id=15");
		$db->sql_transaction('commit');
		break;
	default: exit(2);
}
