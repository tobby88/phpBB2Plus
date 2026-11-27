<?php
// Isolate UTC boundary, publication and password cases on the disposable converted board.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }

$store = $phpbb_container->get('config_text');
$key = 'plus_test_news_archive_dates';
$topics = TOPICS_TABLE;
$posts = POSTS_TABLE;
$forums = FORUMS_TABLE;
$users = USERS_TABLE;
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
};
$update = static function ($table, $field, $id, array $values) use ($db) {
	$db->sql_query("UPDATE $table SET " . $db->sql_build_array('UPDATE', $values) . " WHERE $field=" . (int) $id);
};

if ($argv[2] === 'setup')
{
	$state = array(
		'topics' => $rows("SELECT topic_id,topic_title,topic_time FROM $topics WHERE topic_id IN (1,2,7,601,695)"),
		'post' => $rows("SELECT post_id,post_visibility FROM $posts WHERE post_id=40"),
		'forum' => $rows("SELECT forum_id,forum_password FROM $forums WHERE forum_id=7"),
		'member' => $rows("SELECT user_id,user_timezone,user_lang FROM $users WHERE username='ModernMember'"),
		'board' => array('board_timezone' => $config['board_timezone'], 'default_lang' => $config['default_lang'],
			'plus_news_page_size' => $config['plus_news_page_size']),
	);
	if ($store->get($key) !== null || count($state['topics']) !== 5 || !$state['member'] || !$state['post'] || !$state['forum'])
	{
		throw new RuntimeException('Missing an original news archive fixture or a previous restoration');
	}
	$store->set($key, json_encode($state));
	$config->set('board_timezone', 'Pacific/Honolulu');
	$config->set('default_lang', 'de');
	$update($users, 'user_id', $state['member'][0]['user_id'], array('user_timezone'=>'Asia/Kathmandu', 'user_lang'=>'en'));
	$dates = array(1=>'2004-02-29 23:59:59', 2=>'2004-03-01 00:00:00', 7=>'2004-02-29 00:00:00',
		601=>'2012-04-03', 695=>'2008-11-03');
	foreach ($dates as $id => $date)
	{
		$fields = array('topic_time'=>(new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp());
		if ($id === 1) { $fields['topic_title'] = 'Archiv &amp; Grüße'; }
		$update($topics, 'topic_id', $id, $fields);
	}
	return;
}

$state = json_decode($store->get($key) ?? '', true);
if (!$state) { throw new RuntimeException('News archive setup has not run'); }
switch ($argv[2])
{
	case 'paged':
		$config->set('plus_news_page_size', 1);
		break;
	case 'locked':
		$update($forums, 'forum_id', 7, array('forum_password'=>$phpbb_container->get('passwords.manager')->hash('disposable archive fixture')));
		break;
	case 'unapproved':
		$update($forums, 'forum_id', 7, array('forum_password'=>$state['forum'][0]['forum_password']));
		$update($posts, 'post_id', 40, array('post_visibility'=>ITEM_UNAPPROVED));
		break;
	case 'restore':
		foreach ($state['topics'] as $row)
		{
			$update($topics, 'topic_id', $row['topic_id'], array('topic_title'=>$row['topic_title'], 'topic_time'=>$row['topic_time']));
		}
		$update($posts, 'post_id', 40, array('post_visibility'=>$state['post'][0]['post_visibility']));
		$update($forums, 'forum_id', 7, array('forum_password'=>$state['forum'][0]['forum_password']));
		$member = $state['member'][0];
		$update($users, 'user_id', $member['user_id'], array('user_timezone'=>$member['user_timezone'], 'user_lang'=>$member['user_lang']));
		foreach ($state['board'] as $name => $value) { $config->set($name, $value); }
		$store->delete($key);
		break;
	default: exit(2);
}
