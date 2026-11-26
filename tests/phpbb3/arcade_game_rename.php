<?php
// The runner supplies a freshly converted, disposable loopback board.
if (PHP_SAPI!=='cli' || getenv('PLUS_TEST_DATABASE')!=='1' || $argc!==3 || !preg_match('/^plus3_test_[a-f0-9]{24}$/D',$argv[1])) { exit(2); }
define('IN_PHPBB',true); $phpbb_root_path='./'; $phpEx='php'; require './common.php';
if ($dbhost!=='127.0.0.1' || $dbname!==$argv[1]) { exit(2); }
$p=$table_prefix.'plus_arc_'; $phase=$argv[2]; $state='plus_test_arcade_rename';
$rows=static function($sql) use ($db) {
	$res=$db->sql_query($sql); $data=$db->sql_fetchrowset($res); $db->sql_freeresult($res); return $data;
};
$text=$phpbb_container->get('config_text');
$actor=(int)$rows('SELECT user_id FROM '.USERS_TABLE." WHERE username_clean='arcadescoresjunior'")[0]['user_id'];
$refs=array('scores'=>'game_name','all_scores'=>'game_name','favorites'=>'fav_game_name',
	'comments'=>array('comment_game_name','game_name'),'ratings'=>'rate_game_name','monthly'=>'highscore_game','bans'=>'game',
	'tour_games'=>'game_name','tour_plays'=>'last_played_game','players'=>'last_played','categories'=>'last_game');
$keys=array('games'=>'game_id','scores'=>'row_id','all_scores'=>'row_id','favorites'=>'row_id','comments'=>'comment_id',
	'ratings'=>'row_id','monthly'=>'highscore_id','bans'=>'row_id','tour_games'=>'row_id','tour_plays'=>'row_id','players'=>'row_id',
	'categories'=>'cat_id','saves'=>'row_id','launches'=>'token_key','log'=>'record_no');
$snapshot=static function() use ($keys,$p,$rows) {
	$tables=array(); foreach ($keys as $table=>$key) { $tables[$table]=array_column($rows("SELECT * FROM $p$table ORDER BY $key"),null,$key); }
	return $tables;
};
$asset_signature=static function() use ($rows,$p,$config) {
	$data=$rows("SELECT * FROM {$p}assets WHERE game_id=60 ORDER BY path_key");
	foreach ($data as &$r) { $r['digest']=hash_file('sha256','./'.trim($config['upload_path'],'/').'/'.$r['storage_name']); }
	return hash('sha256',serialize($data));
};
if ($phase==='prepare')
{
	$original=$snapshot();
	if ($original['games'][60]['game_name']!=='puzzle' || $original['games'][60]['asset_status']!=='ready') { throw new RuntimeException('Expected the converted puzzle fixture'); }
	$text->set($state,json_encode(array('before'=>$original,'assets'=>$asset_signature()),JSON_THROW_ON_ERROR));
	$db->sql_query("UPDATE {$p}games SET game_charge=0,game_reward=0,game_bonus=0,at_game_bonus=0,highscore_limit=0,at_highscore_limit=0,score_type=0 WHERE game_id=60");
	// Put a recognisable fixture row in every historical name column, including rarely populated tables.
	foreach ($refs as $table=>$fields)
	{
		$values=array_fill_keys((array)$fields,'puzzle');
		$values[$keys[$table]]=899901;
		if ($table==='categories') { $values+=array('cat_type'=>'p','cat_name'=>'Rename history','total_games'=>18,'total_played'=>'27'); }
		if ($table==='scores' || $table==='all_scores') { $values+=array('score_units'=>370000,'player'=>'Rename history'); }
		if ($table==='players')
		{
			foreach (array('first','second','third','at_first','at_second','at_third') as $field)
			{ $values[$field.'_list']='old-medal-game|puzzle-extra|puzzle|puzzle||'; $values[$field.'_places']=83; }
		}
		$db->sql_query("INSERT INTO $p$table ".$db->sql_build_array('INSERT',$values));
	}
	$db->sql_query("INSERT INTO {$p}bans ".$db->sql_build_array('INSERT',array('row_id'=>899902,'game'=>'removed-game')));
}
elseif ($phase==='pin')
{
	$backup=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	// Simulate an active receipt issued before v118 was installed on an existing board.
	$db->sql_query("UPDATE {$p}launches SET start_name='' WHERE game_id=60 AND user_id=$actor AND finished_at=0");
	$backup['ready']=$snapshot(); $text->set($state,json_encode($backup,JSON_THROW_ON_ERROR));
}
elseif ($phase==='never' || $phase==='allow')
{
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$actor,array('a_plus_arc_games'=>$phase==='never'?ACL_NEVER:ACL_NO));
}
elseif ($phase==='full-list' || $phase==='restore-list')
{
	if ($phase==='full-list') { $list=str_repeat('puzzle|',9362); }
	else
	{
		$g=$rows("SELECT game_name,protocol_name FROM {$p}games WHERE game_id=60")[0];
		if ($g['game_name']!=='puzzle' || $g['protocol_name']!=='' ||
			$rows("SELECT row_id FROM {$p}all_scores WHERE game_name='longer-puzzle-name'") ||
			$rows("SELECT row_id FROM {$p}bans WHERE game='longer-puzzle-name'"))
		{ throw new RuntimeException('A ranking-list conflict committed part of a rename'); }
		$list='old-medal-game|puzzle-extra|puzzle|puzzle||';
	}
	$db->sql_query("UPDATE {$p}players SET first_list='".$db->sql_escape($list)."' WHERE row_id=899901");
}
elseif ($phase==='renamed')
{
	$backup=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$expected=$backup['ready']; $after=$snapshot();
	$new='schlüssel&neu';
	foreach ($refs as $table=>$fields)
	{
		foreach ($expected[$table] as &$r) { foreach ((array)$fields as $field) { if (($r[$field] ?? '')==='puzzle') { $r[$field]=$new; } } }
		unset($r);
		if ($table==='players')
		{
			foreach ($expected[$table] as &$r)
			{
				foreach (array('first_list','second_list','third_list','at_first_list','at_second_list','at_third_list') as $field)
				{
					if ($r[$field]===null) { continue; }
					$parts=explode('|',$r[$field]); foreach (array_keys($parts,'puzzle',true) as $index) { $parts[$index]=$new; }
					$r[$field]=implode('|',$parts);
				}
			}
			unset($r);
		}
		if ($expected[$table]!==$after[$table]) { throw new RuntimeException('Unexpected history rewrite in '.$table); }
	}
	$expected['games'][60]['game_name']=$new; $expected['games'][60]['protocol_name']='puzzle';
	if ($expected['games']!==$after['games'] || $expected['saves']!==$after['saves'] || $asset_signature()!==$backup['assets'])
	{ throw new RuntimeException('Rename changed a counter, winner, save or protected game file'); }
	foreach ($expected['launches'] as &$launch)
	{
		if ((int)$launch['game_id']===60 && $launch['start_name']==='' && (int)$launch['expires_at']>time()) { $launch['start_name']='puzzle'; }
	}
	unset($launch);
	if ($expected['launches']!==$after['launches']) { throw new RuntimeException('Rename lost or reassigned a valid native play receipt'); }
}
elseif ($phase==='verify')
{
	$backup=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	$g=$rows("SELECT game_name,protocol_name,main_asset FROM {$p}games WHERE game_id=60")[0];
	$sc=$rows("SELECT score_units FROM {$p}scores WHERE owner_id=$actor AND game_name='Spiel-Neu'");
	if ($g['game_name']!=='Spiel-Neu' || $g['protocol_name']!=='puzzle' || $g['main_asset']!==$backup['before']['games'][60]['main_asset'] ||
		$asset_signature()!==$backup['assets'] || (int)($sc[0]['score_units'] ?? 0)!==9930000) { throw new RuntimeException('Media mapping or a verified score was lost after repeated renames'); }
	foreach ($refs as $table=>$fields)
	{
		$r=$rows("SELECT * FROM $p$table WHERE {$keys[$table]}=899901")[0];
		foreach ((array)$fields as $field) { if ($r[$field]!=='Spiel-Neu') { throw new RuntimeException('Missing final source reference '.$table.'.'.$field); } }
	}
}
elseif ($phase==='cleanup')
{
	$backup=json_decode($text->get($state),true,512,JSON_THROW_ON_ERROR);
	require_once './includes/acp/auth.php';
	(new auth_admin())->acl_set('user',0,$actor,array('a_plus_arc_games'=>ACL_NO));
	foreach ($snapshot() as $table=>$after)
	{
		$key=$keys[$table]; $before=$backup['before'][$table];
		foreach ($after as $id=>$row)
		{
			$where=$key."='".$db->sql_escape((string)$id)."'";
			if (!isset($before[$id])) { $db->sql_query("DELETE FROM $p$table WHERE $where"); }
			elseif ($row!==$before[$id]) { $db->sql_query("UPDATE $p$table SET ".$db->sql_build_array('UPDATE',$before[$id])." WHERE $where"); }
		}
		foreach (array_diff_key($before,$after) as $row) { $db->sql_query("INSERT INTO $p$table ".$db->sql_build_array('INSERT',$row)); }
	}
	$db->sql_query('DELETE FROM '.LOG_TABLE." WHERE user_id=$actor AND log_operation='LOG_PLUS_ARC_RENAME_GAME'");
	$text->delete($state);
}
else { throw new RuntimeException('Unexpected Arcade rename fixture phase'); }
