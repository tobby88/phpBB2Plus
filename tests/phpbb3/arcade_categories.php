<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'seed':
		$result = $db->sql_query("SELECT user_id FROM " . USERS_TABLE . " WHERE username_clean='historicmember'");
		$member = (int) $db->sql_fetchfield('user_id');
		$db->sql_freeresult($result);
		if ($member <= ANONYMOUS || $member === 2) { throw new RuntimeException('Missing remapped Arcade member'); }
		$db->sql_query("INSERT INTO {$p}categories (cat_id,cat_name,cat_type,cat_parent,group_required,cat_desc) VALUES
			(902000,'Test catalogue','p',0,0,'Historical totals'),(902001,'Members subcategory','s',902000,71,''),
			(902002,'Reference link','l',0,0,'https://example.invalid/archive?x=1&amp;y=2'),
			(902003,'Members link','l',0,71,'https://example.invalid/members'),(902004,'Unconfigured link','l',0,0,'')");
		$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id,game_avail) VALUES
			(902010,'overview-public','Open fixture game',902000,1),(902011,'overview-private','Private fixture game',902001,1),
			(902012,'overview-offline','Offline fixture game',902000,0)");
		$db->sql_query("UPDATE {$p}categories SET total_games=17,total_played='18446744073709551615',moderator_id=$member,
			last_player=87,last_user_id=87,last_time=11111,last_game='overview-private' WHERE cat_id=902000");
		break;
	case 'public':
		$db->sql_query("UPDATE {$p}categories SET last_game='overview-public' WHERE cat_id=902000");
		break;
	case 'former':
		$db->sql_query("UPDATE {$p}categories SET last_user_id=0,last_player=98 WHERE cat_id=902000");
		break;
	case 'guest':
		$db->sql_query("UPDATE {$p}categories SET last_user_id=0,last_player=-1,last_game='overview-offline' WHERE cat_id=902000");
		break;
	case 'incomplete':
		$db->sql_query("UPDATE {$p}categories SET last_user_id=0,last_player=NULL WHERE cat_id=902000");
		break;
	case 'moved':
		$db->sql_query("UPDATE {$p}categories SET last_game='puzzle',last_player=-1 WHERE cat_id=902000");
		break;
	case 'duplicate':
		$db->sql_query("UPDATE {$p}categories SET last_game='overview-public' WHERE cat_id=902000");
		$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id,game_avail) VALUES (902013,'overview-public','Ambiguous private title',902001,1)");
		break;
	case 'verify-visits':
		$result = $db->sql_query("SELECT cat_id,total_played FROM {$p}categories WHERE cat_id IN (902002,902003) ORDER BY cat_id");
		$counts = array_map('intval', array_column($db->sql_fetchrowset($result), 'total_played'));
		$db->sql_freeresult($result);
		if ($counts !== array(1,0)) { throw new RuntimeException('Category link visit counts changed unexpectedly'); }
		$db->sql_query("UPDATE {$p}categories SET total_played='18446744073709551615' WHERE cat_id=902002");
		break;
	case 'charts':
		$keys = array('games_total_top','games_new_games','games_show_stats','games_at_highscore');
		$result = $db->sql_query("SELECT config_name,config_value FROM {$p}settings WHERE " . $db->sql_in_set('config_name', $keys));
		$original = array_column($db->sql_fetchrowset($result), 'config_value', 'config_name');
		$db->sql_freeresult($result);
		if (count($original) !== count($keys)) { throw new RuntimeException('Missing original Arcade chart settings'); }
		$config->set('plus_test_category_chart_settings', json_encode($original), false);
		$db->sql_query("UPDATE {$p}settings SET config_value=CASE WHEN config_name='games_total_top' THEN '2' ELSE '1' END WHERE " . $db->sql_in_set('config_name', $keys));
		$db->sql_query("UPDATE {$p}games SET played=100000+game_id,date_added=100000+game_id WHERE game_id BETWEEN 902010 AND 902012");
		$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id,game_avail,allow_guest,played,date_added)
			VALUES (902014,'overview-second','Second public fixture',902000,1,1,900000,2000015)");
		for ($id = 902020; $id <= 902023; $id++)
		{
			$db->sql_query("INSERT INTO {$p}games (game_id,game_name,game_desc,cat_id,game_avail,at_highscore_limit)
				VALUES ($id,'overview-club-$id','Club game $id',902001,1,10)");
			foreach (array('scores','all_scores') as $table)
			{
				$db->sql_query("INSERT INTO $p$table (row_id,game_name,player_id,owner_id,player,score_units,recorded_at)
					VALUES ($id,'overview-club-$id',87,87,'DepartingFixture',100,11111)");
			}
		}
		break;
	case 'least':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name IN ('games_new_games','games_at_highscore')");
		$db->sql_query("UPDATE {$p}categories SET special_play=2 WHERE cat_id=902000");
		break;
	case 'eligible-least':
		$db->sql_query("UPDATE {$p}categories SET special_play=1 WHERE cat_id=902000");
		break;
	case 'stats-off':
		$db->sql_query("UPDATE {$p}settings SET config_value='0' WHERE config_name='games_show_stats'");
		break;
	case 'cleanup':
		if (isset($config['plus_test_category_chart_settings']))
		{
			foreach (json_decode($config['plus_test_category_chart_settings'], true) as $name => $value)
			{
				$db->sql_query("UPDATE {$p}settings SET config_value='" . $db->sql_escape($value) . "' WHERE config_name='" . $db->sql_escape($name) . "'");
			}
			$config->delete('plus_test_category_chart_settings');
		}
		foreach (array('scores','all_scores') as $table) { $db->sql_query("DELETE FROM $p$table WHERE row_id BETWEEN 902020 AND 902023"); }
		$db->sql_query("DELETE FROM {$p}games WHERE game_id BETWEEN 902010 AND 902023");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id BETWEEN 902000 AND 902004");
		break;
	default:
		throw new InvalidArgumentException('Unknown Arcade category fixture phase');
}
$db->sql_transaction('commit');
