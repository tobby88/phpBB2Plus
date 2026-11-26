<?php
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D', $argv[1])) { exit(2); }
define('IN_PHPBB', true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$prefix = $table_prefix . 'plus_arc_';
$cats = $prefix . 'categories';
$games = $prefix . 'games';
$group = 905300;
$store = $phpbb_container->get('config_text');
$key = 'plus_test_arcade_category_admin';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$found = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $found;
};
$role = static function ($value) use ($db, $auth, $phpbb_root_path, $phpEx) {
	require_once './includes/acp/auth.php';
	$manager = new auth_admin();
	$manager->acl_set('user', 0, 44, array('a_plus_arc_categories' => $value));
	$auth->acl_clear_prefetch(44);
};
$db->sql_transaction('begin');
switch ($argv[2])
{
	case 'older':
	case 'upgraded':
		$upgraded = $argv[2] === 'upgraded';
		if ((bool) $phpbb_container->get('dbal.tools')->sql_column_exists($cats, 'native_group_id') !== $upgraded ||
			(bool) $rows('SELECT auth_option_id FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option='a_plus_arc_categories'") !== $upgraded)
		{ throw new RuntimeException('Unexpected Arcade category upgrade state'); }
		$original = $rows("SELECT * FROM $cats ORDER BY cat_id");
		foreach ($original as &$row)
		{
			if ($upgraded && (int) $row['native_group_id'] !== 0) { throw new RuntimeException('The upgrade reinterpreted an existing category restriction'); }
			unset($row['native_group_id']);
		}
		unset($row);
		if (!in_array(-1, array_map('intval', array_column($original, 'cat_id')), true)) { throw new RuntimeException('Lost the global category'); }
		echo hash('sha256', json_encode(array($original, $rows("SELECT * FROM {$prefix}grants ORDER BY legacy_group,subject_type,subject_id"))));
		break;
	case 'seed':
		$original = $rows("SELECT * FROM $cats ORDER BY cat_id");
		$locations = $rows("SELECT game_id,cat_id FROM $games ORDER BY game_id");
		if (count($original) > 150 || count($locations) > 300 || $store->get($key) !== null ||
			$rows("SELECT cat_id FROM $cats WHERE cat_id>=908001") || $rows("SELECT game_id FROM $games WHERE game_id>=908010") ||
			$rows('SELECT group_id FROM ' . GROUPS_TABLE . " WHERE group_id=$group") || $rows("SELECT legacy_group FROM {$prefix}grants WHERE legacy_group=$group"))
		{ throw new RuntimeException('Expected the disposable converted Arcade fixture'); }
		$member = (int) ($rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='historicmember'")[0]['user_id'] ?? 0);
		if ($member <= ANONYMOUS || $member === 2) { throw new RuntimeException('Missing the converted original Arcade player'); }
		$record = $rows("SELECT rights_json,modules_b64 FROM {$table_prefix}plus_jr_records WHERE source_user=44 AND user_id=44")[0] ?? array();
		if (($record['rights_json'] ?? '') !== '["a_plus_arc_categories"]' || base64_decode($record['modules_b64'], true) !== md5('ArcadeCategoriesadmin_arcade_cats.php'))
		{ throw new RuntimeException('The historical category registration did not map to its sole native module'); }
		$store->set($key, json_encode(array($original, $locations)));
		$prototype = $rows('SELECT * FROM ' . GROUPS_TABLE . ' WHERE group_name=\'REGISTERED\'')[0];
		$prototype['group_id'] = $group;
		$prototype['group_type'] = GROUP_CLOSED;
		$prototype['group_name'] = 'Category operators test club';
		$db->sql_query('INSERT INTO ' . GROUPS_TABLE . ' ' . $db->sql_build_array('INSERT', $prototype));
		$db->sql_query('INSERT INTO ' . USER_GROUP_TABLE . " (group_id,user_id,user_pending,group_leader) VALUES ($group,44,1,0)");
		$db->sql_query("INSERT INTO {$prefix}grants (legacy_group,subject_type,subject_id) VALUES ($group,'u',$member)");
		$db->sql_query("INSERT INTO $cats (cat_id,cat_name,cat_type,cat_parent,cat_order,group_required,cat_desc) VALUES
			(908001,'Fixture first','p',0,901,0,''),(908002,'Fixture child','s',908001,902,0,''),(908003,'Fixture sibling','s',908001,903,0,''),
			(908004,'Fixture second','p',0,904,0,''),(908005,'Fixture long-lived link','l',0,905,0,'https://example.invalid/fixture'),
			(908006,'Fixture orphan','s',909991,906,0,''),(908007,'Fixture historical scope','p',0,907,$group,'')");
		$db->sql_query("INSERT INTO $games (game_id,game_name,game_desc,cat_id,game_avail,played) VALUES
			(908010,'cat-first','First game',908001,1,5),(908011,'cat-child','Child game',908002,1,8),
			(908012,'cat-offline','Offline child game',908002,0,3),(908013,'cat-sibling','Sibling game',908003,1,4),
			(908014,'cat-second','Second game',908004,1,2),(908015,'cat-orphan','Orphaned game',909992,1,2),
			(908016,'cat-orphan','Ambiguous old game name',908006,0,0)");
		$db->sql_query("UPDATE $cats SET last_game='cat-child',last_player=1,last_user_id=$member,last_time=2000000200 WHERE cat_id IN (908001,908002)");
		$db->sql_query("UPDATE $cats SET last_game='cat-sibling',last_player=98,last_user_id=0,last_time=2000000150 WHERE cat_id=908003");
		$db->sql_query("UPDATE $cats SET last_game='cat-second',last_player=-1,last_user_id=0,last_time=2000000050 WHERE cat_id=908004");
		$db->sql_query("UPDATE $cats SET total_played='18446744073709551615',last_game='cat-second',last_player=-1,last_time=2100000100 WHERE cat_id=908005");
		$db->sql_query("UPDATE $cats SET last_game='cat-orphan',last_player=1,last_user_id=$member,last_time=2100000000 WHERE cat_id=908006");
		foreach (array('scores','all_scores') as $table)
		{
			$db->sql_query("INSERT INTO $prefix$table (row_id,game_name,player_id,owner_id,player,score_units,recorded_at)
				VALUES (908011,'cat-child',1,$member,'HistoricMember',134500,12345)");
		}
		$db->sql_query("INSERT INTO {$prefix}saves (row_id,user_id,owner_id,game_id,game_data,saved_at)
			VALUES (908011,1,$member,908011,'Testspielstand Ü','12346')");
		break;
	case 'state':
		$state = $rows("SELECT cat_id,cat_name,cat_type,cat_parent,cat_order,group_required,native_group_id,moderator_id,special_play,
			total_games,total_played,last_time,last_game,last_player,last_user_id,cat_icon,icon_storage FROM $cats WHERE cat_id=-1 OR cat_id>=908001 ORDER BY cat_order,cat_id");
		echo json_encode(array('cats' => $state, 'games' => array_column($rows("SELECT game_id,cat_id FROM $games WHERE game_id BETWEEN 908010 AND 908015"), 'cat_id', 'game_id')));
		break;
	case 'active':
		$migration = new \phpbb2plus\arcade\migrations\v113_category_administration($config, $db, $phpbb_container->get('dbal.tools'), $phpbb_root_path, $phpEx, $table_prefix);
		if ($migration->revert_schema()) { throw new RuntimeException('Downgrading would discard an actively selected native group'); }
		$db->sql_query('UPDATE ' . USER_GROUP_TABLE . " SET user_pending=0 WHERE group_id=$group AND user_id=44");
		break;
	case 'missing-global':
		$original = $rows("SELECT * FROM $cats WHERE cat_id=-1")[0];
		$other = $rows("SELECT * FROM $cats WHERE cat_id<>-1 ORDER BY cat_id");
		$db->sql_query("DELETE FROM $cats WHERE cat_id=-1");
		for ($i = 0; $i < 2; $i++)
		{
			\phpbb2plus\arcade\service\category_history::ensure_global($db, $prefix);
			$restored = $rows("SELECT * FROM $cats WHERE cat_id=-1")[0];
			foreach (array('total_games','total_played','last_time','last_game','last_user_id','last_player') as $field)
			{
				if ($original[$field] !== $restored[$field]) { throw new RuntimeException('Missing global category lost ' . $field); }
			}
		}
		if ($rows("SELECT * FROM $cats WHERE cat_id<>-1 ORDER BY cat_id") !== $other) { throw new RuntimeException('Global category recovery altered another category'); }
		unset($original['cat_id']);
		$db->sql_query("UPDATE $cats SET " . $db->sql_build_array('UPDATE', $original) . ' WHERE cat_id=-1');
		break;
	case 'never': $role(ACL_NEVER); break;
	case 'restore': $role(ACL_NO); break;
	case 'preserved':
		foreach (array('scores','all_scores') as $table)
		{
			if (!$rows("SELECT row_id FROM $prefix$table WHERE row_id=908011 AND game_name='cat-child' AND score_units=134500 AND recorded_at=12345 AND player_id=1"))
			{ throw new RuntimeException('A category change discarded or rewrote a score'); }
		}
		if (!$rows("SELECT row_id FROM {$prefix}saves WHERE row_id=908011 AND game_id=908011 AND game_data='Testspielstand Ü' AND saved_at='12346'"))
		{ throw new RuntimeException('A category change rewrote a player save'); }
		break;
	case 'stale-picture':
		$id = (int) $rows("SELECT cat_id FROM $cats WHERE cat_id>908007 AND cat_icon='images/arcade/new-shared.png'")[0]['cat_id'];
		$artwork = $phpbb_container->get('phpbb2plus.arcade.artwork');
		$source = $artwork->source_root('../legacy');
		if (!$id || !$artwork->import_category(10, 'images/arcade/category.png', $source) ||
			$artwork->import_category($id, 'images/arcade/category.png', $source) ||
			$rows("SELECT cat_id FROM $cats WHERE cat_id=$id AND icon_storage<>''"))
		{ throw new RuntimeException('An offline artwork import ignored the current category path'); }
		break;
	case 'cleanup':
		$data = json_decode($store->get($key), true);
		if (!is_array($data) || count($data) !== 2) { throw new RuntimeException('Lost original category fixture state'); }
		$role(ACL_NO);
		$db->sql_query('DELETE FROM ' . USER_GROUP_TABLE . " WHERE group_id=$group AND user_id=44");
		$db->sql_query('DELETE FROM ' . GROUPS_TABLE . " WHERE group_id=$group AND group_name='Category operators test club'");
		$db->sql_query("DELETE FROM {$prefix}grants WHERE legacy_group=$group AND subject_type='u'");
		$db->sql_query("DELETE FROM $games WHERE game_id BETWEEN 908010 AND 908016");
		foreach (array('scores','all_scores','saves') as $table) { $db->sql_query("DELETE FROM $prefix$table WHERE row_id=908011"); }
		$db->sql_query("DELETE FROM $cats WHERE cat_id BETWEEN 908001 AND 908007 OR (cat_id>908007 AND cat_name LIKE 'Fixture ACP%')");
		foreach ($data[0] as $row)
		{
			$id = (int) $row['cat_id'];
			unset($row['cat_id']);
			$db->sql_query("UPDATE $cats SET " . $db->sql_build_array('UPDATE', $row) . " WHERE cat_id=$id");
		}
		foreach ($data[1] as $row)
		{
			$db->sql_query("UPDATE $games SET " . $db->sql_build_array('UPDATE', array('cat_id'=>$row['cat_id'])) . ' WHERE game_id=' . (int) $row['game_id']);
		}
		$store->delete($key);
		break;
	default: throw new InvalidArgumentException('Unknown fixture phase');
}
$db->sql_transaction('commit');
