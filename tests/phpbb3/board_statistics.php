<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[2])) { exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', getenv('PLUS_TEST_DB_USER') ?: 'root', getenv('PLUS_TEST_DB_PASSWORD'), $argv[2], (int) (getenv('PLUS_TEST_DB_PORT') ?: 3306));
$db->set_charset('utf8mb4');

function stats_expect($value, $message) { if (!$value) { throw new RuntimeException($message); } }
function stats_clone($table, $template, $changes)
{
	global $db;
	$row = array_replace($template, $changes);
	$values = array_map(static function ($value) use ($db) { return $value === null ? 'NULL' : "'" . $db->real_escape_string($value) . "'"; }, $row);
	$db->query("INSERT INTO $table (" . implode(',', array_keys($row)) . ') VALUES (' . implode(',', $values) . ')');
}

switch ($argv[1])
{
	case 'seed':
		$db->query("UPDATE phpbb_stats_config SET config_value='2' WHERE config_name='return_limit'");
		$db->query("UPDATE phpbb_stats_config SET config_value='157' WHERE config_name='page_views'");
		$db->query("INSERT INTO phpbb_stats_config VALUES ('install_date','1000000000') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)");
		$db->query("INSERT INTO phpbb_stats_modules (module_id,name,active,installed,display_order,auth_value,update_time) VALUES
			(11,'most_viewed_topics',1,1,2,0,17),(12,'most_active_topics',1,1,7,1,0),(13,'top_posters',1,1,4,0,0),
			(14,'site_hist_week_top_posters',1,1,5,1,11),(15,'site_hist_month_top_posters',1,1,8,5,12),(16,'forum_index',1,1,1,0,0),
			(17,'most_active_topics',0,1,10,0,0),(18,'most_active_topics',1,0,9,0,0),(19,'most_viewed_topics',1,1,3,2,0),
			(20,'custom_fixture',1,1,11,0,0),(21,'top_smilies',1,1,12,0,0)");
		break;
	case 'converted':
		require_once dirname(__DIR__, 2) . '/phpBB3/ext/phpbb2plus/community/controller/statistics.php';
		$zone = new DateTimeZone('Europe/Berlin');
		$sunday = new DateTimeImmutable('2024-03-31 19:00', $zone);
		foreach (array('week'=>'2024-03-25', 'month'=>'2024-03-01') as $period=>$start)
		{
			$range = \phpbb2plus\community\controller\statistics::window($period, $sunday);
			stats_expect($range === array((new DateTimeImmutable($start, $zone))->getTimestamp(),
				(new DateTimeImmutable('2024-04-01', $zone))->getTimestamp()), 'The board-local period lost Sunday or a daylight-saving transition');
		}
		$value = $db->query("SELECT config_value FROM p3_config_text WHERE config_name='plus_statistics_modules'")->fetch_row()[0];
		$modules = json_decode($value, true);
		stats_expect(array_column($modules, 'module_id') === array(16,11,19,13,14,12,15,18,17,20,21), 'Lost statistics registration or module order');
		$map = array_column($modules, null, 'module_id');
		stats_expect($map[11]['renderer'] === 'native' && $map[14]['renderer'] === 'native' && $map[11]['update_time'] === 17 &&
			$map[20]['renderer'] === 'unported' && $map[20]['source_sha256'] === '' && $map[21]['renderer'] === 'unported' &&
			preg_match('/^[a-f0-9]{64}$/D', $map[21]['source_sha256']), 'Lost the statistics module metadata');
		$config = array_column($db->query("SELECT config_name,config_value FROM p3_config WHERE config_name LIKE 'plus_statistics_%'")->fetch_all(MYSQLI_ASSOC), 'config_value', 'config_name');
		stats_expect((int) $config['plus_statistics_limit'] === 2 && (int) $config['plus_statistics_views'] === 157 &&
			(int) $config['plus_statistics_installed'] === 1000000000 && $config['plus_statistics_version'] === '2.1.5', 'Statistics settings changed');
		break;
	case 'setup':
		$db->begin_transaction();
		$user = $db->query("SELECT * FROM p3_users WHERE username='ModernMember'")->fetch_assoc();
		$topic = $db->query('SELECT * FROM p3_topics WHERE topic_id=1')->fetch_assoc();
		$post = $db->query('SELECT * FROM p3_posts WHERE post_id=1')->fetch_assoc();
		stats_expect($db->query("SELECT forum_password FROM p3_forums WHERE forum_id=15")->fetch_row()[0] === '', 'Unexpected protected test forum');
		$db->query("UPDATE p3_forums SET forum_password='temporary-local-forum-password' WHERE forum_id=15");
		$profiles = array(1=>'StatsPublic', 2=>'StatsPrivate', 3=>'StatsPassword', 4=>'StatsDormant', 5=>'StatsDeleted');
		foreach ($profiles as $id=>$name)
		{
			stats_clone('p3_users', $user, array('user_id'=>975800+$id,'username'=>$name,'username_clean'=>strtolower($name),
				'user_email'=>strtolower($name) . '@example.invalid','user_type'=>$id === 4 ? 1 : 0,'user_posts'=>99999));
		}
		// Fields are forum, number of posts, topic visibility, post visibility, account and moved target.
		$cases = array(
			'Stats public &amp; Grüße'=>array(1,4,1,1,1,0), 'Stats private'=>array(9,5,1,1,2,0),
			'Stats password'=>array(15,6,1,1,3,0), 'Stats hidden topic'=>array(1,1,0,1,5,0),
			'Stats moved'=>array(1,1,1,1,5,1), 'Stats removed first post'=>array(1,1,1,2,5,0),
			'Stats inactive author'=>array(1,2,1,1,4,0),
		);
		$id = 0;
		foreach ($cases as $label=>$case)
		{
			++$id;
			list($forum,$count,$topic_state,$post_state,$author,$moved) = $case;
			$topic_id = 975500 + $id;
			$first = 975600 + $id * 10;
			stats_clone('p3_topics', $topic, array('topic_id'=>$topic_id,'forum_id'=>$forum,'topic_title'=>$label,'topic_visibility'=>$topic_state,
				'topic_views'=>999900+$count,'topic_posts_approved'=>$count,'topic_moved_id'=>$moved,'topic_status'=>$moved ? 2 : 0,
				'topic_first_post_id'=>$first,'topic_last_post_id'=>$first+$count-1));
			for ($i=0; $i<$count; ++$i)
			{
				stats_clone('p3_posts', $post, array('post_id'=>$first+$i,'topic_id'=>$topic_id,'forum_id'=>$forum,'poster_id'=>975800+$author,
					'post_time'=>time(),'post_postcount'=>1,'post_visibility'=>$post_state));
			}
		}
		$db->commit();
		break;
	case 'cleanup':
		$db->begin_transaction();
		$db->query("UPDATE p3_forums SET forum_password='' WHERE forum_id=15 AND forum_password='temporary-local-forum-password'");
		$db->query('DELETE FROM p3_posts WHERE post_id BETWEEN 975610 AND 975674 AND topic_id BETWEEN 975501 AND 975507');
		$db->query('DELETE FROM p3_topics WHERE topic_id BETWEEN 975501 AND 975507');
		$db->query('DELETE FROM p3_users WHERE user_id BETWEEN 975801 AND 975805');
		$db->commit();
		break;
	case 'hide-profiles':
	case 'restore-profiles':
		$member = (int) $db->query("SELECT user_id FROM p3_users WHERE username='HistoricMember'")->fetch_row()[0];
		$option = (int) $db->query("SELECT auth_option_id FROM p3_acl_options WHERE auth_option='u_viewprofile'")->fetch_row()[0];
		stats_expect($member > 1 && $option, 'Missing ordinary fixture member or native profile permission');
		$db->begin_transaction();
		if ($argv[1] === 'hide-profiles')
		{
			stats_expect(!$db->query("SELECT 1 FROM p3_acl_users WHERE user_id=$member AND forum_id=0 AND auth_option_id=$option")->num_rows, 'Unexpected fixture override');
			$db->query("INSERT INTO p3_acl_users (user_id,forum_id,auth_option_id,auth_role_id,auth_setting) VALUES ($member,0,$option,0,0)");
		}
		else { $db->query("DELETE FROM p3_acl_users WHERE user_id=$member AND forum_id=0 AND auth_option_id=$option AND auth_role_id=0 AND auth_setting=0"); }
		$db->query("UPDATE p3_users SET user_permissions='' WHERE user_id=$member");
		$db->commit();
		break;
	default: exit(2);
}
