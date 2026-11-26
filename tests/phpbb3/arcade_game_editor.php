<?php
// Inspect and restore only this disposable converter fixture.
if (PHP_SAPI !== 'cli' || getenv('PLUS_TEST_DATABASE') !== '1' || $argc !== 3 ||
	!preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true);
$phpbb_root_path = './';
$phpEx = 'php';
require './common.php';
if ($dbhost !== '127.0.0.1' || $dbname !== $argv[1]) { exit(2); }
$p = $table_prefix . 'plus_arc_';
$rows = static function ($sql) use ($db) {
	$result = $db->sql_query($sql);
	$data = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $data;
};
$phase = $argv[2];
$grant = (int) $rows('SELECT user_id FROM ' . USERS_TABLE . " WHERE username_clean='arcadescoresjunior'")[0]['user_id'];
$text = $phpbb_container->get('config_text');
$key = 'plus_test_game_edit_backup';
$game = $rows("SELECT * FROM {$p}games WHERE game_id=61")[0];
$cats = array_column($rows("SELECT * FROM {$p}categories WHERE cat_id IN(-1,10,11,12)"),null,'cat_id');
$history = static function () use ($rows,$p,$config) {
	$data = array();
	foreach (array('scores','all_scores') as $suffix)
	{ $data[] = $rows("SELECT * FROM $p$suffix WHERE game_name='timer' ORDER BY row_id"); }
	$data[] = $rows("SELECT * FROM {$p}monthly WHERE highscore_game='timer' ORDER BY highscore_id");
	$assets = $rows("SELECT * FROM {$p}assets WHERE game_id=61 ORDER BY path_key");
	foreach ($assets as $asset)
	{
		$file = './' . trim($config['upload_path'],'/') . '/' . $asset['storage_name'];
		if (!is_file($file)) { throw new RuntimeException('Missing synthetic Arcade medium'); }
		$data[] = array($asset,hash_file('sha256',$file));
	}
	return hash('sha256',serialize($data));
};
if ($phase==='prepare')
{
	$rights = $rows("SELECT rights_json FROM {$table_prefix}plus_jr_delegations WHERE user_id=$grant");
	if (json_decode($rights[0]['rights_json'],true)!==array('a_plus_arc_scores','a_plus_arc_games') ||
		\phpbb2plus\arcade\service\catalog::plain($rows("SELECT instructions FROM {$p}games WHERE game_id=63")[0]['instructions'])!=='Prüfung 🎮 & Punkte')
	{ throw new RuntimeException('Original edit grant or imported supplementary text did not survive the upgrade'); }
	$size = $rows("SELECT config_value FROM {$p}settings WHERE config_name='games_per_admin_page'")[0]['config_value'];
	$text->set($key,json_encode(array('game'=>$game,'cats'=>$cats,'history'=>$history(),'size'=>$size),JSON_THROW_ON_ERROR));
	$db->sql_query("UPDATE {$p}settings SET config_value='2' WHERE config_name='games_per_admin_page'");
	$db->sql_query("UPDATE {$p}games SET played=7,reverse_list=1,game_avail=1 WHERE game_id=61");
	$db->sql_query("UPDATE {$p}categories SET total_games=41,total_played='125',last_game='timer',last_player=1,last_time=1000000 WHERE cat_id=10");
	$db->sql_query("UPDATE {$p}categories SET total_games=3,total_played='222' WHERE cat_id=12");
	$db->sql_query("UPDATE {$p}categories SET total_played='350' WHERE cat_id=11");
}
elseif ($phase==='edited')
{
	if ((int) $game['game_control']!==3 || (int) $game['score_type']!==6 || (int) $game['win_height']!==180 ||
		$game['highscore_limit']!=='9223372036854775807' || (int) $game['game_bonus']!==65535 ||
		(int) $game['at_game_bonus']!==32767 || (int) $game['game_reward']!==4294967295 ||
		\phpbb2plus\arcade\service\catalog::plain($game['instructions'])!=="Mäuse 🎮\nText <Probe> &lt;Beispiel&gt;" ||
		\phpbb2plus\arcade\service\catalog::plain($game['game_desc'])!==str_repeat('🎮',255))
	{ throw new RuntimeException('Rare settings, exact numbers, Unicode or literal entity text did not round-trip'); }
	foreach (array('scores'=>'winner_id','all_scores'=>'all_winner_id') as $table=>$column)
	{
		$top = $rows("SELECT s.owner_id FROM $p$table s JOIN " . USERS_TABLE . " u ON u.user_id=s.owner_id WHERE s.game_name='timer'
			ORDER BY s.score_units DESC,s.recorded_at,s.player_id,s.row_id LIMIT 1");
		if (!$top || (int) $game[$column] !== (int) $top[0]['owner_id']) { throw new RuntimeException('Leaderboard winner was not refreshed after reversing the order'); }
	}
}
elseif ($phase==='moved')
{
	$backup = json_decode($text->get($key),true,512,JSON_THROW_ON_ERROR);
	if ((int) $game['cat_id']!==12 || (int) $cats[10]['total_games']!==40 || (int) $cats[12]['total_games']!==4 ||
		(string) $cats[10]['total_played']!=='118' || (string) $cats[12]['total_played']!=='229' ||
		(string) $cats[11]['total_played']!=='357' || (int) $cats[-1]['total_games']!==(int) $backup['cats'][-1]['total_games'] ||
		$cats[12]['last_game']!=='timer' || $cats[10]['last_game']==='timer')
	{ throw new RuntimeException('Moving an individual game lost historical counts, parents or last-play information'); }
}
elseif ($phase==='never' || $phase==='restore-right')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$grant,array('a_plus_arc_games'=>$phase==='never' ? ACL_NEVER : ACL_NO));
}
elseif ($phase==='verify' || $phase==='cleanup')
{
	$backup = json_decode($text->get($key),true,512,JSON_THROW_ON_ERROR);
	if ($phase==='verify')
	{
		if ($history()!==$backup['history'] || (int) $game['native_group_id']<=0 || (int) $game['group_required']!==-1)
		{ throw new RuntimeException('The native group scope, protected file bytes or historical leaderboard rows changed unexpectedly'); }
		foreach (array('game_name','game_path','image_path','main_asset','game_flash','game_use_gl','asset_status','icon_storage') as $field)
		{ if ($backup['game'][$field]!==$game[$field]) { throw new RuntimeException('Metadata update changed the protected field ' . $field); } }
	}
	else
	{
		require_once './includes/acp/auth.php';
		(new auth_admin())->acl_set('user',0,$grant,array('a_plus_arc_games'=>ACL_NO));
		$db->sql_query("UPDATE {$p}games SET " . $db->sql_build_array('UPDATE',$backup['game']) . ' WHERE game_id=61');
		foreach ($backup['cats'] as $id=>$data)
		{ $db->sql_query("UPDATE {$p}categories SET " . $db->sql_build_array('UPDATE',$data) . ' WHERE cat_id=' . (int) $id); }
		$db->sql_query("UPDATE {$p}settings SET config_value='" . $db->sql_escape($backup['size']) . "' WHERE config_name='games_per_admin_page'");
		$db->sql_query('DELETE FROM ' . LOG_TABLE . " WHERE log_operation='LOG_PLUS_ARC_EDIT_GAME' AND user_id=$grant");
		$text->delete($key);
	}
}
else { throw new RuntimeException('Unexpected Arcade game editor fixture phase'); }
