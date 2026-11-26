<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './'; $phpEx='php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$rows = static function ($sql) use ($db) {
	$r=$db->sql_query($sql); $rows=$db->sql_fetchrowset($r); $db->sql_freeresult($r); return $rows;
};
$stamp = static function ($date) { return (new DateTimeImmutable($date,new DateTimeZone('Europe/Berlin')))->format('U'); };
$store=$phpbb_container->get('config_text'); $key='plus_test_portal_calendar';
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		if ((int) $config['plus_cal_birthday_days'] !== 9) { throw new RuntimeException('The converted portal lost its configured birthday window'); }
		$topic=$rows('SELECT * FROM ' . TOPICS_TABLE . ' WHERE topic_id=1')[0];
		$post=$rows('SELECT * FROM ' . POSTS_TABLE . ' WHERE post_id=32')[0];
		$members=$rows('SELECT user_id,user_timezone,user_birthday FROM ' . USERS_TABLE . " WHERE user_id IN (1,2) OR username_clean='historicmember'");
		$forum=$rows('SELECT forum_password FROM ' . FORUMS_TABLE . ' WHERE forum_id=1')[0];
		$keys=array('board_timezone','posts_per_page','search_interval','search_anonymous_interval','plus_cal_birthday_days');
		$original=array(); foreach ($keys as $k) { $original[$k]=$config[$k]; }
		if ($store->get($key) !== null || $forum['forum_password'] !== '' ||
			$rows('SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id BETWEEN 912001 AND 912010') ||
			$rows('SELECT post_id FROM ' . POSTS_TABLE . ' WHERE post_id BETWEEN 912011 AND 912024'))
		{ throw new RuntimeException('Unexpected calendar fixture state'); }
		$bylines=$table_prefix . 'plus_bylines';
		$access = array_column($rows('SELECT session_id FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=1 AND user_id=' . ANONYMOUS),'session_id');
		$store->set($key,json_encode(array($members,$original,$forum['forum_password'],$access)));
		foreach (array('board_timezone'=>'Europe/Berlin','posts_per_page'=>2,'search_interval'=>0,'search_anonymous_interval'=>0,'plus_cal_birthday_days'=>7) as $k=>$v) { $config->set($k,$v); }
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_timezone='Europe/Berlin' WHERE " . $db->sql_in_set('user_id',array_column($members,'user_id')));
		$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Berlin'));
		$db->sql_query('UPDATE ' . USERS_TABLE . " SET user_birthday='" . sprintf('%2d-%2d-1990',(int)$today->format('j'),(int)$today->format('n')) . "' WHERE username_clean='historicmember'");
		for ($i=1; $i<=10; $i++)
		{
			$start=$today->modify('+' . max(0,$i-2) . ' days');
			if ($i===1) { $start=$today->modify('-1 day'); }
			if ($i===10) { $start=$today->modify('-2 days'); }
			$row=array_replace($topic,array('topic_id'=>912000+$i,'forum_id'=>$i===2 ? 7 : 1,'topic_title'=>'Portal date ' . $i,
				'plus_cal_start'=>$start->format('U'),'plus_cal_end'=>$start->modify('+2 days')->format('U'),
				'topic_moved_id'=>0,'topic_visibility'=>$i===10 ? ITEM_DELETED : ($i===3 ? ITEM_UNAPPROVED : ITEM_APPROVED),'topic_poster'=>2,
				'topic_first_post_id'=>0,'topic_last_post_id'=>0));
			$db->sql_query('INSERT INTO ' . TOPICS_TABLE . ' ' . $db->sql_build_array('INSERT',$row));
		}
		$dates=array(11=>'2024-03-30T22:59:59Z',12=>'2024-03-30T23:00:00Z',13=>'2024-03-31T01:30:00Z',14=>'2024-03-31T21:59:59Z',15=>'2024-03-31T22:00:00Z',
			16=>'2024-10-26T21:59:59Z',17=>'2024-10-26T22:00:00Z',18=>'2024-10-27T00:30:00Z',19=>'2024-10-27T01:30:00Z',20=>'2024-10-27T22:59:59Z',21=>'2024-10-27T23:00:00Z',
			22=>'2024-03-31T03:00:00Z',23=>'2024-03-31T04:00:00Z',24=>'2024-03-31T05:00:00Z');
		foreach ($dates as $i=>$value)
		{
			$row=array_replace($post,array('post_id'=>912000+$i,'topic_id'=>912000+($i===22 ? 2 : ($i===24 ? 3 : 1)),
				'forum_id'=>$i===22 ? 7 : 1,'post_time'=>$stamp($value),'post_visibility'=>$i===23 ? ITEM_UNAPPROVED : ITEM_APPROVED,
				'poster_id'=>ANONYMOUS,'post_username'=>$i===13 ? $post['post_username'] : 'GuestFixture','post_subject'=>'Calendar post ' . $i,'post_text'=>'<t>Synthetic calendar post ' . $i . '</t>'));
			$db->sql_query('INSERT INTO ' . POSTS_TABLE . ' ' . $db->sql_build_array('INSERT',$row));
		}
		$byline=$rows("SELECT * FROM $bylines WHERE post_id=32")[0];
		$byline['post_id']=912013;
		$db->sql_query("INSERT INTO $bylines " . $db->sql_build_array('INSERT',$byline));
		echo $today->format('Y-m-d');
		break;
	case 'password':
		$hashed=$phpbb_container->get('passwords.manager')->hash('calendar-local-fixture');
		$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='" . $db->sql_escape($hashed) . "' WHERE forum_id=1");
		break;
	case 'cleanup':
		list($members,$original,$password,$access)=json_decode($store->get($key),true);
		foreach ($original as $name=>$value) { $config->set($name,$value); }
		foreach ($members as $m)
		{
			$id=(int)$m['user_id']; unset($m['user_id']);
			$db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $db->sql_build_array('UPDATE',$m) . " WHERE user_id=$id");
		}
		$db->sql_query('UPDATE ' . FORUMS_TABLE . " SET forum_password='" . $db->sql_escape($password) . "' WHERE forum_id=1");
		$db->sql_query('DELETE FROM ' . FORUMS_ACCESS_TABLE . ' WHERE forum_id=1 AND user_id=' . ANONYMOUS .
			($access ? ' AND ' . $db->sql_in_set('session_id',$access,true) : ''));
		$db->sql_query("DELETE FROM {$table_prefix}plus_bylines WHERE post_id=912013");
		$db->sql_query('DELETE FROM ' . POSTS_TABLE . ' WHERE post_id BETWEEN 912011 AND 912024');
		$db->sql_query('DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id BETWEEN 912001 AND 912010');
		$store->delete($key);
		break;
	default: throw new InvalidArgumentException('Unknown portal calendar fixture');
}
$db->sql_transaction('commit');
