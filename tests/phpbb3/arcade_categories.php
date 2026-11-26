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
	case 'cleanup':
		$db->sql_query("DELETE FROM {$p}games WHERE game_id BETWEEN 902010 AND 902013");
		$db->sql_query("DELETE FROM {$p}categories WHERE cat_id BETWEEN 902000 AND 902004");
		break;
	default:
		throw new InvalidArgumentException('Unknown Arcade category fixture phase');
}
$db->sql_transaction('commit');
