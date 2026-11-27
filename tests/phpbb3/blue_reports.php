<?php
// Disposable source and native reports for the public and restricted test forums.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
const BLUE_BIG = '18446744073709551615';
if ($argv[2] === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$old = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[1],
		(int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$old->query('ALTER TABLE phpbb_posts MODIFY post_bluecard DECIMAL(20,0) NULL');
	foreach (array(946210 => 1, 946220 => 7) as $id => $forum)
	{
		$last = $id + (int) ($forum === 1);
		$old->query("INSERT INTO phpbb_topics (topic_id,forum_id,topic_title,topic_poster,topic_time,topic_replies,topic_first_post_id,topic_last_post_id)
			VALUES ($id,$forum,'Blue source $id',-1,1010101010," . ($last - $id) . ",$id,$last)");
		for ($p = $id; $p <= $last; ++$p)
		{
			$count = $p === 946210 ? BLUE_BIG : ($p === 946220 ? '4' : '0');
			$old->query("INSERT INTO phpbb_posts (post_id,topic_id,forum_id,poster_id,poster_ip,post_time,post_bluecard)
				VALUES ($p,$id,$forum,-1,'c0000205',1010101010,'$count')");
			$old->query("INSERT INTO phpbb_posts_text (post_id,post_subject,post_text) VALUES ($p,'Blue source $p','Current body $p')");
		}
	}
	// The normal converter preserves these damaged rows and erased places using recovery policies.
	$old->query('UPDATE phpbb_posts SET post_bluecard=7 WHERE post_id IN (31,14711,14712,14713,14720,14722)');
	$old->query('UPDATE phpbb_posts SET post_bluecard=-2 WHERE post_id=45');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './'; $phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
require_once __DIR__ . '/../../phpBB3/install/convertors/plus_blue_reports.php';
function blue_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}
function blue_require($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function blue_cases($post)
{
	return blue_rows('SELECT * FROM ' . REPORTS_TABLE . ' WHERE pm_id=0 AND post_id=' . (int) $post . ' ORDER BY report_id');
}
function blue_batch($rows)
{
	global $db;
	$source = array();
	foreach ($rows as $id => $count)
	{
		$source[$id] = array('post_id' => $id, 'topic_id' => $id === 946211 ? 946210 : $id,
			'forum_id' => $id === 946220 ? 7 : 1, 'post_bluecard' => $count);
	}
	plus_store_blue_reports($db, $source);
}
function blue_notifications()
{
	return blue_rows('SELECT n.notification_id FROM ' . NOTIFICATIONS_TABLE . ' n JOIN ' . NOTIFICATION_TYPES_TABLE . ' t
		ON t.notification_type_id=n.notification_type_id WHERE n.item_id IN (946210,946211,946220)
		AND t.notification_type_name IN (\'notification.type.report_post\',\'notification.type.report_post_closed\') ORDER BY n.notification_id');
}
$state = $phpbb_container->get('config_text');
$key = 'plus_test_blue_reports';
$saved = json_decode((string) $state->get($key), true);
$member = (int) blue_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='HistoricMember'")[0]['user_id'];
$founder = (int) blue_rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username='ModernMember'")[0]['user_id'];
$options = array_column(blue_rows('SELECT auth_option,auth_option_id FROM ' . ACL_OPTIONS_TABLE .
	" WHERE auth_option IN ('f_report','m_report')"), 'auth_option_id', 'auth_option');
$acl = "forum_id=1 AND user_id=$member AND auth_role_id=0 AND " . $db->sql_in_set('auth_option_id', $options);
switch ($argv[2])
{
	case 'prepare':
		blue_require(!$saved, 'Blue Card fixture still active');
		foreach (array(946210 => BLUE_BIG, 946220 => '4') as $id => $count)
		{
			$cases = blue_cases($id);
			$r = $cases[0] ?? array();
			blue_require(count($cases) === 1 && $r['plus_blue_count'] === $count && (int) $r['plus_blue_only'] === 1 &&
				(int) $r['report_closed'] === 0 && (int) $r['user_notify'] === 0 && (int) $r['user_id'] === ANONYMOUS &&
				(int) $r['report_time'] === 0 && $r['report_text'] === '' && $r['reported_post_text'] === '',
				"Missing count or invented historical details on $id");
			$flags = blue_rows('SELECT p.post_reported,t.topic_reported,p.plus_blue_imported FROM ' . POSTS_TABLE . ' p
				JOIN ' . TOPICS_TABLE . " t ON t.topic_id=p.topic_id WHERE p.post_id=$id")[0];
			blue_require(!in_array(0, array_map('intval', $flags), true), 'Native case markers are missing');
		}
		blue_require(!blue_rows('SELECT report_id FROM ' . REPORTS_TABLE .
			' WHERE post_id IN (31,45,14711,14712,14713,14720,14722,946211)') && !blue_notifications(),
			'Erased, reset, mismatched or missing parents generated reports or notifications');
		foreach (array(array(POSTS_TABLE, 'post', 946211, ITEM_UNAPPROVED), array(TOPICS_TABLE, 'topic', 946210, ITEM_DELETED)) as $case)
		{
			list($table, $kind, $id, $visibility) = $case;
			$db->sql_query("UPDATE $table SET {$kind}_visibility=$visibility WHERE {$kind}_id=$id");
			try
			{
				blue_batch(array(946211 => '13'));
				blue_require(!blue_cases(946211), 'A hidden native post or topic acquired an open report');
			}
			finally { $db->sql_query("UPDATE $table SET {$kind}_visibility=" . ITEM_APPROVED . " WHERE {$kind}_id=$id"); }
		}
		$saved = array('acl' => blue_rows('SELECT * FROM ' . ACL_USERS_TABLE . " WHERE $acl"),
			'lang' => blue_rows('SELECT user_lang FROM ' . USERS_TABLE . " WHERE user_id=$founder")[0]['user_lang']);
		$state->set($key, json_encode($saved));
		$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE $acl");
		foreach ($options as $option)
		{
			$db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . " (user_id,forum_id,auth_option_id,auth_role_id,auth_setting) VALUES ($member,1,$option,0,1)");
		}
		$auth->acl_clear_prefetch($member);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='de' WHERE user_id=$founder");
		echo json_encode(array(blue_cases(946210)[0]['report_id'], blue_cases(946220)[0]['report_id']));
		break;
	case 'en':
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='en' WHERE user_id=$founder");
		// Complete the later public reporting forms as an ordinary member.
		$db->sql_query('UPDATE ' . ACL_USERS_TABLE . ' SET auth_setting=' . ACL_NEVER .
			" WHERE $acl AND auth_option_id=" . (int) $options['m_report']);
		$auth->acl_clear_prefetch($member);
		break;
	case 'closed':
		blue_batch(array(946210 => BLUE_BIG, 946220 => '4'));
		$cases = blue_cases(946210);
		blue_require(count($cases) === 1 && $cases[0]['plus_blue_count'] === BLUE_BIG && (int) $cases[0]['report_closed'] === 1 &&
			!(int) blue_rows('SELECT post_reported FROM ' . POSTS_TABLE . ' WHERE post_id=946210')[0]['post_reported'] &&
			!(int) blue_rows('SELECT topic_reported FROM ' . TOPICS_TABLE . ' WHERE topic_id=946210')[0]['topic_reported'],
			'A closed aggregate was reopened on resume');
		break;
	case 'native':
		$later = blue_cases(946210);
		$open = blue_cases(946211);
		blue_require(count($later) === 2 && (int) $later[0]['report_closed'] === 1 && $later[1]['plus_blue_count'] === '' &&
			(int) $later[1]['user_id'] === $member && count($open) === 1 && (int) $open[0]['user_id'] === $member &&
			(int) $open[0]['report_time'] > 0 && $open[0]['report_text'] === 'Native report 946211' &&
			strpos($open[0]['reported_post_text'], 'Current body 946211') !== false,
			'Expected an ordinary native report with the real reporter, time and snapshot');
		$notifications = blue_notifications();
		// An old native target without the source counter cannot supply any historical evidence.
		$src_db = $db; $convert = (object) array('src_table_prefix' => $table_prefix);
		plus_import_blue_reports();
		blue_require(blue_cases(946211) === $open, 'No-source upgrade modified an ordinary native report');
		blue_batch(array(946210 => BLUE_BIG, 946211 => '13', 946220 => '4'));
		blue_batch(array(946211 => '13'));
		$open[0]['plus_blue_count'] = '13';
		blue_require(blue_cases(946211) === $open && blue_cases(946210) === $later && blue_notifications() === $notifications,
			'Resuming replaced existing report facts, duplicated a report or created a notification');
		echo json_encode(array($later[1]['report_id'], $open[0]['report_id']));
		break;
	case 'deleted':
		blue_batch(array(946220 => '4'));
		blue_require(!blue_cases(946220) && !(int) blue_rows('SELECT topic_reported FROM ' . TOPICS_TABLE .
			' WHERE topic_id=946220')[0]['topic_reported'], 'A deleted report reappeared on resume');
		break;
	case 'cleanup':
		if (!$saved) { break; }
		$db->sql_query('DELETE FROM ' . ACL_USERS_TABLE . " WHERE $acl");
		foreach ($saved['acl'] as $row) { $db->sql_query('INSERT INTO ' . ACL_USERS_TABLE . ' ' . $db->sql_build_array('INSERT', $row)); }
		$auth->acl_clear_prefetch($member);
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_lang='" . $db->sql_escape($saved['lang']) . "' WHERE user_id=$founder");
		require_once './includes/functions_admin.php';
		$user->session_begin();
		$user->setup();
		delete_topics('topic_id', array(946210, 946220));
		$state->delete($key);
		break;
	default: exit(2);
}
