<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
$phase = $argv[2];
if ($phase === 'source')
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$source = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'),
		$argv[1], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
	$source->query('INSERT INTO phpbb_topic_view (topic_id,user_id,view_time,view_count) VALUES
		(1,1,4500,4),(1,1,4700,6),(1,1,-1,-2),(1,-1,5000,12),(1,2,7000,21),(1,87,8000,2147483647),(1,87,8400,40),
		(1,7000000,4200,19),(982004,1,4500,5)');
	return;
}
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$table = $table_prefix . 'plus_topic_readers';
function readers_value($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row ? (int) reset($row) : null;
}
function readers_expect($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$historic = readers_value('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'");
if ($phase === 'converted')
{
	readers_expect($historic !== ANONYMOUS && $config['plus_topic_readers'] && readers_value("SELECT COUNT(*) FROM $table") === 4 &&
		readers_value("SELECT visit_count FROM $table WHERE topic_id=1 AND user_id=$historic AND last_read=4700") === 10 &&
		readers_value("SELECT visit_count FROM $table WHERE topic_id=1 AND user_id=1") === 12 &&
		readers_value("SELECT visit_count FROM $table WHERE topic_id=1 AND user_id=87 AND last_read=8400") === 2147483647 &&
		readers_value('SELECT COUNT(*) FROM ' . $table_prefix . 'plus_readers_import') === 0,
		'Reader conversion mixed source identities, duplicate counts, retired rows or staging data.');
}
elseif ($phase === 'visibility')
{
	readers_expect(readers_value('SELECT user_allow_viewonline FROM ' . USERS_TABLE . ' WHERE user_id=87') === 0,
		'The converted hidden member should already have a private online status.');
	$db->sql_query('UPDATE ' . SESSIONS_TABLE . " SET session_viewonline=0 WHERE session_user_id=$historic");
}
elseif ($phase === 'password')
{
	$hash = $phpbb_container->get('passwords.manager')->hash('readers-fixture');
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $hash)) . ' WHERE forum_id=1');
}
elseif ($phase === 'origin')
{
	$hash = $phpbb_container->get('passwords.manager')->hash('original-room-fixture');
	$db->sql_query('UPDATE ' . FORUMS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', array('forum_password' => $hash)) . ' WHERE forum_id=9');
	$db->sql_query("INSERT INTO $table (topic_id,user_id,forum_id,last_read,visit_count) VALUES (1,2,9,7100,30)");
}
elseif ($phase === 'disable') { $config->set('plus_topic_readers', 0); }
elseif ($phase === 'older')
{
	$tools = (new \phpbb\db\tools\factory())->get($db);
	readers_expect(!$tools->sql_table_exists($table) && !isset($config['plus_topic_readers']), 'The older installation still had reader tracking.');
}
elseif ($phase === 'upgraded')
{
	readers_expect(isset($config['plus_topic_readers']) && !$config['plus_topic_readers'] &&
		readers_value("SELECT COUNT(*) FROM $table") === 0 && readers_value('SELECT COUNT(*) FROM ' . $table_prefix . 'plus_readers_import') === 0,
		'Updating a prior installation unexpectedly enabled tracking or fabricated historical visits.');
}
elseif ($phase === 'cleanup')
{
	$db->sql_query('UPDATE ' . SESSIONS_TABLE . " SET session_viewonline=1 WHERE session_user_id=$historic");
	$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='' WHERE forum_id IN (1,9)");
	$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id IN (1,9)');
	$db->sql_query("DELETE FROM $table WHERE topic_id=1 AND user_id=2 AND forum_id=9");
	$config->set('plus_topic_readers', 1);
}
elseif ($phase === 'lifecycle')
{
	require_once './includes/functions_admin.php';
	$user->session_begin();
	$user->setup();
	$topics = TOPICS_TABLE;
	$posts = POSTS_TABLE;
	$db->sql_transaction('begin');
	try
	{
		$db->sql_query("INSERT INTO $topics (topic_id,forum_id,topic_title) VALUES (983501,1,'First'),(983502,1,'Second'),(983503,2,'Other')");
		$db->sql_query("INSERT INTO $posts (post_id,forum_id,topic_id,poster_id,post_subject,post_text,post_checksum,post_username,poster_ip,bbcode_uid,bbcode_bitfield,post_edit_reason,post_delete_reason)
			VALUES (983501,1,983501,2,'First','','','','','','','',''),(983502,1,983501,2,'Second','','','','','','','',''),
			(983503,2,983503,2,'Other','','','','','','','','')");
		$db->sql_query("INSERT INTO $table (topic_id,user_id,forum_id,last_read,visit_count) VALUES (983501,2,1,20,4),(983502,2,1,10,7),(983503,2,2,25,15)");
		move_posts(array(983501), 983502, false);
		readers_expect(readers_value("SELECT visit_count FROM $table WHERE topic_id=983501") === 4, 'Partial move took the source readership.');
		move_posts(array(983502), 983502, false);
		readers_expect(readers_value("SELECT visit_count FROM $table WHERE topic_id=983502 AND last_read=20") === 11 &&
			readers_value("SELECT COUNT(*) FROM $table WHERE topic_id=983501") === 0, 'Full merge lost or duplicated visit counts.');
		move_posts(array(983503), 983502, false);
		readers_expect(readers_value("SELECT visit_count FROM $table WHERE topic_id=983502 AND forum_id=1") === 11 &&
			readers_value("SELECT visit_count FROM $table WHERE topic_id=983502 AND forum_id=2") === 15, 'Cross-forum merge lost the original readers or their audience.');
		move_topics(array(983502), 9, false);
		readers_expect(readers_value("SELECT forum_id FROM $topics WHERE topic_id=983502") === 9 &&
			readers_value("SELECT COUNT(*) FROM $table WHERE topic_id=983502 AND forum_id IN (1,2)") === 2, 'Moving a whole topic changed its original audiences.');
		delete_topics('topic_id', array(983502), false, false, false);
		readers_expect(readers_value("SELECT COUNT(*) FROM $table WHERE topic_id=983502") === 0, 'Native topic deletion left reader history.');
		// Forum content deletion has its own native path and may not receive any attachment topic IDs.
		$db->sql_query("UPDATE $topics SET forum_id=98811 WHERE topic_id=983501");
		$db->sql_query("INSERT INTO $table (topic_id,user_id,forum_id,last_read,visit_count) VALUES (983501,2,1,20,4),(1,2,98811,10,1)");
		require_once './includes/acp/acp_forums.php';
		(new acp_forums())->delete_forum_content(98811);
		readers_expect(readers_value("SELECT COUNT(*) FROM $table WHERE topic_id=983501 OR forum_id=98811") === 0, 'Bulk forum cleanup retained a moved topic or an old audience.');
	}
	finally { $db->sql_transaction('rollback'); }
}
else { throw new RuntimeException('Unknown topic reader fixture phase'); }
